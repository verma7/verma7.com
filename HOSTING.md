# Hosting & Deployment

This site is served as static files by [Caddy](https://caddyserver.com/) on a
Google Cloud VM. Set up on 2026-07-23.

## Architecture

| Component | Value |
|---|---|
| GCP project | `freqtrade-478700` |
| VM | `verma7-web` — e2-micro (free tier), Debian 12, 30 GB pd-standard, zone `us-central1-c` |
| Static IP | `136.116.17.249` (reserved address `verma7-web-ip`, ~$3.60/mo — the only recurring cost) |
| Web server | Caddy (Debian package), serves `/var/www/verma7.com`, auto-HTTPS via Let's Encrypt |
| Site content | Clone of this repo at `/var/www/verma7.com` on the VM |
| DNS | Cloudflare (zone `verma7.com`): A records for apex and `www` → `136.116.17.249`, **DNS-only (unproxied)** so Caddy can pass ACME challenges |

TLS certificates are issued and renewed automatically by Caddy; no action needed.

## Deploying

### Automatic (default)

Just push to `master`. A cron job on the VM (`/etc/cron.d/verma7-sync`) runs
`git pull --ff-only` in `/var/www/verma7.com` every 15 minutes, so changes go
live within 15 minutes of pushing. No build step — files are served as-is.

### Manual (immediate)

To deploy right now instead of waiting for the cron:

```bash
gcloud compute ssh verma7-web --zone=us-central1-c \
  --command='cd /var/www/verma7.com && sudo git pull --ff-only'
```

Caddy serves files directly from disk, so no restart is needed.

## Operations

```bash
# SSH into the VM
gcloud compute ssh verma7-web --zone=us-central1-c

# Web server logs (ACME/cert issues show up here)
sudo journalctl -u caddy --since "1 hour ago"

# Restart the web server
sudo systemctl restart caddy

# Caddy config lives at /etc/caddy/Caddyfile
```

## Recreating the VM from scratch

If the VM is ever lost, recreate it with the reserved IP and this startup
script (safe to re-run; it is idempotent):

```bash
cat > /tmp/startup.sh <<'STARTUP'
#!/bin/bash
set -x
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y caddy git

if [ ! -d /var/www/verma7.com/.git ]; then
  rm -rf /var/www/verma7.com
  git clone https://github.com/verma7/verma7.com.git /var/www/verma7.com
fi

cat > /etc/caddy/Caddyfile <<'EOF'
verma7.com, www.verma7.com {
	root * /var/www/verma7.com
	file_server
	encode gzip
}

http:// {
	root * /var/www/verma7.com
	file_server
	encode gzip
}
EOF

systemctl enable caddy
systemctl restart caddy

cat > /etc/cron.d/verma7-sync <<'EOF'
*/15 * * * * root cd /var/www/verma7.com && git pull --ff-only >/dev/null 2>&1
EOF
STARTUP

gcloud compute instances create verma7-web \
  --project=freqtrade-478700 \
  --zone=us-central1-c \
  --machine-type=e2-micro \
  --image-family=debian-12 --image-project=debian-cloud \
  --boot-disk-size=30GB --boot-disk-type=pd-standard \
  --address=verma7-web-ip \
  --tags=http-server,https-server \
  --metadata-from-file=startup-script=/tmp/startup.sh
```

The firewall rules for the `http-server`/`https-server` tags (ports 80/443)
already exist in the project. DNS needs no changes as long as the reserved IP
`verma7-web-ip` is reused.

## Query-router demo service

`/research/query-routing/demo/` calls `GET /classify?q=...`, which Caddy
reverse-proxies to a local service (added 2026-07-23):

| Component | Value |
|---|---|
| Service | `query-router.service` (systemd, `Restart=always`, `MemoryMax=450M`) |
| App | `/opt/query-router/vm_server.py` — Python stdlib HTTP on `127.0.0.1:8642` |
| Model | int8 ONNX export of a fine-tuned DistilBERT (`/opt/query-router/model.int8.onnx`, 64 MB) |
| Deps | venv at `/opt/query-router/venv` (onnxruntime, tokenizers, numpy — no torch) |
| Caddy | `reverse_proxy /classify* 127.0.0.1:8642` in the main site block |

Source and training pipeline live in the local `Fable/query-classifier` repo on
the MacBook (deploy artifacts built by `src/…` + `deploy/`). To update the model:
re-export ONNX, `gcloud compute scp` the new `model.int8.onnx` + `tokenizer/` to
`/opt/query-router/`, then `sudo systemctl restart query-router`.

```bash
# health check
curl -s "https://verma7.com/classify?q=best+tv"
# service logs
sudo journalctl -u query-router --since "1 hour ago"
```

## Health dashboard service

`/health/` is a **private** training + sleep dashboard (Caddy Basic Auth) built
around triathlon load and sleep quality. Rebuilt 2026-08-24 (v2); the original
2026-07-24 version is kept as `/opt/health-api/server.py.v1.bak`.

| Component | Value |
|---|---|
| Service | `health-api.service` (systemd, `Restart=always`, `MemoryMax=280M`, runs as user `health`) |
| App | `/opt/health-api/server.py` + `metrics.py` — Python stdlib HTTP on `127.0.0.1:8643` |
| Storage | SQLite at `/opt/health-api/health.db`, tables `daily(date,key,value)` and `sessions(id,…)`, plus `appmeta` |
| Ingest secret | `/opt/health-api/ingest.secret` (bearer token the iPhone automation sends) |
| Caddy | `reverse_proxy /health/ingest` + `/health/api*` → `:8643`; `basicauth` on `/health*` **except** `/health/ingest` |

The v1 tables (`samples`, `workouts`, `meta`) are left in place untouched — v2
deliberately uses different table names rather than migrating over them.

### Endpoints

- `POST /health/ingest` — bearer-auth (`Authorization: Bearer <ingest.secret>`).
  Accepts `{"days":[{"date":…, "steps":…}], "workouts":[{"start":…,"type":…}]}`,
  a single-day `{"date":…, "values":{…}}`, or flat top-level keys. Unknown keys
  are ignored, and values may be strings with units (`"7.5 hr"`).
- `GET /health/api/summary?days=N` — Basic-Auth-gated computed payload
  (`days=all` for full history). ~0.4s cold, ~0.1s cached, 3.1 MB at `all`.
- `GET /health/api/ping` — row counts and last ingest time.

### What it computes

All derived metrics live in `metrics.py` so the backfill and the daily phone
sync go through identical logic:

- **Training load** — Banister TRIMP per session from heart rate (no power
  meter), then CTL (42-day) / ATL (7-day) exponential averages and form
  (CTL − ATL), plus Foster monotony and strain.
- **Readiness** — 0–100 from HRV (30%), sleep (30%), resting HR (20%) and form
  (20%), each scored against a rolling 60-day personal baseline.
- **Sleep** — stages per night, rolling 14-night debt, bed/wake regularity.
  Sleep samples are unioned and split into sessions on a 1-hour gap so naps and
  overlapping HealthKit records do not inflate a night.
- **Correlations** — sleep vs next-session efficiency, and load vs that night's
  recovery, with Pearson r, n and a real t-test p-value. Session efficiency is
  detrended against the athlete's last 20 sessions in that discipline and
  winsorised at ±4σ, so multi-year fitness drift cannot manufacture a result.
- **Swim technique** — SWOLF (length time + strokes for that length) per swim.
  Length COUNT comes from distance, never from lap markers: the Watch emits one
  marker per length but MySwimPro emits its own interval markers. Per-length
  TIME is used only when `lap_count == lengths`, otherwise SWOLF is withheld
  with the reason recorded. `swolf_25` normalises to one 25 yd length because
  SWOLF scales with the unit it is measured over (a lap-counting app reports
  roughly double). Pool defaults to 22.86 m, overridden by `HKLapLength`.
- **Running form** — power, stride length, ground contact, vertical oscillation.
  Every sport statistic is bounded by a plausible range and retried at 1/100 and
  1/1000 before being discarded, because the live exporter reports stride length
  in centimetres while the XML backfill uses metres.

Two guards exist because the raw data lies in specific ways:

- **Marker sets are validated against their own workout.** Cycling `segment`
  events overlap — a 70-minute ride produced 31 markers totalling 209 minutes.
  Splits exceeding the session duration are recorded as `laps_unusable` rather
  than averaged into a meaningless split time.
- **Pool length is only applied to swims.** It was previously stamped on every
  workout, so bike rides carried `pool: 25 yd`.

Source for the whole pipeline is a git repo at `Fable/health-sync` on the
MacBook (local only, no remote). Its README carries the data-quality rules
learned the hard way.

### Data pipeline

Two sources feed the same ingest endpoint:

1. **Backfill** (one-off / occasional) — `Fable/health-sync/etl_duckdb.py` reads
   the DuckDB built by `Fable/apple-health-mcp-server` from an Apple Health
   `export.zip`, then `push_backfill.py` POSTs it. 3,581 days and 1,346 sessions
   back to Oct 2016.
2. **Daily** — the iPhone app *Health Exporter & Shortcuts* ($0.99, one-time)
   provides `Export Health Data` and `Export Workouts` Shortcuts actions; a
   time-of-day Automation posts their JSON to `/health/ingest`. See
   `Fable/health-sync/SHORTCUT.md`.

The dashboard shows a staleness banner when the newest day on record is more
than two days old, so a silently dead automation is visible.

Lap splits and workout statistics are **not** in the DuckDB export — the
importer drops `<WorkoutEvent>`. `parse_export_laps.py` streams the 2.4 GB
export.xml straight out of export.zip to recover them (16s for 5.17M records);
`push_backfill.py --file laps.json` merges them in. Snap lap timestamps to the
nearest existing session first: DuckDB and export.xml disagree by exactly one
hour across DST boundaries.

```bash
# refresh the backfill from a new export.zip
cd ~/Fable/apple-health-mcp-server && uv run scripts/duckdb_importer.py
uv run --frozen python ~/Fable/health-sync/etl_duckdb.py --out ~/Fable/health-sync/backfill.json
cd ~/Fable/health-sync && python3 push_backfill.py --secret "$(gcloud compute ssh verma7-web \
  --zone=us-central1-c --command='sudo cat /opt/health-api/ingest.secret')"

# update the service
gcloud compute scp server.py metrics.py verma7-web:/tmp/ --zone=us-central1-c
gcloud compute ssh verma7-web --zone=us-central1-c --command="\
  sudo install -o health -g health -m 644 /tmp/server.py /opt/health-api/server.py && \
  sudo install -o health -g health -m 644 /tmp/metrics.py /opt/health-api/metrics.py && \
  sudo systemctl restart health-api"

# health check + logs
curl -s localhost:8643/health/api/ping   # (on the VM)
sudo journalctl -u health-api --since "1 hour ago"
# rotate the ingest secret
echo -n "$(openssl rand -base64 32 | tr -d /+= )" | sudo tee /opt/health-api/ingest.secret
sudo systemctl restart health-api   # then update the Shortcut's bearer token
# reset the dashboard password
caddy hash-password --plaintext 'NEWPASS'   # paste hash into /etc/caddy/Caddyfile, then: sudo systemctl reload caddy
```

## Finance dashboard service

`/finance/` is a **private** dashboard (WebAuthn passkey login, no Basic Auth)
showing bank balances/transactions synced read-only from Plaid (production,
Transactions + Investments) and Kraken (direct API; added 2026-08-11,
SimpleFIN support removed 2026-08-17):

| Component | Value |
|---|---|
| Service | `finance-api.service` (systemd, `Restart=always`, `MemoryMax=200M`, runs as user `finance`, hardened: `ProtectSystem=strict`) |
| App | `/opt/finance-api/server.py` — Python on `127.0.0.1:8644` under `/opt/finance-api/venv` (single dep: `webauthn==2.7.1`) |
| Storage | `/opt/finance-api/finance.db` (bank data) + `auth.db` (passkeys/sessions — never served) |
| Secrets | `/opt/finance-api/secrets/` (700): `plaid.json` (8 Items: banks + brokerages), `kraken.json` (query-only crypto key), `smtp.json` (weekly digest), `setup.secret` (gates passkey registration), `mcp.token` (bearer for DB snapshot/refresh) |
| Caddy | `reverse_proxy /finance/api* → :8644` in the **https block only** (WebAuthn needs the real origin; no plain-HTTP exposure) |
| Sync | in-process thread: on start + every 6h + on `POST /finance/api/refresh` |
| Weekly email | `finance-weekly.timer` Fri 18:00 PT → `server.py --weekly-email`; SMTP creds in `secrets/smtp.json` |

Endpoints (all under `verma7.com/finance/api/`): `ping` (public);
`auth/register/*` (gated by `X-Setup-Secret` header, rate-limited);
`auth/login/*` (rate-limited, mints 30-day session cookie); `overview`,
`transactions`, `spending`, `recurring`, `plaid/link-token`,
`plaid/exchange` (session); `overrides`, `account-type` (session or MCP
bearer); `refresh` (session or MCP bearer); `db` (MCP bearer — streams a
snapshot of finance.db only, never auth.db).

Source lives in `Fable/finance/` on the MacBook (`server.py` + `mcp/` MCP
server + `DEPLOY.md` runbook). Service update: `gcloud compute scp server.py
verma7-web:~/finance-server.py …`, `sudo mv` into `/opt/finance-api/server.py`,
`sudo systemctl restart finance-api`. Dashboard pages (`finance/index.html`,
`finance/connect.html`) deploy via the normal git push flow. A local MCP
server on the MacBook (`~/.claude.json` entry `finance`) downloads DB
snapshots hourly for chat queries.

```bash
# health check + service logs
curl -s localhost:8644/finance/api/ping   # (on the VM)
sudo journalctl -u finance-api --since "1 hour ago"
# show the passkey setup secret (needed to register a new device)
sudo cat /opt/finance-api/secrets/setup.secret
# rotate the MCP token (then update ~/.config/finance-mcp/token on the Mac)
openssl rand -hex 32 | sudo tee /opt/finance-api/secrets/mcp.token
sudo systemctl restart finance-api
# bank connections: managed at https://dashboard.plaid.com; link/re-link
# items via "Connect with Plaid" at https://verma7.com/finance/connect.html
# (ITEM_LOGIN_REQUIRED sync errors on the dashboard header = re-link needed)
```

## History / legacy

- Previously served via a Cloudflare Tunnel (`26fce452-…cfargotunnel.com`);
  the `fgi`, `ft`, and `games` subdomains still point at that tunnel.
- Before that, hosted on a GCS bucket — `sync.sh` (`gsutil rsync` to
  `gs://www.verma7.com`) is left over from that era and is no longer used.
