<?php
// Set Timezone
date_default_timezone_set('Asia/Jakarta');

// Load Koneksi DB & Config
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auto_reply_engine.php';

// Pastikan API Token ada
if (!defined('ONESENDER_API_URL') || !defined('ONESENDER_API_TOKEN')) {
    die(json_encode(['status' => 'error', 'reason' => 'API Config Missing']));
}

// Jalankan Engine sebagai Listener
$autoReply = new AutoReplyEngine(
    $conn, 
    ONESENDER_API_URL, 
    ONESENDER_API_TOKEN, 
    __DIR__ . '/webhook_engine.log'
);

// Engine mengambil alih tangkapan webhook
$autoReply->listen();