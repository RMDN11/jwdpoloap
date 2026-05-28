<?php
session_start();
require_once 'config.php';

$_SESSION['logout_message'] = "Berhasil logout!";
session_unset();
session_destroy();

header("Location: loginwa.php");
exit();
