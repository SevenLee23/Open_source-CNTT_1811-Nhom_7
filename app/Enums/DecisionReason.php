<?php
namespace App\Enums;

final class DecisionReason
{
    const KILL_SWITCH = 'kill_switch';
    const ACCOUNT_OVERRIDE = 'account_override';
    const USER_RULE = 'user_rule';
    const GROUP_RULE = 'group_rule';
    const PERCENTAGE_ROLLOUT = 'percentage_rollout';
    const DEFAULT_VALUE = 'default_value';
    const UNKNOWN_FLAG = 'unknown_flag';
}
