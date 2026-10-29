<?php
namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Project;
use App\Services\AuditLogService;
use App\Services\VersionService;
use App\Support\Auth;
use App\Support\Database;

final class ChildController extends Controller
{
    public function logout()
    {
        Auth::logout();
        header('Location: ' . url('login'));
        exit;
    }

    public function dashboard()
    {
        $user = $this->child();
        $projectId = Auth::projectId();
        if (!$projectId) { http_response_code(403); exit('Tài khoản con chưa được gán workspace.'); }
        $project = Project::findForUser($projectId, (int) $user['id']);
        if (!$project || (int) $project['created_by'] !== (int) $user['parent_user_id']) { http_response_code(403); exit('Dự án không thuộc tài khoản cha.'); }
        $projects = array_values(array_filter(Project::forUser((int) $user['id']), function ($item) use ($user) { return (int) $item['created_by'] === (int) $user['parent_user_id']; }));
        $environments = Environment::forProject($projectId);
        $environmentId = Auth::environmentId($projectId);
        $environment = Environment::find($environmentId);
        $isTestEnvironment = $this->isTestEnvironment($projectId, $environmentId);
        $currentPublished = \App\Models\Version::current($environmentId);
        $currentDraft = $isTestEnvironment ? \App\Models\Version::draft($environmentId, (int) $user['id']) : null;
        $current = $currentDraft ?: $currentPublished;
        $membershipQuery = Database::connection()->prepare('SELECT id FROM project_memberships WHERE project_id=? AND user_id=?');
        $membershipQuery->execute([$projectId, (int) $user['id']]);
        $membershipId = (int) $membershipQuery->fetchColumn();
        $versionId = (int) ($current['id'] ?? 0);
        $query = Database::connection()->prepare('SELECT f.id,f.`key`,f.name,f.description,COALESCE(vf.default_value,0) default_value,COALESCE(vf.kill_switch,0) kill_switch,vf.forced_value,COALESCE(vf.rollout_enabled,0) rollout_enabled,COALESCE(vf.rollout_percentage,0) rollout_percentage,CASE WHEN p.can_toggle=1 THEN 1 ELSE 0 END can_toggle FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 LEFT JOIN subaccount_flag_permissions p ON p.flag_id=f.id AND p.membership_id=? AND p.can_toggle=1 LEFT JOIN version_flags vf ON vf.flag_id=f.id AND vf.version_id=? WHERE f.project_id=? AND f.is_active=1 ORDER BY f.name');
        $query->execute([$membershipId, $versionId, $projectId]);
        $flags = $query->fetchAll();
        $previous = $currentDraft ? $currentPublished : $this->previousPublishedVersion($environmentId, $currentPublished);
        $hasTogglePermission = (bool) array_filter($flags, function ($flag) { return (int) $flag['can_toggle'] === 1; });
        $this->view('child-dashboard', compact('user','project','projects','projectId','environment','environments','environmentId','current','flags','previous','hasTogglePermission','isTestEnvironment'));
    }

    public function switchProject()
    {
        $user = $this->child();
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $project = Project::findForUser($projectId, (int) $user['id']);
        if (!$project || (int) $project['created_by'] !== (int) $user['parent_user_id']) { http_response_code(403); exit('Dự án không thuộc tài khoản cha.'); }
        $_SESSION['project_id'] = $projectId;
        unset($_SESSION['environment_id']);
        redirect('child/dashboard');
    }

    public function switchEnvironment()
    {
        $this->child();
        $projectId = Auth::projectId();
        $environmentId = (int) ($_POST['environment_id'] ?? 0);
        $query = Database::connection()->prepare('SELECT id FROM environments WHERE id=? AND project_id=?');
        $query->execute([$environmentId, $projectId]);
        if (!$query->fetchColumn()) { http_response_code(403); exit('Môi trường không thuộc tài khoản cha.'); }
        $_SESSION['environment_id'] = $environmentId;
        redirect('child/dashboard');
    }

