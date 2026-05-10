<?php
return [
    // Allowed legacy UI folders (whitelist used by the proxy)
    'allowed_folders' => [
        'TABLE TENNIS ADMIN UI',
        'Badminton Admin UI',
        'Basketball Admin UI',
        'DARTS ADMIN UI',
        'Volleyball Admin UI',
        'analytics',
    ],

    // When true the LegacySessionMiddleware will inject a short-lived
    // compatibility $_SESSION/$_COOKIE identity into requests proxied
    // to legacy PHP files. Set to false to disable injection during tests.
    'inject_session' => env('LEGACY_INJECT_SESSION', true),

    // Default legacy session keys
    'session_keys' => [
        'user_id' => 'user_id',
        'role' => 'role',
        'username' => 'username',
    ],
];
