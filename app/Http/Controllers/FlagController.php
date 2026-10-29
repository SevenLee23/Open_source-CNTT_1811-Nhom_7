<?php
namespace App\Http\Controllers;

use App\Http\Requests\StoreFlagRequest;
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
        $statement = Database::connection()->prepare('SELECT f.*,vf.id version_flag_id,vf.kill_switch,vf.default_value,vf.forced_value,vf.rollout_enabled,vf.rollout_percentage,vf.fallback_value FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 LEFT JOIN version_flags vf ON vf.flag_id=f.id AND vf.version_id=? WHERE f.project_id=? AND f.is_active=1 ORDER BY f.`key`');
        $statement->execute([(int) ($draft['id'] ?? 0), $projectId]);
        $flags = $statement->fetchAll();
        foreach ($flags as &$flag) $flag['can_edit'] = $isTestEnvironment && Auth::canEditFlag($flag['id'], $projectId);
        unset($flag);
        $availableQuery = Database::connection()->prepare('SELECT c.id,c.`key`,c.name,c.description FROM feature_catalog c WHERE c.is_active=1 AND NOT EXISTS (SELECT 1 FROM flags f WHERE f.project_id=? AND f.catalog_feature_id=c.id AND f.is_active=1) ORDER BY c.name');
        $availableQuery->execute([$projectId]);
        $availableCatalogFeatures = $availableQuery->fetchAll();
        $groups = \App\Models\TargetGroup::forProject($projectId);
        $membership = Auth::membership($projectId);
        $this->render('flags', compact('user','projectId','environment','draft','flags','groups','membership','availableCatalogFeatures','isTestEnvironment'));
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
        if (($user['account_type'] ?? '') !== 'owner' || !Auth::isAdmin($projectId)) { http_response_code(403); exit('Chỉ tài khoản cha mới thêm feature từ thư viện.'); }
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

    public function update()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireTestEnvironment($projectId);
        $flagId = (int) ($_POST['flag_id'] ?? 0);
        if (!Auth::canEditFlag($flagId, $projectId)) { http_response_code(403); exit('Bạn không có quyền sửa feature này.'); }
        $pdo = Database::connection();
        $flagQuery = $pdo->prepare('SELECT f.* FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.id=? AND f.project_id=? AND f.is_active=1');
        $flagQuery->execute([$flagId, $projectId]);
        $flag = $flagQuery->fetch();
        if (!$flag) { http_response_code(404); exit('Không tìm thấy feature.'); }
        try { $input = StoreFlagRequest::validate(array_merge($_POST, ['key' => $flag['key'], 'name' => $flag['name']])); }
        catch (\Throwable $exception) { flash('error', $exception->getMessage()); redirect('flags'); }
        $environmentId = Auth::environmentId($projectId);
        try {
            $draft = (new VersionService())->createDraft($environmentId, $user['id'], null, 'Cập nhật ' . $flag['key'], true);
            $configQuery = $pdo->prepare('SELECT * FROM version_flags WHERE version_id=? AND flag_id=?');
            $configQuery->execute([(int) $draft['id'], $flagId]);
            $old = $configQuery->fetch();
            if (!$old) {
                $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,created_at,updated_at) VALUES (?,?,0,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE default_value=VALUES(default_value),rollout_enabled=VALUES(rollout_enabled),rollout_percentage=VALUES(rollout_percentage),fallback_value=VALUES(fallback_value),updated_at=NOW()')
                    ->execute([(int) $draft['id'], $flagId, $input['default_value'] ? 1 : 0, $input['rollout_enabled'] ? 1 : 0, $input['rollout_percentage'], $input['fallback_value'] ? 1 : 0]);
            } else {
                $pdo->prepare('UPDATE version_flags SET kill_switch=?,forced_value=NULL,default_value=?,rollout_enabled=?,rollout_percentage=?,fallback_value=?,updated_at=NOW() WHERE id=? AND version_id=?')
                    ->execute([isset($_POST['kill_switch']) ? 1 : 0, $input['default_value'] ? 1 : 0, $input['rollout_enabled'] ? 1 : 0, $input['rollout_percentage'], $input['fallback_value'] ? 1 : 0, (int) $old['id'], (int) $draft['id']]);
            }
            (new AuditLogService())->record($projectId, $user['id'], 'flag.configuration_updated', 'flag', $flag['key'], $old ?: null, $input, trim((string) ($_POST['reason'] ?? 'Cập nhật cấu hình')), $environmentId, $draft['id']);
            flash('success', 'Đã lưu thay đổi vào bản nháp. Bản đang phát hành chưa bị thay đổi.');
        } catch (\Throwable $exception) { flash('error', $exception->getMessage()); }
        redirect('flags');
    }

    public function toggleQuick()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        if (($user['account_type'] ?? '') !== 'owner' || !Auth::isAdmin($projectId)) { http_response_code(403); exit('Chỉ tài khoản cha mới được bật/tắt nhanh tính năng.'); }
        $environmentId = Auth::environmentId($projectId);
        $this->requireTestEnvironment($projectId, $environmentId);
        $flagId = (int) ($_POST['flag_id'] ?? 0);
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
        $flagQuery = Database::connection()->prepare('SELECT f.id,f.`key` FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.id=? AND f.project_id=? AND f.is_active=1');
        $flagQuery->execute([$flagId, $projectId]);
        $flag = $flagQuery->fetch();
        if (!$flag) { http_response_code(404); exit('Không tìm thấy tính năng trong dự án này.'); }
        try {
            $versions = new VersionService();
            $draft = $versions->createDraft($environmentId, (int) $user['id'], null, 'Tài khoản cha bật/tắt ' . $flag['key']);
            $pdo = Database::connection();
            $config = $pdo->prepare('SELECT id FROM version_flags WHERE version_id=? AND flag_id=?');
            $config->execute([(int) $draft['id'], $flagId]);
            $configId = (int) $config->fetchColumn();
            if (!$configId) {
                $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,forced_value,created_at,updated_at) VALUES (?,?,0,?,0,0,0,?,NOW(),NOW())')
                    ->execute([(int) $draft['id'], $flagId, $enabled ? 1 : 0, $enabled ? 1 : 0]);
                $configId = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('UPDATE version_flags SET kill_switch=0,forced_value=?,default_value=?,rollout_enabled=0,rollout_percentage=0,updated_at=NOW() WHERE id=? AND version_id=?')
                ->execute([$enabled ? 1 : 0, $enabled ? 1 : 0, $configId, (int) $draft['id']]);
            (new AuditLogService())->record($projectId, (int) $user['id'], 'feature.quick_toggled', 'flag', $flag['key'], null, ['enabled' => $enabled], 'Tài khoản cha bật/tắt nhanh tính năng', $environmentId, (int) $draft['id']);
            flash('success', 'Đã lưu trạng thái ' . ($enabled ? 'bật' : 'tắt') . ' vào bản nháp môi trường thử nghiệm.');
        } catch (\Throwable $exception) {
            flash('error', 'Không thay đổi được tính năng: ' . $exception->getMessage());
        }
        redirect('flags');
    }
}
