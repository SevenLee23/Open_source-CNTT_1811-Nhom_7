<?php
namespace App\Models;

use App\Support\Database;

class Environment extends Record
{
    protected static $table = 'environments';

    public static function forProject($projectId)
    {
        $statement = Database::connection()->prepare('SELECT e.*,v.version_number current_version_number FROM environments e LEFT JOIN versions v ON v.id=e.current_version_id WHERE e.project_id=? ORDER BY e.id');
        $statement->execute([(int) $projectId]);
        return $statement->fetchAll();
    }

    public static function findByProjectAndKey($projectId, $key)
    {
        $statement = Database::connection()->prepare('SELECT * FROM environments WHERE project_id=? AND `key`=? LIMIT 1');
        $statement->execute([(int) $projectId, $key]);
        return $statement->fetch() ?: null;
    }
}
