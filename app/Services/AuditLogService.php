<?php
namespace App\Services;

use App\Support\Database;

final class AuditLogService
{
    public function record($projectId, $actorId, $action, $subjectType, $subjectKey = null, $old = null, $new = null, $reason = null, $environmentId = null, $versionId = null)
    {
        $statement = Database::connection()->prepare('INSERT INTO audit_logs (project_id,environment_id,version_id,actor_user_id,action,subject_type,subject_key,old_values,new_values,reason,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
        $statement->execute([(int) $projectId, $environmentId ? (int) $environmentId : null, $versionId ? (int) $versionId : null, $actorId ? (int) $actorId : null, $action, $subjectType, $subjectKey, $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE), $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE), $reason]);
        return (int) Database::connection()->lastInsertId();
    }
}
