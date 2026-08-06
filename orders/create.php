<?php
require_once __DIR__ . '/../config/config.php';

header('Location: ' . BASE_URL . 'orders/index.php', true, 302);
exit();