    public function toggle()
    {
        $user = $this->child();
        $projectId = Auth::projectId();
        $environmentId = (int) ($_POST['environment_id'] ?? 0);
        $this->requireTestEnvironment($projectId, $environmentId);
        $flagId = (int) ($_POST['flag_id'] ?? 0);
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        $check = Database::connection()->prepare('SELECT f.id,f.`key` FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 JOIN project_memberships m ON m.project_id=f.project_id AND m.user_id=? JOIN subaccount_flag_permissions p ON p.membership_id=m.id AND p.flag_id=f.id AND p.can_toggle=1 WHERE f.id=? AND f.project_id=? AND f.is_active=1');
        $check->execute([(int) $user['id'], $flagId, $projectId]);
        $flag = $check->fetch();
        $env = Database::connection()->prepare('SELECT id FROM environments WHERE id=? AND project_id=?');
        $env->execute([$environmentId, $projectId]);
        if (!$flag || !$env->fetchColumn()) { http_response_code(403); exit('Feature hoặc môi trường không thuộc tài khoản cha.'); }
        try {
            $versions = new VersionService();
            $draft = $versions->createDraft($environmentId, (int) $user['id'], null, 'Tài khoản con đổi ' . $flag['key'], false);
            $pdo = Database::connection();
            $read = $pdo->prepare('SELECT id FROM version_flags WHERE version_id=? AND flag_id=?');
            $read->execute([(int) $draft['id'], $flagId]);
            $configId = (int) $read->fetchColumn();
            if (!$configId) {
                $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,forced_value,created_at,updated_at) VALUES (?,?,0,?,0,0,0,?,NOW(),NOW())')
                    ->execute([(int) $draft['id'], $flagId, $enabled ? 1 : 0, $enabled ? 1 : 0]);
                $configId = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('UPDATE version_flags SET forced_value=?,default_value=?,rollout_enabled=0,rollout_percentage=0,updated_at=NOW() WHERE id=? AND version_id=?')
                ->execute([$enabled ? 1 : 0, $enabled ? 1 : 0, $configId, (int) $draft['id']]);
            (new AuditLogService())->record($projectId, (int) $user['id'], 'subaccount.feature_toggled', 'flag', $flag['key'], null, ['enabled' => $enabled], 'Tài khoản con bật/tắt feature', $environmentId, (int) $draft['id']);
            flash('success', 'Đã lưu trạng thái ' . ($enabled ? 'bật' : 'tắt') . ' của ' . $flag['key'] . ' vào bản nháp thử nghiệm.');
        } catch (\Throwable $exception) {
            flash('error', 'Không đổi được trạng thái feature: ' . $exception->getMessage());
        }
        $_SESSION['environment_id'] = $environmentId;
        redirect('child/dashboard');
    }

