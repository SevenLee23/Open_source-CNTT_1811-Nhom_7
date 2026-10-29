<?php
namespace App\Models;

use App\Support\Database;

class AuditLog extends Record
{
    protected static $table = 'audit_logs';

    public static function forProject($projectId, array $filters = [])
    {
        $sql = 'SELECT a.*,u.name actor_name,e.name environment_name,v.version_number FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_user_id LEFT JOIN environments e ON e.id=a.environment_id LEFT JOIN versions v ON v.id=a.version_id WHERE a.project_id=?';
        $params = [(int) $projectId];
        foreach (['actor_user_id','environment_id','version_id','subject_key'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') { $sql .= ' AND a.' . $field . '=?'; $params[] = $filters[$field]; }
        }
        if (!empty($filters['from'])) { $sql .= ' AND a.created_at>=?'; $params[] = $filters['from'] . ' 00:00:00'; }
        if (!empty($filters['to'])) { $sql .= ' AND a.created_at<=?'; $params[] = $filters['to'] . ' 23:59:59'; }
        $sql .= ' ORDER BY a.id DESC LIMIT 500';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll();
    }
}
