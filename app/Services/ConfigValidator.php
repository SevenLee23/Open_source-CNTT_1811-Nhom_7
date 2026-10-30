<?php
namespace App\Services;

use App\Support\Database;

final class ConfigValidator
{
    public function validateVersion($versionId)
    {
        $pdo = Database::connection();
        $version = $pdo->prepare('SELECT project_id,status FROM versions WHERE id=?');
        $version->execute([(int) $versionId]);
        $record = $version->fetch();
        if (!$record) throw new \DomainException('Không tìm thấy phiên bản cấu hình.');
        $query = $pdo->prepare('SELECT vf.*,f.`key` FROM version_flags vf JOIN flags f ON f.id=vf.flag_id WHERE vf.version_id=? AND f.project_id=?');
        $query->execute([(int) $versionId, (int) $record['project_id']]);
        $configs = $query->fetchAll();
        if (!$configs) throw new \DomainException('Phiên bản không có feature nào.');
        foreach ($configs as $config) {
            $rules = $pdo->prepare('SELECT r.*,g.project_id group_project_id FROM rules r LEFT JOIN target_groups g ON g.id=r.target_group_id WHERE r.version_flag_id=?');
            $rules->execute([(int) $config['id']]);
            foreach ($rules->fetchAll() as $rule) {
                if (!in_array($rule['target_type'], ['user','group'], true) || !in_array($rule['effect'], ['on','off'], true)) {
                    throw new \DomainException('Quy tắc của ' . $config['key'] . ' có target hoặc effect không hợp lệ.');
                }
                if ($rule['target_type'] === 'group' && ((int) $rule['group_project_id'] !== (int) $record['project_id'] || !$rule['target_group_id'])) {
                    throw new \DomainException('Quy tắc nhóm tham chiếu đến nhóm không hợp lệ.');
                }
                if ($rule['target_type'] === 'user') {
                    $count = $pdo->prepare('SELECT COUNT(*) FROM rule_users WHERE rule_id=?');
                    $count->execute([(int) $rule['id']]);
                    if (!(int) $count->fetchColumn()) throw new \DomainException('Quy tắc người dùng cần ít nhất một mã người dùng.');
                }
            }
        }
        return true;
    }

    public function validateImport(array $payload, $projectId, $environmentId)
    {
        if (($payload['schema_version'] ?? null) !== '1.0') throw new \DomainException('schema_version phải là 1.0.');
        if (!isset($payload['flags']) || !is_array($payload['flags']) || !$payload['flags']) throw new \DomainException('JSON cần có danh sách flags.');
        $pdo = Database::connection();
        $groupKeys = array_column(\App\Models\TargetGroup::forProject($projectId), 'key');
        $groups = $payload['groups'] ?? [];
        if (!is_array($groups)) throw new \DomainException('groups phải là một danh sách.');
        $seenGroups = [];
        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['key']) || !in_array($group['key'], $groupKeys, true)) throw new \DomainException('JSON có nhóm không hợp lệ hoặc không tồn tại.');
            if (isset($seenGroups[$group['key']])) throw new \DomainException('JSON có nhóm trùng: ' . $group['key']);
            $seenGroups[$group['key']] = true;
            if (!isset($group['members']) || !is_array($group['members'])) throw new \DomainException('Mỗi nhóm cần có danh sách members.');
            foreach ($group['members'] as $memberId) if (!is_string($memberId) || $memberId === '' || strlen($memberId) > 100) throw new \DomainException('Mã thành viên nhóm không hợp lệ.');
        }
        $seen = [];
        foreach ($payload['flags'] as $flag) {
            if (!is_array($flag) || empty($flag['key']) || !preg_match('/^[a-z][a-z0-9_]{1,119}$/', (string) $flag['key'])) throw new \DomainException('Mỗi feature cần có key hợp lệ.');
            if (isset($seen[$flag['key']])) throw new \DomainException('JSON có key feature trùng: ' . $flag['key']);
            $seen[$flag['key']] = true;
            if (!array_key_exists('default_value', $flag) || !is_bool($flag['default_value'])) throw new \DomainException('default_value phải là true hoặc false.');
            foreach (['kill_switch','fallback_value'] as $booleanField) if (isset($flag[$booleanField]) && !is_bool($flag[$booleanField])) throw new \DomainException($booleanField . ' phải là true hoặc false.');
            if (array_key_exists('forced_value', $flag) && $flag['forced_value'] !== null && !is_bool($flag['forced_value'])) throw new \DomainException('forced_value phải là true, false hoặc null.');
            $nameQuery = $pdo->prepare('SELECT f.id FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.`key`=? AND f.is_active=1');
            $nameQuery->execute([(int) $projectId, $flag['key']]);
            if (!$nameQuery->fetchColumn()) throw new \DomainException('Feature không tồn tại trong dự án: ' . $flag['key']);
            $rules = $flag['rules'] ?? [];
            if (!is_array($rules)) throw new \DomainException('rules phải là một danh sách.');
            foreach ($rules as $rule) {
                if (!is_array($rule)) throw new \DomainException('Mỗi rule phải là một object.');
                if (!in_array($rule['target_type'] ?? '', ['user','group'], true) || !in_array($rule['effect'] ?? '', ['on','off'], true)) throw new \DomainException('target_type/effect của rule không hợp lệ.');
                $priority = filter_var($rule['priority'] ?? 100, FILTER_VALIDATE_INT);
                if ($priority === false || $priority < 0 || $priority > 1000000) throw new \DomainException('priority của rule không hợp lệ.');
                if (($rule['target_type'] ?? '') === 'group' && !in_array($rule['group_key'] ?? '', $groupKeys, true)) throw new \DomainException('Nhóm không tồn tại: ' . ($rule['group_key'] ?? ''));
                if (($rule['target_type'] ?? '') === 'user') {
                    if (empty($rule['user_ids']) || !is_array($rule['user_ids'])) throw new \DomainException('Rule user cần danh sách user_ids.');
                    foreach ($rule['user_ids'] as $memberId) if (!is_string($memberId) || $memberId === '' || strlen($memberId) > 100) throw new \DomainException('Mã user trong rule không hợp lệ.');
                }
            }
        }
        $expectedQuery = $pdo->prepare('SELECT f.`key` FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.is_active=1');
        $expectedQuery->execute([(int) $projectId]);
        $missing = array_values(array_diff($expectedQuery->fetchAll(\PDO::FETCH_COLUMN), array_keys($seen)));
        if ($missing) throw new \DomainException('File cấu hình thiếu feature của dự án: ' . implode(', ', $missing));
        return true;
    }
}
