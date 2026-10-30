<?php
namespace App\Services;

use App\Support\Database;

final class AccountApiKeyService
{
    public function issue($projectId, $accountUserId)
    {
        $owner = Database::connection()->prepare("SELECT p.id FROM projects p JOIN users u ON u.id=p.created_by WHERE p.id=? AND p.is_active=1 AND u.id=? AND u.account_type='owner' AND u.is_active=1");
        $owner->execute([(int) $accountUserId]);
        if (!$owner->fetchColumn()) throw new \DomainException('Chỉ chủ dự án mới được cấp API key.');
        $token = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        Database::connection()->prepare('INSERT INTO api_keys (environment_id,project_id,account_user_id,name,key_prefix,key_hash,created_at) VALUES (NULL,?,?,?,?,?,NOW())')
            ->execute([(int) $projectId, (int) $accountUserId, 'Account read-only key', substr($token, 0, 12), hash('sha256', $token)]);
        return $token;
    }

    public function rotate($projectId, $accountUserId)
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE project_id=? AND account_user_id=? AND environment_id IS NULL AND revoked_at IS NULL')
            ->execute([(int) $projectId, (int) $accountUserId]);
        return $this->issue($projectId, $accountUserId);
    }
}
