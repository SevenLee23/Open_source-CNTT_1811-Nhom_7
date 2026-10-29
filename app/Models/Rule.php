<?php
namespace App\Models;

use App\Support\Database;

class Rule extends Record
{
    protected static $table = 'rules';

    public static function forVersionFlag($versionFlagId)
    {
        $statement = Database::connection()->prepare('SELECT r.*,g.`key` group_key,g.name group_name,GROUP_CONCAT(ru.user_id ORDER BY ru.user_id SEPARATOR ", ") users FROM rules r LEFT JOIN target_groups g ON g.id=r.target_group_id LEFT JOIN rule_users ru ON ru.rule_id=r.id WHERE r.version_flag_id=? GROUP BY r.id ORDER BY r.priority,r.id');
        $statement->execute([(int) $versionFlagId]);
        return $statement->fetchAll();
    }
}
