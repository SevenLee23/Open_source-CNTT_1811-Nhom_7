<?php
namespace App\Http\Controllers;

use App\Support\Auth;

final class AuthController extends Controller
{
    public function form()
    {
        $current = Auth::user();
        if ($current) redirect($current['account_type'] === 'child' ? 'child/dashboard' : 'dashboard');
        $this->view('auth/login', ['error' => flash('error')]);
    }

    public function login()
    {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if (!Auth::login($email, $password, 'owner')) {
            if (Auth::login($email, $password, 'child')) redirect('child/dashboard');
            flash('error', 'Email hoặc mật khẩu không chính xác.');
            redirect('login');
        }
        $projects = \App\Models\Project::forUser((int) $_SESSION['user_id']);
        if ($projects) $_SESSION['project_id'] = (int) $projects[0]['id'];
        redirect($projects ? 'dashboard' : 'projects');
    }

    public function registerForm()
    {
        $current = Auth::user();
        if ($current) redirect($current['account_type'] === 'child' ? 'child/dashboard' : 'dashboard');
        $this->view('auth/register', ['error' => flash('error')]);
    }

    public function register()
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($name === '' || strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            flash('error', 'Nhập tên, email hợp lệ và mật khẩu tối thiểu 8 ký tự.');
            redirect('register');
        }
        $pdo = \App\Support\Database::connection();
        try {
            $pdo->beginTransaction();
            $ownerId = \App\Models\User::create($name, $email, $password, 'owner');
            $now = date('Y-m-d H:i:s');
            $project = $pdo->prepare('INSERT INTO projects (name,`key`,description,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?)');
            $project->execute([$name . ' Workspace', 'account-' . $ownerId, 'Workspace for ' . $email, $ownerId, $now, $now]);
            $projectId = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO project_memberships (project_id,user_id,role,can_view_audit,created_at,updated_at) VALUES (?,?,'admin',1,?,?)")
                ->execute([$projectId, $ownerId, $now, $now]);
            $environment = $pdo->prepare('INSERT INTO environments (project_id,name,`key`,created_at,updated_at) VALUES (?,?,?,?,?)');
            $environment->execute([$projectId, 'Staging', 'staging', $now, $now]);
            $environment->execute([$projectId, 'Phát hành', 'production_sim', $now, $now]);
            (new \App\Services\ProjectProvisioningService())->installBuiltInFeatures($pdo, $projectId, $ownerId);
            $projectApiKey = (new \App\Services\ProjectApiKeyService())->issue($projectId);
            (new \App\Services\AuditLogService())->record($projectId, $ownerId, 'account.registered', 'account', $email, null, ['project_id' => $projectId], 'Đăng ký tài khoản');
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $ownerId;
            $_SESSION['project_id'] = $projectId;
            $env = $pdo->prepare('SELECT id FROM environments WHERE project_id=? AND `key`=\'staging\'');
            $env->execute([$projectId]);
            $_SESSION['environment_id'] = (int) $env->fetchColumn();
            $_SESSION['_new_project_api_keys'] = [$projectId => $projectApiKey];
            flash('success', 'Đăng ký thành công. Đã tạo workspace với một API key dùng chung cho dự án.');
            redirect('projects');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không tạo được tài khoản. Email có thể đã được đăng ký.');
            redirect('register');
        }
    }

    public function logout()
    {
        Auth::logout();
        header('Location: ' . url('login'));
        exit;
    }
}
