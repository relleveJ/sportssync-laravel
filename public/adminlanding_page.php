<?php
// ============================================================
// adminlanding_page.php — SportSync Admin Landing Page
// ============================================================
require_once __DIR__ . '/auth.php';

if (!function_exists('currentUser')) {
    http_response_code(500);
    echo '<h1>Server configuration error</h1>';
    exit;
}

 $user = currentUser();
 if (!$user) {
  // Redirect direct web access to the Laravel superadmin login (legacy wrapper
  // accesses this file inside Laravel and will not hit this branch).
  header('Location: /superadmin/login?next=adminlanding_page.php'); exit;
 }

 // Determine role robustly (handle casing, JSON, arrays, legacy cookies)
 $rawRole = $user['role'] ?? '';
 $roleNorm = '';
 if (is_array($rawRole)) {
   $roleNorm = strtolower(trim((string)($rawRole[0] ?? $rawRole['role'] ?? '')));
 } else {
   $decoded = json_decode((string)$rawRole, true);
   if (json_last_error() === JSON_ERROR_NONE && $decoded) {
     if (is_array($decoded)) {
       $roleNorm = strtolower(trim((string)($decoded[0] ?? $decoded['role'] ?? '')));
     } elseif (is_string($decoded)) {
       $roleNorm = strtolower(trim($decoded));
     }
   } else {
     $roleNorm = strtolower(trim((string)$rawRole));
   }
 }
 if ($roleNorm === 'scorekeeper') $roleNorm = 'admin';
 if ($roleNorm !== 'superadmin') {
  http_response_code(403);
  echo '<!DOCTYPE html><html><head><title>403</title>'
     . '<style>body{background:#0a0a0a;color:#fff;font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;flex-direction:column;gap:16px;}'
     . 'h1{font-size:4rem;color:#FFD700;}p{color:#888;}a{color:#FFD700;}</style></head>'
     . '<body><h1>403</h1><p>Unauthorized access</p><a href="/">← Back</a></body></html>';
  exit;
 }

// ── DB ────────────────────────────────────────────────────────
require_once __DIR__ . '/db.php'; // provides $pdo (PDO)

$adminId       = (int)($user['id']       ?? 0);
$adminUsername = (string)($user['username'] ?? 'admin');
$adminRole     = (string)($user['role']     ?? 'admin');

// ── HELPER: activity logger ───────────────────────────────────
function logActivity(PDO $pdo, ?int $userId, string $username, string $action): void {
    try {
        $s = $pdo->prepare(
            'INSERT INTO activity_log (user_id, username, action, timestamp)
             VALUES (:uid, :un, :ac, NOW())'
        );
        $s->execute([':uid' => $userId, ':un' => $username, ':ac' => $action]);
    } catch (Throwable $e) { /* non-fatal */ }
}

// ── WS EMIT HELPER (notify public/ws-server) ─────────────────
function ws_emit(array $obj): void {
  // Default relay — can be overridden by environment variable WS_EMIT_URL
  $emitUrl = getenv('WS_EMIT_URL') ?: 'http://127.0.0.1:3000/emit';
  $token   = getenv('WS_TOKEN') ?: null;
  $payload = json_encode($obj);
  $headers = "Content-Type: application/json\r\n";
  if ($token) $headers .= "x-ws-token: {$token}\r\n";
  $opts = ['http' => ['method' => 'POST', 'header' => $headers, 'content' => $payload, 'timeout' => 2]];
  $ctx = stream_context_create($opts);
  // Best-effort notify; do not throw on failure
  @file_get_contents($emitUrl, false, $ctx);
}

// NOTE: Login activity is logged in auth.php — add logActivity() call there:
// logActivity($pdo, $userId, $username, 'Logged in');
//
// NOTE: Logout activity — in your logout handler (where session_destroy() is called),
// add BEFORE session_destroy(): logActivity($pdo, $userId, $username, 'Logged out');
//
// NOTE: Match saved activity — after any successful INSERT into matches,
// volleyball_matches, badminton_matches, table_tennis_matches, or darts_matches,
// add: logActivity($pdo, $adminId, $adminUsername, 'Match saved: [sport]');

// ============================================================
// AJAX / POST HANDLERS — must run before any HTML output
// ============================================================
$ajaxAction = trim($_POST['ajax_action'] ?? '');

// ── AJAX: APPROVE / REJECT ADMIN APPLICANT ───────────────────
if ($ajaxAction === 'approve_admin' || $ajaxAction === 'reject_admin') {
  header('Content-Type: application/json');
  $targetId = (int)($_POST['user_id'] ?? 0);
  if (!$targetId) { echo json_encode(['success' => false, 'message' => 'Invalid user ID']); exit; }
  try {
    $sel = $pdo->prepare('SELECT id, username, email, role, status FROM users WHERE id = :id LIMIT 1');
    $sel->execute([':id' => $targetId]);
    $u = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$u) { echo json_encode(['success' => false, 'message' => 'User not found']); exit; }
    if ($u['role'] !== 'admin') { echo json_encode(['success' => false, 'message' => 'Target is not an admin applicant']); exit; }
    $newStatus = $ajaxAction === 'approve_admin' ? 'approved' : 'rejected';
    $upd = $pdo->prepare('UPDATE users SET status = :st WHERE id = :id');
    $upd->execute([':st' => $newStatus, ':id' => $targetId]);
    // Optionally ensure is_active when approved
    if ($newStatus === 'approved') {
      try { $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = :id')->execute([':id' => $targetId]); } catch (Throwable $_) { }
    }
    logActivity($pdo, $adminId, $adminUsername, ucfirst($newStatus) . ' admin applicant: ' . $u['username']);
    // Broadcast the canonical user status update to the WS relay (SSOT)
    try {
      ws_emit([
        'type'    => 'user_status_change',
        'payload' => [
          'user_id'   => $targetId,
          'new_status'=> $newStatus,
          'username'  => $u['username'] ?? null,
        ],
        'ts' => time(),
      ]);
    } catch (Throwable $_) { /* best-effort */ }

    echo json_encode(['success' => true, 'new_status' => $newStatus, 'user_id' => $targetId]);
  } catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
  }
  exit;
}

// ── EXPORT ACTIVITY LOG AS CSV ────────────────────────────────
if ($ajaxAction === 'export_activity_log') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'User ID', 'Username', 'Action', 'Timestamp']);
    try {
        $stmt = $pdo->query(
            'SELECT id, user_id, username, action, timestamp
               FROM activity_log
              ORDER BY timestamp DESC'
        );
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [$row['id'], $row['user_id'], $row['username'], $row['action'], $row['timestamp']]);
        }
    } catch (Throwable $e) {
        fputcsv($out, ['ERROR', '', '', $e->getMessage(), '']);
    }
    fclose($out);
    exit;
}

