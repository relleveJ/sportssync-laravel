<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Basketball Report</title>
<link rel="stylesheet" href="/Basketball Admin UI/basketball_viewer.css"></head>
<body>{!! $legacy_html !!}
<script>
	(function(){
		try {
			if (typeof window.BASKETBALL_WS_URL === 'undefined' || !window.BASKETBALL_WS_URL) {
				<?php $ws = env('BASKETBALL_WS_URL') ?: null; if ($ws) { echo "window.BASKETBALL_WS_URL = " . json_encode($ws) . "\n"; } ?>
			}
		} catch(e) {}
	})();
</script>
<script src="/Basketball Admin UI/basketball_viewer.js"></script></body></html>
