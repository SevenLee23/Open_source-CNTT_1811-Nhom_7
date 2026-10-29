<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Auth;
use App\Support\Database;

final class SubaccountController extends Controller
{
    public function index()
    {
        $owner = $this->owner();
        $projectId = $this->projectId();
        $this->requireOwnedProject($owner, $projectId);
        if (!Auth::isAdmin($projectId)) { http_response_code(403); exit('Chủ tài khoản cần quyền quản trị workspace.'); }
        $query = Database::connection()->prepare("SELECT id,name,username,email,created_at FROM users WHERE parent_user_id=? AND account_type='child' AND is_active=1 ORDER BY id DESC");
        $query->execute([(int) $owner['id']]);
        $subaccounts = $query->fetchAll();
        $projectQuery = Database::connection()->prepare('SELECT id,name,`key` FROM projects WHERE created_by=? AND is_active=1 ORDER BY name');
        $projectQuery->execute([(int) $owner['id']]);
        $projects = $projectQuery->fetchAll();
        $projectAssignments = [];
        if ($subaccounts) {
            $childIds = array_map('intval', array_column($subaccounts, 'id'));
            $placeholders = implode(',', array_fill(0, count($childIds), '?'));
            $assignmentQuery = Database::connection()->prepare("SELECT m.user_id,m.project_id FROM project_memberships m JOIN projects p ON p.id=m.project_id WHERE p.created_by=? AND p.is_active=1 AND m.user_id IN ($placeholders)");
            $assignmentQuery->execute(array_merge([(int) $owner['id']], $childIds));
            foreach ($assignmentQuery->fetchAll() as $assignment) $projectAssignments[(int) $assignment['user_id']][] = (int) $assignment['project_id'];
        }
        $featureQuery = Database::connection()->prepare('SELECT f.id,f.`key`,f.name FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.is_active=1 ORDER BY f.name');
        $featureQuery->execute([$projectId]);
        $features = $featureQuery->fetchAll();
        $permissionQuery = Database::connection()->prepare('SELECT m.user_id,p.flag_id FROM project_memberships m JOIN subaccount_flag_permissions p ON p.membership_id=m.id WHERE m.project_id=? AND p.can_toggle=1');
        $permissionQuery->execute([$projectId]);
        $permissions = [];
        foreach ($permissionQuery->fetchAll() as $row) $permissions[(int) $row['user_id']][] = (int) $row['flag_id'];
        $this->render('subaccounts', compact('owner','projectId','subaccounts','features','permissions','projects','projectAssignments'));
    }

