<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config.php';
$token = (string)($_GET['token'] ?? '');
if ($token !== 'jwd_auto_cron') exit('denied');
header('Content-Type: text/plain; charset=utf-8');
$res = $conn->query("SELECT id, keyword, reply, priority, is_active FROM auto_reply_rules WHERE is_active = 1 ORDER BY priority DESC");
while ($r = $res->fetch_assoc()) {
    echo "ID: {$r['id']} | KW: {$r['keyword']}\nREPLY:\n{$r['reply']}\n-------------------\n";
}
