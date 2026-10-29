<?php
namespace App\Models;

use App\Support\Database;

class Flag extends Record
{
    protected static $table = 'flags';

    public static function forProject($projectId)
    {
        $statement = Database::connection()->prepare('SELECT f.* FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.is_active=1 ORDER BY f.`key`');
        $statement->execute([(int) $projectId]);
        return $statement->fetchAll();
    }

    public static function findByKey($projectId, $key)
    {
        $statement = Database::connection()->prepare('SELECT f.* FROM flags f JOIN feature_catalog c ON c.id=f.catalog_feature_id AND c.is_active=1 WHERE f.project_id=? AND f.`key`=? AND f.is_active=1 LIMIT 1');
        $statement->execute([(int) $projectId, $key]);
        return $statement->fetch() ?: null;
    }
}
