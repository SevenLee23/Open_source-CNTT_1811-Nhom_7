<?php
namespace App\Http\Controllers;

class Controller
{
    protected function render($name, array $data = [])
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require \App\Support\Config::get('root') . '/resources/views/' . $name . '.php';
        $content = ob_get_clean();
        require \App\Support\Config::get('root') . '/resources/views/layout.php';
    }

    protected function view($name, array $data = [])
    {
        extract($data, EXTR_SKIP);
        $viewFile = \App\Support\Config::get('root') . '/resources/views/' . $name . '.php';
        if (!is_file($viewFile)) throw new \RuntimeException('Không tìm thấy view ' . $name);
        require $viewFile;
    }

    protected function renderChild($name, array $data = [])
    {
        extract($data, EXTR_SKIP);
        $viewFile = \App\Support\Config::get('root') . '/resources/views/' . $name . '.php';
        if (!is_file($viewFile)) throw new \RuntimeException('Không tìm thấy view ' . $name);
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        require \App\Support\Config::get('root') . '/resources/views/child-layout.php';
    }

    protected function json(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return null;
    }

    protected function inputJson()
    {
        $payload = json_decode(file_get_contents('php://input'), true);
        return is_array($payload) ? $payload : [];
    }

    protected function user()
    {
        return \App\Support\Auth::requireUser();
    }

    protected function projectId()
    {
        $id = \App\Support\Auth::projectId();
        if (!$id) redirect('projects');
        return (int) $id;
    }

    protected function requireAdmin($projectId = null)
    {
        if (!\App\Support\Auth::isAdmin($projectId)) {
            http_response_code(403);
            exit('Bạn cần quyền Admin của dự án.');
        }
    }

    protected function requireEditor($projectId = null)
    {
        $membership = \App\Support\Auth::membership($projectId);
        if (!$membership || !in_array($membership['role'], ['admin','editor'], true)) {
            http_response_code(403);
            exit('Tài khoản chỉ có quyền xem trong dự án này.');
        }
    }

    protected function isTestEnvironment($projectId = null, $environmentId = null)
    {
        $projectId = $projectId ?: $this->projectId();
        $environmentId = $environmentId ?: \App\Support\Auth::environmentId($projectId);
        if (!$environmentId) return false;
        $query = \App\Support\Database::connection()->prepare('SELECT `key` FROM environments WHERE id=? AND project_id=?');
        $query->execute([(int) $environmentId, (int) $projectId]);
        return strtolower((string) $query->fetchColumn()) === 'staging';
    }

    protected function requireTestEnvironment($projectId = null, $environmentId = null)
    {
        if (!$this->isTestEnvironment($projectId, $environmentId)) {
            http_response_code(403);
            exit('Chỉ được thay đổi cấu hình trong môi trường thử nghiệm (staging). Môi trường phát hành chỉ xem.');
        }
    }
}
