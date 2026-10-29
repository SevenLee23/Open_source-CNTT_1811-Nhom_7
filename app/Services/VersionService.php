<?php
namespace App\Services;

use App\Enums\VersionStatus;
use App\Support\Database;
use PDO;

final class VersionService
{
    private $validator;
    private $audit;

    public function __construct()
    {
        $this->validator = new ConfigValidator();
        $this->audit = new AuditLogService();
    }

    public function createDraft($environmentId, $userId, $sourceVersionId = null, $reason = null, $snapshotLiveGroups = true, $forceNew = false)
    {
        return Database::transaction(function (PDO $pdo) use ($environmentId, $userId, $sourceVersionId, $reason, $snapshotLiveGroups, $forceNew) {
            $envQuery = $pdo->prepare('SELECT * FROM environments WHERE id=? FOR UPDATE');
            $envQuery->execute([(int) $environmentId]);
            $environment = $envQuery->fetch();
            if (!$environment) throw new \DomainException('Không tìm thấy môi trường.');
            if (strtolower((string) $environment['key']) !== 'staging') throw new \DomainException('Chỉ được tạo bản nháp trong môi trường thử nghiệm (staging).');
            if (!$forceNew) {
                $existing = $pdo->prepare("SELECT * FROM versions WHERE environment_id=? AND status='draft' ORDER BY version_number DESC LIMIT 1 FOR UPDATE");
                $existing->execute([(int) $environmentId]);
                $draft = $existing->fetch();
                if ($draft) return $draft;
            }

            $sourceId = $sourceVersionId ?: $environment['current_version_id'];
            $source = null;
            if ($sourceId) {
                $sourceQuery = $pdo->prepare('SELECT * FROM versions WHERE id=? AND environment_id=?');
                $sourceQuery->execute([(int) $sourceId, (int) $environmentId]);
                $source = $sourceQuery->fetch();
                if (!$source) throw new \DomainException('Phiên bản nguồn không thuộc môi trường này.');
            }
            $nextQuery = $pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM versions WHERE environment_id=?');
            $nextQuery->execute([(int) $environmentId]);
            $number = (int) $nextQuery->fetchColumn();
            $insert = $pdo->prepare("INSERT INTO versions (project_id,environment_id,version_number,status,parent_version_id,change_reason,created_by,created_at,updated_at) VALUES (?,?,?,'draft',?,?,?,NOW(),NOW())");
            $insert->execute([(int) $environment['project_id'], (int) $environmentId, $number, $source ? (int) $source['id'] : null, $reason, (int) $userId]);
            $draftId = (int) $pdo->lastInsertId();
            if ($source) {
                $this->copyFlagsAndRules($pdo, (int) $source['id'], $draftId);
            } else {
                $initialFlags = $pdo->prepare('SELECT id FROM flags WHERE project_id=? ORDER BY id');
                $initialFlags->execute([(int) $environment['project_id']]);
                $insertFlag = $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,created_at,updated_at) VALUES (?,?,0,0,0,0,0,NOW(),NOW())');
                foreach ($initialFlags->fetchAll() as $flag) $insertFlag->execute([$draftId, (int) $flag['id']]);
            }

            if ($snapshotLiveGroups) {
                $groups = $pdo->prepare('INSERT INTO version_group_members (version_id,group_id,user_id) SELECT ?,gm.group_id,gm.user_id FROM group_members gm JOIN target_groups g ON g.id=gm.group_id WHERE g.project_id=?');
                $groups->execute([$draftId, (int) $environment['project_id']]);
            } elseif ($source) {
                $groups = $pdo->prepare('INSERT INTO version_group_members (version_id,group_id,user_id) SELECT ?,group_id,user_id FROM version_group_members WHERE version_id=?');
                $groups->execute([$draftId, (int) $source['id']]);
            }
            $this->audit->record((int) $environment['project_id'], $userId, 'version.draft_created', 'version', (string) $number, null, ['status' => 'draft'], $reason, (int) $environmentId, $draftId);
            $result = $pdo->prepare('SELECT * FROM versions WHERE id=?');
            $result->execute([$draftId]);
            return $result->fetch();
        });
    }

    private function copyFlagsAndRules(PDO $pdo, $sourceId, $targetId)
    {
        $configs = $pdo->prepare('SELECT * FROM version_flags WHERE version_id=? ORDER BY id');
        $configs->execute([(int) $sourceId]);
        $configInsert = $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,forced_value,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $ruleRead = $pdo->prepare('SELECT * FROM rules WHERE version_flag_id=? ORDER BY id');
        $ruleInsert = $pdo->prepare('INSERT INTO rules (version_flag_id,target_type,effect,priority,target_group_id,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
        $userRead = $pdo->prepare('SELECT user_id FROM rule_users WHERE rule_id=?');
        $userInsert = $pdo->prepare('INSERT INTO rule_users (rule_id,user_id,created_at) VALUES (?,?,NOW())');
        foreach ($configs->fetchAll() as $config) {
            $configInsert->execute([$targetId, $config['flag_id'], $config['kill_switch'], $config['default_value'], $config['rollout_enabled'], $config['rollout_percentage'], $config['fallback_value'], $config['forced_value'], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $newConfigId = (int) $pdo->lastInsertId();
            $ruleRead->execute([(int) $config['id']]);
            foreach ($ruleRead->fetchAll() as $rule) {
                $ruleInsert->execute([$newConfigId, $rule['target_type'], $rule['effect'], $rule['priority'], $rule['target_group_id']]);
                $newRuleId = (int) $pdo->lastInsertId();
                $userRead->execute([(int) $rule['id']]);
                foreach ($userRead->fetchAll() as $user) $userInsert->execute([$newRuleId, $user['user_id']]);
            }
        }
    }

    public function publish($versionId, $userId, $reason = null)
    {
        $this->validator->validateVersion($versionId);
        return Database::transaction(function (PDO $pdo) use ($versionId, $userId, $reason) {
            $query = $pdo->prepare('SELECT v.*,e.`key` environment_key FROM versions v JOIN environments e ON e.id=v.environment_id WHERE v.id=? FOR UPDATE');
            $query->execute([(int) $versionId]);
            $version = $query->fetch();
            if (!$version) throw new \DomainException('Không tìm thấy phiên bản.');
            if (strtolower((string) $version['environment_key']) !== 'staging') throw new \DomainException('Môi trường phát hành chỉ xem; chỉ phát hành phiên bản trong Staging.');
            if ($version['status'] !== VersionStatus::DRAFT) throw new \DomainException('Bản đã phát hành là bất biến. Hãy tạo phiên bản mới.');
            $pdo->prepare("UPDATE versions SET status='published',published_by=?,published_at=NOW(),change_reason=COALESCE(?,change_reason),updated_at=NOW() WHERE id=?")
                ->execute([(int) $userId, $reason, (int) $versionId]);
            $pdo->prepare('UPDATE environments SET current_version_id=?,updated_at=NOW() WHERE id=? AND project_id=?')
                ->execute([(int) $versionId, (int) $version['environment_id'], (int) $version['project_id']]);
            $this->audit->record((int) $version['project_id'], $userId, 'version.published', 'version', (string) $version['version_number'], ['status' => 'draft'], ['status' => 'published'], $reason ?: $version['change_reason'], (int) $version['environment_id'], (int) $versionId);
            $query = $pdo->prepare('SELECT * FROM versions WHERE id=?');
            $query->execute([(int) $versionId]);
            return $query->fetch();
        });
    }

    public function rollback($environmentId, $targetVersionId, $userId, $reason)
    {
        if (trim((string) $reason) === '') throw new \DomainException('Cần ghi lý do rollback.');
        $draft = $this->createDraft($environmentId, $userId, $targetVersionId, 'Rollback: ' . $reason, false, true);
        Database::connection()->prepare('UPDATE versions SET rollback_of_id=? WHERE id=?')->execute([(int) $targetVersionId, (int) $draft['id']]);
        $published = $this->publish((int) $draft['id'], $userId, 'Rollback: ' . $reason);
        $this->audit->record((int) $published['project_id'], $userId, 'version.rolled_back', 'version', (string) $published['version_number'], ['rollback_of_id' => (int) $targetVersionId], ['new_version_id' => (int) $published['id']], $reason, (int) $environmentId, (int) $published['id']);
        return $published;
    }

    public function compare($leftVersionId, $rightVersionId)
    {
        $pdo = Database::connection();
        $left = $pdo->prepare('SELECT * FROM versions WHERE id=?');
        $left->execute([(int) $leftVersionId]);
        $leftVersion = $left->fetch();
        $right = $pdo->prepare('SELECT * FROM versions WHERE id=?');
        $right->execute([(int) $rightVersionId]);
        $rightVersion = $right->fetch();
        if (!$leftVersion || !$rightVersion || (int) $leftVersion['environment_id'] !== (int) $rightVersion['environment_id']) throw new \DomainException('Chọn hai phiên bản cùng một môi trường.');
        return ['left' => $leftVersion, 'right' => $rightVersion, 'diff' => $this->snapshotMap($leftVersionId, $rightVersionId)];
    }

    private function snapshotMap($beforeId, $afterId)
    {
        $pdo = Database::connection();
        $read = $pdo->prepare('SELECT vf.*,f.`key` flag_key FROM version_flags vf JOIN flags f ON f.id=vf.flag_id WHERE vf.version_id=?');
        $ruleRead = $pdo->prepare('SELECT r.id,r.target_type,r.effect,r.priority,g.`key` group_key FROM rules r LEFT JOIN target_groups g ON g.id=r.target_group_id WHERE r.version_flag_id=? ORDER BY r.priority,r.id');
        $userRead = $pdo->prepare('SELECT user_id FROM rule_users WHERE rule_id=? ORDER BY user_id');
        $read->execute([(int) $beforeId]);
        $before = [];
        foreach ($read->fetchAll() as $row) {
            $row['rules'] = $this->rulesSnapshot($ruleRead, $userRead, (int) $row['id']);
            $before[$row['flag_key']] = $row;
        }
        $read->execute([(int) $afterId]);
        $after = [];
        foreach ($read->fetchAll() as $row) {
            $row['rules'] = $this->rulesSnapshot($ruleRead, $userRead, (int) $row['id']);
            $after[$row['flag_key']] = $row;
        }
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $fields = ['kill_switch','forced_value','default_value','rollout_enabled','rollout_percentage','fallback_value','rules'];
        $diff = [];
        foreach ($keys as $key) {
            $changes = [];
            foreach ($fields as $field) if (($before[$key][$field] ?? null) != ($after[$key][$field] ?? null)) $changes[$field] = ['before' => $before[$key][$field] ?? null, 'after' => $after[$key][$field] ?? null];
            if ($changes) $diff[$key] = $changes;
        }
        $groupRead = $pdo->prepare('SELECT g.`key` group_key,vgm.user_id FROM version_group_members vgm JOIN target_groups g ON g.id=vgm.group_id WHERE vgm.version_id=? ORDER BY g.`key`,vgm.user_id');
        $groups = [];
        foreach ([$beforeId, $afterId] as $versionId) {
            $groupRead->execute([(int) $versionId]);
            $snapshot = [];
            foreach ($groupRead->fetchAll() as $member) $snapshot[$member['group_key']][] = $member['user_id'];
            $groups[] = $snapshot;
        }
        if ($groups[0] != $groups[1]) $diff['@groups'] = ['members' => ['before' => $groups[0], 'after' => $groups[1]]];
        return $diff;
    }

    private function rulesSnapshot($ruleRead, $userRead, $versionFlagId)
    {
        $ruleRead->execute([(int) $versionFlagId]);
        $rules = [];
        foreach ($ruleRead->fetchAll() as $rule) {
            $entry = ['target_type' => $rule['target_type'], 'effect' => $rule['effect'], 'priority' => (int) $rule['priority']];
            if ($rule['target_type'] === 'group') $entry['group'] = $rule['group_key'];
            if ($rule['target_type'] === 'user') {
                $userRead->execute([(int) $rule['id']]);
                $entry['user_ids'] = array_column($userRead->fetchAll(), 'user_id');
            }
            $rules[] = $entry;
        }
        return $rules;
    }
}