// ── AJAX: TOGGLE USER STATUS ──────────────────────────────────
if ($ajaxAction === 'toggle_user_status') {
    header('Content-Type: application/json');
    $targetId = (int)($_POST['user_id'] ?? 0);
    if (!$targetId) { echo json_encode(['success' => false, 'message' => 'Invalid user ID']); exit; }
    try {
        $sel = $pdo->prepare('SELECT id, username, status FROM users WHERE id = :id LIMIT 1');
        $sel->execute([':id' => $targetId]);
        $u = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$u) { echo json_encode(['success' => false, 'message' => 'User not found']); exit; }
        $newStatus = ($u['status'] === 'active') ? 'deactivated' : 'active';
        $upd = $pdo->prepare('UPDATE users SET status = :st WHERE id = :id');
        $upd->execute([':st' => $newStatus, ':id' => $targetId]);
        $label = $newStatus === 'active' ? 'Account activated' : 'Account deactivated';
        logActivity($pdo, $adminId, $adminUsername, $label . ': ' . $u['username']);
        // Broadcast status change to relay
        try {
          ws_emit([ 'type' => 'user_status_change', 'payload' => ['user_id' => $targetId, 'new_status' => $newStatus, 'username' => $u['username'] ?? null], 'ts' => time() ]);
        } catch (Throwable $_) { }
        echo json_encode(['success' => true, 'new_status' => $newStatus]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: DELETE USER ─────────────────────────────────────────
if ($ajaxAction === 'delete_user') {
    header('Content-Type: application/json');
    $targetId = (int)($_POST['user_id'] ?? 0);
    if (!$targetId) { echo json_encode(['success' => false, 'message' => 'Invalid user ID']); exit; }
    try {
        $sel = $pdo->prepare('SELECT id, username, status FROM users WHERE id = :id LIMIT 1');
        $sel->execute([':id' => $targetId]);
        $u = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$u) { echo json_encode(['success' => false, 'message' => 'User not found']); exit; }
        if ($u['status'] !== 'deactivated') {
            echo json_encode(['success' => false, 'message' => 'Deactivate the user first before deleting']);
            exit;
        }
        $del = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $del->execute([':id' => $targetId]);
        logActivity($pdo, $adminId, $adminUsername, 'Account deleted: ' . $u['username']);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: CHANGE USERNAME ─────────────────────────────────────
if ($ajaxAction === 'change_username') {
    header('Content-Type: application/json');
    $targetId    = (int)($_POST['user_id']     ?? 0);
    $newUsername = trim($_POST['new_username'] ?? '');
    if (!$targetId || $newUsername === '') {
        echo json_encode(['success' => false, 'message' => 'User ID and new username required']); exit;
    }
    if (strlen($newUsername) < 3 || strlen($newUsername) > 60) {
        echo json_encode(['success' => false, 'message' => 'Username must be 3–60 characters']); exit;
    }
    try {
        $chk = $pdo->prepare('SELECT id FROM users WHERE username = :un AND id != :id LIMIT 1');
        $chk->execute([':un' => $newUsername, ':id' => $targetId]);
        if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Username already taken']); exit; }
        $old = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
        $old->execute([':id' => $targetId]);
        $oldUsername = (string)$old->fetchColumn();
        $upd = $pdo->prepare('UPDATE users SET username = :un WHERE id = :id');
        $upd->execute([':un' => $newUsername, ':id' => $targetId]);
        logActivity($pdo, $adminId, $adminUsername, "Username changed: {$oldUsername} → {$newUsername}");
        echo json_encode(['success' => true, 'new_username' => $newUsername]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: ADD USER ────────────────────────────────────────────
if ($ajaxAction === 'add_user') {
    header('Content-Type: application/json');
    $newUsername = trim($_POST['username'] ?? '');
    $newPassword = $_POST['password']      ?? '';
    $newRole     = trim($_POST['role']     ?? 'viewer');
    if ($newUsername === '' || $newPassword === '') {
        echo json_encode(['success' => false, 'message' => 'Username and password required']); exit;
    }
    if (strlen($newUsername) < 3 || strlen($newUsername) > 60) {
        echo json_encode(['success' => false, 'message' => 'Username must be 3–60 characters']); exit;
    }
    if (strlen($newPassword) < 6) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']); exit;
    }
    if (!in_array($newRole, ['admin', 'viewer', 'scorer'], true)) { $newRole = 'viewer'; }
    try {
        $chk = $pdo->prepare('SELECT id FROM users WHERE username = :un LIMIT 1');
        $chk->execute([':un' => $newUsername]);
        if ($chk->fetch()) { echo json_encode(['success' => false, 'message' => 'Username already exists']); exit; }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $ins  = $pdo->prepare(
            'INSERT INTO users (username, password, role, status, created_at)
             VALUES (:un, :pw, :rl, :st, NOW())'
        );
        $ins->execute([':un' => $newUsername, ':pw' => $hash, ':rl' => $newRole, ':st' => 'active']);
        $newId = (int)$pdo->lastInsertId();
        logActivity($pdo, $adminId, $adminUsername, "Account created: {$newUsername} (role: {$newRole})");
        echo json_encode(['success' => true, 'user_id' => $newId, 'username' => $newUsername, 'role' => $newRole]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: TOGGLE SPORT STATUS ─────────────────────────────────
if ($ajaxAction === 'toggle_sport_status') {
    header('Content-Type: application/json');
    $sportId = (int)($_POST['sport_id'] ?? 0);
    if (!$sportId) { echo json_encode(['success' => false, 'message' => 'Invalid sport ID']); exit; }
    try {
        $sel = $pdo->prepare('SELECT id, name, status FROM sports WHERE id = :id LIMIT 1');
        $sel->execute([':id' => $sportId]);
        $sp = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$sp) { echo json_encode(['success' => false, 'message' => 'Sport not found']); exit; }
        $newStatus = ($sp['status'] === 'active') ? 'inactive' : 'active';
        $upd = $pdo->prepare('UPDATE sports SET status = :st WHERE id = :id');
        $upd->execute([':st' => $newStatus, ':id' => $sportId]);
        logActivity($pdo, $adminId, $adminUsername, 'Sport ' . ucfirst($newStatus) . ': ' . $sp['name']);
        echo json_encode(['success' => true, 'new_status' => $newStatus]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ── AJAX: SAVE SYSTEM SETTING ─────────────────────────────────
if ($ajaxAction === 'save_system_setting') {
    header('Content-Type: application/json');
    $key   = trim($_POST['key']   ?? '');
    $value = trim($_POST['value'] ?? '');
    $allowed = ['maintenance_mode'];
    if (!in_array($key, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Unknown setting key']); exit;
    }
    try {
        $s = $pdo->prepare(
            'INSERT INTO system_settings (`key`, `value`) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()'
        );
        $s->execute([':k' => $key, ':v' => $value]);
        $label = $key === 'maintenance_mode'
            ? ($value === '1' ? 'Maintenance Mode: ON' : 'Maintenance Mode: OFF')
            : "Setting changed: {$key} = {$value}";
        logActivity($pdo, $adminId, $adminUsername, $label);
        echo json_encode(['success' => true, 'key' => $key, 'value' => $value]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================================
// PAGE DATA FETCHING
// ============================================================
$dbError = [];

// ── Users ─────────────────────────────────────────────────────
$users = [];
try {
    $users = $pdo->query(
        'SELECT id, username, role, status, created_at FROM users ORDER BY created_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError['users'] = $e->getMessage();
}

// Pending admin applicants (for superadmin notification & approval panel)
$pendingApplicants = [];
$pendingCount = 0;
try {
  $pendingApplicants = $pdo->query(
    "SELECT id, username, email, role, status, created_at FROM users WHERE role = 'admin' AND status = 'pending' ORDER BY created_at ASC"
  )->fetchAll(PDO::FETCH_ASSOC);
  $pendingCount = count($pendingApplicants);
} catch (Throwable $e) {
  // Non-fatal; absence of pending users is fine
}

// ── Total users count ─────────────────────────────────────────
$totalUsers = count($users);

// ── Events this calendar month ────────────────────────────────
$eventsThisMonth = 0;
try {
    $ms = date('Y-m-01 00:00:00');
    $me = date('Y-m-t 23:59:59');
    $stmt = $pdo->prepare(
        'SELECT
           (SELECT COUNT(*) FROM matches              WHERE saved_at   BETWEEN :ms1 AND :me1)
         + (SELECT COUNT(*) FROM volleyball_matches   WHERE created_at BETWEEN :ms2 AND :me2)
         + (SELECT COUNT(*) FROM badminton_matches    WHERE created_at BETWEEN :ms3 AND :me3)
         + (SELECT COUNT(*) FROM table_tennis_matches WHERE created_at BETWEEN :ms4 AND :me4)
         + (SELECT COUNT(*) FROM darts_matches        WHERE created_at BETWEEN :ms5 AND :me5)
         AS total'
    );
    $stmt->execute([
        ':ms1' => $ms, ':me1' => $me, ':ms2' => $ms, ':me2' => $me,
        ':ms3' => $ms, ':me3' => $me, ':ms4' => $ms, ':me4' => $me,
        ':ms5' => $ms, ':me5' => $me,
    ]);
    $eventsThisMonth = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $dbError['events'] = $e->getMessage();
}

// ── Activity log ──────────────────────────────────────────────
$activityLog = [];
try {
    $activityLog = $pdo->query(
        'SELECT id, user_id, username, action, timestamp
           FROM activity_log ORDER BY timestamp DESC LIMIT 50'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError['activity'] = $e->getMessage();
}

// ── Sports ────────────────────────────────────────────────────
$sports = [];
try {
    $sports = $pdo->query(
        'SELECT id, name, status FROM sports ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError['sports'] = $e->getMessage();
}
$activeSportsCount = count(array_filter($sports, fn($s) => $s['status'] === 'active'));

// ── System Settings ───────────────────────────────────────────
$maintenanceMode = '0';
try {
    $sSetting = $pdo->prepare('SELECT `value` FROM system_settings WHERE `key` = ? LIMIT 1');
    $sSetting->execute(['maintenance_mode']);
    $settingRow = $sSetting->fetch(PDO::FETCH_ASSOC);
    if ($settingRow !== false) $maintenanceMode = $settingRow['value'];
} catch (Throwable $e) { /* table may not exist yet */ }

// ── All matches (latest 200, union all sports) ────────────────
$allMatches = [];
try {
    $allMatches = $pdo->query(
        "SELECT 'Basketball' AS sport,
                CONCAT(team_a_name, ' vs ', team_b_name)                            AS teams,
                CONCAT(team_a_score, ' - ', team_b_score, ' (', match_result, ')')  AS score_result,
                saved_at AS match_date
           FROM matches
         UNION ALL
         SELECT 'Volleyball',
                CONCAT(team_a_name, ' vs ', team_b_name),
                CONCAT(team_a_score, ' - ', team_b_score, ' (', match_result, ')'),
                created_at FROM volleyball_matches
         UNION ALL
         SELECT 'Badminton',
                CONCAT(team_a_name, ' vs ', team_b_name),
                CONCAT('Winner: ', COALESCE(winner_name,'-'), ' | ', status),
                created_at FROM badminton_matches
         UNION ALL
         SELECT 'Table Tennis',
                CONCAT(team_a_name, ' vs ', team_b_name),
                CONCAT('Winner: ', COALESCE(winner_name,'-'), ' | ', status),
                created_at FROM table_tennis_matches
         UNION ALL
         SELECT 'Darts',
                CONCAT(game_type, ' — ', COALESCE(legs_to_win,'?'), ' legs'),
                CONCAT('Winner: ', COALESCE(winner_name,'-')),
                created_at FROM darts_matches
         ORDER BY match_date DESC LIMIT 200"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $dbError['matches'] = $e->getMessage();
}

// ── Recent users for overview table (latest 5) ────────────────
$recentUsers = array_slice($users, 0, 5);

// Sport emojis
$sportEmojis = [
    'basketball'   => '🏀',
    'volleyball'   => '🏐',
    'badminton'    => '🏸',
    'table tennis' => '🏓',
    'darts'        => '🎯',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="landingpage.css">
<style>
/* ═══════════════════════════════════════
   ADMIN LAYOUT
═══════════════════════════════════════ */
.admin-wrap {
  display: flex;
  min-height: 100vh;
  padding-top: var(--nav-h);
}

/* ── Sidebar ── */
.admin-sidebar {
  position: fixed;
  top: var(--nav-h);
  left: 0;
  bottom: 0;
  width: 240px;
  background: var(--black-mid);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  z-index: 200;
  transition: transform 0.3s ease;
}
.sidebar-section { padding: 28px 20px 12px; }
.sidebar-label {
  font-family: var(--font-head);
  font-size: 0.65rem;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--gray);
  margin-bottom: 10px;
}
.sidebar-nav { display: flex; flex-direction: column; gap: 2px; }
.sidebar-link {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 14px;
  border-radius: 6px;
  font-family: var(--font-head);
  font-size: 0.88rem;
  font-weight: 500;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--gray-light);
  cursor: pointer;
  transition: all 0.22s ease;
  border: none;
  background: none;
  width: 100%;
  text-align: left;
  text-decoration: none;
}
.sidebar-link .s-icon { font-size: 1.05rem; width: 20px; text-align: center; flex-shrink: 0; }
.sidebar-link:hover { background: rgba(255,215,0,0.07); color: var(--white); }
.sidebar-link.active { background: rgba(255,215,0,0.12); color: var(--yellow); border-left: 2px solid var(--yellow); }
.sidebar-divider { height: 1px; background: var(--border); margin: 12px 20px; }
.sidebar-bottom { margin-top: auto; padding: 20px; border-top: 1px solid var(--border); }
.sidebar-user { display: flex; align-items: center; gap: 12px; }
.sidebar-avatar {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: var(--yellow);
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head);
  font-weight: 700; font-size: 0.9rem;
  color: var(--black); flex-shrink: 0;
}
.sidebar-user-info { flex: 1; min-width: 0; }
.sidebar-user-name {
  font-family: var(--font-head);
  font-size: 0.85rem; font-weight: 600;
  color: var(--white); letter-spacing: 0.04em;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sidebar-user-role { font-size: 0.72rem; color: var(--yellow); letter-spacing: 0.08em; text-transform: uppercase; }

/* ── Main Content ── */
.admin-main {
  margin-left: 240px;
  flex: 1;
  padding: 40px 40px 60px;
  background: var(--black);
  min-height: calc(100vh - var(--nav-h));
}
.admin-page { display: none; animation: fadeIn 0.3s ease; }
.admin-page.active { display: block; }
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ── Page Header ── */
.admin-page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 36px;
  flex-wrap: wrap;
  gap: 16px;
}
.admin-page-title {
  font-family: var(--font-head);
  font-size: clamp(1.8rem, 3vw, 2.6rem);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  line-height: 1.1;
}
.admin-page-title span { color: var(--yellow); }
.admin-breadcrumb { font-size: 0.78rem; color: var(--gray); margin-top: 6px; letter-spacing: 0.04em; }
.admin-breadcrumb strong { color: var(--yellow); }

/* ── Stat Cards ── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
  margin-bottom: 36px;
}
.stat-card {
  background: var(--black-card);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 28px 24px;
  position: relative;
  overflow: hidden;
  transition: var(--transition);
}
.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: var(--yellow);
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 0.35s ease;
}
.stat-card:hover { box-shadow: var(--shadow-hover); border-color: rgba(255,215,0,0.2); }
.stat-card:hover::before { transform: scaleX(1); }
.stat-icon { font-size: 1.8rem; margin-bottom: 14px; display: block; }
.stat-value {
  font-family: var(--font-head);
  font-size: 2.4rem;
  font-weight: 700;
  line-height: 1;
  color: var(--white);
  margin-bottom: 6px;
  letter-spacing: 0.02em;
}
.stat-label { font-size: 0.8rem; color: var(--gray); letter-spacing: 0.08em; text-transform: uppercase; font-family: var(--font-head); }
.stat-sub { font-size: 0.72rem; color: var(--gray); margin-top: 4px; }

/* ── Content Grid ── */
.admin-content-grid {
  display: grid;
  grid-template-columns: 1fr 340px;
  gap: 24px;
  align-items: start;
}
.admin-content-grid.full { grid-template-columns: 1fr; }

/* ── Panel / Table ── */
.admin-panel {
  background: var(--black-card);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 24px;
}
.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 20px 24px;
  border-bottom: 1px solid var(--border);
}
.panel-title {
  font-family: var(--font-head);
  font-size: 0.95rem;
  font-weight: 600;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--white);
  display: flex;
  align-items: center;
  gap: 10px;
}
.panel-title .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--yellow); display: inline-block; }
.panel-action {
  font-family: var(--font-head);
  font-size: 0.72rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--yellow);
  cursor: pointer;
  background: none;
  border: 1px solid rgba(255,215,0,0.25);
  padding: 5px 12px;
  border-radius: 4px;
  transition: all 0.22s ease;
}
.panel-action:hover { background: rgba(255,215,0,0.1); }

/* Table */
.admin-table { width: 100%; border-collapse: collapse; }
.admin-table th {
  font-family: var(--font-head);
  font-size: 0.7rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--gray);
  padding: 14px 24px;
  text-align: left;
  border-bottom: 1px solid var(--border);
  background: rgba(255,255,255,0.02);
}
.admin-table td {
  padding: 14px 24px;
  font-size: 0.88rem;
  color: var(--gray-light);
  border-bottom: 1px solid rgba(255,215,0,0.05);
  vertical-align: middle;
}
.admin-table tr:last-child td { border-bottom: none; }
.admin-table tr:hover td { background: rgba(255,215,0,0.03); }
.dt-empty { text-align: center; color: var(--gray); padding: 28px !important; font-style: italic; }

.user-cell { display: flex; align-items: center; gap: 12px; }
.user-avatar-sm {
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-family: var(--font-head);
  font-size: 0.8rem; font-weight: 700;
  flex-shrink: 0;
}
.user-name { color: var(--white); font-weight: 500; }
.user-meta { font-size: 0.75rem; color: var(--gray); }

/* Badges */
.badge {
  display: inline-flex;
  align-items: center;
  padding: 3px 10px;
  border-radius: 20px;
  font-family: var(--font-head);
  font-size: 0.68rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  font-weight: 600;
}
.badge-admin      { background: rgba(255,215,0,0.15);  color: var(--yellow); border: 1px solid rgba(255,215,0,0.3); }
.badge-superadmin { background: rgba(255,140,0,0.15);  color: #ffb347;       border: 1px solid rgba(255,140,0,0.3); }
.badge-viewer     { background: rgba(21,101,192,0.15); color: #64b5f6;       border: 1px solid rgba(21,101,192,0.3); }
.badge-scorer     { background: rgba(0,200,83,0.12);   color: #69f0ae;       border: 1px solid rgba(0,200,83,0.25); }
.badge-active     { background: rgba(0,200,83,0.12);   color: #00c853;       border: 1px solid rgba(0,200,83,0.25); }
.badge-deactivated{ background: rgba(255,82,82,0.1);   color: #ff7675;       border: 1px solid rgba(255,82,82,0.2); }
.badge-inactive   { background: rgba(255,82,82,0.1);   color: #ff7675;       border: 1px solid rgba(255,82,82,0.2); }

/* Action buttons */
.table-action-btn {
  background: none;
  border: none;
  cursor: pointer;
  color: var(--gray);
  font-size: 0.95rem;
  padding: 5px 7px;
  border-radius: 4px;
  transition: all 0.2s ease;
}
.table-action-btn:hover:not(:disabled) { color: var(--yellow); background: rgba(255,215,0,0.1); }
.table-action-btn.danger:hover { color: #ff5252; background: rgba(255,82,82,0.1); }
.table-action-btn:disabled { opacity: 0.3; cursor: not-allowed; }

/* Inline edit */
.uname-wrap { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.uname-display { color: var(--white); font-weight: 500; }
.uname-field {
  display: none;
  padding: 5px 9px;
  border-radius: 5px;
  border: 1px solid var(--yellow);
  background: rgba(255,255,255,0.06);
  color: var(--white);
  font-size: 0.84rem;
  width: 140px;
  outline: none;
}
.uname-msg { font-size: 0.7rem; }
.btn-save-u, .btn-cancel-u {
  display: none;
  padding: 4px 10px;
  border-radius: 4px;
  border: none;
  cursor: pointer;
  font-size: 0.72rem;
  font-weight: 700;
  font-family: var(--font-head);
  letter-spacing: 0.06em;
}
.btn-save-u   { background: #1565C0; color: #fff; }
.btn-cancel-u { background: rgba(255,255,255,0.08); color: var(--gray); }

/* Activity feed */
.activity-feed { display: flex; flex-direction: column; }
.activity-item {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 16px 24px;
  border-bottom: 1px solid rgba(255,215,0,0.05);
  transition: background 0.2s;
}
.activity-item:last-child { border-bottom: none; }
.activity-item:hover { background: rgba(255,215,0,0.02); }
.activity-dot { width: 8px; height: 8px; border-radius: 50%; margin-top: 6px; flex-shrink: 0; }
.activity-body { flex: 1; }
.activity-text { font-size: 0.84rem; color: var(--gray-light); line-height: 1.5; }
.activity-text strong { color: var(--white); }
.activity-time { font-size: 0.72rem; color: var(--gray); margin-top: 3px; }

/* Sport status list */
.sport-status-list { display: flex; flex-direction: column; }
.sport-status-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 24px;
  border-bottom: 1px solid rgba(255,215,0,0.05);
}
.sport-status-item:last-child { border-bottom: none; }
.sport-name { display: flex; align-items: center; gap: 10px; font-size: 0.88rem; color: var(--gray-light); }

/* Quick actions */
.quick-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 20px 24px; }
.quick-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 18px 12px;
  background: rgba(255,255,255,0.03);
  border: 1px solid var(--border);
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.25s ease;
  text-decoration: none;
  color: var(--gray-light);
}
.quick-btn .q-icon { font-size: 1.5rem; }
.quick-btn .q-label { font-family: var(--font-head); font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase; text-align: center; }
.quick-btn:hover { border-color: rgba(255,215,0,0.3); background: rgba(255,215,0,0.06); color: var(--yellow); transform: translateY(-2px); }

/* Toolbar */
.toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; }
.search-input {
  flex: 1; min-width: 200px;
  background: var(--black-card);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 10px 16px;
  font-family: var(--font-body);
  font-size: 0.88rem;
  color: var(--white);
  outline: none;
  transition: border-color 0.25s ease;
}
.search-input::placeholder { color: var(--gray); }
.search-input:focus { border-color: rgba(255,215,0,0.4); }
.filter-select {
  background: var(--black-card);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 10px 14px;
  font-family: var(--font-head);
  font-size: 0.78rem;
  letter-spacing: 0.06em;
  color: var(--gray-light);
  cursor: pointer;
  outline: none;
}
.filter-select:focus { border-color: rgba(255,215,0,0.4); }

/* Modal */
.modal-bg {
  display: none;
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.8);
  z-index: 600;
  align-items: center;
  justify-content: center;
}
.modal-bg.open { display: flex; }
.modal-box {
  background: #1a1a2e;
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 32px 36px;
  min-width: 380px;
  max-width: 480px;
  width: 95%;
}
.modal-title {
  font-family: var(--font-head);
  font-size: 1.1rem;
  font-weight: 700;
  color: var(--yellow);
  letter-spacing: 0.06em;
  text-transform: uppercase;
  margin-bottom: 22px;
}
.fg { margin-bottom: 16px; }
.fg label {
  display: block;
  font-size: 0.72rem;
  color: var(--gray);
  text-transform: uppercase;
  letter-spacing: 0.1em;
  margin-bottom: 6px;
  font-family: var(--font-head);
}
.fg input, .fg select {
  width: 100%;
  padding: 10px 14px;
  background: rgba(255,255,255,0.06);
  border: 1px solid var(--border);
  border-radius: 6px;
  color: var(--white);
  font-size: 0.9rem;
  outline: none;
}
.fg input:focus, .fg select:focus { border-color: rgba(255,215,0,0.5); }
.modal-err { color: #ff5252; font-size: 0.78rem; min-height: 18px; margin: 6px 0 10px; }
.modal-foot { display: flex; gap: 10px; margin-top: 20px; }
.btn-primary {
  flex: 1; padding: 11px;
  border: none; border-radius: 7px;
  background: var(--yellow); color: #000;
  font-family: var(--font-head);
  font-weight: 700; cursor: pointer;
  font-size: 0.88rem; letter-spacing: 0.06em;
  text-transform: uppercase;
}
.btn-primary:hover { filter: brightness(1.1); }
.btn-secondary {
  padding: 11px 20px;
  border: 1px solid var(--border);
  border-radius: 7px;
  background: transparent;
  color: var(--gray-light);
  cursor: pointer;
  font-size: 0.88rem;
  font-family: var(--font-body);
}

/* Settings */
.settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.settings-group { display: flex; flex-direction: column; gap: 16px; padding: 24px; }
.settings-row {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.05);
}
.settings-row:last-child { border-bottom: none; padding-bottom: 0; }
.settings-key { font-size: 0.88rem; color: var(--white); }
.settings-hint { font-size: 0.75rem; color: var(--gray); margin-top: 3px; }
.toggle-switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; background: rgba(255,255,255,0.12); border-radius: 24px; cursor: pointer; transition: 0.25s; }
.toggle-slider::before { content: ''; position: absolute; width: 18px; height: 18px; top: 3px; left: 3px; border-radius: 50%; background: var(--white); transition: 0.25s; }
.toggle-switch input:checked + .toggle-slider { background: var(--yellow); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); background: var(--black); }

/* DB error banner */
.db-err {
  background: rgba(255,82,82,0.1);
  border: 1px solid rgba(255,82,82,0.3);
  border-radius: 8px;
  padding: 10px 16px;
  margin-bottom: 16px;
  color: #ff7675;
  font-size: 0.82rem;
}

/* Match history filter buttons */
.match-filter-btn {
  background: rgba(255,255,255,0.05);
  border: 1px solid var(--border);
  color: var(--gray);
  padding: 4px 11px;
  border-radius: 4px;
  font-family: var(--font-head);
  font-size: 0.68rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}
.match-filter-btn:hover { border-color: rgba(255,215,0,0.35); color: var(--white); background: rgba(255,215,0,0.06); }
.match-filter-btn.active { background: rgba(255,215,0,0.12); border-color: rgba(255,215,0,0.45); color: var(--yellow); }

/* Maintenance mode admin banner */
#ss-maint-banner {
  position: fixed; top: var(--nav-h, 60px); left: 0; right: 0; z-index: 9999;
  background: #7c2d12; color: #fed7aa;
  padding: 10px 48px 10px 20px;
  font-family: Arial, sans-serif; font-size: 13px; font-weight: 700;
  border-bottom: 2px solid #f97316;
  display: flex; align-items: center; gap: 10px;
}

/* Mobile */
.sidebar-toggle {
  display: none;
  position: fixed;
  bottom: 24px; right: 24px;
  z-index: 500;
  background: var(--yellow);
  color: var(--black);
  border: none;
  border-radius: 50%;
  width: 52px; height: 52px;
  font-size: 1.4rem;
  cursor: pointer;
  box-shadow: 0 4px 20px rgba(255,215,0,0.4);
}

@media (max-width: 1100px) {
  .stats-grid { grid-template-columns: repeat(2, 1fr); }
  .admin-content-grid { grid-template-columns: 1fr; }
  .settings-grid { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
  .admin-sidebar { transform: translateX(-100%); }
  .admin-sidebar.open { transform: translateX(0); box-shadow: 8px 0 40px rgba(0,0,0,0.6); }
  .admin-main { margin-left: 0; padding: 28px 20px 60px; }
  .sidebar-toggle { display: flex; align-items: center; justify-content: center; }
}
@media (max-width: 480px) {
  .stats-grid { grid-template-columns: 1fr; }
  .quick-actions { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<script>window.__SS_CURRENT_USER_ID = <?= json_encode($adminId) ?>;</script>

<!-- ════════════ NAVBAR ════════════ -->
<nav class="navbar" id="navbar">
  <div class="nav-container">
    <a href="/" class="nav-logo">
      <span class="logo-bolt">⚡</span>SportSync
    </a>
    <ul class="nav-links" id="nav-links">
      <li><a href="adminlanding_page.php" class="nav-link">Home</a></li>
      <li><a href="/" class="nav-link active">Dashboard</a></li>
    </ul>
    <div class="nav-auth">
      <?php if (!empty($pendingCount)): ?>
        <a href="#" id="pendingApplicantsBtn" style="margin-right:14px;color:var(--yellow);font-weight:700;text-decoration:none;">🔔 Applications <span style="background:var(--yellow);color:#000;border-radius:12px;padding:2px 8px;margin-left:8px;font-weight:700;"><?= $pendingCount ?></span></a>
      <?php endif; ?>
      <span class="nav-user">👤 <?= htmlspecialchars($adminUsername) ?></span>
      <a href="/legacy-logout" class="nav-auth-btn nav-logout">Logout</a>
    </div>
    <button class="hamburger" id="hamburger" aria-label="Toggle menu" aria-expanded="false">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<!-- ════════════ ADMIN LAYOUT ════════════ -->
<div class="admin-wrap">

  <!-- ── Sidebar ── -->
  <aside class="admin-sidebar" id="adminSidebar">

    <div class="sidebar-section">
      <div class="sidebar-label">Main</div>
      <nav class="sidebar-nav">
        <button class="sidebar-link active" data-page="overview">
          <span class="s-icon">📊</span> Overview
        </button>
        <button class="sidebar-link" data-page="users">
          <span class="s-icon">👥</span> Users
        </button>
        <button class="sidebar-link" data-page="sports">
          <span class="s-icon">🏆</span> Sports
        </button>
        <button class="sidebar-link" data-page="matches">
          <span class="s-icon">🎮</span> Match History
        </button>
        <button class="sidebar-link" data-page="activity">
          <span class="s-icon">📋</span> Activity Log
        </button>
      </nav>
    </div>

    <div class="sidebar-section">
      <div class="sidebar-label">Matches</div>
      <nav class="sidebar-nav">
        <a href="/Volleyball%20Admin%20UI/volleyball_matches_admin.php" class="sidebar-link">
          <span class="s-icon">🏐</span> Volleyball Matches
        </a>
        <a href="/Basketball%20Admin%20UI/basketball_matches_admin.php" class="sidebar-link">
          <span class="s-icon">🏀</span> Basketball Matches
        </a>
      </nav>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-section">
      <div class="sidebar-label">System</div>
      <nav class="sidebar-nav">
        <button class="sidebar-link" data-page="settings">
          <span class="s-icon">⚙️</span> Settings
        </button>
        <a href="/" class="sidebar-link">
          <span class="s-icon">🏠</span> Landing Page
        </a>
      </nav>
    </div>

    <div class="sidebar-bottom">
      <div class="sidebar-user">
        <div class="sidebar-avatar"><?= strtoupper(substr($adminUsername, 0, 1)) ?></div>
        <div class="sidebar-user-info">
          <div class="sidebar-user-name"><?= htmlspecialchars($adminUsername) ?></div>
          <div class="sidebar-user-role"><?= htmlspecialchars($adminRole) ?></div>
        </div>
      </div>
    </div>

  </aside>

  <!-- ── Main Content ── -->
  <main class="admin-main">

    <!-- ══════════════════════════════════════
         PAGE: OVERVIEW
    ══════════════════════════════════════ -->
    <div class="admin-page active" id="page-overview">
      <div class="admin-page-header">
        <div>
          <h1 class="admin-page-title">Admin <span>Overview</span></h1>
          <p class="admin-breadcrumb">SportSync / <strong>Dashboard</strong> &mdash; <?= date('l, F j, Y') ?></p>
        </div>
        <span style="font-size:0.78rem;color:var(--gray);font-family:var(--font-head);letter-spacing:0.06em;" id="liveTime"></span>
      </div>

      <?php if (!empty($dbError)): ?>
        <div class="db-err">⚠️ DB Warning: <?= htmlspecialchars(implode(' | ', $dbError)) ?></div>
      <?php endif; ?>

      <!-- Stat Cards — 3 cards, no "Active Games" -->
      <div class="stats-grid">
        <div class="stat-card">
          <span class="stat-icon">👥</span>
          <div class="stat-value"><?= $totalUsers ?></div>
          <div class="stat-label">Total Users</div>
          <div class="stat-sub">All registered accounts</div>
        </div>
        <div class="stat-card">
          <span class="stat-icon">📅</span>
          <div class="stat-value"><?= $eventsThisMonth ?></div>
          <div class="stat-label">Events This Month</div>
          <div class="stat-sub"><?= date('F Y') ?></div>
        </div>
        <div class="stat-card">
          <span class="stat-icon">🏅</span>
          <div class="stat-value"><?= $activeSportsCount ?><span style="font-size:1rem;color:var(--gray);font-weight:400;">/<?= count($sports) ?></span></div>
          <div class="stat-label">Sports Active</div>
          <div class="stat-sub"><?= count($allMatches) ?>+ total matches on record</div>
        </div>
      </div>

      <div class="admin-content-grid">
        <!-- Recent Users Table -->
        <div class="admin-panel">
          <div class="panel-header">
            <span class="panel-title"><span class="dot"></span>Recent Users</span>
            <button class="panel-action" onclick="navigate('users')">View All</button>
          </div>
          <table class="admin-table">
            <thead>
              <tr>
                <th>User</th>
                <th>Role</th>
                <th>Status</th>
                <th>Joined</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentUsers)): ?>
                <tr><td colspan="4" class="dt-empty">No users found.</td></tr>
              <?php else: foreach ($recentUsers as $ru):
                $initial = strtoupper(substr($ru['username'], 0, 1));
                $bgColor = ($ru['role'] === 'admin' || $ru['role'] === 'superadmin') ? '#FFD700' : '#1565C0';
                $fgColor = ($ru['role'] === 'admin' || $ru['role'] === 'superadmin') ? '#000' : '#fff';
              ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="user-avatar-sm" style="background:<?= $bgColor ?>;color:<?= $fgColor ?>;"><?= $initial ?></div>
                      <div><div class="user-name"><?= htmlspecialchars($ru['username']) ?></div></div>
                    </div>
                  </td>
                  <td><span class="badge badge-<?= htmlspecialchars($ru['role']) ?>"><?= ucfirst(htmlspecialchars($ru['role'])) ?></span></td>
                  <td><span class="badge badge-<?= htmlspecialchars($ru['status']) ?>"><?= ucfirst(htmlspecialchars($ru['status'])) ?></span></td>
                  <td style="color:var(--gray);font-size:0.8rem;"><?= htmlspecialchars(substr($ru['created_at'] ?? '—', 0, 10)) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Right column -->
        <div style="display:flex;flex-direction:column;gap:24px;">
          <!-- Quick Actions — no "New Sport" button -->
          <div class="admin-panel">
            <div class="panel-header">
              <span class="panel-title"><span class="dot"></span>Quick Actions</span>
            </div>
            <div class="quick-actions">
              <button class="quick-btn" onclick="navigate('users')">
                <span class="q-icon">👥</span>
                <span class="q-label">Manage Users</span>
              </button>
              <button class="quick-btn" onclick="navigate('matches')">
                <span class="q-icon">🎮</span>
                <span class="q-label">Match History</span>
              </button>
              <button class="quick-btn" onclick="navigate('activity')">
                <span class="q-icon">📋</span>
                <span class="q-label">Activity Log</span>
              </button>
              <button class="quick-btn" onclick="navigate('settings')">
                <span class="q-icon">⚙️</span>
                <span class="q-label">Settings</span>
              </button>
            </div>
          </div>

          <!-- Sports Status -->
          <?php if (!empty($sports)): ?>
          <div class="admin-panel">
            <div class="panel-header">
              <span class="panel-title"><span class="dot"></span>Sports Status</span>
              <button class="panel-action" onclick="navigate('sports')">Manage</button>
            </div>
            <div class="sport-status-list">
              <?php foreach ($sports as $sp):
                $em = $sportEmojis[strtolower($sp['name'])] ?? '🏅';
                $isAct = $sp['status'] === 'active';
              ?>
              <div class="sport-status-item">
                <div class="sport-name">
                  <span><?= $em ?></span>
                  <?= htmlspecialchars($sp['name']) ?>
                </div>
                <span class="badge badge-<?= $isAct ? 'active' : 'inactive' ?>"><?= $isAct ? 'Active' : 'Inactive' ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         PAGE: USER MANAGEMENT
    ══════════════════════════════════════ -->
    <div class="admin-page" id="page-users">
      <div class="admin-page-header">
        <div>
          <h1 class="admin-page-title">User <span>Management</span></h1>
          <p class="admin-breadcrumb">SportSync / Admin / <strong>Users</strong></p>
        </div>
        <button class="btn btn-primary" style="font-size:0.82rem;padding:10px 22px;" onclick="openAddUser()">+ Add User</button>
      </div>

      <?php if (!empty($dbError['users'])): ?>
        <div class="db-err">⚠️ <?= htmlspecialchars($dbError['users']) ?></div>
      <?php endif; ?>

      <div class="toolbar">
        <input type="text" class="search-input" placeholder="🔍  Search users…" id="userSearch">
        <select class="filter-select" id="roleFilter">
          <option value="">All Roles</option>
          <option value="admin">Admin</option>
          <option value="superadmin">Superadmin</option>
          <option value="viewer">Viewer</option>
          <option value="scorer">Scorer</option>
        </select>
        <select class="filter-select" id="statusFilter">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="deactivated">Deactivated</option>
        </select>
      </div>

      <div class="admin-panel">
        <div class="panel-header">
          <span class="panel-title"><span class="dot"></span>All Users</span>
          <span id="userCountLabel" style="font-family:var(--font-head);font-size:0.72rem;color:var(--gray);letter-spacing:0.08em;"><?= count($users) ?> Total</span>
        </div>
        <table class="admin-table" id="usersTable">
          <thead>
            <tr>
              <th>User</th>
              <th>Role</th>
              <th>Status</th>
              <th>Joined</th>
              <th>Activate / Deactivate</th>
              <th>Username</th>
              <th>Delete</th>
            </tr>
          </thead>
          <tbody id="usersTbody">
          <?php if (empty($users)): ?>
            <tr><td colspan="7" class="dt-empty">No users found.</td></tr>
          <?php else: foreach ($users as $u):
            $uid      = (int)$u['id'];
            $uname    = htmlspecialchars($u['username'], ENT_QUOTES);
            $role     = htmlspecialchars($u['role']);
            $status   = $u['status'];
            $isActive = ($status === 'active');
            $initial  = strtoupper(substr($u['username'], 0, 1));
            $bgColor  = ($role === 'admin' || $role === 'superadmin') ? '#FFD700' : (($role === 'scorer') ? '#1b5e20' : '#1565C0');
            $fgColor  = ($role === 'admin' || $role === 'superadmin') ? '#000' : '#fff';
          ?>
            <tr id="urow-<?= $uid ?>" data-role="<?= $role ?>" data-status="<?= htmlspecialchars($status) ?>">
              <!-- User -->
              <td>
                <div class="user-cell">
                  <div class="user-avatar-sm" style="background:<?= $bgColor ?>;color:<?= $fgColor ?>;"><?= $initial ?></div>
                  <div>
                    <div class="user-name"><?= $uname ?></div>
                    <div class="user-meta">ID: <?= $uid ?></div>
                  </div>
                </div>
              </td>
              <!-- Role -->
              <td><span class="badge badge-<?= $role ?>"><?= ucfirst($role) ?></span></td>
              <!-- Status -->
              <td id="ustatus-<?= $uid ?>">
                <span class="badge badge-<?= htmlspecialchars($status) ?>"><?= ucfirst(htmlspecialchars($status)) ?></span>
              </td>
              <!-- Joined -->
              <td style="color:var(--gray);font-size:0.8rem;white-space:nowrap;">
                <?= htmlspecialchars(substr($u['created_at'] ?? '—', 0, 10)) ?>
              </td>
              <!-- Toggle -->
              <td>
                <button class="table-action-btn <?= $isActive ? 'danger' : '' ?>"
                        id="utoggle-<?= $uid ?>"
                        onclick="toggleUserStatus(<?= $uid ?>)"
                        title="<?= $isActive ? 'Deactivate user' : 'Activate user' ?>">
                  <?= $isActive ? '🔴 Deactivate' : '🟢 Activate' ?>
                </button>
              </td>
              <!-- Username inline edit -->
              <td>
                <div class="uname-wrap" id="ucell-<?= $uid ?>">
                  <span class="uname-display" id="udisplay-<?= $uid ?>"><?= $uname ?></span>
                  <input class="uname-field" id="ufield-<?= $uid ?>"
                         type="text" value="<?= $uname ?>" maxlength="60"
                         placeholder="New username"
                         onkeydown="if(event.key==='Enter')saveUsername(<?= $uid ?>);
                                    if(event.key==='Escape')cancelEdit(<?= $uid ?>)" />
                  <button class="table-action-btn" id="ubtn-edit-<?= $uid ?>"
                          onclick="startEdit(<?= $uid ?>)" title="Edit username">✏️</button>
                  <button class="btn-save-u" id="ubtn-save-<?= $uid ?>"
                          onclick="saveUsername(<?= $uid ?>)">Save</button>
                  <button class="btn-cancel-u" id="ubtn-cancel-<?= $uid ?>"
                          onclick="cancelEdit(<?= $uid ?>)">✕</button>
                  <span class="uname-msg" id="umsg-<?= $uid ?>"></span>
                </div>
              </td>
              <!-- Delete -->
              <td>
                <button class="table-action-btn danger"
                        id="udel-<?= $uid ?>"
                        <?= $isActive ? 'disabled title="Deactivate the user first"' : 'title="Delete user permanently"' ?>
                        onclick="deleteUser(<?= $uid ?>, '<?= $uname ?>')">🗑️</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         PAGE: SPORTS MANAGEMENT
    ══════════════════════════════════════ -->
    <div class="admin-page" id="page-sports">
      <div class="admin-page-header">
        <div>
          <h1 class="admin-page-title">Sports <span>Management</span></h1>
          <p class="admin-breadcrumb">SportSync / Admin / <strong>Sports</strong></p>
        </div>
        <!-- "Add Sport" button intentionally removed per spec -->
        <span style="font-family:var(--font-head);font-size:0.82rem;color:var(--gray);">
          <?= $activeSportsCount ?> active / <?= count($sports) ?> total
        </span>
      </div>

      <?php if (!empty($dbError['sports'])): ?>
        <div class="db-err">⚠️ <?= htmlspecialchars($dbError['sports']) ?></div>
      <?php endif; ?>

      <!-- ACTION REQUIRED: In each sport's admin and viewer page, query:
           SELECT status FROM sports WHERE id = :id
           If status = 'inactive', display a "Sport is currently deactivated" notice
           and hide scoring controls. -->

      <div class="admin-panel">
        <div class="panel-header">
          <span class="panel-title"><span class="dot"></span>All Sports</span>
        </div>
        <table class="admin-table">
          <thead>
            <tr>
              <th>Sport</th>
              <th>Status</th>
              <th>Activate / Deactivate</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($sports)): ?>
            <tr><td colspan="3" class="dt-empty">No sports found in the sports table.</td></tr>
          <?php else: foreach ($sports as $sp):
            $spId     = (int)$sp['id'];
            $spName   = htmlspecialchars($sp['name']);
            $spStatus = $sp['status'];
            $spActive = ($spStatus === 'active');
            $em       = $sportEmojis[strtolower($sp['name'])] ?? '🏅';
          ?>
            <tr id="sprow-<?= $spId ?>">
              <td>
                <div class="user-cell">
                  <span style="font-size:1.3rem;"><?= $em ?></span>
                  <div class="user-name"><?= $spName ?></div>
                </div>
              </td>
              <td id="spstatus-<?= $spId ?>">
                <span class="badge badge-<?= $spActive ? 'active' : 'inactive' ?>">
                  <?= $spActive ? 'Active' : 'Inactive' ?>
                </span>
              </td>
              <td>
                <button class="table-action-btn <?= $spActive ? 'danger' : '' ?>"
                        id="sptoggle-<?= $spId ?>"
                        onclick="toggleSportStatus(<?= $spId ?>)">
                  <?= $spActive ? '🔴 Deactivate' : '🟢 Activate' ?>
                </button>
                <span id="spmsg-<?= $spId ?>" style="font-size:0.72rem;margin-left:6px;"></span>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <p style="color:var(--gray);font-size:0.78rem;line-height:1.7;margin-top:4px;">
        ℹ️ Toggling a sport sets <code style="color:var(--yellow);background:rgba(255,215,0,0.08);padding:1px 5px;border-radius:3px;">status</code>
        in the <code style="color:var(--yellow);background:rgba(255,215,0,0.08);padding:1px 5px;border-radius:3px;">sports</code> table.
        Each sport's admin/viewer page should query this flag and display a notice if the sport is inactive.
      </p>
    </div>

    <!-- ══════════════════════════════════════
         PAGE: MATCH HISTORY
    ══════════════════════════════════════ -->
    <div class="admin-page" id="page-matches">
      <div class="admin-page-header">
        <div>
          <h1 class="admin-page-title">Match <span>History</span></h1>
          <p class="admin-breadcrumb">SportSync / Admin / <strong>Matches</strong></p>
        </div>
        <span style="font-family:var(--font-head);font-size:0.82rem;color:var(--gray);">
          Latest <?= count($allMatches) ?> records &middot; all sports &middot; date desc
        </span>
      </div>

      <?php if (!empty($dbError['matches'])): ?>
        <div class="db-err">⚠️ <?= htmlspecialchars($dbError['matches']) ?></div>
      <?php endif; ?>

      <div class="admin-panel">
        <div class="panel-header" style="flex-wrap:wrap;gap:10px;">
          <span class="panel-title"><span class="dot"></span>All Matches</span>
          <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <span style="font-family:var(--font-head);font-size:0.68rem;color:var(--gray);letter-spacing:.08em;text-transform:uppercase;">Filter:</span>
            <button class="match-filter-btn active" data-sport="all"           onclick="filterMatchHistory('all',this)">All</button>
            <button class="match-filter-btn" data-sport="Basketball"           onclick="filterMatchHistory('Basketball',this)">🏀 Basketball</button>
            <button class="match-filter-btn" data-sport="Volleyball"           onclick="filterMatchHistory('Volleyball',this)">🏐 Volleyball</button>
            <button class="match-filter-btn" data-sport="Badminton"            onclick="filterMatchHistory('Badminton',this)">🏸 Badminton</button>
            <button class="match-filter-btn" data-sport="Table Tennis"         onclick="filterMatchHistory('Table Tennis',this)">🏓 Table Tennis</button>
            <button class="match-filter-btn" data-sport="Darts"                onclick="filterMatchHistory('Darts',this)">🎯 Darts</button>
          </div>
        </div>

          <!-- Committee Applications (superadmin only) -->
          <div class="admin-panel" id="panel-committee-apps">
            <div class="panel-header">
              <span class="panel-title"><span class="dot"></span>Committee Applications</span>
              <span style="font-family:var(--font-head);font-size:0.72rem;color:var(--gray);">Pending: <?= $pendingCount ?></span>
            </div>
            <div style="padding:16px;">
              <?php if (empty($pendingApplicants)): ?>
                <div style="color:var(--gray);padding:12px;font-style:italic;">No pending committee applications.</div>
              <?php else: ?>
                <table class="admin-table" id="pendingApplicantsTable">
                  <thead>
                    <tr><th>Name</th><th>Email</th><th>Registered</th><th>Action</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($pendingApplicants as $pa): ?>
                      <tr id="app-<?= (int)$pa['id'] ?>">
                        <td><?= htmlspecialchars($pa['username']) ?></td>
                        <td><?= htmlspecialchars($pa['email'] ?? '') ?></td>
                        <td style="color:var(--gray);font-size:0.8rem;white-space:nowrap;"><?= htmlspecialchars(substr($pa['created_at'] ?? '',0,16)) ?></td>
                        <td>
                          <button class="table-action-btn" onclick="approveApplicant(<?= (int)$pa['id'] ?>)">✅ Approve</button>
                          <button class="table-action-btn danger" onclick="rejectApplicant(<?= (int)$pa['id'] ?>)">❌ Reject</button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>
        <table class="admin-table" id="matchHistoryTable">
          <thead>
            <tr>
              <th>#</th>
              <th>Sport</th>
              <th>Teams / Players</th>
              <th>Score / Result</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($allMatches)): ?>
            <tr><td colspan="5" class="dt-empty">No matches recorded yet.</td></tr>
          <?php else: foreach ($allMatches as $i => $m): ?>
            <tr class="match-history-row" data-sport="<?= htmlspecialchars($m['sport']) ?>">
              <td style="color:var(--gray);font-size:0.78rem;"><?= $i + 1 ?></td>
              <td>
                <span style="font-size:0.75rem;font-family:var(--font-head);letter-spacing:0.08em;
                             background:rgba(255,215,0,0.1);color:var(--yellow);
                             padding:2px 8px;border-radius:4px;">
                  <?= htmlspecialchars($m['sport']) ?>
                </span>
              </td>
              <td><?= htmlspecialchars($m['teams']) ?></td>
              <td style="color:var(--gray-light);"><?= htmlspecialchars($m['score_result']) ?></td>
              <td style="color:var(--gray);font-size:0.78rem;white-space:nowrap;"><?= htmlspecialchars($m['match_date'] ?? '—') ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         PAGE: ACTIVITY LOG
    ══════════════════════════════════════ -->
    <div class="admin-page" id="page-activity">
      <div class="admin-page-header">
        <div>
          <h1 class="admin-page-title">Activity <span>Log</span></h1>
          <p class="admin-breadcrumb">SportSync / Admin / <strong>Activity</strong></p>
        </div>
        <button class="panel-action" onclick="exportActivityLog()">⬇ Export CSV</button>
      </div>

      <?php if (!empty($dbError['activity'])): ?>
        <div class="db-err">⚠️ <?= htmlspecialchars($dbError['activity']) ?></div>
      <?php endif; ?>

      <div class="admin-panel">
        <div class="panel-header">
          <span class="panel-title"><span class="dot"></span>Recent Activity</span>
          <span style="font-family:var(--font-head);font-size:0.72rem;color:var(--gray);">Last 50 entries</span>
        </div>
        <div class="activity-feed">
          <?php if (empty($activityLog)): ?>
            <div style="padding:28px;text-align:center;color:var(--gray);font-style:italic;">No activity logged yet.</div>
          <?php else: foreach ($activityLog as $log):
            // Color-code by action type
            $ac = strtolower($log['action'] ?? '');
            $dotColor = '#00c853'; // default green
            if (str_contains($ac, 'delet') || str_contains($ac, 'deactivat')) $dotColor = '#ff5252';
            elseif (str_contains($ac, 'creat') || str_contains($ac, 'activat') || str_contains($ac, 'login')) $dotColor = '#00c853';
            elseif (str_contains($ac, 'chang') || str_contains($ac, 'updat') || str_contains($ac, 'sport')) $dotColor = '#FFD700';
            elseif (str_contains($ac, 'logout')) $dotColor = '#888';
          ?>
            <div class="activity-item">
              <div class="activity-dot" style="background:<?= $dotColor ?>;"></div>
              <div class="activity-body">
                <div class="activity-text">
                  <strong><?= htmlspecialchars($log['username']) ?></strong>
                  — <?= htmlspecialchars($log['action']) ?>
                </div>
                <div class="activity-time"><?= htmlspecialchars($log['timestamp'] ?? '') ?></div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════
         PAGE: SETTINGS
    ══════════════════════════════════════ -->
    <div class="admin-page" id="page-settings">
      <div class="admin-page-header">
        <div>
          <h1 class="admin-page-title">System <span>Settings</span></h1>
          <p class="admin-breadcrumb">SportSync / Admin / <strong>Settings</strong></p>
        </div>
        <button class="btn btn-primary" style="font-size:0.82rem;padding:10px 22px;">Save Changes</button>
      </div>

      <div class="settings-grid">
        <div class="admin-panel">
          <div class="panel-header"><span class="panel-title"><span class="dot"></span>General</span></div>
          <div class="settings-group">
            <div class="settings-row">
              <div><div class="settings-key">User Registration</div><div class="settings-hint">Allow new users to register</div></div>
              <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
            </div>
            <div class="settings-row">
              <div><div class="settings-key">Email Notifications</div><div class="settings-hint">Send alerts for key events</div></div>
              <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
            </div>
            <div class="settings-row" id="maintenanceRow">
              <div>
                <div class="settings-key">Maintenance Mode</div>
                <div class="settings-hint" id="maintenanceHint">
                  <?php if ($maintenanceMode === '1'): ?>
                    <span style="color:#ff7675;font-weight:700;">⚠️ ACTIVE — All viewer &amp; admin pages are blocked for non-admins</span>
                  <?php else: ?>
                    Take the app offline for maintenance
                  <?php endif; ?>
                </div>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" id="maintenanceModeToggle"
                       <?= $maintenanceMode === '1' ? 'checked' : '' ?>
                       onchange="toggleMaintenanceMode(this.checked)">
                <span class="toggle-slider"></span>
              </label>
            </div>
            <div class="settings-row">
              <div><div class="settings-key">Public Leaderboard</div><div class="settings-hint">Show leaderboard to non-logged users</div></div>
              <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
            </div>
          </div>
        </div>

        <div class="admin-panel">
          <div class="panel-header"><span class="panel-title"><span class="dot"></span>Security</span></div>
          <div class="settings-group">
            <div class="settings-row">
              <div><div class="settings-key">Two-Factor Auth</div><div class="settings-hint">Require 2FA for all admin accounts</div></div>
              <label class="toggle-switch"><input type="checkbox"><span class="toggle-slider"></span></label>
            </div>
            <div class="settings-row">
              <div><div class="settings-key">Session Timeout</div><div class="settings-hint">Auto-logout after 30 minutes idle</div></div>
              <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
            </div>
            <div class="settings-row">
              <div><div class="settings-key">Login Attempt Limit</div><div class="settings-hint">Lock account after 5 failed attempts</div></div>
              <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
            </div>
            <div class="settings-row">
              <div><div class="settings-key">Activity Logging</div><div class="settings-hint">Log all admin and user actions</div></div>
              <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
            </div>
          </div>
        </div>

        <div class="admin-panel">
          <div class="panel-header"><span class="panel-title"><span class="dot"></span>Database</span></div>
          <div class="settings-group">
            <div class="settings-row">
              <div><div class="settings-key">Auto Backup</div><div class="settings-hint">Daily automated database backup</div></div>
              <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
            </div>
            <div class="settings-row">
              <div><div class="settings-key">Query Caching</div><div class="settings-hint">Cache frequent read queries</div></div>
              <label class="toggle-switch"><input type="checkbox" checked><span class="toggle-slider"></span></label>
            </div>
            <div class="settings-row" style="border-bottom:none;padding-bottom:0;">
              <div style="flex:1;">
                <div class="settings-key" style="margin-bottom:8px;">Max Connections</div>
                <input type="number" value="25" min="1" max="200"
                  style="background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:6px;
                         padding:8px 12px;color:var(--white);font-family:var(--font-head);font-size:0.9rem;width:100%;outline:none;">
              </div>
            </div>
          </div>
        </div>

        <div class="admin-panel" style="border-color:rgba(255,82,82,0.2);">
          <div class="panel-header" style="border-color:rgba(255,82,82,0.12);">
            <span class="panel-title" style="color:#ff5252;"><span class="dot" style="background:#ff5252;"></span>Danger Zone</span>
          </div>
          <div class="settings-group">
            <div class="settings-row">
              <div><div class="settings-key">Clear Activity Logs</div><div class="settings-hint">Permanently delete all logs</div></div>
              <button class="btn btn-outline" style="font-size:0.72rem;padding:7px 16px;border-color:rgba(255,82,82,0.4);color:#ff5252;"
                      onclick="if(confirm('Clear all activity logs? This cannot be undone.')){}">Clear Logs</button>
            </div>
            <div class="settings-row" style="border-bottom:none;padding-bottom:0;">
              <div><div class="settings-key">Reset All Scores</div><div class="settings-hint">Wipe all game scores from the system</div></div>
              <button class="btn btn-outline" style="font-size:0.72rem;padding:7px 16px;border-color:rgba(255,82,82,0.4);color:#ff5252;"
                      onclick="if(confirm('Reset all scores? This cannot be undone.')){}">Reset</button>
            </div>
          </div>
        </div>
      </div>
    </div>

  </main>
</div>

<!-- ══ ADD USER MODAL ══ -->
<div class="modal-bg" id="addUserModal" onclick="if(event.target===this)closeAddUser()">
  <div class="modal-box">
    <div class="modal-title">+ Add New User</div>
    <div class="fg">
      <label for="au-username">Username <span style="color:#ff5252">*</span></label>
      <input type="text" id="au-username" placeholder="e.g. john_doe" maxlength="60" autocomplete="off">
    </div>
    <div class="fg">
      <label for="au-password">Password <span style="color:#ff5252">*</span></label>
      <input type="password" id="au-password" placeholder="Minimum 6 characters" autocomplete="new-password">
    </div>
    <div class="fg">
      <label for="au-role">Role</label>
      <select id="au-role">
        <option value="viewer">Viewer</option>
        <option value="scorer">Scorer</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    <div class="modal-err" id="au-err"></div>
    <div class="modal-foot">
      <button class="btn-primary" onclick="submitAddUser()">✔ Create User</button>
      <button class="btn-secondary" onclick="closeAddUser()">Cancel</button>
    </div>
  </div>
</div>

<!-- Mobile sidebar toggle -->
<button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">☰</button>

<script src="landingpage.js"></script>
<script>
'use strict';

/* ── Real-time: WebSocket + BroadcastChannel listener ──
   Listens for `user_status_change` messages and applies UI updates instantly. */
(function(){
  try {
    var WS_HOST = location.hostname || '127.0.0.1';
    var WS_PORT = (typeof WS_SERVER_PORT !== 'undefined') ? WS_SERVER_PORT : 3000;
    var wsUrl = ((location.protocol === 'https:') ? 'wss://' : 'ws://') + WS_HOST + ':' + WS_PORT;
    var ws = null;
    function initWS(){
      try { ws = new WebSocket(wsUrl); ws.addEventListener('message', onMessage); ws.addEventListener('error', function(){}); ws.addEventListener('close', function(){ setTimeout(initWS, 3000); }); } catch (e) { setTimeout(initWS, 3000); }
    }
    function onMessage(ev){
      try {
        var msg = JSON.parse(ev.data);
        if (!msg || !msg.type) return;
        if (msg.type === 'user_status_change' || (msg.type === 'action' && msg.payload && msg.payload.type === 'user_status_change')) {
          var p = msg.payload || msg;
          var data = p.payload || p;
          handleUserStatusChange(data.user_id, data.new_status);
        }
      } catch (e) { }
    }
    initWS();

    // BroadcastChannel fallback for same-origin tabs
    var bc = null;
    try { bc = new BroadcastChannel('sportssync:user_status'); bc.onmessage = function(e){ var d = e.data; if (!d) return; if (d.type === 'user_status_change') handleUserStatusChange(d.payload.user_id, d.payload.new_status); }; } catch (e) { bc = null; }

    function handleUserStatusChange(uid, status) {
      if (!uid) return;
      // Update pending applicants table row
      try {
        var appRow = document.getElementById('app-' + uid);
        if (appRow) appRow.remove();
        var pendingBtn = document.getElementById('pendingApplicantsBtn');
        if (pendingBtn) {
          var sp = pendingBtn.querySelector('span');
          if (sp) sp.textContent = Math.max(0, parseInt(sp.textContent||'0',10) - 1);
        }
      } catch (e) {}

      // Update main users table if present
      try {
        var row = document.getElementById('urow-' + uid);
        if (row) {
          row.dataset.status = status;
          var st = document.getElementById('ustatus-' + uid);
          if (st) st.innerHTML = '<span class="badge badge-' + status + '">' + (status.charAt(0).toUpperCase() + status.slice(1)) + '</span>';
          var toggle = document.getElementById('utoggle-' + uid);
          if (toggle) {
            if (status === 'active') { toggle.textContent = '🔴 Deactivate'; toggle.className = 'table-action-btn danger'; toggle.disabled = false; document.getElementById('udel-' + uid)?.setAttribute('disabled','disabled'); }
            else { toggle.textContent = '🟢 Activate'; toggle.className = 'table-action-btn'; document.getElementById('udel-' + uid)?.removeAttribute('disabled'); }
          }
        }
      } catch (e) {}

      // If this client is the affected user, revalidate session (simple approach: hit auth-check URL)
      try {
        var currentUid = window.__SS_CURRENT_USER_ID || null;
        if (!currentUid) {
          // attempt to read from nav meta or global var if available
          var meta = document.querySelector('meta[name="auth-check-url"]');
          // nothing else to do here
        }
        if (currentUid && String(currentUid) === String(uid)) {
          // If status is no longer approved, redirect to logout to force re-login
          if (String(status).toLowerCase() !== 'approved' && location.pathname.indexOf('adminlanding_page.php') === -1) {
            try { window.location.replace('/legacy-logout'); } catch (e) { window.location.reload(); }
          }
        }
      } catch (e) {}

      // Mirror to BroadcastChannel for same-origin tabs
      try { if (bc) bc.postMessage({ type: 'user_status_change', payload: { user_id: uid, new_status: status } }); } catch (e) {}
    }
  } catch (e) { /* noop */ }
})();


/* ── Page navigation ── */
function navigate(pageId) {
  document.querySelectorAll('.admin-page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.sidebar-link').forEach(l => l.classList.remove('active'));
  const page = document.getElementById('page-' + pageId);
  if (page) page.classList.add('active');
  const link = document.querySelector(`.sidebar-link[data-page="${pageId}"]`);
  if (link) link.classList.add('active');
  document.getElementById('adminSidebar').classList.remove('open');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.querySelectorAll('.sidebar-link[data-page]').forEach(btn => {
  btn.addEventListener('click', () => navigate(btn.dataset.page));
});

/* ── Mobile sidebar ── */
document.getElementById('sidebarToggle').addEventListener('click', () => {
  document.getElementById('adminSidebar').classList.toggle('open');
});

/* ── Live clock ── */
function updateClock() {
  const el = document.getElementById('liveTime');
  if (!el) return;
  el.textContent = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
}
updateClock();
setInterval(updateClock, 1000);

/* ── User search / filter ── */
function filterUsers() {
  const q      = (document.getElementById('userSearch')?.value || '').toLowerCase();
  const role   = (document.getElementById('roleFilter')?.value || '').toLowerCase();
  const status = (document.getElementById('statusFilter')?.value || '').toLowerCase();
  document.querySelectorAll('#usersTbody tr[id^="urow-"]').forEach(row => {
    const text      = row.textContent.toLowerCase();
    const rowRole   = (row.dataset.role   || '').toLowerCase();
    const rowStatus = (row.dataset.status || '').toLowerCase();
    const mQ = !q      || text.includes(q);
    const mR = !role   || rowRole   === role;
    const mS = !status || rowStatus === status;
    row.style.display = (mQ && mR && mS) ? '' : 'none';
  });
}
document.getElementById('userSearch')?.addEventListener('input', filterUsers);
document.getElementById('roleFilter')?.addEventListener('change', filterUsers);
document.getElementById('statusFilter')?.addEventListener('change', filterUsers);

/* ── XSS-safe escape ── */
function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/* ── AJAX helper ── */
async function doAjax(params) {
  const fd = new FormData();
  Object.entries(params).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
  if (!r.ok) throw new Error('HTTP ' + r.status);
  return r.json();
}

/* ── TOGGLE USER STATUS ── */
async function toggleUserStatus(uid) {
  const btn = document.getElementById('utoggle-' + uid);
  if (!btn) return;
  const orig = btn.textContent;
  btn.disabled = true; btn.textContent = '…';
  try {
    const d = await doAjax({ ajax_action: 'toggle_user_status', user_id: uid });
    if (!d.success) { alert('Error: ' + (d.message || 'Unknown')); btn.disabled = false; btn.textContent = orig; return; }
    const active = d.new_status === 'active';
    // Update toggle button
    btn.textContent = active ? '🔴 Deactivate' : '🟢 Activate';
    btn.className   = 'table-action-btn' + (active ? ' danger' : '');
    btn.disabled    = false;
    btn.title       = active ? 'Deactivate user' : 'Activate user';
    // Update status badge
    const sc = document.getElementById('ustatus-' + uid);
    if (sc) {
      const label = d.new_status.charAt(0).toUpperCase() + d.new_status.slice(1);
      sc.innerHTML = `<span class="badge badge-${esc(d.new_status)}">${esc(label)}</span>`;
    }
    // Update delete button
    const delBtn = document.getElementById('udel-' + uid);
    if (delBtn) {
      delBtn.disabled = active;
      delBtn.title    = active ? 'Deactivate the user first' : 'Delete user permanently';
    }
    // Update row data-status for filter
    const row = document.getElementById('urow-' + uid);
    if (row) row.dataset.status = d.new_status;
  } catch (e) {
    alert('Network error: ' + e.message);
    btn.disabled = false; btn.textContent = orig;
  }
}

/* ── APPROVE / REJECT APPLICANT ── */
async function approveApplicant(uid) {
  if (!confirm('Approve this committee application?')) return;
  try {
    const d = await doAjax({ ajax_action: 'approve_admin', user_id: uid });
    if (!d.success) { alert('Error: ' + (d.message || 'Unknown')); return; }
    const row = document.getElementById('app-' + uid);
    if (row) row.remove();
    // Update pending badge
    const btn = document.getElementById('pendingApplicantsBtn');
    if (btn) {
      const m = btn.querySelector('span');
      if (m) m.textContent = Math.max(0, parseInt(m.textContent || '0', 10) - 1);
    }
    alert('Applicant approved');
  } catch (e) { alert('Network error: ' + e.message); }
}

async function rejectApplicant(uid) {
  if (!confirm('Reject this committee application? This cannot be undone.')) return;
  try {
    const d = await doAjax({ ajax_action: 'reject_admin', user_id: uid });
    if (!d.success) { alert('Error: ' + (d.message || 'Unknown')); return; }
    const row = document.getElementById('app-' + uid);
    if (row) row.remove();
    const btn = document.getElementById('pendingApplicantsBtn');
    if (btn) {
      const m = btn.querySelector('span');
      if (m) m.textContent = Math.max(0, parseInt(m.textContent || '0', 10) - 1);
    }
    alert('Applicant rejected');
  } catch (e) { alert('Network error: ' + e.message); }
}

/* ── DELETE USER ── */
async function deleteUser(uid, uname) {
  if (!confirm('Permanently delete user "' + uname + '"?\nThis cannot be undone.')) return;
  try {
    const d = await doAjax({ ajax_action: 'delete_user', user_id: uid });
    if (!d.success) { alert('Error: ' + (d.message || 'Unknown')); return; }
    const row = document.getElementById('urow-' + uid);
    if (row) row.remove();
    const lbl = document.getElementById('userCountLabel');
    if (lbl) {
      const n = document.querySelectorAll('#usersTbody tr[id^="urow-"]').length;
      lbl.textContent = n + ' Total';
    }
  } catch (e) { alert('Network error: ' + e.message); }
}

/* ── USERNAME INLINE EDIT ── */
function startEdit(uid) {
  const disp  = document.getElementById('udisplay-'    + uid);
  const field = document.getElementById('ufield-'      + uid);
  const eBtn  = document.getElementById('ubtn-edit-'   + uid);
  const sBtn  = document.getElementById('ubtn-save-'   + uid);
  const cBtn  = document.getElementById('ubtn-cancel-' + uid);
  const msg   = document.getElementById('umsg-'        + uid);
  field.value = disp.textContent.trim();
  disp.style.display  = 'none';
  field.style.display = 'inline-block';
  eBtn.style.display  = 'none';
  sBtn.style.display  = 'inline-flex';
  cBtn.style.display  = 'inline-flex';
  if (msg) msg.textContent = '';
  field.focus(); field.select();
}

function cancelEdit(uid) {
  document.getElementById('udisplay-'    + uid).style.display = '';
  document.getElementById('ufield-'      + uid).style.display = 'none';
  document.getElementById('ubtn-edit-'   + uid).style.display = '';
  document.getElementById('ubtn-save-'   + uid).style.display = 'none';
  document.getElementById('ubtn-cancel-' + uid).style.display = 'none';
  const msg = document.getElementById('umsg-' + uid);
  if (msg) msg.textContent = '';
}

async function saveUsername(uid) {
  const field = document.getElementById('ufield-'    + uid);
  const sBtn  = document.getElementById('ubtn-save-' + uid);
  const msg   = document.getElementById('umsg-'      + uid);
  const val   = field.value.trim();
  if (!val) { if (msg) { msg.style.color = '#ff5252'; msg.textContent = 'Cannot be empty.'; } return; }
  sBtn.disabled = true;
  if (msg) { msg.style.color = 'var(--gray)'; msg.textContent = '…'; }
  try {
    const d = await doAjax({ ajax_action: 'change_username', user_id: uid, new_username: val });
    if (!d.success) {
      if (msg) { msg.style.color = '#ff5252'; msg.textContent = d.message || 'Error'; }
      sBtn.disabled = false; return;
    }
    document.getElementById('udisplay-' + uid).textContent = d.new_username;
    document.getElementById('ufield-'   + uid).value       = d.new_username;
    cancelEdit(uid);
    const m2 = document.getElementById('umsg-' + uid);
    if (m2) {
      m2.style.color = '#00c853'; m2.textContent = '✔ Saved';
      setTimeout(() => { if (m2.textContent === '✔ Saved') m2.textContent = ''; }, 3000);
    }
  } catch (e) {
    if (msg) { msg.style.color = '#ff5252'; msg.textContent = 'Network error'; }
    sBtn.disabled = false;
  }
}

/* ── ADD USER MODAL ── */
function openAddUser() {
  document.getElementById('au-username').value = '';
  document.getElementById('au-password').value = '';
  document.getElementById('au-role').value     = 'viewer';
  document.getElementById('au-err').textContent = '';
  document.getElementById('addUserModal').classList.add('open');
  setTimeout(() => document.getElementById('au-username').focus(), 80);
}
function closeAddUser() {
  document.getElementById('addUserModal').classList.remove('open');
}

async function submitAddUser() {
  const username = document.getElementById('au-username').value.trim();
  const password = document.getElementById('au-password').value;
  const role     = document.getElementById('au-role').value;
  const errEl    = document.getElementById('au-err');
  errEl.textContent = '';
  if (!username)           { errEl.textContent = 'Username is required.'; return; }
  if (username.length < 3) { errEl.textContent = 'Username must be at least 3 characters.'; return; }
  if (!password || password.length < 6) { errEl.textContent = 'Password must be at least 6 characters.'; return; }
  try {
    const d = await doAjax({ ajax_action: 'add_user', username, password, role });
    if (!d.success) { errEl.textContent = d.message || 'Failed to create user.'; return; }
    closeAddUser();
    const tbody = document.getElementById('usersTbody');
    const uid   = d.user_id;
    const un    = esc(d.username);
    const rl    = esc(d.role);
    const rlCap = rl.charAt(0).toUpperCase() + rl.slice(1);
    const bgCol = (rl === 'admin' || rl === 'superadmin') ? '#FFD700' : '#1565C0';
    const fgCol = (rl === 'admin' || rl === 'superadmin') ? '#000' : '#fff';
    const init  = un.charAt(0).toUpperCase();
    // Remove empty placeholder
    const empty = tbody.querySelector('.dt-empty');
    if (empty) empty.closest('tr').remove();
    tbody.insertAdjacentHTML('afterbegin', `
      <tr id="urow-${uid}" data-role="${rl}" data-status="active">
        <td>
          <div class="user-cell">
            <div class="user-avatar-sm" style="background:${bgCol};color:${fgCol};">${init}</div>
            <div><div class="user-name">${un}</div><div class="user-meta">ID: ${uid}</div></div>
          </div>
        </td>
        <td><span class="badge badge-${rl}">${rlCap}</span></td>
        <td id="ustatus-${uid}"><span class="badge badge-active">Active</span></td>
        <td style="color:var(--gray);font-size:0.8rem;">Just now</td>
        <td>
          <button class="table-action-btn danger" id="utoggle-${uid}"
                  onclick="toggleUserStatus(${uid})" title="Deactivate user">🔴 Deactivate</button>
        </td>
        <td>
          <div class="uname-wrap" id="ucell-${uid}">
            <span class="uname-display" id="udisplay-${uid}">${un}</span>
            <input class="uname-field" id="ufield-${uid}" type="text" value="${un}" maxlength="60"
                   placeholder="New username"
                   onkeydown="if(event.key==='Enter')saveUsername(${uid});if(event.key==='Escape')cancelEdit(${uid})" />
            <button class="table-action-btn" id="ubtn-edit-${uid}" onclick="startEdit(${uid})" title="Edit username">✏️</button>
            <button class="btn-save-u"   id="ubtn-save-${uid}"   onclick="saveUsername(${uid})">Save</button>
            <button class="btn-cancel-u" id="ubtn-cancel-${uid}" onclick="cancelEdit(${uid})">✕</button>
            <span class="uname-msg" id="umsg-${uid}"></span>
          </div>
        </td>
        <td>
          <button class="table-action-btn danger" id="udel-${uid}" disabled
                  title="Deactivate the user first"
                  onclick="deleteUser(${uid}, '${un}')">🗑️</button>
        </td>
      </tr>`);
    const lbl = document.getElementById('userCountLabel');
    if (lbl) {
      const n = document.querySelectorAll('#usersTbody tr[id^="urow-"]').length;
      lbl.textContent = n + ' Total';
    }
  } catch (e) { errEl.textContent = 'Network error: ' + e.message; }
}

/* ── TOGGLE SPORT STATUS ── */
async function toggleSportStatus(spId) {
  const btn   = document.getElementById('sptoggle-' + spId);
  const msgEl = document.getElementById('spmsg-'    + spId);
  if (!btn) return;
  const orig = btn.textContent;
  btn.disabled = true;
  if (msgEl) { msgEl.style.color = 'var(--gray)'; msgEl.textContent = '…'; }
  try {
    const d = await doAjax({ ajax_action: 'toggle_sport_status', sport_id: spId });
    if (!d.success) {
      if (msgEl) { msgEl.style.color = '#ff5252'; msgEl.textContent = d.message || 'Error'; }
      btn.disabled = false; btn.textContent = orig; return;
    }
    const active = d.new_status === 'active';
    btn.textContent = active ? '🔴 Deactivate' : '🟢 Activate';
    btn.className   = 'table-action-btn' + (active ? ' danger' : '');
    btn.disabled    = false;
    const sc = document.getElementById('spstatus-' + spId);
    if (sc) {
      sc.innerHTML = `<span class="badge badge-${active ? 'active' : 'inactive'}">${active ? 'Active' : 'Inactive'}</span>`;
    }
    if (msgEl) {
      msgEl.style.color = '#00c853'; msgEl.textContent = '✔ Updated';
      setTimeout(() => { if (msgEl.textContent === '✔ Updated') msgEl.textContent = ''; }, 3000);
    }
  } catch (e) {
    if (msgEl) { msgEl.style.color = '#ff5252'; msgEl.textContent = 'Network error'; }
    btn.disabled = false; btn.textContent = orig;
  }
}

/* ── EXPORT ACTIVITY LOG (POST → CSV download) ── */
function exportActivityLog() {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = '';
  const inp = document.createElement('input');
  inp.type = 'hidden'; inp.name = 'ajax_action'; inp.value = 'export_activity_log';
  form.appendChild(inp);
  document.body.appendChild(form);
  form.submit();
  document.body.removeChild(form);
}

/* ── MAINTENANCE MODE TOGGLE ── */
async function toggleMaintenanceMode(checked) {
  const value  = checked ? '1' : '0';
  const toggle = document.getElementById('maintenanceModeToggle');
  const hint   = document.getElementById('maintenanceHint');
  if (toggle) toggle.disabled = true;
  try {
    const d = await doAjax({ ajax_action: 'save_system_setting', key: 'maintenance_mode', value });
    if (!d.success) {
      alert('Failed to update maintenance mode: ' + (d.message || 'Unknown error'));
      if (toggle) { toggle.checked = !checked; toggle.disabled = false; }
      return;
    }
    if (hint) {
      hint.innerHTML = checked
        ? '<span style="color:#ff7675;font-weight:700;">⚠️ ACTIVE — All viewer &amp; admin pages are blocked for non-admins</span>'
        : 'Take the app offline for maintenance';
    }
    _syncMaintenanceBanner(checked);
  } catch (e) {
    alert('Network error: ' + e.message);
    if (toggle) { toggle.checked = !checked; }
  }
  if (toggle) toggle.disabled = false;
}

function _syncMaintenanceBanner(on) {
  let banner = document.getElementById('ss-maint-banner');
  if (on) {
    if (!banner) {
      banner = document.createElement('div');
      banner.id = 'ss-maint-banner';
      banner.innerHTML = '🔧 <strong>Maintenance Mode is ON</strong> — Non-admin users see the maintenance screen on all pages.'
        + '<button onclick="document.getElementById(\'ss-maint-banner\').remove()" '
        + 'style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:#fed7aa;font-size:18px;cursor:pointer;line-height:1" title="Dismiss">✕</button>';
      document.body.prepend(banner);
    }
  } else {
    if (banner) banner.remove();
  }
}

/* Show banner on initial load if maintenance is already ON */
(function() {
  <?php if ($maintenanceMode === '1'): ?>
  _syncMaintenanceBanner(true);
  <?php endif; ?>
})();

/* ── MATCH HISTORY FILTER ── */
function filterMatchHistory(sport, btn) {
  document.querySelectorAll('.match-filter-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');
  const rows = document.querySelectorAll('.match-history-row');
  rows.forEach(row => {
    row.style.display = (sport === 'all' || row.dataset.sport === sport) ? '' : 'none';
  });
  const visibleCount = sport === 'all'
    ? rows.length
    : document.querySelectorAll(`.match-history-row[data-sport="${sport.replace(/"/g,'')}"]:not([style*="none"])`).length;
  const subEl = document.querySelector('#page-matches .admin-page-header > span');
  if (subEl) {
    subEl.textContent = sport === 'all'
      ? 'Latest <?= count($allMatches) ?> records · all sports · date desc'
      : visibleCount + ' records · ' + sport + ' · date desc';
  }
}

// ── Pending applicants quick jump
(function() {
  const btn = document.getElementById('pendingApplicantsBtn');
  if (!btn) return;
  btn.addEventListener('click', function (ev) {
    ev.preventDefault();
    // Open the Matches page (where Committee Applications panel lives)
    try {
      navigate('matches');
    } catch (e) { /* ignore */ }
    // After page change, scroll to the committee panel and highlight it
    setTimeout(() => {
      const panel = document.getElementById('panel-committee-apps');
      if (!panel) return;
      panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
      const prev = panel.style.boxShadow;
      panel.style.boxShadow = '0 0 0 4px rgba(255,215,0,0.12)';
      panel.style.transition = 'box-shadow 0.35s ease';
      setTimeout(() => { panel.style.boxShadow = prev; }, 2200);
    }, 180);
  });
})();
</script>
</body>
</html>