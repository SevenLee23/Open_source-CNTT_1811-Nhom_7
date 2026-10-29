<?php
namespace App\Support;

final class Auth
{
    public static function user()
    {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if (!$id) return null;
        $statement = Database::connection()->prepare('SELECT id,name,username,email,account_type,parent_user_id,is_active FROM users WHERE id=? AND is_active=1');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public static function login($identifier, $password, $accountType = 'owner')
    {
        $sql = $accountType === 'owner'
            ? "SELECT id,password FROM users WHERE email=? AND account_type IN ('owner','member') AND is_active=1"
            : 'SELECT id,password FROM users WHERE (username=? OR email=?) AND account_type=? AND is_active=1';
        $statement = Database::connection()->prepare($sql);
        $identifier = strtolower(trim((string) $identifier));
        $statement->execute($accountType === 'owner' ? [$identifier] : [$identifier, $identifier, $accountType]);
        $matched = false;
        foreach ($statement->fetchAll() as $user) if (password_verify((string) $password, $user['password'])) { $matched = $user; break; }
        if (!$matched) return false;
        session_regenerate_id(true);
        unset($_SESSION['project_id'], $_SESSION['environment_id']);
        $_SESSION['user_id'] = (int) $matched['id'];
        return true;
    }

    public static function logout()
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function requireUser()
    {
        $user = self::user();
        if (!$user) redirect('login');
        return $user;
    }

    public static function membership($projectId = null)
    {
        $user = self::user();
        if (!$user) return null;
        $projectId = $projectId ?: self::projectId();
        if (!$projectId) return null;
        $statement = Database::connection()->prepare('SELECT * FROM project_memberships WHERE project_id=? AND user_id=?');
        $statement->execute([$projectId, $user['id']]);
        return $statement->fetch() ?: null;
    }

    public static function projectId()
    {
        $user = self::user();
        if (!$user) return null;
        $stored = (int) ($_SESSION['project_id'] ?? 0);
        if ($stored && self::membership($stored) && \App\Models\Project::findForUser($stored, (int) $user['id'])) return $stored;
        $statement = Database::connection()->prepare('SELECT m.project_id FROM project_memberships m JOIN projects p ON p.id=m.project_id WHERE m.user_id=? AND p.is_active=1 ORDER BY m.id LIMIT 1');
        $statement->execute([$user['id']]);
        $projectId = $statement->fetchColumn();
        if ($projectId) $_SESSION['project_id'] = (int) $projectId;
        return $projectId ? (int) $projectId : null;
    }

    public static function environmentId($projectId = null)
    {
        $projectId = $projectId ?: self::projectId();
        if (!$projectId) return null;
        $stored = (int) ($_SESSION['environment_id'] ?? 0);
        $pdo = Database::connection();
        if ($stored) {
            $check = $pdo->prepare('SELECT id FROM environments WHERE id=? AND project_id=?');
            $check->execute([$stored, $projectId]);
            if ($check->fetchColumn()) return $stored;
        }
        $statement = $pdo->prepare('SELECT id FROM environments WHERE project_id=? ORDER BY id LIMIT 1');
        $statement->execute([$projectId]);
        $id = $statement->fetchColumn();
        if ($id) $_SESSION['environment_id'] = (int) $id;
        return $id ? (int) $id : null;
    }

    public static function canEditFlag($flagId, $projectId = null)
    {
        $membership = self::membership($projectId);
        if (!$membership) return false;
        if ($membership['role'] === 'admin') return true;
        if ($membership['role'] !== 'editor') return false;
        $statement = Database::connection()->prepare('SELECT can_edit FROM membership_flag_permissions WHERE membership_id=? AND flag_id=?');
        $statement->execute([$membership['id'], $flagId]);
        return (bool) $statement->fetchColumn();
    }

    public static function isAdmin($projectId = null)
    {
        $membership = self::membership($projectId);
        return $membership && $membership['role'] === 'admin';
    }

    public static function canViewAudit($projectId = null)
    {
        $membership = self::membership($projectId);
        return $membership && ($membership['role'] === 'admin' || (bool) $membership['can_view_audit']);
    }

    public static function isChild()
    {
        $user = self::user();
        return $user && $user['account_type'] === 'child';
    }

    public static function isPlatformAdmin($user = null)
    {
        $user = $user ?: self::user();
        if (!$user || ($user['account_type'] ?? '') !== 'owner') return false;
        return strtolower((string) $user['email']) === strtolower((string) Config::get('app.admin_email'));
    }
}
