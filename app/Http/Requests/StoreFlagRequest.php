<?php
namespace App\Http\Requests;

final class StoreFlagRequest
{
    public static function validate(array $input)
    {
        $key = trim((string) ($input['key'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_]{1,119}$/', $key)) throw new \DomainException('Key cần bắt đầu bằng chữ thường và chỉ gồm chữ thường, số, gạch dưới.');
        if ($name === '' || mb_strlen($name) > 160) throw new \DomainException('Tên feature là bắt buộc và tối đa 160 ký tự.');
        $rollout = filter_var($input['rollout_percentage'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($rollout === false || $rollout < 0 || $rollout > 100) throw new \DomainException('Rollout phải từ 0 đến 100.');
        return ['key' => $key, 'name' => $name, 'description' => trim((string) ($input['description'] ?? '')), 'default_value' => !empty($input['default_value']), 'rollout_enabled' => isset($input['rollout_enabled']), 'rollout_percentage' => $rollout, 'fallback_value' => !empty($input['fallback_value'])];
    }
}
