<?php
namespace App\Http\Controllers;

use App\Services\AuditLogService;
use App\Support\Auth;
use App\Support\Database;

final class AdminFeatureCatalogController extends Controller
{
    public function index()
    {
        $user = $this->user();
        $this->requirePlatformAdmin($user);
        $features = Database::connection()->query('SELECT id,`key`,name,description,is_active,created_at FROM feature_catalog ORDER BY id DESC')->fetchAll();
        $this->render('admin-feature-catalog', compact('user','features'));
    }

    public function create()
    {
        $user = $this->user();
        $this->requirePlatformAdmin($user);
        $key = strtolower(trim((string) ($_POST['key'] ?? '')));
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{1,119}$/', $key) || $name === '' || strlen($name) > 160) {
            flash('error', 'Nhập key hợp lệ và tên tính năng tối đa 160 ký tự.');
            redirect('admin/features');
        }
        try {
            $pdo = Database::connection();
            $pdo->prepare('INSERT INTO feature_catalog (`key`,name,description,is_active,created_by,created_at,updated_at) VALUES (?,?,?,1,?,NOW(),NOW())')
                ->execute([$key, $name, $description, (int) $user['id']]);
            $projectId = Auth::projectId();
            if ($projectId) (new AuditLogService())->record($projectId, (int) $user['id'], 'feature_catalog.created', 'feature_catalog', $key, null, ['name' => $name], 'Admin thêm tính năng vào thư viện');
            flash('success', 'Đã thêm tính năng vào thư viện Admin. Chủ dự án có thể thêm tính năng này vào dự án của họ.');
        } catch (\Throwable $exception) {
            flash('error', 'Không tạo được tính năng. Key có thể đã tồn tại.');
        }
        redirect('admin/features');
    }

    public function deactivate()
    {
        $user = $this->user();
        $this->requirePlatformAdmin($user);
        $featureId = (int) ($_POST['feature_id'] ?? 0);
        $pdo = Database::connection();
        $query = $pdo->prepare('SELECT `key` FROM feature_catalog WHERE id=? AND is_active=1');
        $query->execute([$featureId]);
        $key = $query->fetchColumn();
        if (!$key) { flash('error', 'Không tìm thấy tính năng đang hoạt động.'); redirect('admin/features'); }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE feature_catalog SET is_active=0,updated_at=NOW() WHERE id=?')->execute([$featureId]);
            $pdo->prepare('UPDATE flags SET is_active=0,updated_at=NOW() WHERE catalog_feature_id=?')->execute([$featureId]);
            $projectId = Auth::projectId();
            if ($projectId) (new AuditLogService())->record($projectId, (int) $user['id'], 'feature_catalog.deactivated', 'feature_catalog', $key, ['active' => true], ['active' => false], 'Admin ngừng cung cấp tính năng');
            $pdo->commit();
            flash('success', 'Đã ngừng cung cấp tính năng. Các dự án hiện có không thể bật tính năng này nữa.');
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'Không thể ngừng cung cấp tính năng.');
        }
        redirect('admin/features');
    }

    private function requirePlatformAdmin(array $user)
    {
        if (!Auth::isPlatformAdmin($user)) { http_response_code(403); exit('Chỉ tài khoản Admin hệ thống mới được quản lý thư viện tính năng.'); }
    }
}
