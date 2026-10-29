<?php
namespace App\Models;

use App\Support\Database;

class VersionFlag extends Record
{
    protected static $table = 'version_flags';

    public static function snapshot($versionId)
    {
        $statement = Database::connection()->prepare('SELECT vf.*,f.`key`,f.name,f.description FROM version_flags vf JOIN flags f ON f.id=vf.flag_id WHERE vf.version_id=? ORDER BY f.`key`');
        $statement->execute([(int) $versionId]);
        return $statement->fetchAll();
    }

    public static function forKey($versionId, $flagKey)
    {
        $statement = Database::connection()->prepare('SELECT vf.*,f.`key`,f.name FROM version_flags vf JOIN flags f ON f.id=vf.flag_id WHERE vf.version_id=? AND f.`key`=? LIMIT 1');
        $statement->execute([(int) $versionId, $flagKey]);
        return $statement->fetch() ?: null;
    }
}
