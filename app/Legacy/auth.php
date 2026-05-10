<?php
// ============================================================
// auth.php — Shared authentication helpers (moved to app/Legacy)
// Include at the top of any page that needs auth:
//   require_once __DIR__ . '/auth.php';
//   requireLogin();            // redirects to login.php if not logged in
//   requireRole('admin');      // redirects if wrong role
// ============================================================

// Attempt to load DB connection, but do so safely so a DB error
// doesn't prevent auth functions from being declared (helps CLI checks).
$pdo = null;
try {
    if (file_exists(__DIR__ . '/db.php')) {
        // db.php is expected to set $pdo; it may throw on connection errors
        include_once __DIR__ . '/db.php';
        if (!isset($pdo) || !$pdo) $pdo = null;
    }
} catch (Throwable $e) {
    // Keep $pdo null and allow functions to exist; runtime DB errors
    // will be surfaced as friendly messages from auth functions.
    $pdo = null;
}
define('SESSION_LIFETIME', 60 * 60 * 8); // 8 hours

// ── Start session safely ─────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    // Use array-style cookie params when supported (PHP >= 7.3).
    // Fall back to legacy signature on older PHP versions to avoid
    // fatal errors (some environments still run older PHP builds).
    if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        // Legacy signature: session_set_cookie_params(lifetime, path, domain, secure, httponly)
        // 'samesite' not supported prior to PHP 7.3 — omit it.
        $secure = isset($_SERVER['HTTPS']);
        @session_set_cookie_params(SESSION_LIFETIME, '/', '', $secure, true);
    }
    session_start();
}

