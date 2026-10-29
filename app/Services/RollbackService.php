<?php
namespace App\Services;

final class RollbackService
{
    private $versions;

    public function __construct()
    {
        $this->versions = new VersionService();
    }

    public function rollback($environmentId, $targetVersionId, $userId, $reason)
    {
        return $this->versions->rollback($environmentId, $targetVersionId, $userId, $reason);
    }
}
