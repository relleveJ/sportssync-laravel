<?php
require_once __DIR__ . '/auth.php';
logoutUser();
// Redirect to Laravel route that clears both Laravel and legacy sessions/cookies
header('Location: /legacy-logout');
exit;