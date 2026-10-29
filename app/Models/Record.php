<?php
namespace App\Models;

use App\Support\Database;

abstract class Record
{
    protected static $table;

    public static function find($id)
    {
        $statement = Database::connection()->prepare('SELECT * FROM `' . static::$table . '` WHERE id=? LIMIT 1');
        $statement->execute([(int) $id]);
        return $statement->fetch() ?: null;
    }

    public static function where($column, $value)
    {
        if (!preg_match('/^[a-z_]+$/', $column)) throw new \InvalidArgumentException('Invalid column name.');
        $statement = Database::connection()->prepare('SELECT * FROM `' . static::$table . '` WHERE `' . $column . '`=?');
        $statement->execute([$value]);
        return $statement->fetchAll();
    }
}
