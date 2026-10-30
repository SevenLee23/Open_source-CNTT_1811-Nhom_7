<?php
namespace App\Models;

use App\Support\Database;

class Version extends Record
{
    protected static $table = 'versions';

    public static function forEnvironment($environmentId)
    {
        $statement = Database::connection()->prepare('SELECT v.*,u.name created_by_name,publisher.name published_by_name,p.version_number parent_number FROM versions v JOIN users u ON u.id=v.created_by LEFT JOIN users publisher ON publisher.id=v.published_by LEFT JOIN versions p ON p.id=v.parent_version_id WHERE v.environment_id=? ORDER BY v.version_number DESC');
        $statement->execute([(int) $environmentId]);
        return $statement->fetchAll();
    }

    public static function current($environmentId)
    {
        $statement = Database::connection()->prepare('SELECT v.* FROM environments e JOIN versions v ON v.id=e.current_version_id WHERE e.id=?');
        $statement->execute([(int) $environmentId]);
        return $statement->fetch() ?: null;
    }

    public static function draft($environmentId, $userId)
    {
        $statement = Database::connection()->prepare("SELECT * FROM versions WHERE environment_id=? AND status='draft' ORDER BY version_number DESC LIMIT 1");
        $statement->execute([(int) $environmentId]);
        return $statement->fetch() ?: null;
    }
}
