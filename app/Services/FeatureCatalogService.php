<?php
namespace App\Services;

use App\Support\Config;
use PDO;

final class FeatureCatalogService
{
    public static function defaults()
    {
        return [
            ['new_dashboard', 'Giao diện tổng quan mới', 'Bật giao diện tổng quan mới cho ứng dụng.'],
            ['beta_search', 'Tìm kiếm nâng cao', 'Bật trải nghiệm tìm kiếm nâng cao.'],
            ['dark_mode', 'Chế độ tối', 'Cho phép ứng dụng sử dụng giao diện tối.'],
            ['team_workspaces', 'Không gian làm việc nhóm', 'Bật không gian cộng tác nhóm.'],
            ['ai_suggestions', 'Gợi ý thông minh', 'Bật các gợi ý thông minh trong ứng dụng.'],
            ['quick_filters', 'Bộ lọc nhanh', 'Bật bộ lọc nhanh trong danh sách.'],
            ['export_v2', 'Xuất dữ liệu phiên bản mới', 'Bật trải nghiệm xuất dữ liệu mới.'],
            ['onboarding_flow', 'Hướng dẫn người dùng mới', 'Bật luồng hướng dẫn người dùng mới.'],
            ['realtime_sync', 'Đồng bộ thời gian thực', 'Bật đồng bộ dữ liệu thời gian thực.'],
            ['billing_portal', 'Cổng thanh toán mới', 'Bật giao diện cổng thanh toán mới.'],
        ];
    }

    public function seedDefaults(PDO $pdo)
    {
        $admin = $pdo->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $admin->execute([Config::get('app.admin_email')]);
        $adminId = $admin->fetchColumn();
        $insert = $pdo->prepare('INSERT IGNORE INTO feature_catalog (`key`,name,description,is_active,created_by,created_at,updated_at) VALUES (?,?,?,1,?,NOW(),NOW())');
        foreach (self::defaults() as $feature) $insert->execute([$feature[0], $feature[1], $feature[2], $adminId ?: null]);
        if ($adminId) $pdo->prepare('UPDATE feature_catalog SET created_by=? WHERE created_by IS NULL')->execute([(int) $adminId]);
    }
}
