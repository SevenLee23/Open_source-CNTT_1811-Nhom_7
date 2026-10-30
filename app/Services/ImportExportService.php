<?php
namespace App\Services;

use App\Support\Database;
use PDO;

final class ImportExportService
{
    private $validator;

    public function __construct()
    {
        $this->validator = new ConfigValidator();
    }

    public function export($projectId, $environmentId, $versionId)
    {
        $pdo = Database::connection();
        $header = $pdo->prepare('SELECT p.`key` project_key,e.`key` environment_key,v.version_number,v.status,v.change_reason FROM versions v JOIN projects p ON p.id=v.project_id JOIN environments e ON e.id=v.environment_id WHERE v.id=? AND v.project_id=? AND v.environment_id=?');
        $header->execute([(int) $versionId, (int) $projectId, (int) $environmentId]);
        $meta = $header->fetch();
        if (!$meta) throw new \DomainException('Phiên bản không thuộc dự án hoặc môi trường đang chọn.');

        $flagsQuery = $pdo->prepare('SELECT vf.*,f.`key`,f.name,f.description FROM version_flags vf JOIN flags f ON f.id=vf.flag_id JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE vf.version_id=? AND f.is_active=1 ORDER BY f.`key`');
        $flagsQuery->execute([(int) $versionId]);
        $flags = [];
        foreach ($flagsQuery->fetchAll() as $flag) {
            $rulesQuery = $pdo->prepare('SELECT r.*,g.`key` group_key FROM rules r LEFT JOIN target_groups g ON g.id=r.target_group_id WHERE r.version_flag_id=? ORDER BY r.priority,r.id');
            $rulesQuery->execute([(int) $flag['id']]);
            $rules = [];
            foreach ($rulesQuery->fetchAll() as $rule) {
                $entry = ['target_type' => $rule['target_type'], 'effect' => $rule['effect'], 'priority' => (int) $rule['priority']];
                if ($rule['target_type'] === 'group') $entry['group_key'] = $rule['group_key'];
                if ($rule['target_type'] === 'user') {
                    $users = $pdo->prepare('SELECT user_id FROM rule_users WHERE rule_id=? ORDER BY user_id');
                    $users->execute([(int) $rule['id']]);
                    $entry['user_ids'] = array_column($users->fetchAll(), 'user_id');
                }
                $rules[] = $entry;
            }
            $flags[] = [
                'key' => $flag['key'], 'name' => $flag['name'], 'description' => $flag['description'],
                'kill_switch' => (bool) $flag['kill_switch'], 'default_value' => (bool) $flag['default_value'],
                'forced_value' => $flag['forced_value'] === null ? null : (bool) $flag['forced_value'],
                'fallback_value' => (bool) $flag['default_value'], 'rules' => $rules,
            ];
        }
        $groupQuery = $pdo->prepare('SELECT g.`key`,g.name,vgm.user_id FROM version_group_members vgm JOIN target_groups g ON g.id=vgm.group_id WHERE vgm.version_id=? ORDER BY g.`key`,vgm.user_id');
        $groupQuery->execute([(int) $versionId]);
        $groups = [];
        foreach ($groupQuery->fetchAll() as $row) {
            if (!isset($groups[$row['key']])) $groups[$row['key']] = ['key' => $row['key'], 'name' => $row['name'], 'members' => []];
            $groups[$row['key']]['members'][] = $row['user_id'];
        }
        return [
            'schema_version' => '1.0',
            'project' => $meta['project_key'],
            'environment' => $meta['environment_key'],
            'version' => ['number' => (int) $meta['version_number'], 'status' => $meta['status'], 'change_reason' => $meta['change_reason']],
            'groups' => array_values($groups),
            'flags' => $flags,
        ];
    }