    public function create()
    {
        $owner = $this->owner();
        $projectId = $this->projectId();
        $this->requireOwnedProject($owner, $projectId);
        if (!Auth::isAdmin($projectId)) { http_response_code(403); exit('Chủ tài khoản cần quyền quản trị workspace.'); }
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($name === '' || strlen($name) > 120 || !preg_match('/^[a-z0-9][a-z0-9_.-]{2,79}$/', $username) || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || strlen($password) < 8) {
            flash('error', 'Nhập tên, username hợp lệ (3–80 ký tự), email hợp lệ nếu có và mật khẩu tối thiểu 8 ký tự.');
            redirect('subaccounts');
        }
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $childId = User::create($name, $email, $password, 'child', (int) $owner['id'], $username);
            $membershipInsert = $pdo->prepare("INSERT INTO project_memberships (project_id,user_id,role,can_view_audit,created_at,updated_at) VALUES (?,?,'child',0,NOW(),NOW())");
            $ownerProjects = $pdo->prepare('SELECT id FROM projects WHERE created_by=? AND is_active=1');
            $ownerProjects->execute([(int) $owner['id']]);
            $ownedProjectIds = array_map('intval', $ownerProjects->fetchAll(\PDO::FETCH_COLUMN));
            $requestedProjectIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['project_ids'] ?? [])))));
            $selectedProjectIds = array_values(array_intersect($requestedProjectIds, $ownedProjectIds));
            if (!$selectedProjectIds) throw new \DomainException('Chọn ít nhất một dự án cho tài khoản con.');
            foreach ($selectedProjectIds as $selectedProjectId) $membershipInsert->execute([$selectedProjectId, $childId]);
            (new AuditLogService())->record($projectId, (int) $owner['id'], 'subaccount.created', 'subaccount', $username, null, ['user_id' => $childId, 'email' => $email ?: null], 'Tạo tài khoản con');
            $pdo->commit();
            flash('success', 'Đã tạo tài khoản con. Tài khoản này chỉ được quản lý bật/tắt feature.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $exception instanceof \DomainException ? $exception->getMessage() : 'Không tạo được tài khoản con. Username hoặc email có thể đã được sử dụng.');
        }
        redirect('subaccounts');
    }

    public function saveEmail()
    {
        $owner = $this->owner();
        $projectId = $this->projectId();
        $this->requireOwnedProject($owner, $projectId);
        if (!Auth::isAdmin($projectId)) { http_response_code(403); exit('Chủ tài khoản cần quyền quản trị workspace.'); }
        $childId = (int) ($_POST['child_id'] ?? 0);
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('error', 'Email không hợp lệ.'); redirect('subaccounts'); }
        $pdo = Database::connection();
        $query = $pdo->prepare("SELECT id,username,email FROM users WHERE id=? AND parent_user_id=? AND account_type='child' AND is_active=1");
        $query->execute([$childId, (int) $owner['id']]);
        $child = $query->fetch();
        if (!$child) { flash('error', 'Không tìm thấy tài khoản con.'); redirect('subaccounts'); }
        try {
            $pdo->prepare('UPDATE users SET email=?,updated_at=NOW() WHERE id=?')->execute([$email !== '' ? $email : null, $childId]);
            (new AuditLogService())->record($projectId, (int) $owner['id'], 'subaccount.email_updated', 'subaccount', $child['username'], ['email' => $child['email']], ['email' => $email ?: null], 'Cập nhật email tài khoản con');
            flash('success', $email !== '' ? 'Đã lưu email cho tài khoản con.' : 'Đã gỡ email khỏi tài khoản con. Username vẫn dùng để đăng nhập.');
        } catch (\Throwable $exception) { flash('error', 'Email này có thể đã được dùng bởi tài khoản khác.'); }
        redirect('subaccounts');
    }

    public function savePermissions()
    {
        $owner = $this->owner();
        $projectId = $this->projectId();
        $this->requireOwnedProject($owner, $projectId);
        if (!Auth::isAdmin($projectId)) { http_response_code(403); exit('Chủ tài khoản cần quyền quản trị workspace.'); }
        $childId = (int) ($_POST['child_id'] ?? 0);
        $child = Database::connection()->prepare("SELECT id,username,email FROM users WHERE id=? AND parent_user_id=? AND account_type='child' AND is_active=1");
        $child->execute([$childId, (int) $owner['id']]);
        $childRow = $child->fetch();
        if (!$childRow) { flash('error', 'Không tìm thấy tài khoản con đang hoạt động.'); redirect('subaccounts'); }
        $membership = Database::connection()->prepare('SELECT id FROM project_memberships WHERE project_id=? AND user_id=?');
        $membership->execute([$projectId, $childId]);
        $membershipId = (int) $membership->fetchColumn();
        if (!$membershipId) { flash('error', 'Tài khoản con chưa được thêm vào dự án.'); redirect('subaccounts'); }
        $requested = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['flag_ids'] ?? [])))));
        $allowed = [];
        if ($requested) {
            $placeholders = implode(',', array_fill(0, count($requested), '?'));
            $valid = Database::connection()->prepare("SELECT f.id FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.is_active=1 AND f.id IN ($placeholders)");
            $valid->execute(array_merge([$projectId], $requested));
            $allowed = array_map('intval', $valid->fetchAll(\PDO::FETCH_COLUMN));
        }
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM subaccount_flag_permissions WHERE membership_id=?')->execute([$membershipId]);
            $grant = $pdo->prepare('INSERT INTO subaccount_flag_permissions (membership_id,flag_id,can_toggle) VALUES (?,?,1)');
            foreach ($allowed as $flagId) $grant->execute([$membershipId, $flagId]);
            (new AuditLogService())->record($projectId, (int) $owner['id'], 'subaccount.permissions_updated', 'subaccount', $childRow['username'], null, ['toggle_flag_ids' => $allowed], 'Cập nhật quyền bật/tắt tính năng');
            $pdo->commit();
            flash('success', 'Đã lưu quyền bật/tắt tính năng cho ' . $childRow['username'] . '.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không lưu được quyền tính năng.');
        }
        redirect('subaccounts');
    }

    public function saveProjects()
    {
        $owner = $this->owner();
        $projectId = $this->projectId();
        $this->requireOwnedProject($owner, $projectId);
        if (!Auth::isAdmin($projectId)) { http_response_code(403); exit('Chủ tài khoản cần quyền quản trị workspace.'); }
        $childId = (int) ($_POST['child_id'] ?? 0);
        $childQuery = Database::connection()->prepare("SELECT id,username,email FROM users WHERE id=? AND parent_user_id=? AND account_type='child' AND is_active=1");
        $childQuery->execute([$childId, (int) $owner['id']]);
        $child = $childQuery->fetch();
        if (!$child) { flash('error', 'Không tìm thấy tài khoản con đang hoạt động.'); redirect('subaccounts'); }
        $ownedQuery = Database::connection()->prepare('SELECT id FROM projects WHERE created_by=? AND is_active=1');
        $ownedQuery->execute([(int) $owner['id']]);
        $ownedIds = array_map('intval', $ownedQuery->fetchAll(\PDO::FETCH_COLUMN));
        $requestedIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['project_ids'] ?? [])))));
        $selectedIds = array_values(array_intersect($requestedIds, $ownedIds));
        if (!$selectedIds) { flash('error', 'Tài khoản con phải được gán ít nhất một dự án.'); redirect('subaccounts'); }
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $currentQuery = $pdo->prepare('SELECT m.id,m.project_id FROM project_memberships m JOIN projects p ON p.id=m.project_id WHERE m.user_id=? AND p.created_by=?');
            $currentQuery->execute([$childId, (int) $owner['id']]);
            $current = $currentQuery->fetchAll();
            $currentMemberships = [];
            foreach ($current as $row) $currentMemberships[(int) $row['project_id']] = (int) $row['id'];
            foreach ($currentMemberships as $assignedProjectId => $membershipId) {
                if (!in_array($assignedProjectId, $selectedIds, true)) $pdo->prepare('DELETE FROM project_memberships WHERE id=?')->execute([$membershipId]);
            }
            $insert = $pdo->prepare("INSERT INTO project_memberships (project_id,user_id,role,can_view_audit,created_at,updated_at) VALUES (?,?,'child',0,NOW(),NOW())");
            foreach ($selectedIds as $selectedProjectId) if (!isset($currentMemberships[$selectedProjectId])) $insert->execute([$selectedProjectId, $childId]);
            (new AuditLogService())->record($projectId, (int) $owner['id'], 'subaccount.projects_updated', 'subaccount', $child['username'], ['project_ids' => array_keys($currentMemberships)], ['project_ids' => $selectedIds], 'Cập nhật dự án được cấp cho tài khoản con');
            $pdo->commit();
            flash('success', 'Đã cập nhật các dự án của ' . $child['email'] . '.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không cập nhật được dự án của tài khoản con.');
        }
        redirect('subaccounts');
    }

    public function delete()
    {
        $owner = $this->owner();
        $projectId = $this->projectId();
        $this->requireOwnedProject($owner, $projectId);
        if (!Auth::isAdmin($projectId)) { http_response_code(403); exit('Chủ tài khoản cần quyền quản trị workspace.'); }
        $childId = (int) ($_POST['child_id'] ?? 0);
        $pdo = Database::connection();
        $query = $pdo->prepare("SELECT id,name,username,email FROM users WHERE id=? AND parent_user_id=? AND account_type='child' AND is_active=1");
        $query->execute([$childId, (int) $owner['id']]);
        $child = $query->fetch();
        if (!$child) {
            flash('error', 'Không tìm thấy tài khoản con đang hoạt động.');
            redirect('subaccounts');
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET is_active=0,updated_at=NOW() WHERE id=? AND parent_user_id=?')->execute([$childId, (int) $owner['id']]);
            $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE account_user_id=? AND revoked_at IS NULL')->execute([$childId]);
            (new AuditLogService())->record($projectId, (int) $owner['id'], 'subaccount.deleted', 'subaccount', $child['username'], ['active' => true], ['active' => false], 'Thu hồi quyền truy cập tài khoản con');
            $pdo->commit();
            flash('success', 'Đã xóa quyền truy cập của ' . $child['email'] . '. Tài khoản không thể đăng nhập nữa.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không xóa được tài khoản con.');
        }
        redirect('subaccounts');
    }

    private function owner()
    {
        $user = $this->user();
        if ($user['account_type'] !== 'owner') { http_response_code(403); exit('Chỉ chủ tài khoản mới quản lý tài khoản con.'); }
        return $user;
    }

    private function requireOwnedProject(array $owner, $projectId)
    {
        $query = Database::connection()->prepare('SELECT id FROM projects WHERE id=? AND created_by=? AND is_active=1');
        $query->execute([(int) $projectId, (int) $owner['id']]);
        if (!$query->fetchColumn()) { http_response_code(403); exit('Tài khoản con chỉ được quản lý trong dự án thuộc tài khoản cha.'); }
    }
}
