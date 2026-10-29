<?php
namespace App\Policies;

use App\Support\Database;

final class VersionPolicy
{
    public function view($userId, $projectId)
    {
        return (new ProjectPolicy())->view($userId, $projectId);
    }

    public function mutate($userId, $projectId, $versionId)
    {
        if (!(new ProjectPolicy())->edit($userId, $projectId)) return false;
        $query = Database::connection()->prepare('SELECT status FROM versions WHERE id=? AND project_id=?');
        $query->execute([(int) $versionId, (int) $projectId]);
        return $query->fetchColumn() === 'draft';
    }

    public function publish($userId, $projectId)
    {
        return (new ProjectPolicy())->edit($userId, $projectId);
    }
}
