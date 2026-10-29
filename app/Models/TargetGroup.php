<?php
namespace App\Models;

use App\Support\Database;

class TargetGroup extends Record
{
    protected static $table = 'target_groups';

    public static function forProject($projectId)
    {
        $statement = Database::connection()->prepare('SELECT g.*,COUNT(gm.id) member_count FROM target_groups g LEFT JOIN group_members gm ON gm.group_id=g.id WHERE g.project_id=? GROUP BY g.id ORDER BY g.name');
        $statement->execute([(int) $projectId]);
        return $statement->fetchAll();
    }
}
