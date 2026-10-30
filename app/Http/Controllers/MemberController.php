<?php
namespace App\Http\Controllers;

use App\Models\Flag;
use App\Models\ProjectMembership;
use App\Models\Project;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Auth;
use App\Support\Database;

final class MemberController extends Controller
{
    public function index()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireAdmin($projectId);
        $memberProjects = array_values(array_filter(Project::forUser((int) $user['id']), static function ($project) { return $project['role'] === 'admin'; }));
        $members = ProjectMembership::members($projectId);
        $flags = Flag::forProject($projectId);
        $permissions = $membersByUserId = [];
        foreach ($members as $member) {
            $membersByUserId[(int) $member['user_id']] = $member;
            $query = Database::connection()->prepare('SELECT flag_id FROM membership_flag_permissions WHERE membership_id=? AND can_edit=1');
            $query->execute([(int) $member['id']]);
            $permissions[$member['id']] = array_map('intval', array_column($query->fetchAll(), 'flag_id'));
        }
        $subaccounts = $projects = $projectAssignments = $subaccountPermissions = $workspaceMembers = [];
        $managedProjectIds = array_map('intval', array_column($memberProjects, 'id'));
        if ($managedProjectIds) {
            $placeholders = implode(',', array_fill(0, count($managedProjectIds), '?'));
            $workspaceQuery = Database::connection()->prepare("SELECT DISTINCT u.id,u.name,u.username,u.email,u.account_type FROM users u JOIN project_memberships m ON m.user_id=u.id JOIN projects p ON p.id=m.project_id WHERE p.id IN ($placeholders) AND u.is_active=1 ORDER BY u.name,u.id");
            $workspaceQuery->execute($managedProjectIds);
            $workspaceMembers = $workspaceQuery->fetchAll();
            if ($workspaceMembers) {
                $ids = array_map('intval', array_column($workspaceMembers, 'id'));
                $userPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                $assignmentQuery = Database::connection()->prepare("SELECT m.user_id,m.project_id,m.role FROM project_memberships m WHERE m.project_id IN ($placeholders) AND m.user_id IN ($userPlaceholders)");
                $assignmentQuery->execute(array_merge($managedProjectIds, $ids));
                foreach ($assignmentQuery->fetchAll() as $assignment) {
                    $projectAssignments[(int) $assignment['user_id']][(int) $assignment['project_id']] = $assignment['role'];
                }
            }
        }
        if (($user['account_type'] ?? '') === 'owner') {
            $ownedProject = Database::connection()->prepare('SELECT id FROM projects WHERE id=? AND created_by=? AND is_active=1');
            $ownedProject->execute([$projectId, (int) $user['id']]);
            if ($ownedProject->fetchColumn()) {
                $query = Database::connection()->prepare("SELECT id,name,username,email,created_at FROM users WHERE parent_user_id=? AND account_type='child' AND is_active=1 ORDER BY id DESC");
                $query->execute([(int) $user['id']]);
                $subaccounts = $query->fetchAll();
                $projectQuery = Database::connection()->prepare('SELECT id,name,`key` FROM projects WHERE created_by=? AND is_active=1 ORDER BY name');
                $projectQuery->execute([(int) $user['id']]);
                $projects = $projectQuery->fetchAll();

                $permissionQuery = Database::connection()->prepare('SELECT m.user_id,m.project_id,p.flag_id FROM project_memberships m JOIN subaccount_flag_permissions p ON p.membership_id=m.id JOIN projects pjt ON pjt.id=m.project_id WHERE pjt.created_by=? AND p.can_toggle=1');
                $permissionQuery->execute([(int) $user['id']]);
                foreach ($permissionQuery->fetchAll() as $row) $subaccountPermissions[(int) $row['user_id']][(int) $row['project_id']][] = (int) $row['flag_id'];
            }
        }
        $managedFeatures = [];
        if ($projects) {
            $ids = array_map('intval', array_column($projects, 'id'));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $featureQuery = Database::connection()->prepare("SELECT f.id,f.project_id,f.`key`,f.name FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id IN ($placeholders) AND f.is_active=1 ORDER BY f.name");
            $featureQuery->execute($ids);
            foreach ($featureQuery->fetchAll() as $feature) $managedFeatures[(int) $feature['project_id']][] = $feature;
        }
        $this->render('members', compact('user','projectId','memberProjects','members','membersByUserId','flags','permissions','subaccounts','projects','projectAssignments','subaccountPermissions','managedFeatures','workspaceMembers'));
    }

    public function updateProjects()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireAdmin($projectId);
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $requestedIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['project_ids'] ?? [])))));
        $pdo = Database::connection();
        $adminProjects = $pdo->prepare("SELECT p.id FROM projects p JOIN project_memberships m ON m.project_id=p.id WHERE m.user_id=? AND m.role='admin' AND p.is_active=1");
        $adminProjects->execute([(int) $user['id']]);
        $allowedIds = array_map('intval', $adminProjects->fetchAll(\PDO::FETCH_COLUMN));
        $targetUser = $pdo->prepare('SELECT id,username,account_type,parent_user_id FROM users WHERE id=? AND is_active=1');
        $targetUser->execute([$memberId]);
        $member = $targetUser->fetch();
        if (!$member || $memberId === (int) $user['id'] || array_diff($requestedIds, $allowedIds)) {
            flash('error', 'Không thể cập nhật quyền dự án cho tài khoản này.');
            redirect('members');
        }
        if (!$requestedIds) { flash('error', 'Hãy giữ lại ít nhất một dự án cho thành viên.'); redirect('members'); }
        $scope = $pdo->prepare('SELECT project_id,role FROM project_memberships WHERE user_id=?');
        $scope->execute([$memberId]);
        $current = [];
        foreach ($scope->fetchAll() as $row) $current[(int) $row['project_id']] = $row['role'];
        if (!array_intersect(array_keys($current), $allowedIds)) { flash('error', 'Tài khoản này không thuộc các dự án bạn quản lý.'); redirect('members'); }
        try {
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO project_memberships (project_id,user_id,role,can_view_audit,created_at,updated_at) VALUES (?,?,?,0,NOW(),NOW())');
            foreach ($requestedIds as $targetProjectId) {
                if (!isset($current[$targetProjectId])) {
                    $role = ($member['account_type'] ?? '') === 'child' && (int) $member['parent_user_id'] === (int) $user['id'] ? 'child' : 'viewer';
                    $insert->execute([$targetProjectId, $memberId, $role]);
                }
            }
            $delete = $pdo->prepare('DELETE FROM project_memberships WHERE user_id=? AND project_id=? AND role<>\'admin\'');
            foreach ($allowedIds as $allowedId) {
                if (!in_array($allowedId, $requestedIds, true)) $delete->execute([$memberId, $allowedId]);
            }
            $pdo->commit();
            (new AuditLogService())->record($projectId, $user['id'], 'member.projects_updated', 'member', (string) $memberId, ['project_ids' => array_keys(array_intersect_key($current, array_flip($allowedIds)))], ['project_ids' => $requestedIds], 'Cập nhật dự án được cấp cho thành viên');
            flash('success', 'Đã cập nhật danh sách dự án của thành viên.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không thể cập nhật dự án: ' . $exception->getMessage());
        }
        redirect('members');
    }

    public function updateAuditPermission()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireAdmin($projectId);
        $membershipId = (int) ($_POST['membership_id'] ?? 0);
        $membershipQuery = Database::connection()->prepare('SELECT id,user_id,role,can_view_audit FROM project_memberships WHERE id=? AND project_id=?');
        $membershipQuery->execute([$membershipId, $projectId]);
        $membership = $membershipQuery->fetch();
        if (!$membership) {
            flash('error', 'Không tìm thấy thành viên trong dự án này.');
            redirect('members');
        }
        if ((int) $membership['user_id'] === (int) $user['id'] || $membership['role'] === 'admin') {
            flash('error', 'Quyền xem Log của quản trị viên không thể thay đổi tại đây.');
            redirect('members');
        }
        $allowed = (int) $membership['can_view_audit'] ? 0 : 1;
        Database::connection()->prepare('UPDATE project_memberships SET can_view_audit=?,updated_at=NOW() WHERE id=?')->execute([$allowed, $membershipId]);
        (new AuditLogService())->record($projectId, $user['id'], 'member.audit_permission_updated', 'member', (string) $membership['user_id'], ['can_view_audit' => (bool) $membership['can_view_audit']], ['can_view_audit' => (bool) $allowed], $allowed ? 'Cho phép thành viên xem Log' : 'Thu hồi quyền xem Log');
        flash('success', $allowed ? 'Đã cho phép thành viên xem Log.' : 'Đã thu hồi quyền xem Log của thành viên.');
        redirect('members');
    }

    public function store()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireAdmin($projectId);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $requestedIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['project_ids'] ?? [])))));
        if ($requestedIds) {
            if (($user['account_type'] ?? '') !== 'owner') { http_response_code(403); exit('Chỉ chủ dự án mới có thể tạo tài khoản theo dự án.'); }
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $managedRole = (string) ($_POST['managed_role'] ?? 'viewer');
            if (!in_array($managedRole, ['viewer','editor'], true)) { flash('error', 'Vai trò không hợp lệ.'); redirect('members'); }
            if ($name === '' || strlen($name) > 120 || !preg_match('/^[a-z0-9][a-z0-9_.-]{2,79}$/', $username) || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || strlen($password) < 8) {
                flash('error', 'Nhập tên, tên đăng nhập hợp lệ, mật khẩu tối thiểu 8 ký tự và email hợp lệ nếu có.');
                redirect('members');
            }
            $pdo = Database::connection();
            $ownedProjects = $pdo->prepare('SELECT id FROM projects WHERE created_by=? AND is_active=1');
            $ownedProjects->execute([(int) $user['id']]);
            $ownedIds = array_map('intval', $ownedProjects->fetchAll(\PDO::FETCH_COLUMN));
            $selectedIds = array_values(array_intersect($requestedIds, $ownedIds));
            if (!$selectedIds) { flash('error', 'Chọn ít nhất một dự án do bạn sở hữu.'); redirect('members'); }
            try {
                $pdo->beginTransaction();
                $newUserId = User::create($name, $email, $password, 'child', (int) $user['id'], $username);
                $canViewAudit = isset($_POST['can_view_audit']) ? 1 : 0;
                $membership = $pdo->prepare("INSERT INTO project_memberships (project_id,user_id,role,can_view_audit,created_at,updated_at) VALUES (?,?,'child',?,NOW(),NOW())");
                $grants = (array) ($_POST['project_flag_ids'] ?? []);
                foreach ($selectedIds as $selectedId) {
                    $membership->execute([$selectedId, $newUserId, $canViewAudit]);
                    $membershipId = (int) $pdo->lastInsertId();
                    if ($managedRole !== 'editor') continue;
                    $requestedFlags = array_values(array_unique(array_filter(array_map('intval', (array) ($grants[$selectedId] ?? [])))));
                    if (!$requestedFlags) continue;
                    $flagPlaceholders = implode(',', array_fill(0, count($requestedFlags), '?'));
                    $validFlags = $pdo->prepare("SELECT id FROM flags WHERE project_id=? AND is_active=1 AND id IN ($flagPlaceholders)");
                    $validFlags->execute(array_merge([$selectedId], $requestedFlags));
                    $grant = $pdo->prepare('INSERT INTO subaccount_flag_permissions (membership_id,flag_id,can_toggle) VALUES (?,?,1)');
                    foreach ($validFlags->fetchAll(\PDO::FETCH_COLUMN) as $flagId) $grant->execute([$membershipId, (int) $flagId]);
                }
                $pdo->commit();
                (new AuditLogService())->record($projectId, $user['id'], 'subaccount.created', 'subaccount', $username, null, ['user_id' => $newUserId, 'project_ids' => $selectedIds, 'role' => $managedRole, 'can_view_audit' => (bool) $canViewAudit, 'email' => $email ?: null], 'Tạo tài khoản và cấp quyền dự án');
                flash('success', 'Đã tạo tài khoản và cấp quyền vào các dự án đã chọn.');
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', 'Không tạo được tài khoản. Tên đăng nhập hoặc email có thể đã được sử dụng.');
            }
            redirect('members');
        }
        $username = strtolower(trim((string) ($_POST['username'] ?? '')));
        if ($username !== '' && !preg_match('/^[a-z0-9][a-z0-9_.-]{2,79}$/', $username)) { flash('error', 'Tên đăng nhập không hợp lệ.'); redirect('members'); }
        $role = (string) ($_POST['role'] ?? 'viewer');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($role, ['editor','viewer'], true)) {
            flash('error', 'Kiểm tra tên, email, mật khẩu (tối thiểu 8 ký tự) và vai trò.');
            redirect('members');
        }
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $newUserId = User::create($name, $email, $password, 'member');
            $canViewAudit = isset($_POST['can_view_audit']) ? 1 : 0;
            $pdo->prepare('INSERT INTO project_memberships (project_id,user_id,role,can_view_audit,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())')
                ->execute([$projectId, $newUserId, $role, $canViewAudit]);
            $membershipId = (int) $pdo->lastInsertId();
            $selected = array_map('intval', (array) ($_POST['flag_ids'] ?? []));
            $permission = $pdo->prepare('INSERT INTO membership_flag_permissions (membership_id,flag_id,can_edit) SELECT ?,f.id,1 FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.id=? AND f.is_active=1');
            if ($role === 'editor') foreach ($selected as $flagId) $permission->execute([$membershipId, $projectId, $flagId]);
            $pdo->commit();
            (new AuditLogService())->record($projectId, $user['id'], 'member.created', 'member', $email, null, ['role' => $role, 'can_view_audit' => (bool) $canViewAudit, 'flag_ids' => $selected], 'Thêm thành viên');
            flash('success', 'Đã tạo tài khoản và gán quyền trong dự án.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không tạo được tài khoản: ' . $exception->getMessage());
        }
        redirect('members');
    }

    public function updatePermissions()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireAdmin($projectId);
        $membershipId = (int) ($_POST['membership_id'] ?? 0);
        $query = Database::connection()->prepare('SELECT * FROM project_memberships WHERE id=? AND project_id=?');
        $query->execute([$membershipId, $projectId]);
        $membership = $query->fetch();
        if (!$membership) { flash('error', 'Không tìm thấy thành viên.'); redirect('members'); }
        if ((int) $membership['user_id'] === (int) $user['id']) { flash('error', 'Không thể tự thay đổi vai trò Admin của mình.'); redirect('members'); }
        $role = (string) ($_POST['role'] ?? $membership['role']);
        if (!in_array($role, ['editor','viewer'], true)) { flash('error', 'Vai trò không hợp lệ.'); redirect('members'); }
        $flags = array_map('intval', (array) ($_POST['flag_ids'] ?? []));
        $canViewAudit = isset($_POST['can_view_audit']) ? 1 : 0;
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE project_memberships SET role=?,can_view_audit=?,updated_at=NOW() WHERE id=?')->execute([$role, $canViewAudit, $membershipId]);
            $pdo->prepare('DELETE FROM membership_flag_permissions WHERE membership_id=?')->execute([$membershipId]);
            if ($role === 'editor') {
                $insert = $pdo->prepare('INSERT INTO membership_flag_permissions (membership_id,flag_id,can_edit) SELECT ?,f.id,1 FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.id=? AND f.is_active=1');
                foreach ($flags as $flagId) $insert->execute([$membershipId, $projectId, $flagId]);
            }
            $pdo->commit();
            (new AuditLogService())->record($projectId, $user['id'], 'member.permissions_updated', 'member', (string) $membership['user_id'], null, ['role' => $role, 'can_view_audit' => (bool) $canViewAudit, 'flag_ids' => $flags], 'Cập nhật quyền thành viên');
            flash('success', 'Đã lưu quyền thành viên.');
        } catch (\Throwable $exception) { if ($pdo->inTransaction()) $pdo->rollBack(); flash('error', $exception->getMessage()); }
        redirect('members');
    }
}
