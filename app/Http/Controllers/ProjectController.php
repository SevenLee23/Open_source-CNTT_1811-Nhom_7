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
        $keys = [];
        $activeProjectId = Auth::projectId();
        $activeProject = $activeProjectId ? Project::find($activeProjectId) : null;
        $canManageProjectKey = $activeProject && ($user['account_type'] ?? '') === 'owner' && (int) $activeProject['created_by'] === (int) $user['id'];
        if ($activeProjectId) {
            $environments = \App\Models\Environment::forProject($activeProjectId);
            $query = Database::connection()->prepare('SELECT environment_id,key_prefix,last_used_at,created_at FROM api_keys WHERE revoked_at IS NULL AND environment_id IN (SELECT id FROM environments WHERE project_id=?) ORDER BY id DESC');
            $query->execute([$activeProjectId]);
            $keys = $query->fetchAll();
        }
        $newApiKey = $_SESSION['_new_api_key'] ?? null;
        unset($_SESSION['_new_api_key']);
        $newAccountApiKey = $_SESSION['_new_account_api_key'] ?? null;
        unset($_SESSION['_new_account_api_key']);
        $accountKeyQuery = Database::connection()->prepare('SELECT key_prefix,last_used_at,created_at FROM api_keys WHERE project_id=? AND account_user_id=? AND environment_id IS NULL AND revoked_at IS NULL ORDER BY id DESC LIMIT 1');
        $accountKeyQuery->execute([(int) $activeProjectId, (int) $user['id']]);
        $accountApiKey = $accountKeyQuery->fetch() ?: null;
        if (!$accountApiKey && $canManageProjectKey && $activeProjectId && Auth::isAdmin($activeProjectId)) {
            $newAccountApiKey = (new \App\Services\AccountApiKeyService())->issue($activeProjectId, (int) $user['id']);
            $_SESSION['_new_account_api_key'] = $newAccountApiKey;
            (new \App\Services\AuditLogService())->record($activeProjectId, (int) $user['id'], 'account.api_key_created', 'account', $user['email'], null, ['key_prefix' => substr($newAccountApiKey, 0, 12)], 'Cấp API key tài khoản');
            $accountKeyQuery->execute([(int) $activeProjectId, (int) $user['id']]);
            $accountApiKey = $accountKeyQuery->fetch() ?: null;
            unset($_SESSION['_new_account_api_key']);
        }
        $this->render('projects', compact('user','projects','environments','keys','activeProjectId','activeProject','canManageProjectKey','newApiKey','newAccountApiKey','accountApiKey'));
    }

    public function create()
    {
        $user = $this->user();
        if (($user['account_type'] ?? '') !== 'owner') { http_response_code(403); exit('Chỉ tài khoản cha mới được tạo dự án.'); }
        $name = trim((string) ($_POST['name'] ?? ''));
        $key = strtolower(trim((string) ($_POST['key'] ?? '')));
        if ($key === '') $key = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        if ($name === '' || strlen($name) > 160 || !preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/', $key)) {
            flash('error', 'Tên hoặc project key chưa hợp lệ.');
            redirect('projects');
        }
        try {
            $apiKey = null;
            $projectId = Database::transaction(function ($pdo) use ($name, $key, $user, &$apiKey) {
                $now = date('Y-m-d H:i:s');
                $insert = $pdo->prepare('INSERT INTO projects (name,`key`,description,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?)');
                $insert->execute([$name, $key, trim((string) ($_POST['description'] ?? '')), (int) $user['id'], $now, $now]);
                $id = (int) $pdo->lastInsertId();
                $pdo->prepare("INSERT INTO project_memberships (project_id,user_id,role,can_view_audit,created_at,updated_at) VALUES (?,?,'admin',1,?,?)")
                    ->execute([$id, (int) $user['id'], $now, $now]);
                $env = $pdo->prepare('INSERT INTO environments (project_id,name,`key`,created_at,updated_at) VALUES (?,?,?,?,?)');
                $env->execute([$id, 'Staging', 'staging', $now, $now]);
                $env->execute([$id, 'Phát hành', 'production_sim', $now, $now]);
                (new \App\Services\ProjectProvisioningService())->installBuiltInFeatures($pdo, $id, (int) $user['id']);
                $apiKey = (new \App\Services\AccountApiKeyService())->issue($id, (int) $user['id']);
                (new \App\Services\AuditLogService())->record($id, (int) $user['id'], 'project.created', 'project', $key, null, ['name' => $name], 'Tạo dự án');
                return $id;
            });
            $_SESSION['_new_account_api_key'] = $apiKey;
            $_SESSION['project_id'] = $projectId;
            $_SESSION['environment_id'] = 0;
            flash('success', 'Dự án đã được tạo, gồm các tính năng có sẵn và API key riêng.');
            redirect('projects');
        } catch (\Throwable $exception) {
            flash('error', 'Không tạo được dự án: ' . $exception->getMessage());
            redirect('projects');
        }
    }

    public function delete()
    {
        $user = $this->user();
        if (($user['account_type'] ?? '') !== 'owner') { http_response_code(403); exit('Chỉ tài khoản cha mới được xóa dự án.'); }
        $projectId = (int) ($_POST['project_id'] ?? 0);
        $projectQuery = Database::connection()->prepare('SELECT id,name,`key` FROM projects WHERE id=? AND created_by=? AND is_active=1');
        $projectQuery->execute([$projectId, (int) $user['id']]);
        $project = $projectQuery->fetch();
        if (!$project) { flash('error', 'Không tìm thấy dự án thuộc tài khoản cha.'); redirect('projects'); }
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
        $_SESSION['project_id'] = $projectId;
        if ($environmentId) {
            $check = Database::connection()->prepare('SELECT id FROM environments WHERE id=? AND project_id=?');
            $check->execute([$environmentId, $projectId]);
            if (!$check->fetchColumn()) {
                http_response_code(422);
                exit('Môi trường không thuộc dự án đã chọn.');
            }
            $_SESSION['environment_id'] = $environmentId;
        } else {
            $_SESSION['environment_id'] = 0;
        }
        redirect(trim((string) ($_POST['return_to'] ?? 'dashboard'), '/'));
    }

    public function addEnvironment()
    {
        $projectId = $this->projectId();
        $this->requireAdmin($projectId);
        $name = trim((string) ($_POST['name'] ?? ''));
        $key = strtolower(trim((string) ($_POST['key'] ?? '')));
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $key)) {
            flash('error', 'Tên môi trường hoặc key chưa hợp lệ.');
            redirect('projects');
        }
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO environments (project_id,name,`key`,created_at,updated_at) VALUES (?,?,?,?,?)')->execute([$projectId, $name, $key, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        (new \App\Services\AuditLogService())->record($projectId, (int) $this->user()['id'], 'environment.created', 'environment', $key, null, ['name' => $name], 'Tạo môi trường');
        flash('success', 'Đã thêm môi trường.');
        redirect('projects');
    }

    public function rotateApiKey()
    {
        $user = $this->user();
        $projectId = $this->projectId();
        $this->requireAdmin($projectId);
        $environmentId = (int) ($_POST['environment_id'] ?? 0);
        $check = Database::connection()->prepare('SELECT id,`key` FROM environments WHERE id=? AND project_id=?');
        $check->execute([$environmentId, $projectId]);
        $environment = $check->fetch();
        if (!$environment) { flash('error', 'Môi trường không thuộc dự án hiện tại.'); redirect('projects'); }
        $token = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE environment_id=? AND revoked_at IS NULL')->execute([$environmentId]);
            $pdo->prepare('INSERT INTO api_keys (environment_id,name,key_prefix,key_hash,created_at) VALUES (?,?,?,?,NOW())')
                ->execute([$environmentId, 'Environment read key', substr($token, 0, 12), hash('sha256', $token)]);
            (new \App\Services\AuditLogService())->record($projectId, (int) $user['id'], 'api_key.rotated', 'environment', $environment['key'], null, ['key_prefix' => substr($token, 0, 12)], 'Đổi API key môi trường');
            $pdo->commit();
            $_SESSION['_new_api_key'] = $token;
            flash('success', 'Đã tạo API key mới. Key cũ đã bị thu hồi; sao chép key mới ngay bây giờ.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không đổi được API key: ' . $exception->getMessage());
        }
        redirect('projects');
    }

    public function rotateAccountApiKey()
    {
        $user = $this->user();
        if ($user['account_type'] !== 'owner') { http_response_code(403); exit('Chỉ chủ tài khoản mới quản lý API key tài khoản.'); }
        $projectId = $this->projectId();
        $owned = Database::connection()->prepare('SELECT id FROM projects WHERE id=? AND created_by=? AND is_active=1');
        $owned->execute([$projectId, (int) $user['id']]);
        if (!$owned->fetchColumn()) { http_response_code(403); exit('API key chỉ được quản lý bởi chủ sở hữu dự án.'); }
        if (!Auth::isAdmin($projectId)) { http_response_code(403); exit('Bạn không phải quản trị viên của workspace này.'); }
        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            $token = (new \App\Services\AccountApiKeyService())->rotate($projectId, (int) $user['id']);
            (new \App\Services\AuditLogService())->record($projectId, (int) $user['id'], 'account.api_key_rotated', 'account', $user['email'], null, ['key_prefix' => substr($token, 0, 12)], 'Đổi API key tài khoản');
            $pdo->commit();
            $_SESSION['_new_account_api_key'] = $token;
            flash('success', 'Đã đổi API key tài khoản. Hãy lưu key mới ngay; key cũ đã bị thu hồi.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không đổi được API key: ' . $exception->getMessage());
        }
        redirect('projects');
    }
}
