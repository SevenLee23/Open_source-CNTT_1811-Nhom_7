<?php
namespace App\Http\Middleware;

use App\Support\Database;

final class AuthenticateEnvironmentKey
{
    public function authenticate()
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) return null;
        $token = trim($matches[1]);
        if ($token === '' || strlen($token) > 255) return null;
        $query = Database::connection()->prepare("SELECT k.id key_id,k.account_user_id,e.id environment_id,e.`key` environment_key,COALESCE(e.project_id,k.project_id) project_id FROM api_keys k LEFT JOIN environments e ON e.id=k.environment_id LEFT JOIN projects p ON p.id=COALESCE(e.project_id,k.project_id) LEFT JOIN users u ON u.id=k.account_user_id WHERE k.key_hash=? AND k.revoked_at IS NULL AND (e.id IS NOT NULL OR k.project_id IS NOT NULL) AND p.is_active=1 AND (k.account_user_id IS NULL OR u.account_type='owner') LIMIT 1");
        $query->execute([hash('sha256', $token)]);
        $context = $query->fetch();
        if (!$context) return null;
        Database::connection()->prepare('UPDATE api_keys SET last_used_at=NOW() WHERE id=?')->execute([(int) $context['key_id']]);
        return $context;
    }
}
