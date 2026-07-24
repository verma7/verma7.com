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

## History / legacy

- Previously served via a Cloudflare Tunnel (`26fce452-…cfargotunnel.com`);
  the `fgi`, `ft`, and `games` subdomains still point at that tunnel.
- Before that, hosted on a GCS bucket — `sync.sh` (`gsutil rsync` to
  `gs://www.verma7.com`) is left over from that era and is no longer used.
