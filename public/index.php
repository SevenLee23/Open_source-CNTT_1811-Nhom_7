<?php
require dirname(__DIR__) . '/bootstrap.php';

use App\Support\Router;

$router = new Router();
foreach (require APP_ROOT . '/routes/web.php' as $route) $router->add($route[0], $route[1], $route[2]);
foreach (require APP_ROOT . '/routes/api.php' as $route) $router->add($route[0], $route[1], $route[2]);

$route = isset($_GET['r']) ? trim((string) $_GET['r'], '/') : '';
if ($route === '') {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = rtrim(dirname($script), '/');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($base !== '' && strpos($path, $base) === 0) $path = substr($path, strlen($base));
    $route = trim($path, '/');
    if ($route === 'index.php') $route = '';
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && strpos($route, 'api/') !== 0) verify_csrf();

try {
    $activeUser = \App\Support\Auth::user();
    if ($activeUser && $activeUser['account_type'] === 'child' && strpos($route, 'child/') !== 0) redirect('child/dashboard');
    if ($activeUser && $activeUser['account_type'] !== 'child' && ($route === 'child-login' || strpos($route, 'child/') === 0)) redirect('dashboard');
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $route);
} catch (\PDOException $exception) {
    http_response_code(503);
    $message = \App\Support\Config::get('app.debug') ? $exception->getMessage() : 'Không thể kết nối cơ sở dữ liệu. Hãy kiểm tra .env và chạy scripts/setup.php.';
    echo '<!doctype html><meta charset="utf-8"><title>Database setup</title><h1>FeatureLab chưa kết nối được MySQL</h1><p>' . e($message) . '</p><p>Xem hướng dẫn trong README.md.</p>';
} catch (\Throwable $exception) {
    http_response_code(500);
    $message = \App\Support\Config::get('app.debug') ? $exception->getMessage() : 'Đã xảy ra lỗi khi xử lý yêu cầu.';
    echo '<!doctype html><meta charset="utf-8"><title>FeatureLab error</title><h1>Không thể hoàn tất yêu cầu</h1><p>' . e($message) . '</p>';
}
