<?php
namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\Auth;
use App\Support\Database;

final class ProjectController extends Controller
{
    public function index()
    {
        $user = $this->user();
        $projects = Project::forUser((int) $user['id']);
        $environments = [];
        $projectApiKey = null;
        $newProjectApiKey = null;
        $activeProjectId = Auth::projectId();
        $activeProject = $activeProjectId ? Project::find($activeProjectId) : null;
        if ($activeProjectId) {
            $environments = \App\Models\Environment::forProject($activeProjectId);
            $newProjectApiKeys = $_SESSION['_new_project_api_keys'] ?? [];
            unset($_SESSION['_new_project_api_keys']);
            $newProjectApiKey = $newProjectApiKeys[$activeProjectId] ?? null;
            if (Auth::isAdmin($activeProjectId)) {
                $pdo = Database::connection();
                $query = $pdo->prepare('SELECT id,key_prefix,last_used_at,created_at FROM api_keys WHERE project_id=? AND environment_id IS NULL AND account_user_id IS NULL AND revoked_at IS NULL ORDER BY id DESC LIMIT 1');
                $query->execute([$activeProjectId]);
                $projectApiKey = $query->fetch() ?: null;
                if (!$projectApiKey) {
                    $pdo->beginTransaction();
                    try {
                        $newProjectApiKey = (new \App\Services\ProjectApiKeyService())->rotate($activeProjectId);
                        $pdo->commit();
                        flash('success', 'Đã tạo API key dùng chung cho dự án. Các API key môi trường cũ đã bị thu hồi; hãy cập nhật client và luôn gửi trường environment.');
                        (new \App\Services\AuditLogService())->record($activeProjectId, (int) $user['id'], 'api_key.project_created', 'project', (string) ($activeProject['key'] ?? $activeProjectId), null, ['key_prefix' => substr($newProjectApiKey, 0, 12)], 'Tạo API key dùng chung cho dự án');
                    } catch (\Throwable $exception) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $exception;
                    }
                    $query->execute([$activeProjectId]);
                    $projectApiKey = $query->fetch() ?: null;
                } else {
                    $legacyCount = $pdo->prepare('SELECT COUNT(*) FROM api_keys WHERE revoked_at IS NULL AND id<>? AND (project_id=? OR environment_id IN (SELECT id FROM environments WHERE project_id=?))');
                    $legacyCount->execute([(int) $projectApiKey['id'], $activeProjectId, $activeProjectId]);
                    if ((int) $legacyCount->fetchColumn() > 0) {
                        $pdo->beginTransaction();
                        try {
                            $newProjectApiKey = (new \App\Services\ProjectApiKeyService())->rotate($activeProjectId);
                            $pdo->commit();
                            flash('success', 'Đã chuyển sang một API key dùng chung cho dự án. Các key cũ đã bị thu hồi; hãy cập nhật client và luôn gửi trường environment.');
                            (new \App\Services\AuditLogService())->record($activeProjectId, (int) $user['id'], 'api_key.project_migrated', 'project', (string) ($activeProject['key'] ?? $activeProjectId), null, ['key_prefix' => substr($newProjectApiKey, 0, 12)], 'Chuyển API key môi trường sang key dùng chung dự án');
                        } catch (\Throwable $exception) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            throw $exception;
                        }
                        $query->execute([$activeProjectId]);
                        $projectApiKey = $query->fetch() ?: null;
                    }
                }
            }
        }
        $this->render('projects', compact('user','projects','environments','projectApiKey','newProjectApiKey','activeProjectId','activeProject'));
    }

    public function create()
    {
        $user = $this->user();
        if (($user['account_type'] ?? '') !== 'owner') { http_response_code(403); exit('Chỉ chủ dự án mới được tạo dự án.'); }
        $name = trim((string) ($_POST['name'] ?? ''));
        $key = strtolower(trim((string) ($_POST['key'] ?? '')));
        if ($key === '') $key = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        if ($name === '' || strlen($name) > 160 || !preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/', $key)) {
            flash('error', 'Tên hoặc project key chưa hợp lệ.');
            redirect('projects');
        }
        try {
            $projectApiKey = null;
            $projectId = Database::transaction(function ($pdo) use ($name, $key, $user, &$projectApiKey) {
                $now = date('Y-m-d H:i:s');
                $insert = $pdo->prepare('INSERT INTO projects (name,`key`,description,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?)');
                $insert->execute([$name, $key, trim((string) ($_POST['description'] ?? '')), (int) $user['id'], $now, $now]);
                $id = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO project_memberships (project_id,user_id,role,can_view_audit,created_at,updated_at) VALUES (?,?,'admin',1,?,?)")
                    ->execute([$id, (int) $user['id'], $now, $now]);
                $env = $pdo->prepare('INSERT INTO environments (project_id,name,`key`,created_at,updated_at) VALUES (?,?,?,?,?)');
                $env->execute([$id, 'Staging', 'staging', $now, $now]);
                $stagingId = (int) $pdo->lastInsertId();
                $env->execute([$id, 'Phát hành', 'production_sim', $now, $now]);
                (new \App\Services\ProjectProvisioningService())->installBuiltInFeatures($pdo, $id, (int) $user['id']);
                $projectApiKey = (new \App\Services\ProjectApiKeyService())->issue($id);
                (new \App\Services\AuditLogService())->record($id, (int) $user['id'], 'project.created', 'project', $key, null, ['name' => $name], 'Tạo dự án');
                return $id;
            });
            $_SESSION['_new_project_api_keys'] = [$projectId => $projectApiKey];
            $_SESSION['project_id'] = $projectId;
            $_SESSION['environment_id'] = 0;
            flash('success', 'Dự án đã được tạo với hai môi trường và một API key dùng chung cho dự án.');
            redirect('projects');
        } catch (\Throwable $exception) {
            flash('error', 'Không tạo được dự án: ' . $exception->getMessage());
            redirect('projects');
        }
    }

    public function delete()
    {
        $user = $this->user();
        if (($user['account_type'] ?? '') !== 'owner') { http_response_code(403); exit('Chỉ chủ dự án mới được xóa dự án.'); }
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $projectQuery = Database::connection()->prepare('SELECT id,name,`key` FROM projects WHERE id=? AND created_by=? AND is_active=1');
        $projectQuery->execute([$projectId, (int) $user['id']]);
        $project = $projectQuery->fetch();
        if (!$project) { flash('error', 'Không tìm thấy dự án thuộc chủ dự án.'); redirect('projects'); }
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            (new \App\Services\AuditLogService())->record($projectId, (int) $user['id'], 'project.deleted', 'project', $project['key'], ['active' => true], ['active' => false], 'Chủ tài khoản xóa dự án');
            $pdo->prepare('UPDATE projects SET is_active=0,updated_at=NOW() WHERE id=? AND created_by=?')->execute([$projectId, (int) $user['id']]);
            $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE project_id=? AND revoked_at IS NULL')->execute([$projectId]);
            $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE environment_id IN (SELECT id FROM environments WHERE project_id=?) AND revoked_at IS NULL')->execute([$projectId]);
            $pdo->commit();
            if ((int) ($_SESSION['project_id'] ?? 0) === $projectId) {
                unset($_SESSION['project_id'], $_SESSION['environment_id']);
                $nextProject = Project::forUser((int) $user['id']);
                if ($nextProject) $_SESSION['project_id'] = (int) $nextProject[0]['id'];
            }
            flash('success', 'Đã xóa dự án và thu hồi toàn bộ API key của dự án. Lịch sử vẫn được giữ để kiểm tra.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không xóa được dự án.');
        }
        redirect('projects');
    }

    public function switchContext()
    {
        $user = $this->user();
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $environmentId = (int) ($_POST['environment_id'] ?? 0);
        if (!$projectId || !Project::findForUser($projectId, $user['id'])) {
            http_response_code(403);
            exit('Bạn không có quyền truy cập dự án này.');
        }
        $environmentQuery = Database::connection()->prepare('SELECT id,`key` FROM environments WHERE project_id=? ORDER BY id');
        $environmentQuery->execute([$projectId]);
        $projectEnvironments = $environmentQuery->fetchAll();
        $validEnvironmentIds = array_map('intval', array_column($projectEnvironments, 'id'));
        if (!$environmentId || !in_array($environmentId, $validEnvironmentIds, true)) {
            $staging = array_values(array_filter($projectEnvironments, static function ($environment) { return strtolower((string) $environment['key']) === 'staging'; }));
            $environmentId = $staging ? (int) $staging[0]['id'] : (int) ($projectEnvironments[0]['id'] ?? 0);
            $submittedEnvironmentId = (int) ($_POST['environment_id'] ?? 0);
            if ($submittedEnvironmentId > 0 && $submittedEnvironmentId !== $environmentId) flash('error', 'Môi trường vừa chọn không thuộc project đó. Đã chọn môi trường thử nghiệm mặc định.');
        }
        $_SESSION['project_id'] = $projectId;
        $_SESSION['environment_id'] = $environmentId;
        $returnTo = trim((string) ($_POST['return_to'] ?? 'dashboard'), '/');
        $allowedRoutes = ['dashboard','flags','rules','versions','members','projects','audit','admin/features'];
        if (!in_array($returnTo, $allowedRoutes, true)) $returnTo = 'dashboard';
        if ($returnTo === 'members' && !Auth::isAdmin($projectId)) $returnTo = 'dashboard';
        if ($returnTo === 'audit' && !Auth::canViewAudit($projectId)) $returnTo = 'dashboard';
        if ($returnTo === 'admin/features' && !Auth::isPlatformAdmin($user)) $returnTo = 'dashboard';
        redirect($returnTo);
    }

    public function rotateApiKey()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireAdmin($projectId);
        $project = Project::find($projectId);
        if (!$project) { flash('error', 'Không tìm thấy dự án hiện tại.'); redirect('projects'); }
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $token = (new \App\Services\ProjectApiKeyService())->rotate($projectId);
            (new \App\Services\AuditLogService())->record($projectId, (int) $user['id'], 'api_key.project_rotated', 'project', (string) $project['key'], null, ['key_prefix' => substr($token, 0, 12)], 'Đổi API key dùng chung cho dự án');
            $pdo->commit();
            $_SESSION['_new_project_api_keys'] = [$projectId => $token];
            flash('success', 'Đã tạo API key dự án mới. Tất cả key cũ đều bị thu hồi; sao chép key mới và cập nhật client cho cả hai môi trường.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không đổi được API key: ' . $exception->getMessage());
        }
        redirect('projects');
    }

}
