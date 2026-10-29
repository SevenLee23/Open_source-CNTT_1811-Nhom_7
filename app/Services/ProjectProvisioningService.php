<?php
namespace App\Services;

use PDO;

final class ProjectProvisioningService
{
    public function installBuiltInFeatures(PDO $pdo, $projectId, $ownerId)
    {
        $catalog = $pdo->prepare('SELECT id,`key`,name,description FROM feature_catalog WHERE is_active=1 ORDER BY id');
        $catalog->execute();
        $features = $catalog->fetchAll(PDO::FETCH_ASSOC);
        $flagInsert = $pdo->prepare('INSERT INTO flags (project_id,`key`,name,description,catalog_feature_id,is_active,created_at,updated_at) VALUES (?,?,?,?,?,1,NOW(),NOW())');
        $flagIds = [];
        foreach ($features as $feature) {
            $flagInsert->execute([(int) $projectId, $feature['key'], $feature['name'], $feature['description'], (int) $feature['id']]);
            $flagIds[] = (int) $pdo->lastInsertId();
        }

        $environments = $pdo->prepare('SELECT id FROM environments WHERE project_id=? ORDER BY id');
        $environments->execute([(int) $projectId]);
        $nextVersion = $pdo->prepare('SELECT COALESCE(MAX(version_number),0)+1 FROM versions WHERE environment_id=?');
        $versionInsert = $pdo->prepare("INSERT INTO versions (project_id,environment_id,version_number,status,parent_version_id,change_reason,created_by,published_by,created_at,updated_at,published_at) VALUES (?,?,?,'published',?,'Built-in feature catalog',?,?,NOW(),NOW(),NOW())");
        $versionFlags = $pdo->prepare('INSERT INTO version_flags (version_id,flag_id,kill_switch,default_value,rollout_enabled,rollout_percentage,fallback_value,forced_value,created_at,updated_at) VALUES (?,?,0,0,0,0,0,NULL,NOW(),NOW())');
        $setCurrent = $pdo->prepare('UPDATE environments SET current_version_id=?,updated_at=NOW() WHERE id=? AND project_id=?');
        foreach ($environments->fetchAll(PDO::FETCH_COLUMN) as $environmentId) {
            $environmentId = (int) $environmentId;
            $nextVersion->execute([$environmentId]);
            $versionNumber = (int) $nextVersion->fetchColumn();
            $parentQuery = $pdo->prepare('SELECT current_version_id FROM environments WHERE id=? AND project_id=?');
            $parentQuery->execute([$environmentId, (int) $projectId]);
            $parentVersionId = $parentQuery->fetchColumn();
            $versionInsert->execute([(int) $projectId, $environmentId, $versionNumber, $parentVersionId ?: null, (int) $ownerId, (int) $ownerId]);
            $versionId = (int) $pdo->lastInsertId();
            foreach ($flagIds as $flagId) $versionFlags->execute([$versionId, $flagId]);
            $setCurrent->execute([$versionId, $environmentId, (int) $projectId]);
        }
        return $flagIds;
    }
}
