<?php
namespace App\Http\Requests;

final class StoreRuleRequest
{
    public static function validate(array $input)
    {
        $type = (string) ($input['target_type'] ?? '');
        $effect = (string) ($input['effect'] ?? '');
        $priority = filter_var($input['priority'] ?? 100, FILTER_VALIDATE_INT);
        if (!in_array($type, ['user','group'], true)) throw new \DomainException('Loại điều kiện chỉ có thể là user hoặc group.');
        if (!in_array($effect, ['on','off'], true)) throw new \DomainException('Effect chỉ có thể là on hoặc off.');
        if ($priority === false || $priority < 0 || $priority > 1000000) throw new \DomainException('Priority cần là số nguyên hợp lệ.');
        $users = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($input['user_ids'] ?? ''))))));
        if ($type === 'user' && !$users) throw new \DomainException('Nhập ít nhất một mã người dùng cho quy tắc user.');
        $groupId = $type === 'group' ? (int) ($input['target_group_id'] ?? 0) : null;
        if ($type === 'group' && !$groupId) throw new \DomainException('Chọn nhóm người dùng cho quy tắc group.');
        return ['target_type' => $type, 'effect' => $effect, 'priority' => $priority, 'target_group_id' => $groupId, 'user_ids' => $users];
    }
}
