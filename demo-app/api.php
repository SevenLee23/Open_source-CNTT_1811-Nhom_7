<?php
declare(strict_types=1);
require_once __DIR__ . '/storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function demo_api_reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$user = demo_current_user();
if ($user === null) {
    demo_api_reply(401, ['ok' => false, 'message' => 'Vui lòng đăng nhập lại.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = [];
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $decoded = json_decode(is_string($rawInput) ? $rawInput : '', true);
    $input = is_array($decoded) ? $decoded : $_POST;
    if (!demo_csrf_is_valid($input['csrf_token'] ?? null)) {
        demo_api_reply(419, ['ok' => false, 'message' => 'Phiên làm việc hết hạn. Tải lại trang rồi thử lại.']);
    }
}

$action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';

if ($method === 'GET' && $action === 'features') {
    demo_api_reply(200, ['ok' => true, 'features' => demo_all_features()]);
}

if ($method === 'GET' && $action === 'users') {
    if (($user['role'] ?? '') !== 'admin') {
        demo_api_reply(403, ['ok' => false, 'message' => 'Chỉ Admin mới có quyền quản lý tài khoản.']);
    }
    $safeUsers = array_map(static function (array $account): array {
        unset($account['password_hash']);
        return $account;
    }, demo_all_users());
    demo_api_reply(200, ['ok' => true, 'users' => $safeUsers]);
}

if ($method === 'GET' && $action === 'audit') {
    if (!demo_user_can_view_audit($user)) {
        demo_api_reply(403, ['ok' => false, 'message' => 'Admin chưa cấp quyền xem nhật ký cho tài khoản này.']);
    }
    demo_api_reply(200, ['ok' => true, 'events' => demo_read_audit()]);
}

if ($method !== 'POST') {
    demo_api_reply(405, ['ok' => false, 'message' => 'Phương thức không được hỗ trợ.']);
}

if ($action === 'toggle_feature') {
    $key = is_string($input['key'] ?? null) ? trim($input['key']) : '';
    $enabled = filter_var($input['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($key === '' || $enabled === null) {
        demo_api_reply(422, ['ok' => false, 'message' => 'Dữ liệu bật/tắt không hợp lệ.']);
    }
    if (!demo_user_can_toggle($user, $key)) {
        demo_api_reply(403, ['ok' => false, 'message' => 'Tài khoản của bạn không có quyền thay đổi feature này.']);
    }

    $features = demo_all_features();
    $previousFeatures = $features;
    $previousValue = null;
    $found = false;
    foreach ($features as &$feature) {
        if (($feature['key'] ?? '') === $key) {
            $previousValue = (bool) ($feature['on'] ?? false);
            $feature['on'] = $enabled;
            $feature['rollout'] = $enabled ? max(10, (int) ($feature['rollout'] ?? 0)) : 0;
            $feature['updated'] = 'Vừa cập nhật';
            $found = true;
            break;
        }
    }
    unset($feature);
    if (!$found) {
        demo_api_reply(404, ['ok' => false, 'message' => 'Không tìm thấy feature này.']);
    }
    demo_json_write('features.json', $features);
    if ($previousValue !== $enabled && !demo_append_audit([
        'event_id' => bin2hex(random_bytes(12)),
        'action' => $enabled ? 'feature.enabled' : 'feature.disabled',
        'actor_username' => (string) $user['username'],
        'actor_name' => (string) ($user['display_name'] ?? $user['username']),
        'actor_role' => (string) ($user['role'] ?? 'viewer'),
        'feature_key' => $key,
        'previous_value' => $previousValue,
        'new_value' => $enabled,
        'reason' => 'Thay đổi trạng thái qua FeatureLab',
        'occurred_at' => date(DATE_ATOM),
    ])) {
        demo_json_write('features.json', $previousFeatures);
        demo_api_reply(500, ['ok' => false, 'message' => 'Không lưu được nhật ký. Thay đổi feature đã được huỷ.']);
    }
    demo_api_reply(200, ['ok' => true, 'features' => $features]);
}

if ($action === 'create_user') {
    if (($user['role'] ?? '') !== 'admin') {
        demo_api_reply(403, ['ok' => false, 'message' => 'Chỉ Admin mới có quyền tạo tài khoản.']);
    }
    $username = is_string($input['username'] ?? null) ? trim($input['username']) : '';
    $displayName = is_string($input['display_name'] ?? null) ? trim($input['display_name']) : '';
    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
    $role = is_string($input['role'] ?? null) ? $input['role'] : 'viewer';
    $permissions = is_array($input['permissions'] ?? null) ? $input['permissions'] : [];
    $canViewAudit = filter_var($input['can_view_audit'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
        demo_api_reply(422, ['ok' => false, 'message' => 'Tên đăng nhập cần dài 3–32 ký tự, chỉ gồm chữ, số, dấu chấm, gạch ngang hoặc gạch dưới.']);
    }
    if (strlen($password) < 8) {
        demo_api_reply(422, ['ok' => false, 'message' => 'Mật khẩu cần có ít nhất 8 ký tự.']);
    }
    if (!in_array($role, ['editor', 'viewer'], true)) {
        demo_api_reply(422, ['ok' => false, 'message' => 'Vai trò chỉ có thể là Editor hoặc Viewer.']);
    }

    $validKeys = array_column(demo_all_features(), 'key');
    $permissions = array_values(array_unique(array_filter($permissions, static fn($permission): bool => is_string($permission) && in_array($permission, $validKeys, true))));
    if ($role === 'viewer') {
        $permissions = [];
    }
    $accounts = demo_all_users();
    foreach ($accounts as $existing) {
        if (strtolower((string) ($existing['username'] ?? '')) === strtolower($username)) {
            demo_api_reply(409, ['ok' => false, 'message' => 'Tên đăng nhập này đã tồn tại.']);
        }
    }

    $accounts[] = [
        'username' => $username,
        'display_name' => $displayName !== '' ? $displayName : $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'permissions' => $permissions,
        'can_view_audit' => $canViewAudit,
        'created_at' => date(DATE_ATOM),
    ];
    demo_json_write('users.json', $accounts);
    demo_append_audit([
        'event_id' => bin2hex(random_bytes(12)),
        'action' => 'account.created',
        'actor_username' => (string) $user['username'],
        'actor_name' => (string) ($user['display_name'] ?? $user['username']),
        'actor_role' => 'admin',
        'target_username' => $username,
        'reason' => 'Tạo tài khoản ' . strtoupper($role),
        'occurred_at' => date(DATE_ATOM),
    ]);
    demo_api_reply(201, ['ok' => true, 'message' => 'Đã tạo tài khoản ' . $username . '.', 'user' => [
        'username' => $username,
        'display_name' => $displayName !== '' ? $displayName : $username,
        'role' => $role,
        'permissions' => $permissions,
        'can_view_audit' => $canViewAudit,
        'created_at' => date(DATE_ATOM),
    ]]);
}

if ($action === 'set_audit_access') {
    if (($user['role'] ?? '') !== 'admin') {
        demo_api_reply(403, ['ok' => false, 'message' => 'Chỉ Admin mới có quyền phân quyền xem nhật ký.']);
    }
    $targetUsername = is_string($input['username'] ?? null) ? trim($input['username']) : '';
    $canView = filter_var($input['can_view_audit'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($targetUsername === '' || $canView === null) {
        demo_api_reply(422, ['ok' => false, 'message' => 'Thông tin phân quyền không hợp lệ.']);
    }
    $accounts = demo_all_users();
    $found = false;
    foreach ($accounts as &$account) {
        if (($account['username'] ?? '') === $targetUsername) {
            if (($account['role'] ?? '') === 'admin') {
                demo_api_reply(422, ['ok' => false, 'message' => 'Tài khoản Admin luôn có quyền xem nhật ký.']);
            }
            $oldValue = !empty($account['can_view_audit']);
            $account['can_view_audit'] = $canView;
            $found = true;
            break;
        }
    }
    unset($account);
    if (!$found) {
        demo_api_reply(404, ['ok' => false, 'message' => 'Không tìm thấy tài khoản.']);
    }
    demo_json_write('users.json', $accounts);
    if ($oldValue !== $canView) {
        demo_append_audit([
            'event_id' => bin2hex(random_bytes(12)),
            'action' => $canView ? 'audit.access_granted' : 'audit.access_revoked',
            'actor_username' => (string) $user['username'],
            'actor_name' => (string) ($user['display_name'] ?? $user['username']),
            'actor_role' => 'admin',
            'target_username' => $targetUsername,
            'reason' => $canView ? 'Cấp quyền xem nhật ký' : 'Thu hồi quyền xem nhật ký',
            'occurred_at' => date(DATE_ATOM),
        ]);
    }
    demo_api_reply(200, ['ok' => true, 'message' => $canView ? 'Đã cấp quyền xem nhật ký.' : 'Đã thu hồi quyền xem nhật ký.']);
}

demo_api_reply(404, ['ok' => false, 'message' => 'Không tìm thấy thao tác yêu cầu.']);
