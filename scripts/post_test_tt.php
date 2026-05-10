<?php
// Quick test script to POST sample payloads to new endpoints
function post_with_csrf($base, $endpoint, $data) {
    $cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt_cookies.txt';

    // First GET to obtain session and XSRF cookie
    $ch = curl_init($base . '/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);

    // Parse XSRF-TOKEN from cookie file
    $xsrf = null;
    if (file_exists($cookieFile)) {
        $lines = file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $L) {
            if (strpos($L, "XSRF-TOKEN") !== false) {
                $parts = preg_split('/\s+/', $L);
                $xsrf = end($parts);
            }
        }
    }

    $url = $base . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $headers = ['Content-Type: application/json'];
    if ($xsrf) $headers[] = 'X-XSRF-TOKEN: ' . $xsrf;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "$url -> $code\n";
    echo $res . "\n";
}

$base = 'http://127.0.0.1:8000/admin/tabletennis';
$post1 = ['match_id' => null, 'match_type' => 'singles', 'best_of' => 3, 'team_a_name' => 'Alpha', 'team_b_name' => 'Beta', 'sets' => [['set_number'=>1,'team_a_score'=>11,'team_b_score'=>8,'team_a_timeout_used'=>0,'team_b_timeout_used'=>0,'serving_team'=>'A','set_winner'=>'A']]];
post_with_csrf('http://127.0.0.1:8000', '/admin/tabletennis/save_set', $post1);
$post2 = ['match_id' => 1, 'total_sets_played' => 1, 'team_a_sets_won' => 1, 'team_b_sets_won' => 0, 'winner_team' => 'A', 'winner_name' => 'Alpha'];
post_with_csrf('http://127.0.0.1:8000', '/admin/tabletennis/declare_winner', $post2);
