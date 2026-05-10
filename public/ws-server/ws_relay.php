<?php
// ws_relay.php — helper to POST notifications to the local ws-server `/emit` hook.
// Minimal, best-effort implementation using file_get_contents stream context.
// Place in `public/ws-server` and include from admin endpoints when a server-side
// authoritative state change needs to be pushed to ws-server.

if (!function_exists('ss_ws_relay_post')) {
    function ss_ws_relay_post(array $obj): bool {
        $cfgFile = __DIR__ . '/ws_relay_config.php';
        $cfg = is_file($cfgFile) ? include $cfgFile : ['url' => 'http://127.0.0.1:3000', 'secret' => null];
        $base = rtrim($cfg['url'] ?? 'http://127.0.0.1:3000', '/');
        $url = $base . '/emit';
        $token = $cfg['secret'] ?? null;
        $body = json_encode($obj);
        if ($body === false) return false;
        $headers = "Content-Type: application/json\r\n";
        if ($token) $headers .= "x-ws-token: " . $token . "\r\n";
        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => $headers,
                'content' => $body,
                'timeout' => 3,
            ]
        ];
        $ctx = stream_context_create($opts);
        try {
            $res = @file_get_contents($url, false, $ctx);
            return ($res !== false);
        } catch (Throwable $_) {
            return false;
        }
    }
}

if (!function_exists('ss_ws_relay_notify_state')) {
    function ss_ws_relay_notify_state($match_id, $payload, $ts = null, $meta = null): bool {
        $obj = [
            'type' => 'basketball_state',
            'match_id' => $match_id,
            'payload' => $payload,
        ];
        if ($ts !== null) $obj['ts'] = $ts;
        if ($meta !== null && is_array($meta)) $obj['meta'] = $meta;
        return ss_ws_relay_post($obj);
    }
}

if (!function_exists('ss_ws_relay_notify_admins')) {
    function ss_ws_relay_notify_admins($payload, $ts = null): bool {
        $obj = [
            'type' => 'timer_control',
            'payload' => $payload,
        ];
        if ($ts !== null) $obj['ts'] = $ts;
        return ss_ws_relay_post($obj);
    }
}
