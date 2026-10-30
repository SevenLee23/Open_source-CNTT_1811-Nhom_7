<?php
namespace App\Services;

use App\Support\Database;

final class ProjectApiKeyService
{
    public function issue($projectId)
    {
        $token = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        Database::connection()->prepare('INSERT INTO api_keys (environment_id,project_id,account_user_id,name,key_prefix,key_hash,created_at) VALUES (NULL,?,NULL,?,?,?,NOW())')
            ->execute([(int) $projectId, 'Project read-only key', substr($token, 0, 12), hash('sha256', $token)]);
        return $token;
    }

    public function rotate($projectId)
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE revoked_at IS NULL AND (project_id=? OR environment_id IN (SELECT id FROM environments WHERE project_id=?))')
            ->execute([(int) $projectId, (int) $projectId]);
        return $this->issue($projectId);
    }
}
