<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isSecure,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function demo_csrf_token(): string
{
    if (!isset($_SESSION['demo_csrf_token'])) {
        $_SESSION['demo_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['demo_csrf_token'];
}

function demo_csrf_is_valid(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['demo_csrf_token'])
        && hash_equals((string) $_SESSION['demo_csrf_token'], $token);
}

function demo_require_login(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    if (empty($_SESSION['demo_user'])) {
        header('Location: login.php');
        exit;
    }
}

