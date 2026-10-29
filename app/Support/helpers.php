<?php
use App\Support\Config;

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url($route = '')
{
    $base = Config::get('app.url');
    $path = 'index.php?r=' . rawurlencode('/' . ltrim($route, '/'));
    return ($base ? $base . '/' : '') . $path;
}

function asset_url($path)
{
    $base = Config::get('app.url');
    return ($base ? $base . '/' : '') . ltrim((string) $path, '/');
}

function redirect($route = '')
{
    header('Location: ' . url($route));
    exit;
}

function csrf_token()
{
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf()
{
    $token = isset($_POST['_token']) ? (string) $_POST['_token'] : (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
        http_response_code(419);
        exit('Phiên làm việc đã hết hạn. Vui lòng tải lại trang.');
    }
}

function flash($key, $value = null)
{
    if ($value !== null) {
        $_SESSION['_flash'][$key] = $value;
        return null;
    }
    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $message;
}

function current_route()
{
    return trim((string) ($_GET['r'] ?? ''), '/');
}