    public function importDraft(array $payload, $projectId, $environmentId, $userId, $reason = null)
    {
        $this->validator->validateImport($payload, $projectId, $environmentId);
        $pdo = Database::connection();
        $env = $pdo->prepare('SELECT `key` FROM environments WHERE id=? AND project_id=?');
        $env->execute([(int) $environmentId, (int) $projectId]);
        $envKey = $env->fetchColumn();
        if (!$envKey || ($payload['environment'] ?? null) !== $envKey) throw new \DomainException('Môi trường trong file JSON không khớp môi trường đang chọn.');
        $project = $pdo->prepare('SELECT `key` FROM projects WHERE id=?');
        $project->execute([(int) $projectId]);
        if (($payload['project'] ?? null) !== $project->fetchColumn()) throw new \DomainException('Dự án trong file JSON không khớp dự án đang chọn.');

        $versionService = new VersionService();
        $draft = $versionService->createDraft($environmentId, $userId, null, $reason ?: 'Nhập cấu hình JSON', true);
        Database::transaction(function (PDO $pdo) use ($payload, $projectId, $environmentId, $userId, $reason, $draft) {
            $lock = $pdo->prepare("SELECT status FROM versions WHERE id=? FOR UPDATE");
            $lock->execute([(int) $draft['id']]);
            if ($lock->fetchColumn() !== 'draft') throw new \DomainException('Chỉ có thể nhập vào bản nháp.');
            $pdo->prepare('DELETE FROM version_flags WHERE version_id=?')->execute([(int) $draft['id']]);
            $pdo->prepare('DELETE FROM version_group_members WHERE version_id=?')->execute([(int) $draft['id']]);
            $groupIds = [];
            $groupQuery = $pdo->prepare('SELECT id FROM target_groups WHERE project_id=? AND `key`=?');
            $memberInsert = $pdo->prepare('INSERT INTO version_group_members (version_id,group_id,user_id) VALUES (?,?,?)');
            foreach (($payload['groups'] ?? []) as $group) {
                $groupQuery->execute([(int) $projectId, $group['key']]);
                $groupId = (int) $groupQuery->fetchColumn();
                if (!$groupId) throw new \DomainException('Nhóm trong JSON không tồn tại: ' . $group['key']);
                $groupIds[$group['key']] = $groupId;
                foreach (($group['members'] ?? []) as $memberId) $memberInsert->execute([(int) $draft['id'], $groupId, (string) $memberId]);
            }

            $flagQuery = $pdo->prepare('SELECT f.id FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.`key`=? AND f.is_active=1');
            $configInsert = $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,forced_value,created_at,updated_at) VALUES (?,?,?, ?,0,0,?,?,?,?)');
            $ruleInsert = $pdo->prepare('INSERT INTO rules (version_flag_id,target_type,effect,priority,target_group_id,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
            $userInsert = $pdo->prepare('INSERT INTO rule_users (rule_id,user_id,created_at) VALUES (?,?,NOW())');
            foreach ($payload['flags'] as $flag) {
                $flagQuery->execute([(int) $projectId, $flag['key']]);
                $flagId = (int) $flagQuery->fetchColumn();
                if (!$flagId) throw new \DomainException('Feature không còn tồn tại: ' . $flag['key']);
                $forced = array_key_exists('forced_value', $flag) && $flag['forced_value'] !== null ? (!empty($flag['forced_value']) ? 1 : 0) : null;
                $configInsert->execute([(int) $draft['id'], $flagId, 0, !empty($flag['default_value']) ? 1 : 0, !empty($flag['default_value']) ? 1 : 0, $forced, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                $configId = (int) $pdo->lastInsertId();
                foreach (($flag['rules'] ?? []) as $rule) {
                    $groupId = ($rule['target_type'] ?? '') === 'group' ? ($groupIds[$rule['group_key']] ?? null) : null;
                    if (($rule['target_type'] ?? '') === 'group' && !$groupId) throw new \DomainException('Nhóm rule chưa có snapshot: ' . ($rule['group_key'] ?? ''));
                    $ruleInsert->execute([$configId, $rule['target_type'], $rule['effect'], (int) ($rule['priority'] ?? 100), $groupId]);
                    $ruleId = (int) $pdo->lastInsertId();
                    if (($rule['target_type'] ?? '') === 'user') foreach ($rule['user_ids'] as $userIdValue) $userInsert->execute([$ruleId, (string) $userIdValue]);
                }
            }
            (new AuditLogService())->record((int) $projectId, $userId, 'configuration.imported', 'version', (string) $draft['version_number'], null, ['flag_count' => count($payload['flags'])], $reason ?: 'Nhập JSON', (int) $environmentId, (int) $draft['id']);
        });
        return $draft;
    }
}
