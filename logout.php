<?php
require_once __DIR__ . '/config/config.php';

session_unset();
session_destroy();

setFlash('info', 'Anda telah logout.');
redirect('login.php');
