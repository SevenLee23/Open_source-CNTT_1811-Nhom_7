<?php
define('APP_ROOT', __DIR__);
require APP_ROOT . '/app/Support/Config.php';
\App\Support\Config::load(APP_ROOT);
spl_autoload_register(function ($class) {
    if (strpos($class, 'App\\') !== 0) return;
    $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) require $file;
});
require APP_ROOT . '/app/Support/helpers.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}
