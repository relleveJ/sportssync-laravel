<?php
// tmp_query_user.php — debug helper to query users table
require_once __DIR__ . '/app/Legacy/db.php';
try {
    $id = $argv[1] ?? '11';
    $id = (int)$id;
    if (!isset($pdo)) {
        echo json_encode(['ok'=>false,'error'=>'$pdo not set']);
        exit(1);
    }
    $st = $pdo->prepare('SELECT id, username, role, is_active FROM users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['ok'=>true,'user'=>$r]);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
