<?php
require_once __DIR__ . '/auth.php';

// Defensive check: ensure `currentUser()` is available
if (!function_exists('currentUser')) {
  // try to include again (covers odd include_path/case issues)
  if (file_exists(__DIR__ . '/auth.php')) {
    include_once __DIR__ . '/auth.php';
  }
}

if (!function_exists('currentUser')) {
  // Helpful fatal message instead of PHP fatal: undefined function
  http_response_code(500);
  echo '<h1>Server configuration error</h1><p>Authentication library not loaded. Please ensure <strong>auth.php</strong> exists in the project root and is readable.</p>';
  exit;
}

if (currentUser()) {
    header('Location: landingpage.php'); exit;
}

$error   = '';
$success = '';
$vals    = ['username' => '', 'email' => '', 'role' => 'scorekeeper'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vals['username'] = trim($_POST['username'] ?? '');
    $vals['email']    = trim($_POST['email']    ?? '');
    $vals['role']     = $_POST['role']           ?? 'scorekeeper';
    $password         = $_POST['password']       ?? '';
    $confirm          = $_POST['confirm']        ?? '';

    if ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $result = registerUser($vals['username'], $vals['email'], $password, $vals['role']);
        if ($result['ok']) {
            $success = 'Account created! <a href="login.php">Sign in now →</a>';
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — SportSync</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="auth.css">
</head>
<body>

<div class="auth-bg">
  <canvas class="auth-canvas" id="authCanvas"></canvas>
  <div class="auth-grid-overlay"></div>
</div>

<div class="auth-wrap">

  <div class="auth-card">
    <a href="landingpage.php" class="auth-logo">
      <span class="logo-bolt">⚡</span>SportSync
    </a>

    <div class="auth-header">
      <h1 class="auth-title">Create Account</h1>
      <p class="auth-sub">Join SportSync to start scoring live</p>
    </div>

    <?php if ($error): ?>
      <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="auth-success"><?= $success ?></div>
    <?php endif; ?>

    <form class="auth-form" method="POST" action="" novalidate>

      <div class="form-group">
        <label for="username">Username</label>
        <input
          type="text"
          id="username"
          name="username"
          placeholder="Choose a username"
          value="<?= htmlspecialchars($vals['username']) ?>"
          autocomplete="username"
          maxlength="40"
          required
        >
        <span class="form-hint">Letters, digits, _ . − only.</span>
      </div>

      <div class="form-group">
        <label for="email">Email</label>
        <input
          type="email"
          id="email"
          name="email"
          placeholder="you@example.com"
          value="<?= htmlspecialchars($vals['email']) ?>"
          autocomplete="email"
          required
        >
      </div>

      <div class="form-group">
        <label for="role">Role</label>
        <select id="role" name="role">
          <option value="scorekeeper" <?= $vals['role'] === 'scorekeeper' ? 'selected' : '' ?>>Scorekeeper</option>
          <option value="viewer"      <?= $vals['role'] === 'viewer'      ? 'selected' : '' ?>>Viewer</option>
        </select>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-wrap">
          <input
            type="password"
            id="password"
            name="password"
            placeholder="At least 8 chars, 1 uppercase, 1 digit"
            autocomplete="new-password"
            required
          >
          <button type="button" class="toggle-pw" onclick="togglePw('password', this)" aria-label="Show password">👁</button>
        </div>
        <div class="pw-strength" id="pwStrength"><div class="pw-bar" id="pwBar"></div></div>
      </div>

      <div class="form-group">
        <label for="confirm">Confirm Password</label>
        <div class="input-wrap">
          <input
            type="password"
            id="confirm"
            name="confirm"
            placeholder="Repeat password"
            autocomplete="new-password"
            required
          >
          <button type="button" class="toggle-pw" onclick="togglePw('confirm', this)" aria-label="Show password">👁</button>
        </div>
      </div>

      <button type="submit" class="auth-btn">Create Account</button>
    </form>

    <p class="auth-switch">
      Already have an account? <a href="login.php">Sign in</a>
    </p>
  </div>

</div>

<script src="auth.js"></script>
</body>
</html>