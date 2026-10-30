<?php
namespace App\Models;

use App\Support\Database;

class ProjectMembership extends Record
{
    protected static $table = 'project_memberships';

    public static function members($projectId)
    {
        $statement = Database::connection()->prepare('SELECT m.*,u.name,u.email,u.username,u.account_type FROM project_memberships m JOIN users u ON u.id=m.user_id WHERE m.project_id=? AND u.is_active=1 ORDER BY m.role,u.name');
        $statement->execute([(int) $projectId]);
        return $statement->fetchAll();
    }
}
