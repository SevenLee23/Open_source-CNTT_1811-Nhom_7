<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function demo_data_dir(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'data';
}

function demo_default_features(): array
{
    return [
        ['key' => 'new_dashboard', 'name' => 'New dashboard experience', 'on' => true, 'rollout' => 100, 'group' => 'internal_testers', 'fallback' => 'false', 'updated' => '2 giờ trước', 'kill' => false, 'def' => false],
        ['key' => 'beta_search', 'name' => 'Beta search engine', 'on' => true, 'rollout' => 25, 'group' => 'beta_users', 'fallback' => 'false', 'updated' => '4 giờ trước', 'kill' => false, 'def' => false],
        ['key' => 'dark_mode', 'name' => 'Dark mode', 'on' => true, 'rollout' => 100, 'group' => 'general_users', 'fallback' => 'true', 'updated' => 'Hôm qua', 'kill' => false, 'def' => true],
        ['key' => 'team_workspaces', 'name' => 'Team workspaces', 'on' => true, 'rollout' => 50, 'group' => 'internal_testers', 'fallback' => 'false', 'updated' => 'Hôm qua', 'kill' => false, 'def' => false],
        ['key' => 'ai_suggestions', 'name' => 'AI-powered suggestions', 'on' => false, 'rollout' => 0, 'group' => 'beta_users', 'fallback' => 'false', 'updated' => '2 ngày trước', 'kill' => false, 'def' => false],
        ['key' => 'quick_filters', 'name' => 'Quick filters', 'on' => true, 'rollout' => 100, 'group' => 'general_users', 'fallback' => 'true', 'updated' => '3 ngày trước', 'kill' => false, 'def' => false],
        ['key' => 'export_v2', 'name' => 'Export v2', 'on' => false, 'rollout' => 0, 'group' => 'internal_testers', 'fallback' => 'false', 'updated' => '4 ngày trước', 'kill' => false, 'def' => false],
        ['key' => 'onboarding_flow', 'name' => 'New onboarding flow', 'on' => true, 'rollout' => 40, 'group' => 'beta_users', 'fallback' => 'false', 'updated' => '5 ngày trước', 'kill' => false, 'def' => false],
        ['key' => 'realtime_sync', 'name' => 'Real-time sync', 'on' => true, 'rollout' => 100, 'group' => 'general_users', 'fallback' => 'true', 'updated' => '1 tuần trước', 'kill' => false, 'def' => true],
        ['key' => 'billing_portal', 'name' => 'Billing portal refresh', 'on' => false, 'rollout' => 0, 'group' => 'internal_testers', 'fallback' => 'false', 'updated' => '1 tuần trước', 'kill' => false, 'def' => false],
    ];
}

function demo_ensure_storage(): void
{
    $dir = demo_data_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Không thể tạo thư mục dữ liệu demo.');
    }

    $userPath = $dir . DIRECTORY_SEPARATOR . 'users.json';
    if (!is_file($userPath)) {
        $username = getenv('DEMO_APP_USERNAME') ?: 'admin';
        $password = getenv('DEMO_APP_PASSWORD') ?: 'FeatureLab2026!';
        demo_json_write('users.json', [[
            'username' => $username,
            'display_name' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'admin',
            'permissions' => ['*'],
            'created_at' => date(DATE_ATOM),
        ]]);
    }

    if (!is_file($dir . DIRECTORY_SEPARATOR . 'features.json')) {
        demo_json_write('features.json', demo_default_features());
    }
}

function demo_json_read(string $filename, array $default = []): array
{
    demo_ensure_data_dir();
    $path = demo_data_dir() . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        demo_json_write($filename, $default);
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return $default;
    }
    flock($handle, LOCK_SH);
    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    $data = json_decode(is_string($contents) ? $contents : '', true);
    return is_array($data) ? $data : $default;
}

function demo_json_write(string $filename, array $data): void
{
    demo_ensure_data_dir();
    $path = demo_data_dir() . DIRECTORY_SEPARATOR . $filename;
    $handle = fopen($path, 'c+b');
    if ($handle === false) {
        throw new RuntimeException('Không thể ghi dữ liệu demo.');
    }
    flock($handle, LOCK_EX);
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function demo_ensure_data_dir(): void
{
    $dir = demo_data_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Không thể tạo thư mục dữ liệu demo.');
    }
}

function demo_all_users(): array
{
    demo_ensure_storage();
    return demo_json_read('users.json', []);
}

function demo_find_user(string $username): ?array
{
    foreach (demo_all_users() as $user) {
        if (isset($user['username']) && hash_equals((string) $user['username'], $username)) {
            return $user;
        }
    }
    return null;
}

function demo_authenticate_user(string $username, string $password): ?array
{
    $user = demo_find_user($username);
    if ($user === null || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    return $user;
}

function demo_current_user(): ?array
{
    $username = $_SESSION['demo_user'] ?? null;
    return is_string($username) && $username !== '' ? demo_find_user($username) : null;
}

function demo_all_features(): array
{
    demo_ensure_storage();
    return demo_json_read('features.json', demo_default_features());
}

function demo_user_can_toggle(array $user, string $flagKey): bool
{
    return ($user['role'] ?? '') === 'admin'
        || in_array('*', $user['permissions'] ?? [], true)
        || in_array($flagKey, $user['permissions'] ?? [], true);
}

function demo_user_can_view_audit(array $user): bool
{
    return ($user['role'] ?? '') === 'admin' || !empty($user['can_view_audit']);
}

function demo_append_audit(array $event): bool
{
    demo_ensure_data_dir();
    $path = demo_data_dir() . DIRECTORY_SEPARATOR . 'audit.jsonl';
    $handle = fopen($path, 'ab');
    if ($handle === false) {
        return false;
    }
    $record = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($record) || !flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
    }
    $written = fwrite($handle, $record . PHP_EOL);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $written !== false;
}

function demo_read_audit(): array
{
    demo_ensure_data_dir();
    $path = demo_data_dir() . DIRECTORY_SEPARATOR . 'audit.jsonl';
    if (!is_file($path)) {
        return [];
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }
    flock($handle, LOCK_SH);
    $events = [];
    while (($line = fgets($handle)) !== false) {
        $event = json_decode($line, true);
        if (is_array($event)) {
            $events[] = $event;
        }
    }
    flock($handle, LOCK_UN);
    fclose($handle);
    return array_reverse($events);
}
