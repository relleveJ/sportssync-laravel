<?php
require_once __DIR__ . '/auth.php';

// Defensive check: ensure `currentUser()` is available
if (!function_exists('currentUser')) {
  if (file_exists(__DIR__ . '/auth.php')) {
    include_once __DIR__ . '/auth.php';
  }
}
if (!function_exists('currentUser')) {
  http_response_code(500);
  echo '<h1>Server configuration error</h1><p>Authentication library not loaded. Please ensure <strong>auth.php</strong> exists in the project root and is readable.</p>';
  exit;
}

// Already logged in → go to landing
if (currentUser()) {
    header('Location: landingpage.php'); exit;
}

$error = '';
$next  = isset($_GET['next']) ? $_GET['next'] : 'landingpage.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = $_POST['identifier'] ?? '';
    $password   = $_POST['password']   ?? '';
    $result     = loginUser($identifier, $password);
    if ($result['ok']) {
        $safe = filter_var($next, FILTER_SANITIZE_URL);
        // Only allow relative redirect
        if (!$safe || strpos($safe, '//') !== false) $safe = 'landingpage.php';
        header('Location: ' . $safe); exit;
    }
    $error = $result['error'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — SportSync</title>
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
      <h1 class="auth-title">Welcome Back</h1>
      <p class="auth-sub">Sign in to your account to continue</p>
    </div>

    <?php if ($error): ?>
      <div class="auth-alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form class="auth-form" method="POST" action="">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

      <div class="form-group">
        <label for="identifier">Username or Email</label>
        <input
          type="text"
          id="identifier"
          name="identifier"
          placeholder="Enter username or email"
          value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>"
          autocomplete="username"
          required
        >
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <div class="input-wrap">
          <input
            type="password"
            id="password"
            name="password"
            placeholder="Enter your password"
            autocomplete="current-password"
            required
          >
          <button type="button" class="toggle-pw" onclick="togglePw('password', this)" aria-label="Show/hide password">👁</button>
        </div>
      </div>

      <button type="submit" class="auth-btn">Sign In</button>
    </form>

    <p class="auth-switch">
      Don't have an account? <a href="register.php">Create one</a>
    </p>
  </div>

</div>

<script src="auth.js"></script>
</body>
</html>
