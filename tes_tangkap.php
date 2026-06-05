<?php
$input = file_get_contents('php://input');
file_put_contents('hasil_tangkap.txt', date('H:i:s') . " - " . $input . "\n", FILE_APPEND);