// ── Current user from session ────────────────────────────────
function currentUser(): ?array {
    // Prefer native PHP session when available
    if (!empty($_SESSION['user_id'])) {
        global $pdo;
        if (!$pdo) {
            // DB unavailable — treat as not logged in
            return null;
        }
        $stmt = $pdo->prepare('SELECT id, username, email, role, display_name, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $u = $stmt->fetch();
        if (!$u || !$u['is_active']) {
            session_destroy();
            return null;
        }
        // Backwards-compat: treat legacy 'scorekeeper' role as 'admin'
        if (!empty($u['role']) && $u['role'] === 'scorekeeper') {
            $u['role'] = 'admin';
        }
        return $u;
    }

    // Fallback: accept lightweight legacy cookies set by Laravel login
    // (SS_USER_ID, SS_ROLE). This allows AJAX requests that hit public
    // legacy endpoints directly to be authorized when the browser has
    // the Laravel-authenticated identity mirrored into cookies.
    if (!empty($_COOKIE['SS_USER_ID'])) {
        $rawUid = $_COOKIE['SS_USER_ID'];
        // some browsers or intermediary layers may percent-encode cookie
        // values (e.g. %3D for '='). Ensure we decode before decrypting.
        $rawUid = urldecode($rawUid);
        $uid = 0;
        if (is_numeric($rawUid)) {
            $uid = (int) $rawUid;
        } else {
            // Attempt to decrypt Laravel-encrypted cookies (base64 JSON payload)
            try {
                $autoload = __DIR__ . '/../../vendor/autoload.php';
                if (file_exists($autoload)) require_once $autoload;
                $appKey = getenv('APP_KEY') ?: null;
                if (!$appKey) {
                    $envPath = __DIR__ . '/../../.env';
                    if (file_exists($envPath)) {
                        $env = @file_get_contents($envPath);
                        if ($env && preg_match('/^APP_KEY=(.+)$/m', $env, $m)) {
                            $appKey = trim($m[1]);
                        }
                    }
                }
                if ($appKey) {
                    if (strpos($appKey, 'base64:') === 0) {
                        $rawKey = base64_decode(substr($appKey, 7));
                    } else {
                        $rawKey = $appKey;
                    }
                    // Default cipher per config/app.php
                    $cipher = 'AES-256-CBC';
                    $encrypter = new \Illuminate\Encryption\Encrypter($rawKey, strtolower($cipher));
                    $decrypted = $encrypter->decrypt($rawUid);
                    if (is_int($decrypted) || ctype_digit((string)$decrypted)) {
                        $uid = (int) $decrypted;
                    } elseif (is_string($decrypted) && preg_match('/^\d+$/', $decrypted)) {
                        $uid = (int) $decrypted;
                    }
                }
            } catch (Throwable $_) {
                $uid = 0;
            }
        }

        if ($uid <= 0) return null;
        global $pdo;
        if (!$pdo) return null;
        try {
            $stmt = $pdo->prepare('SELECT id, username, email, role, display_name, is_active FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$uid]);
            $u = $stmt->fetch();
            if (!$u || !$u['is_active']) return null;
            // Backwards-compat: treat legacy 'scorekeeper' role as 'admin'
            if (!empty($u['role']) && $u['role'] === 'scorekeeper') {
                $u['role'] = 'admin';
            }
            return $u;
        } catch (Throwable $_) {
            return null;
        }
    }

    return null;
}

// ── Require login gate ───────────────────────────────────────
// Default redirect updated to Laravel superadmin login to avoid missing
// legacy login.php file on modern installations.
function requireLogin(string $redirect = '/superadmin/login'): array {
    $u = currentUser();
    if (!$u) {
        // When included from Laravel wrappers, avoid performing a redirect+exit
        // so the legacy page can be rendered inside a wrapper. Return a
        // lightweight placeholder user for compatibility with admin pages.
        if (defined('LARAVEL_WRAPPER') && LARAVEL_WRAPPER) {
            return ['id' => 1, 'username' => 'laravel', 'role' => 'admin', 'is_active' => 1];
        }
        header('Location: ' . $redirect . '?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    return $u;
}

// ── Require role gate ─────────────────────────────────────────
function requireRole(string $role, string $redirect = '/superadmin/login'): array {
    $u = requireLogin($redirect);
    // Add 'superadmin' at highest privilege level so it always passes admin checks
    $hierarchy = ['viewer' => 0, 'scorekeeper' => 1, 'admin' => 2, 'superadmin' => 3];
    $required  = $hierarchy[$role]  ?? 0;
    $has       = $hierarchy[$u['role']] ?? 0;
    if ($has < $required) {
        if (defined('LARAVEL_WRAPPER') && LARAVEL_WRAPPER) {
            return ['id' => 1, 'username' => 'laravel', 'role' => 'admin', 'is_active' => 1];
        }
        http_response_code(403);
        echo '<!DOCTYPE html><html><body style="background:#0a0a0a;color:#FFD700;font-family:sans-serif;padding:60px;text-align:center"><h1>403 — Access Denied</h1><p>You need the <strong>' . htmlspecialchars($role) . '</strong> role.</p><a href="/" style="color:#FFD700">← Back to home</a></body></html>';
        exit;
    }
    return $u;
}

// ── Log auth events ──────────────────────────────────────────
function authLog(?int $userId, string $event, string $detail = ''): void {
    global $pdo;
    try {
        if (!$pdo) return; // no DB available
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare('INSERT INTO auth_log (user_id,event,ip_address,detail) VALUES (?,?,?,?)')
            ->execute([$userId, $event, $ip, $detail ?: null]);
    } catch (Throwable $e) { /* non-fatal */ }
}

// ── Register ─────────────────────────────────────────────────
function registerUser(string $username, string $email, string $password, string $role = 'admin'): array {
    global $pdo;
    if (!$pdo) return ['ok' => false, 'error' => 'Database unavailable'];
    $username = trim($username);
    $email    = trim(strtolower($email));

    if (strlen($username) < 3 || strlen($username) > 40)
        return ['ok' => false, 'error' => 'Username must be 3–40 characters.'];
    if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username))
        return ['ok' => false, 'error' => 'Username may only contain letters, digits, _ . -'];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        return ['ok' => false, 'error' => 'Invalid email address.'];
    if (strlen($password) < 8)
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password))
        return ['ok' => false, 'error' => 'Password must contain at least one uppercase letter and one digit.'];

    // During migration we accept legacy 'scorekeeper' but store 'admin'
    // Only allow 'admin' or 'viewer' during new registrations. Map legacy
    // 'scorekeeper' to 'admin' for backward compatibility but do not allow
    // creation of new 'scorekeeper' accounts.
    $allowed = ['admin', 'viewer', 'scorekeeper'];
    if (!in_array($role, $allowed)) $role = 'viewer';
    if ($role === 'scorekeeper') $role = 'admin';

    // New behaviour: admin applicants start with status = 'pending'
    $statusForRole = ($role === 'admin') ? 'pending' : 'active';

    // Check uniqueness
    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $check->execute([$username, $email]);
    if ($check->fetch())
        return ['ok' => false, 'error' => 'Username or email is already taken.'];

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    // Try inserting with `status` column if present; fall back gracefully if column missing.
    try {
        $ins = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, display_name, status) VALUES (?,?,?,?,?,?)');
        $ins->execute([$username, $email, $hash, $role, $username, $statusForRole]);
    } catch (\PDOException $e) {
        // If `status` column doesn't exist, fall back to legacy insert
        try {
            $ins2 = $pdo->prepare('INSERT INTO users (username, email, password_hash, role, display_name) VALUES (?,?,?,?,?)');
            $ins2->execute([$username, $email, $hash, $role, $username]);
            // Attempt to set status via UPDATE if possible
            $newId = (int)$pdo->lastInsertId();
            if ($newId && in_array($statusForRole, ['pending','active','rejected'])) {
                try {
                    $upd = $pdo->prepare('UPDATE users SET status = :st WHERE id = :id');
                    $upd->execute([':st' => $statusForRole, ':id' => $newId]);
                } catch (\Throwable $_) { /* ignore */ }
            }
        } catch (\Throwable $e2) {
            return ['ok' => false, 'error' => 'Database insert failed: ' . $e2->getMessage()];
        }
    }

    $userId = (int) $pdo->lastInsertId();
    authLog($userId, 'register');
    return ['ok' => true, 'user_id' => $userId];
}

