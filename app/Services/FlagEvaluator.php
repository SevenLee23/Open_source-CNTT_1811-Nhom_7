<?php
namespace App\Services;

use App\Enums\DecisionReason;
use App\Support\Database;

final class FlagEvaluator
{
    public function evaluate($projectId, $environmentId, $userId, array $flagKeys = [], $versionId = null)
    {
        $pdo = Database::connection();
        if (!$versionId) {
            $statement = $pdo->prepare('SELECT current_version_id FROM environments WHERE id=? AND project_id=?');
            $statement->execute([(int) $environmentId, (int) $projectId]);
            $versionId = $statement->fetchColumn();
        }
        if (!$versionId) return ['version' => null, 'results' => []];

        $sql = 'SELECT vf.*,f.`key` flag_key FROM version_flags vf JOIN flags f ON f.id=vf.flag_id JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE vf.version_id=? AND f.is_active=1';
        $params = [(int) $versionId];
        if ($flagKeys) {
            $placeholders = implode(',', array_fill(0, count($flagKeys), '?'));
            $sql .= ' AND f.`key` IN (' . $placeholders . ')';
            $params = array_merge($params, array_values($flagKeys));
        }
        $statement = $pdo->prepare($sql . ' ORDER BY f.`key`');
        $statement->execute($params);
        $configs = $statement->fetchAll();
        $results = [];
        foreach ($configs as $config) {
            $results[$config['flag_key']] = $this->evaluateOne($config, (int) $projectId, (int) $environmentId, (string) $userId);
            $decision = $results[$config['flag_key']];
            $log = $pdo->prepare('INSERT INTO decision_logs (project_id,environment_id,version_id,user_id,flag_key,result,reason_code,matched_rule_id,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
            $log->execute([(int) $projectId, (int) $environmentId, (int) $versionId, (string) $userId, $config['flag_key'], $decision['value'] ? 1 : 0, $decision['reason'], $decision['matched_rule_id']]);
        }
        $version = $pdo->prepare('SELECT version_number FROM versions WHERE id=?');
        $version->execute([(int) $versionId]);
        return ['version' => (int) $version->fetchColumn(), 'version_id' => (int) $versionId, 'results' => $results];
    }

    private function evaluateOne(array $config, $projectId, $environmentId, $userId)
    {
        if ((int) $config['kill_switch'] === 1) return $this->result(false, DecisionReason::KILL_SWITCH, null, null);
        if ($config['forced_value'] !== null) return $this->result((bool) $config['forced_value'], DecisionReason::ACCOUNT_OVERRIDE, null, null);
        $pdo = Database::connection();
        $userRule = $pdo->prepare("SELECT r.id,r.effect FROM rules r JOIN rule_users ru ON ru.rule_id=r.id WHERE r.version_flag_id=? AND r.target_type='user' AND ru.user_id=? ORDER BY r.priority ASC,r.id ASC LIMIT 1");
        $userRule->execute([(int) $config['id'], $userId]);
        $rule = $userRule->fetch();
        if ($rule) return $this->result($rule['effect'] === 'on', DecisionReason::USER_RULE, (int) $rule['id'], null);

        $groupRule = $pdo->prepare("SELECT r.id,r.effect FROM rules r JOIN version_group_members gm ON gm.group_id=r.target_group_id AND gm.version_id=? AND gm.user_id=? WHERE r.version_flag_id=? AND r.target_type='group' ORDER BY r.priority ASC,r.id ASC LIMIT 1");
        $groupRule->execute([(int) $config['version_id'], $userId, (int) $config['id']]);
        $rule = $groupRule->fetch();
        if ($rule) return $this->result($rule['effect'] === 'on', DecisionReason::GROUP_RULE, (int) $rule['id'], null);

        if ((int) $config['rollout_enabled'] === 1) {
            $percentage = max(0.0, min(100.0, (float) $config['rollout_percentage']));
            $bucket = hexdec(substr(hash('sha256', $projectId . ':' . $environmentId . ':' . $config['flag_key'] . ':' . $userId), 0, 8)) % 10000;
            return $this->result($percentage >= 100 || ($percentage > 0 && $bucket < ($percentage * 100)), DecisionReason::PERCENTAGE_ROLLOUT, null, $bucket);
        }

        return $this->result((bool) $config['default_value'], DecisionReason::DEFAULT_VALUE, null, null);
    }

    private function result($value, $reason, $ruleId, $bucket)
    {
        return ['value' => (bool) $value, 'reason' => $reason, 'matched_rule_id' => $ruleId, 'bucket' => $bucket];
    }
}
