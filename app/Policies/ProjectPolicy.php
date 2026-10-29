<?php
namespace App\Policies;

use App\Support\Database;

final class ProjectPolicy
{
    public function view($userId, $projectId)
    {
        $query = Database::connection()->prepare('SELECT 1 FROM project_memberships WHERE user_id=? AND project_id=?');
        $query->execute([(int) $userId, (int) $projectId]);
        return (bool) $query->fetchColumn();
    }

    public function manage($userId, $projectId)
    {
        $query = Database::connection()->prepare("SELECT 1 FROM project_memberships WHERE user_id=? AND project_id=? AND role='admin'");
        $query->execute([(int) $userId, (int) $projectId]);
        return (bool) $query->fetchColumn();
    }

    public function edit($userId, $projectId)
    {
        $query = Database::connection()->prepare("SELECT 1 FROM project_memberships WHERE user_id=? AND project_id=? AND role IN ('admin','editor')");
        $query->execute([(int) $userId, (int) $projectId]);
        return (bool) $query->fetchColumn();
    }

    public function viewAudit($userId, $projectId)
    {
        $query = Database::connection()->prepare("SELECT 1 FROM project_memberships WHERE user_id=? AND project_id=? AND (role='admin' OR can_view_audit=1)");
        $query->execute([(int) $userId, (int) $projectId]);
        return (bool) $query->fetchColumn();
    }
}
