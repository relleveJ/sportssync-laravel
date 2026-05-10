<?php
$GLOBALS['_SERVER']['REQUEST_METHOD'] = 'GET';
$_GET = ['match_id' => '0'];
ob_start();
include __DIR__ . '/state.php';
$out = ob_get_clean();
echo $out;
