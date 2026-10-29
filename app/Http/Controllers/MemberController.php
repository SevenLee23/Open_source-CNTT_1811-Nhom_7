<?php
namespace App\Http\Controllers;

use App\Models\Flag;
use App\Models\ProjectMembership;
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
        $members = ProjectMembership::members($projectId);
        $flags = Flag::forProject($projectId);
        $permissions = [];
        foreach ($members as $member) {
            $query = Database::connection()->prepare('SELECT flag_id FROM membership_flag_permissions WHERE membership_id=? AND can_edit=1');
            $query->execute([(int) $member['id']]);
            $permissions[$member['id']] = array_map('intval', array_column($query->fetchAll(), 'flag_id'));
        }
        $this->render('members', compact('user','projectId','members','flags','permissions'));
    }

    public function store()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireAdmin($projectId);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
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
