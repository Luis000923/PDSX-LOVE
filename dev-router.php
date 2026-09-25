<?php
// Solo desarrollo: emula el RewriteRule de public/.htaccess para `php -S`.
if (preg_match('#^/c/([a-z0-9]{6,12})/?$#', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
    $_GET['u'] = $m[1];
    require __DIR__ . '/public/view.php';
    return true;
}
return false;
