<?php

	function getTopBar($current) {
		$a[$current] = ''; // style="color: #FF8000;" ';
		$retString = '
	<div class="topLinks">
	<span> <a class="linkSpan" '.$a["home"].' href=".">HOME</a></span>
	<span> <a class="linkSpan" '.$a["pub"].' href="pub.html">PUBLICATIONS</a></span>
	<span> <a class="linkSpan" '.$a["prof"].' href="prof.html">WORK EXPERIENCE</a></span>
	<span> <a class="linkSpan" '.$a["travel"].' href="travel.html">TRAVEL</a></span>
	</div>';
		
		return $retString;	
	}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	        <meta name="keywords" content="abhishek verma, abhishekverma, verma7.com, vermaa, uiuc, phd, computer science, illinois" />
	        <meta name="description" content="The personal homepage of Abhishek Verma" />
		<link rel="icon" href="fav.gif" />
		<link rel="stylesheet" href="style.css" type="text/css" />
        	<title>verma7.com - Abhishek Verma's humble abode</title>
		<script language="javascript" type="text/javascript">
		function toggle(divId) {
			if (document.getElementById(divId).style.display == "none") {
				document.getElementById(divId).style.display = 'block';
			}
			else {
				document.getElementById(divId).style.display = 'none';
			}
		}
		</script>
   <script type="text/javascript"
      src="https://maps.googleapis.com/maps/api/js?key=AIzaSyD4MIB8mT2kteFqj3fgn3srtA8dfS6J2Vk&sensor=false">
    </script>
    <script type="text/javascript">
     function initialize() {
				if (!document.getElementById('map-canvas')) return
        var map = new google.maps.Map(document.getElementById('map-canvas'), {
          center: new google.maps.LatLng(30, -20),
          zoom: 2,
          mapTypeId: google.maps.MapTypeId.ROADMAP
        });

        var infoWindow = new google.maps.InfoWindow();
        var layer = new google.maps.FusionTablesLayer({
          query: {
            select: 'Coordinates',
            from: '1PpxInPZ6Sl9LPq2tX-gidwVelkhoGyNIJnGK6Kw'
          },
          map: map,
          styleId: 2,
          templateId: 2,
        });
      }
      google.maps.event.addDomListener(window, 'load', initialize);
    </script>
	
		<!-- Google Tracking -->
		<script type="text/javascript">

		  var _gaq = _gaq || [];
		  _gaq.push(['_setAccount', 'UA-27282669-1']);
		  _gaq.push(['_trackPageview']);

		  (function() {
		    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
		    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
		    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
		  })();

		</script>

	</head>

	<body>
	<div class="container">
	<h1 align="center"> <a href=".." class="header"> Abhishek Verma </a> </h1>
	