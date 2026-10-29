<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreRuleRequest;
use App\Models\Rule;
use App\Models\TargetGroup;
use App\Services\AuditLogService;
use App\Services\VersionService;
use App\Support\Auth;
use App\Support\Database;

final class RuleController extends Controller
{
    public function index()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $environmentId = Auth::environmentId($projectId);
        $isTestEnvironment = $this->isTestEnvironment($projectId, $environmentId);
        $draftQuery = Database::connection()->prepare("SELECT * FROM versions WHERE environment_id=? AND status='draft' ORDER BY version_number DESC LIMIT 1");
        $draftQuery->execute([$environmentId]);
        $draft = ($isTestEnvironment ? $draftQuery->fetch() : false) ?: \App\Models\Version::current($environmentId);
        $flagsQuery = Database::connection()->prepare('SELECT f.id,f.`key`,f.name,vf.id version_flag_id FROM version_flags vf JOIN flags f ON f.id=vf.flag_id JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE vf.version_id=? AND f.project_id=? AND f.is_active=1 ORDER BY f.`key`');
        $flagsQuery->execute([(int) ($draft['id'] ?? 0), $projectId]);
        $flags = $flagsQuery->fetchAll();
        foreach ($flags as &$flag) {
            $flag['can_edit'] = $isTestEnvironment && Auth::canEditFlag($flag['id'], $projectId);
            $flag['rules'] = Rule::forVersionFlag($flag['version_flag_id']);
        }
        unset($flag);
        $groups = TargetGroup::forProject($projectId);
        $membership = Auth::membership($projectId);
        $this->render('rules', compact('user','projectId','draft','flags','groups','membership','isTestEnvironment'));
    }

    public function store()
    {
        $projectId = $this->projectId();
        $this->requireTestEnvironment($projectId);
        $flagId = (int) ($_POST['flag_id'] ?? 0);
        if (!Auth::canEditFlag($flagId, $projectId)) { http_response_code(403); exit('Bạn không có quyền sửa rule của feature này.'); }
        try { $data = StoreRuleRequest::validate($_POST); }
        catch (\Throwable $exception) { flash('error', $exception->getMessage()); redirect('rules'); }
        $pdo = Database::connection();
        $featureQuery = $pdo->prepare('SELECT f.id FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.id=? AND f.project_id=? AND f.is_active=1');
        $featureQuery->execute([$flagId, $projectId]);
        if (!$featureQuery->fetchColumn()) { http_response_code(404); exit('Không tìm thấy feature.'); }
        if ($data['target_type'] === 'group') {
            $group = $pdo->prepare('SELECT id FROM target_groups WHERE id=? AND project_id=?');
            $group->execute([$data['target_group_id'], $projectId]);
            if (!$group->fetchColumn()) { flash('error', 'Nhóm không thuộc dự án hiện tại.'); redirect('rules'); }
        }
        $user = $this->user();
        $environmentId = Auth::environmentId($projectId);
        try {
            $draft = (new VersionService())->createDraft($environmentId, $user['id'], null, 'Cập nhật rule');
            $versionFlag = $pdo->prepare('SELECT id FROM version_flags WHERE version_id=? AND flag_id=?');
            $versionFlag->execute([(int) $draft['id'], $flagId]);
            $versionFlagId = (int) $versionFlag->fetchColumn();
            if (!$versionFlagId) throw new \DomainException('Feature chưa nằm trong bản nháp hiện tại.');
            $insert = $pdo->prepare('INSERT INTO rules (version_flag_id,target_type,effect,priority,target_group_id,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
            $insert->execute([$versionFlagId, $data['target_type'], $data['effect'], $data['priority'], $data['target_group_id']]);
            $ruleId = (int) $pdo->lastInsertId();
            $userInsert = $pdo->prepare('INSERT INTO rule_users (rule_id,user_id,created_at) VALUES (?,?,NOW())');
            foreach ($data['user_ids'] as $userId) $userInsert->execute([$ruleId, $userId]);
            (new AuditLogService())->record($projectId, $user['id'], 'rule.created', 'flag', (string) $flagId, null, $data, trim((string) ($_POST['reason'] ?? 'Thêm rule')), $environmentId, $draft['id']);
            flash('success', 'Rule đã được thêm vào bản nháp.');
        } catch (\Throwable $exception) { flash('error', $exception->getMessage()); }
        redirect('rules');
    }

    public function delete()
    {
        $projectId = $this->projectId();
        $ruleId = (int) ($_POST['rule_id'] ?? 0);
        $query = Database::connection()->prepare('SELECT r.*,vf.version_id,f.id flag_id,f.`key`,v.environment_id FROM rules r JOIN version_flags vf ON vf.id=r.version_flag_id JOIN versions v ON v.id=vf.version_id JOIN flags f ON f.id=vf.flag_id JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE r.id=? AND v.project_id=? AND v.status=\'draft\' AND f.is_active=1');
        $query->execute([$ruleId, $projectId]);
        $rule = $query->fetch();
        if (!$rule) { flash('error', 'Chỉ có thể xóa rule trong bản nháp.'); redirect('rules'); }
        $this->requireTestEnvironment($projectId, (int) $rule['environment_id']);
        if (!Auth::canEditFlag($rule['flag_id'], $projectId)) { http_response_code(403); exit('Bạn không có quyền sửa rule này.'); }
        $user = $this->user();
        Database::connection()->prepare('DELETE FROM rules WHERE id=?')->execute([$ruleId]);
        (new AuditLogService())->record($projectId, $user['id'], 'rule.deleted', 'flag', $rule['key'], $rule, null, trim((string) ($_POST['reason'] ?? 'Xóa rule')), Auth::environmentId($projectId), $rule['version_id']);
        flash('success', 'Rule đã được xóa khỏi bản nháp.');
        redirect('rules');
    }

    public function createGroup()
    {
        $projectId = $this->projectId();
        $this->requireTestEnvironment($projectId);
        $this->requireAdmin($projectId);
        $key = strtolower(trim((string) ($_POST['key'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $members = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($_POST['members'] ?? ''))))));
        if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/', $key) || $name === '') { flash('error', 'Nhập key và tên nhóm hợp lệ.'); redirect('rules'); }
        $pdo = Database::connection();
        try {
            $pdo->prepare('INSERT INTO target_groups (project_id,`key`,name,created_at,updated_at) VALUES (?,?,?,?,?)')->execute([$projectId, $key, $name, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $groupId = (int) $pdo->lastInsertId();
            $insert = $pdo->prepare('INSERT IGNORE INTO group_members (group_id,user_id,created_at) VALUES (?,?,NOW())');
            foreach ($members as $memberId) if (strlen($memberId) <= 100) $insert->execute([$groupId, $memberId]);
            $snapshot = $pdo->prepare("INSERT IGNORE INTO version_group_members (version_id,group_id,user_id) SELECT v.id,?,gm.user_id FROM versions v JOIN environments e ON e.id=v.environment_id JOIN group_members gm ON gm.group_id=? WHERE e.project_id=? AND e.`key`='staging' AND v.status='draft'");
            $snapshot->execute([$groupId, $groupId, $projectId]);
            (new AuditLogService())->record($projectId, $this->user()['id'], 'group.created', 'group', $key, null, ['name' => $name, 'member_count' => count($members)], 'Tạo nhóm người dùng', Auth::environmentId($projectId));
            flash('success', 'Đã tạo nhóm và thêm người dùng vào snapshot các bản nháp.');
        } catch (\Throwable $exception) { flash('error', 'Không tạo được nhóm: ' . $exception->getMessage()); }
        redirect('rules');
    }

    public function addGroupMembers()
    {
        $projectId = $this->projectId();
        $this->requireTestEnvironment($projectId);
        $this->requireAdmin($projectId);
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $group = Database::connection()->prepare('SELECT * FROM target_groups WHERE id=? AND project_id=?');
        $group->execute([$groupId, $projectId]);
        $record = $group->fetch();
        if (!$record) { flash('error', 'Không tìm thấy nhóm.'); redirect('rules'); }
        $members = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($_POST['members'] ?? ''))))));
        $pdo = Database::connection();
        $insert = $pdo->prepare('INSERT IGNORE INTO group_members (group_id,user_id,created_at) VALUES (?,?,NOW())');
        $snapshot = $pdo->prepare('INSERT IGNORE INTO version_group_members (version_id,group_id,user_id) SELECT v.id,?,? FROM versions v JOIN environments e ON e.id=v.environment_id WHERE e.project_id=? AND e.`key`=\'staging\' AND v.status=\'draft\'');
        foreach ($members as $memberId) {
            if (strlen($memberId) > 100) continue;
            $insert->execute([$groupId, $memberId]);
            $snapshot->execute([$groupId, $memberId, $projectId]);
        }
        (new AuditLogService())->record($projectId, $this->user()['id'], 'group.members_updated', 'group', $record['key'], null, ['added_count' => count($members)], 'Cập nhật thành viên nhóm', Auth::environmentId($projectId));
        flash('success', 'Đã cập nhật thành viên nhóm. Bản đã phát hành không bị thay đổi.');
        redirect('rules');
    }
}
