<?php
namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Flag;
use App\Models\Version;
use App\Services\AuditLogService;
use App\Services\VersionService;
use App\Support\Auth;
use App\Support\Database;

final class FlagController extends Controller
{
    public function index()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $environmentId = Auth::environmentId($projectId);
        $environment = $environmentId ? Environment::find($environmentId) : null;
        if (!$environment) redirect('projects');
        $isTestEnvironment = $this->isTestEnvironment($projectId, $environmentId);
        $draftQuery = Database::connection()->prepare("SELECT * FROM versions WHERE environment_id=? AND status='draft' ORDER BY version_number DESC LIMIT 1");
        $draftQuery->execute([$environmentId]);
        $draft = ($isTestEnvironment ? $draftQuery->fetch() : false) ?: Version::current($environmentId);
        $statement = Database::connection()->prepare('SELECT f.*,vf.id version_flag_id,f.emergency_kill_switch kill_switch,vf.default_value,vf.forced_value,vf.fallback_value FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 LEFT JOIN version_flags vf ON vf.flag_id=f.id AND vf.version_id=? WHERE f.project_id=? AND f.is_active=1 ORDER BY f.`key`');
        $statement->execute([(int) ($draft['id'] ?? 0), $projectId]);
        $flags = $statement->fetchAll();
        foreach ($flags as &$flag) $flag['can_edit'] = $isTestEnvironment && Auth::canEditFlag($flag['id'], $projectId);
        unset($flag);
        $availableQuery = Database::connection()->prepare('SELECT c.id,c.`key`,c.name,c.description FROM feature_catalog c WHERE c.is_active=1 AND NOT EXISTS (SELECT 1 FROM flags f WHERE f.project_id=? AND f.catalog_feature_id=c.id AND f.is_active=1) ORDER BY c.name');
        $availableQuery->execute([$projectId]);
        $availableCatalogFeatures = $availableQuery->fetchAll();
        $groups = \App\Models\TargetGroup::forProject($projectId);
        $membership = Auth::membership($projectId);
        $canPublish = $membership && in_array($membership['role'], ['admin','editor'], true);
        $canEmergencyToggle = ($user['account_type'] ?? '') === 'owner' && Auth::isAdmin($projectId);
        $this->render('flags', compact('user','projectId','environment','draft','flags','groups','membership','availableCatalogFeatures','isTestEnvironment','canPublish','canEmergencyToggle'));
    }

    public function create()
    {
        $this->user();
        http_response_code(403);
        exit('Không thể tạo feature trực tiếp. Hãy chọn feature có sẵn từ thư viện Admin.');
    }

    public function addFromCatalog()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $environmentId = Auth::environmentId($projectId);
        $this->requireTestEnvironment($projectId, $environmentId);
        if (($user['account_type'] ?? '') !== 'owner' || !Auth::isAdmin($projectId)) { http_response_code(403); exit('Chỉ chủ dự án mới thêm feature từ thư viện.'); }
        $pdo = Database::connection();
        $project = $pdo->prepare('SELECT id FROM projects WHERE id=? AND created_by=? AND is_active=1');
        $project->execute([$projectId, (int) $user['id']]);
        if (!$project->fetchColumn()) { http_response_code(403); exit('Chỉ chủ dự án mới thêm feature từ thư viện.'); }
        $catalogId = (int) ($_POST['catalog_feature_id'] ?? 0);
        $catalogQuery = $pdo->prepare('SELECT id,`key`,name,description FROM feature_catalog WHERE id=? AND is_active=1');
        $catalogQuery->execute([$catalogId]);
        $catalog = $catalogQuery->fetch();
        if (!$catalog) { flash('error', 'Tính năng không có trong thư viện đang hoạt động.'); redirect('flags'); }
        $exists = $pdo->prepare('SELECT id FROM flags WHERE project_id=? AND catalog_feature_id=?');
        $exists->execute([$projectId, $catalogId]);
        if ($exists->fetchColumn()) { flash('error', 'Tính năng này đã có trong dự án.'); redirect('flags'); }
        try {
            $flagInsert = $pdo->prepare('INSERT INTO flags (project_id,`key`,name,description,catalog_feature_id,is_active,created_at,updated_at) VALUES (?,?,?,?,?,1,NOW(),NOW())');
            $flagInsert->execute([$projectId, $catalog['key'], $catalog['name'], $catalog['description'], $catalogId]);
            $flagId = (int) $pdo->lastInsertId();
            $versions = new VersionService();
            $draft = $versions->createDraft($environmentId, (int) $user['id'], null, 'Thêm tính năng từ thư viện Admin: ' . $catalog['key']);
            $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,forced_value,created_at,updated_at) VALUES (?,?,0,0,0,0,0,NULL,NOW(),NOW())')
                ->execute([(int) $draft['id'], $flagId]);
            (new AuditLogService())->record($projectId, (int) $user['id'], 'feature.added_from_catalog', 'feature', $catalog['key'], null, ['catalog_feature_id' => $catalogId], 'Thêm feature từ thư viện Admin');
            flash('success', 'Đã thêm tính năng có sẵn vào bản nháp môi trường thử nghiệm. Tính năng mặc định đang tắt.');
        } catch (\Throwable $exception) {
            flash('error', 'Không thêm được tính năng từ thư viện.');
        }
        redirect('flags');
    }

    public function saveDraft()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $environmentId = Auth::environmentId($projectId);
        $this->requireTestEnvironment($projectId, $environmentId);
        $postedFeatures = $_POST['features'] ?? [];
        if (!is_array($postedFeatures)) { flash('error', 'Danh sách trạng thái feature không hợp lệ.'); redirect('flags'); }
        $pdo = Database::connection();
        $featureQuery = $pdo->prepare('SELECT id,`key` FROM flags WHERE id=? AND project_id=? AND is_active=1');
        $changes = [];
        foreach ($postedFeatures as $rawFlagId => $state) {
            $flagId = (int) $rawFlagId;
            if ($flagId < 1 || !is_array($state) || !in_array((string) ($state['enabled'] ?? ''), ['0','1'], true) || !in_array((string) ($state['original_enabled'] ?? ''), ['0','1'], true)) {
                flash('error', 'Dữ liệu trạng thái feature không hợp lệ.');
                redirect('flags');
            }
            if ((string) $state['enabled'] === (string) $state['original_enabled']) continue;
            if (!Auth::canEditFlag($flagId, $projectId)) { http_response_code(403); exit('Bạn không có quyền sửa một trong các feature đã chọn.'); }
            $featureQuery->execute([$flagId, $projectId]);
            $feature = $featureQuery->fetch();
            if (!$feature) { http_response_code(404); exit('Không tìm thấy feature trong dự án này.'); }
            $changes[] = ['id' => $flagId, 'key' => $feature['key'], 'enabled' => (string) $state['enabled'] === '1'];
        }
        if (!$changes) { flash('success', 'Không có thay đổi trạng thái nào cần lưu.'); redirect('flags'); }
        $reason = trim((string) ($_POST['reason'] ?? '')) ?: 'Cập nhật trạng thái các feature';
        try {
            $draft = (new VersionService())->createDraft($environmentId, (int) $user['id'], null, $reason, true);
            $configQuery = $pdo->prepare('SELECT * FROM version_flags WHERE version_id=? AND flag_id=?');
            $insert = $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,forced_value,created_at,updated_at) VALUES (?,?,0,?,0,0,?,?,NOW(),NOW())');
            $update = $pdo->prepare('UPDATE version_flags SET kill_switch=0,forced_value=?,default_value=?,fallback_value=?,rollout_enabled=0,rollout_percentage=0,updated_at=NOW() WHERE id=? AND version_id=?');
            foreach ($changes as $change) {
                $configQuery->execute([(int) $draft['id'], $change['id']]);
                $old = $configQuery->fetch();
                $value = $change['enabled'] ? 1 : 0;
                if (!$old) {
                    $insert->execute([(int) $draft['id'], $change['id'], $value, $value, $value]);
                } else {
                    $update->execute([$value, $value, $value, (int) $old['id'], (int) $draft['id']]);
                }
                (new AuditLogService())->record($projectId, (int) $user['id'], 'feature.draft_status_updated', 'flag', $change['key'], $old ?: null, ['enabled' => $change['enabled']], $reason, $environmentId, (int) $draft['id']);
            }
            flash('success', 'Đã lưu thay đổi trạng thái vào bản nháp v' . (int) $draft['version_number'] . '.');
        } catch (\Throwable $exception) {
            flash('error', 'Không lưu được bản nháp: ' . $exception->getMessage());
        }
        redirect('flags');
    }

    public function toggleEmergencyKillSwitch()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        if (($user['account_type'] ?? '') !== 'owner' || !Auth::isAdmin($projectId)) {
            http_response_code(403);
            exit('Chỉ chủ dự án mới được thao tác Kill Switch khẩn cấp.');
        }
        $flagId = (int) ($_POST['flag_id'] ?? 0);
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        $pdo = Database::connection();
        $flagQuery = $pdo->prepare('SELECT id,`key`,emergency_kill_switch FROM flags WHERE id=? AND project_id=? AND is_active=1');
        $flagQuery->execute([$flagId, $projectId]);
        $flag = $flagQuery->fetch();
        if (!$flag) { http_response_code(404); exit('Không tìm thấy feature trong dự án này.'); }
        try {
            $pdo->prepare('UPDATE flags SET emergency_kill_switch=?,updated_at=NOW() WHERE id=? AND project_id=?')
                ->execute([$enabled ? 1 : 0, $flagId, $projectId]);
            $environmentId = Auth::environmentId($projectId);
            (new AuditLogService())->record(
                $projectId,
                (int) $user['id'],
                $enabled ? 'feature.emergency_kill_switch.enabled' : 'feature.emergency_kill_switch.disabled',
                'flag',
                $flag['key'],
                ['emergency_kill_switch' => (bool) $flag['emergency_kill_switch']],
                ['emergency_kill_switch' => $enabled],
                $enabled ? 'Dừng khẩn cấp feature ngay lập tức' : 'Gỡ Kill Switch khẩn cấp',
                $environmentId
            );
            flash('success', $enabled
                ? 'Kill Switch đã dừng feature ngay lập tức trên mọi môi trường.'
                : 'Đã gỡ Kill Switch. Feature tuân theo cấu hình đã phát hành.');
        } catch (\Throwable $exception) {
            flash('error', 'Không cập nhật được Kill Switch: ' . $exception->getMessage());
        }
        redirect('flags');
    }
}
