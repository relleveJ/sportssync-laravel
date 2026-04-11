<?php
$urls = [
    'http://127.0.0.1:8000/admin/basketball/report?match_id=1',
    'http://127.0.0.1:8000/admin/basketball/viewer',
    'http://127.0.0.1:8000/admin/tabletennis/report?match_id=1',
    'http://127.0.0.1:8000/admin/tabletennis/viewer',
    'http://127.0.0.1:8000/admin/darts/admin',
    'http://127.0.0.1:8000/admin/darts/viewer',
    'http://127.0.0.1:8000/admin/volleyball/admin',
    'http://127.0.0.1:8000/admin/volleyball/viewer',
];
foreach ($urls as $u) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (!$code) {
        echo "$u -> ERROR\n";
    } else {
        echo "$u -> $code\n";
    }
    curl_close($ch);
}
