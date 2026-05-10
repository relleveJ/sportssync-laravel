<?php
// ============================================================
// system_guard.php — SportSync system access guard
// Copy to: the same public directory as your sport PHP files
//
// Usage in any sport admin/viewer page:
//   require_once __DIR__ . '/system_guard.php';
//   ss_check_maintenance($pdo, $isAdmin);  // pass false for viewer pages
//   ss_check_sport($pdo, 'Badminton', $isAdmin);
// ============================================================

function ss_get_setting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $s = $pdo->prepare('SELECT `value` FROM system_settings WHERE `key` = ? LIMIT 1');
        $s->execute([$key]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? (string)$row['value'] : $default;
    } catch (Throwable $e) { return $default; }
}

function ss_check_maintenance(PDO $pdo, bool $isAdmin = false): void {
    if (ss_get_setting($pdo, 'maintenance_mode', '0') !== '1') return;
    if ($isAdmin) {
        // Register a banner to inject after <body> — doesn't block admin
        if (!isset($GLOBALS['_ss_banners'])) $GLOBALS['_ss_banners'] = [];
        $GLOBALS['_ss_banners'][] = '<div id="ss-maint-banner" style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#7c2d12;color:#fed7aa;padding:10px 48px 10px 20px;font-family:Arial,sans-serif;font-size:13px;font-weight:700;border-bottom:2px solid #f97316;display:flex;align-items:center;gap:10px;">🔧 <strong>Maintenance Mode is ON</strong> — Viewers see the maintenance screen.<button onclick="this.parentNode.remove()" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#fed7aa;font-size:18px;cursor:pointer">✕</button></div>';
        return;
    }
    ss_show_maintenance_screen();
}

function ss_check_sport(PDO $pdo, string $sportName, bool $isAdmin = false): void {
    try {
        $s = $pdo->prepare('SELECT status FROM sports WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $s->execute([$sportName]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row === false || $row['status'] === 'active') return;
    } catch (Throwable $e) { return; }

    if ($isAdmin) {
        $safe = htmlspecialchars($sportName, ENT_QUOTES);
        if (!isset($GLOBALS['_ss_banners'])) $GLOBALS['_ss_banners'] = [];
        $GLOBALS['_ss_banners'][] = "<div id=\"ss-sport-banner\" style=\"position:fixed;top:0;left:0;right:0;z-index:99999;background:#713f12;color:#fde68a;padding:10px 48px 10px 20px;font-family:Arial,sans-serif;font-size:13px;font-weight:700;border-bottom:2px solid #f59e0b;display:flex;align-items:center;gap:10px;\">⚠️ <strong>{$safe}</strong> is currently <em>deactivated</em>. Viewers cannot access this sport. Activate it in the Admin Panel.<button onclick=\"this.parentNode.remove()\" style=\"position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#fde68a;font-size:18px;cursor:pointer\">✕</button></div>";
        return;
    }
    ss_show_sport_disabled_screen($sportName);
}

// Call this once after all ss_check_* calls on admin pages to render banners
function ss_render_banners(): void {
    if (empty($GLOBALS['_ss_banners'])) return;
    foreach ($GLOBALS['_ss_banners'] as $b) echo $b;
}

function ss_show_maintenance_screen(): void {
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Maintenance — SportSync</title>'
       . '<style>*{box-sizing:border-box;margin:0;padding:0}body{background:#0a0a0a;color:#f0f0f0;font-family:"Segoe UI",Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:20px;text-align:center;padding:24px}.icon{font-size:4rem;animation:pulse 2s infinite}@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}h1{font-size:2rem;color:#FFE600;letter-spacing:2px;text-transform:uppercase}p{color:#aaa;font-size:1rem;max-width:440px;line-height:1.6}.badge{display:inline-block;background:rgba(255,230,0,.12);border:1px solid rgba(255,230,0,.3);color:#FFE600;padding:6px 18px;border-radius:20px;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-top:8px}</style>'
       . '</head><body><div class="icon">🔧</div><h1>Under Maintenance</h1><p>SportSync is currently undergoing scheduled maintenance.<br>We\'ll be back shortly. Thank you for your patience.</p><span class="badge">System Offline</span></body></html>';
    exit;
}

function ss_show_sport_disabled_screen(string $sportName): void {
    $emojis = ['basketball'=>'🏀','volleyball'=>'🏐','badminton'=>'🏸','table tennis'=>'🏓','darts'=>'🎯'];
    $emoji  = $emojis[strtolower($sportName)] ?? '🏅';
    $safe   = htmlspecialchars($sportName, ENT_QUOTES);
    http_response_code(503);
    echo "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1.0\"><title>{$safe} Unavailable — SportSync</title>"
       . '<style>*{box-sizing:border-box;margin:0;padding:0}body{background:#0a0a0a;color:#f0f0f0;font-family:"Segoe UI",Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:20px;text-align:center;padding:24px}.icon{font-size:4rem;opacity:.45}h1{font-size:2rem;color:#FFE600;letter-spacing:2px;text-transform:uppercase}p{color:#aaa;font-size:1rem;max-width:440px;line-height:1.6}.badge{display:inline-block;background:rgba(255,82,82,.1);border:1px solid rgba(255,82,82,.3);color:#ff7675;padding:6px 18px;border-radius:20px;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-top:8px}.back{display:inline-block;margin-top:16px;background:rgba(255,230,0,.1);border:1px solid rgba(255,230,0,.3);color:#FFE600;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:700;font-size:.9rem}</style>'
       . "</head><body><div class=\"icon\">{$emoji}</div><h1>{$safe}</h1><p>This sport is currently <strong style=\"color:#ff7675\">deactivated</strong> by the administrator.<br>Please check back later or contact support.</p><span class=\"badge\">Sport Deactivated</span><a href=\"/\" class=\"back\">← Back to Home</a></body></html>";
    exit;
}