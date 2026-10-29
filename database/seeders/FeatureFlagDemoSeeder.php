<?php
namespace Database\Seeders;

use App\Support\Config;
use PDO;

final class FeatureFlagDemoSeeder
{
    public function run(PDO $pdo)
    {
        $pdo->beginTransaction();
        try {
            $adminEmail = Config::get('app.admin_email');
            $adminPassword = Config::get('app.admin_password');
            $statement = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $statement->execute([$adminEmail]);
            $adminId = $statement->fetchColumn();
            if (!$adminId) {
                $insert = $pdo->prepare('INSERT INTO users (name,email,password,created_at,updated_at) VALUES (?,?,?,?,?)');
                $now = date('Y-m-d H:i:s');
                $insert->execute(['Project Admin', $adminEmail, password_hash($adminPassword, PASSWORD_DEFAULT), $now, $now]);
                $adminId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('INSERT IGNORE INTO projects (name,`key`,description,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?)')
                ->execute(['FeatureLab', 'featurelab', 'Demo project for feature flag management.', $adminId, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $projectId = (int) $pdo->query("SELECT id FROM projects WHERE `key`='featurelab'")->fetchColumn();
            $pdo->prepare("INSERT IGNORE INTO project_memberships (project_id,user_id,role,can_view_audit,created_at,updated_at) VALUES (?,?, 'admin',1,NOW(),NOW())")
                ->execute([$projectId, $adminId]);

            $environments = [
                ['Staging', 'staging', Config::get('app.demo_keys.staging')],
                ['Phát hành', 'production_sim', Config::get('app.demo_keys.production_sim')],
            ];
            foreach ($environments as $environment) {
                $pdo->prepare('INSERT IGNORE INTO environments (project_id,name,`key`,created_at,updated_at) VALUES (?,?,?,?,?)')
                    ->execute([$projectId, $environment[0], $environment[1], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            }

            $flagSpecs = [
                ['new_dashboard','New dashboard experience',0,100,0],
                ['beta_search','Beta search engine',0,25,0],
                ['dark_mode','Dark mode',1,100,1],
                ['team_workspaces','Team workspaces',0,50,0],
                ['ai_suggestions','AI-powered suggestions',0,0,0],
                ['quick_filters','Quick filters',1,100,1],
                ['export_v2','Export v2',0,0,0],
                ['onboarding_flow','New onboarding flow',0,40,0],
                ['realtime_sync','Real-time sync',1,100,1],
                ['billing_portal','Billing portal refresh',0,0,0],
            ];
            $flagInsert = $pdo->prepare('INSERT IGNORE INTO flags (project_id,`key`,name,description,created_at,updated_at) VALUES (?,?,?,?,?,?)');
            foreach ($flagSpecs as $spec) $flagInsert->execute([$projectId, $spec[0], $spec[1], 'Seeded sample feature flag.', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);

            $groupSpecs = [['internal_testers','Internal testers'],['beta_users','Beta users'],['general_users','General users']];
            $groupInsert = $pdo->prepare('INSERT IGNORE INTO target_groups (project_id,`key`,name,created_at,updated_at) VALUES (?,?,?,?,?)');
            foreach ($groupSpecs as $spec) $groupInsert->execute([$projectId, $spec[0], $spec[1], date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $groupIds = [];
            foreach ($groupSpecs as $spec) $groupIds[$spec[0]] = (int) $pdo->query('SELECT id FROM target_groups WHERE project_id=' . $projectId . ' AND `key`=' . $pdo->quote($spec[0]))->fetchColumn();

            $memberInsert = $pdo->prepare('INSERT IGNORE INTO group_members (group_id,user_id,created_at) VALUES (?,?,?)');
            for ($i = 1; $i <= 1000; $i++) {
                $userId = sprintf('USER_%04d', $i);
                if ($i <= 100) $memberInsert->execute([$groupIds['internal_testers'], $userId, date('Y-m-d H:i:s')]);
                if ($i <= 250) $memberInsert->execute([$groupIds['beta_users'], $userId, date('Y-m-d H:i:s')]);
                if ($i > 250) $memberInsert->execute([$groupIds['general_users'], $userId, date('Y-m-d H:i:s')]);
            }

            $versions = [];
            $versionInsert = $pdo->prepare("INSERT IGNORE INTO versions (project_id,environment_id,version_number,status,change_reason,created_by,published_by,created_at,updated_at,published_at) VALUES (?,?,1,'published','Initial sample configuration',?,?,NOW(),NOW(),NOW())");
            foreach ($environments as $environment) {
                $envId = (int) $pdo->query('SELECT id FROM environments WHERE project_id=' . $projectId . ' AND `key`=' . $pdo->quote($environment[1]))->fetchColumn();
                $versionInsert->execute([$projectId, $envId, $adminId, $adminId]);
                $versionId = (int) $pdo->query('SELECT id FROM versions WHERE environment_id=' . $envId . ' AND version_number=1')->fetchColumn();
                $versions[$environment[1]] = [$envId, $versionId];
                $pdo->prepare('UPDATE environments SET current_version_id=? WHERE id=?')->execute([$versionId, $envId]);

                $versionFlagInsert = $pdo->prepare('INSERT IGNORE INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,created_at,updated_at) VALUES (?,?,0,?,?,?,?,NOW(),NOW())');
                foreach ($flagSpecs as $spec) {
                    $flagId = (int) $pdo->query('SELECT id FROM flags WHERE project_id=' . $projectId . ' AND `key`=' . $pdo->quote($spec[0]))->fetchColumn();
                    $versionFlagInsert->execute([$versionId, $flagId, $spec[2], $spec[3] > 0 ? 1 : 0, $spec[3], $spec[4]]);
                }
                $snapshot = $pdo->prepare('INSERT IGNORE INTO version_group_members (version_id,group_id,user_id) SELECT ?,group_id,user_id FROM group_members WHERE group_id=?');
                foreach ($groupIds as $groupId) $snapshot->execute([$versionId, $groupId]);
            }

            $stagingVersion = $versions['staging'][1];
            $findConfig = $pdo->prepare('SELECT vf.id FROM version_flags vf JOIN flags f ON f.id=vf.flag_id WHERE vf.version_id=? AND f.`key`=?');
            $findConfig->execute([$stagingVersion, 'new_dashboard']);
            $newDashboardConfig = (int) $findConfig->fetchColumn();
            $existingRule = $pdo->prepare("SELECT r.id FROM rules r JOIN rule_users ru ON ru.rule_id=r.id WHERE r.version_flag_id=? AND r.target_type='user' AND ru.user_id=? LIMIT 1");
            $existingRule->execute([$newDashboardConfig, 'USER_0001']);
            $ruleId = (int) $existingRule->fetchColumn();
            if (!$ruleId) {
                $pdo->prepare("INSERT INTO rules (version_flag_id,target_type,effect,priority,created_at,updated_at) VALUES (?,'user','on',1,NOW(),NOW())")->execute([$newDashboardConfig]);
                $ruleId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO rule_users (rule_id,user_id,created_at) VALUES (?,?,NOW())')->execute([$ruleId, 'USER_0001']);
            }

            $findConfig->execute([$stagingVersion, 'beta_search']);
            $betaConfig = (int) $findConfig->fetchColumn();
            $existingGroupRule = $pdo->prepare("SELECT id FROM rules WHERE version_flag_id=? AND target_type='group' AND target_group_id=? LIMIT 1");
            $existingGroupRule->execute([$betaConfig, $groupIds['beta_users']]);
            if (!$existingGroupRule->fetchColumn()) $pdo->prepare("INSERT INTO rules (version_flag_id,target_type,effect,priority,target_group_id,created_at,updated_at) VALUES (?,'group','on',10,?,NOW(),NOW())")
                ->execute([$betaConfig, $groupIds['beta_users']]);

            $keyInsert = $pdo->prepare('INSERT IGNORE INTO api_keys (environment_id,name,key_prefix,key_hash,created_at) VALUES (?,?,?,?,NOW())');
            foreach ($environments as $environment) {
                $envId = $versions[$environment[1]][0];
                $key = $environment[2];
                $keyInsert->execute([$envId, 'Demo read-only key', substr($key, 0, 12), hash('sha256', $key)]);
            }

            $pdo->commit();
            return ['staging' => $environments[0][2], 'production_sim' => $environments[1][2]];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}
