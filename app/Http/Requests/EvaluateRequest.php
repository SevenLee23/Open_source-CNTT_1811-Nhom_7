<?php
namespace App\Http\Requests;

final class EvaluateRequest
{
    public static function validate(array $input)
    {
        $environment = trim((string) ($input['environment'] ?? ''));
        $userId = trim((string) ($input['user_id'] ?? ''));
        $flags = $input['flags'] ?? [];
        if (strlen($environment) > 80) throw new \InvalidArgumentException('environment must be at most 80 characters.');
        if ($userId === '' || strlen($userId) > 100) throw new \InvalidArgumentException('user_id is required and must be at most 100 characters.');
        if (!is_array($flags) || !$flags || count($flags) > 100) throw new \InvalidArgumentException('flags must be a non-empty array with at most 100 keys.');
        foreach ($flags as $flag) if (!is_string($flag) || !preg_match('/^[a-z][a-z0-9_]{1,119}$/', $flag)) throw new \InvalidArgumentException('Each flag key must be a valid string.');
        return ['environment' => $environment, 'user_id' => $userId, 'flags' => array_values(array_unique($flags))];
    }
}