    public function restorePrevious()
    {
        $user = $this->child();
        $projectId = Auth::projectId();
        $environmentId = (int) ($_POST['environment_id'] ?? 0);
        $this->requireTestEnvironment($projectId, $environmentId);
        $pdo = Database::connection();
        $environmentQuery = $pdo->prepare('SELECT id,project_id,current_version_id FROM environments WHERE id=? AND project_id=?');
        $environmentQuery->execute([$environmentId, $projectId]);
        $environment = $environmentQuery->fetch();
        $current = $environment && $environment['current_version_id'] ? \App\Models\Version::find((int) $environment['current_version_id']) : null;
        if (!$environment || !$current || $current['status'] !== 'published') {
            flash('error', 'Không tìm thấy phiên bản cấu hình hiện tại để khôi phục.');
            redirect('child/dashboard');
        }
        $draft = \App\Models\Version::draft($environmentId, (int) $user['id']);
        $target = $draft ? $current : $this->previousPublishedVersion($environmentId, $current);
        if (!$target) {
            flash('error', 'Môi trường chưa có phiên bản đã lưu trước đó.');
            redirect('child/dashboard');
        }
        $permissionQuery = $pdo->prepare('SELECT f.id,f.`key` FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 JOIN subaccount_flag_permissions p ON p.flag_id=f.id AND p.can_toggle=1 JOIN project_memberships m ON m.id=p.membership_id AND m.project_id=f.project_id AND m.user_id=? WHERE f.project_id=? AND f.is_active=1');
        $permissionQuery->execute([(int) $user['id'], $projectId]);
        $permittedFlags = $permissionQuery->fetchAll();
        if (!$permittedFlags) {
            flash('error', 'Tài khoản chưa được cấp quyền bật/tắt tính năng nào trong dự án này.');
            redirect('child/dashboard');
        }
        try {
            $versions = new VersionService();
            $draft = $versions->createDraft($environmentId, (int) $user['id'], (int) $current['id'], 'Khôi phục trạng thái đã lưu', false);
            $oldValues = $pdo->prepare('SELECT kill_switch,default_value,forced_value,rollout_enabled,rollout_percentage FROM version_flags WHERE version_id=? AND flag_id=?');
            $draftFlag = $pdo->prepare('SELECT id FROM version_flags WHERE version_id=? AND flag_id=?');
            $insertDraftFlag = $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,created_at,updated_at) VALUES (?,?,0,0,0,0,0,NOW(),NOW())');
            $setState = $pdo->prepare('UPDATE version_flags SET kill_switch=0,forced_value=?,default_value=?,rollout_enabled=0,rollout_percentage=0,updated_at=NOW() WHERE id=? AND version_id=?');
            $restored = [];
            foreach ($permittedFlags as $flag) {
                $oldValues->execute([(int) $target['id'], (int) $flag['id']]);
                $old = $oldValues->fetch();
                $enabled = $old && $this->enabledInSnapshot($old);
                $draftFlag->execute([(int) $draft['id'], (int) $flag['id']]);
                $draftFlagId = (int) $draftFlag->fetchColumn();
                if (!$draftFlagId) {
                    $insertDraftFlag->execute([(int) $draft['id'], (int) $flag['id']]);
                    $draftFlagId = (int) $pdo->lastInsertId();
                }
                $setState->execute([$enabled ? 1 : 0, $enabled ? 1 : 0, $draftFlagId, (int) $draft['id']]);
                $restored[$flag['key']] = $enabled;
            }
            (new AuditLogService())->record($projectId, (int) $user['id'], 'subaccount.features_restored', 'version', (string) $target['version_number'], ['version_id' => (int) $current['id']], ['version_id' => (int) $target['id'], 'features' => $restored], 'Khôi phục trạng thái tính năng đã lưu trước đó', $environmentId, (int) $draft['id']);
            flash('success', 'Đã khôi phục trạng thái các tính năng được cấp vào bản nháp theo phiên bản v' . (int) $target['version_number'] . '.');
        } catch (\Throwable $exception) {
            flash('error', 'Không khôi phục được trạng thái: ' . $exception->getMessage());
        }
        $_SESSION['environment_id'] = $environmentId;
        redirect('child/dashboard');
    }

    private function previousPublishedVersion($environmentId, $current)
    {
        if (!$current) return null;
        $pdo = Database::connection();
        if (!empty($current['parent_version_id'])) {
            $parent = $pdo->prepare("SELECT * FROM versions WHERE id=? AND environment_id=? AND status='published'");
            $parent->execute([(int) $current['parent_version_id'], (int) $environmentId]);
            $version = $parent->fetch();
            if ($version) return $version;
        }
        $previous = $pdo->prepare("SELECT * FROM versions WHERE environment_id=? AND status='published' AND version_number<? ORDER BY version_number DESC LIMIT 1");
        $previous->execute([(int) $environmentId, (int) $current['version_number']]);
        return $previous->fetch() ?: null;
    }

    private function enabledInSnapshot(array $config)
    {
        if ((int) $config['kill_switch'] === 1) return false;
        if ($config['forced_value'] !== null) return (bool) $config['forced_value'];
        return (bool) $config['default_value'] || ((bool) $config['rollout_enabled'] && (float) $config['rollout_percentage'] > 0);
    }

    private function child()
    {
        $user = $this->user();
        if ($user['account_type'] !== 'child' || empty($user['parent_user_id'])) { http_response_code(403); exit('Trang này chỉ dành cho tài khoản con.'); }
        $projectId = Auth::projectId();
        $project = $projectId ? Project::findForUser($projectId, (int) $user['id']) : null;
        if (!$project || (int) $project['created_by'] !== (int) $user['parent_user_id']) { http_response_code(403); exit('Dự án không thuộc tài khoản cha.'); }
        return $user;
    }
}
