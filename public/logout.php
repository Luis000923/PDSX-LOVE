<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

require_post();
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => 1, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
redirect('index.php');
