<?php
namespace App\Policies;

use App\Support\Auth;

final class FlagPolicy
{
    public function view($userId, $projectId)
    {
        return (new ProjectPolicy())->view($userId, $projectId);
    }

    public function update($userId, $projectId, $flagId)
    {
        return Auth::canEditFlag($flagId, $projectId);
    }
}