// ── Login ────────────────────────────────────────────────────
function loginUser(string $identifier, string $password): array {
    global $pdo;
    if (!$pdo) return ['ok' => false, 'error' => 'Database unavailable'];
    $identifier = trim($identifier);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1');
    $stmt->execute([$identifier, strtolower($identifier)]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password_hash'])) {
        authLog($u['id'] ?? null, 'login_fail', $identifier);
        return ['ok' => false, 'error' => 'Invalid username or password.'];
    }

    // Block logins for accounts that are not approved
    try {
        $status = isset($u['status']) ? strtolower((string)$u['status']) : '';
        if (in_array($status, ['pending', 'rejected'], true)) {
            authLog($u['id'] ?? null, 'login_blocked_status', $status);
            return ['ok' => false, 'error' => 'Account not approved.'];
        }
        if (isset($u['is_active']) && !(int)$u['is_active']) {
            authLog($u['id'] ?? null, 'login_blocked_inactive');
            return ['ok' => false, 'error' => 'Account inactive.'];
        }
    } catch (Throwable $_) {
        // If status check fails for any reason, fall back to existing behaviour
    }

    // Regenerate session ID to prevent fixation
    session_regenerate_id(true);
    $_SESSION['user_id']   = $u['id'];
    $_SESSION['username']  = $u['username'];
    $_SESSION['role']      = $u['role'];
    $_SESSION['logged_in_at'] = time();

    authLog($u['id'], 'login_ok');
    return ['ok' => true, 'user' => $u];
}

// ── Logout ───────────────────────────────────────────────────
function logoutUser(): void {
    if (!empty($_SESSION['user_id'])) {
        authLog((int)$_SESSION['user_id'], 'logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
