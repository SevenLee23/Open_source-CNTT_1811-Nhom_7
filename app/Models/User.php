<?php
namespace App\Models;

use App\Support\Database;

class User extends Record
{
    protected static $table = 'users';

    public static function findByEmail($email)
    {
        $statement = Database::connection()->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $statement->execute([(string) $email]);
        return $statement->fetch() ?: null;
    }

    public static function create($name, $email, $password, $accountType = 'owner', $parentUserId = null, $username = null)
    {
        $statement = Database::connection()->prepare('INSERT INTO users (name,username,email,password,account_type,parent_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)');
        $now = date('Y-m-d H:i:s');
        $email = trim((string) $email);
        $username = trim((string) $username);
        $statement->execute([$name, $username !== '' ? strtolower($username) : null, $email !== '' ? strtolower($email) : null, password_hash($password, PASSWORD_DEFAULT), $accountType, $parentUserId ? (int) $parentUserId : null, $now, $now]);
        return (int) Database::connection()->lastInsertId();
    }
}
