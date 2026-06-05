<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$file = __DIR__ . '/test_log.txt';
$write = file_put_contents($file, 'Test write ' . date('H:i:s'));

if ($write) {
    echo "BERHASIL! File test_log.txt terbuat.";
} else {
    echo "GAGAL! Server tidak punya izin tulis di folder ini.";
}