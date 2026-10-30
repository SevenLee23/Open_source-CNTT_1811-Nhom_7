<?php
namespace App\Services;

use App\Support\Database;

final class EnvironmentApiKeyService
{
    public function issue($environmentId)
    {
        $token = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
        Database::connection()->prepare('INSERT INTO api_keys (environment_id,name,key_prefix,key_hash,created_at) VALUES (?,?,?,?,NOW())')
            ->execute([(int) $environmentId, 'Environment read key', substr($token, 0, 12), hash('sha256', $token)]);
        return $token;
    }

    public function rotate($environmentId)
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE api_keys SET revoked_at=NOW() WHERE environment_id=? AND revoked_at IS NULL')
            ->execute([(int) $environmentId]);
        return $this->issue($environmentId);
    }
}
