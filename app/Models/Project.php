<?php
namespace App\Models;

use App\Support\Database;

class Project extends Record
{
    protected static $table = 'projects';

    public static function forUser($userId)
    {
        $statement = Database::connection()->prepare('SELECT p.*,m.role FROM projects p JOIN project_memberships m ON m.project_id=p.id WHERE m.user_id=? AND p.is_active=1 ORDER BY p.name');
        $statement->execute([(int) $userId]);
        return $statement->fetchAll();
    }

    public static function findForUser($id, $userId)
    {
        $statement = Database::connection()->prepare('SELECT p.* FROM projects p JOIN project_memberships m ON m.project_id=p.id WHERE p.id=? AND m.user_id=? AND p.is_active=1');
        $statement->execute([(int) $id, (int) $userId]);
        return $statement->fetch() ?: null;
    }
}
