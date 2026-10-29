<?php
namespace App\Http\Requests;

final class ImportConfigRequest
{
    public static function fromGlobals()
    {
        if (empty($_FILES['config_file']['tmp_name']) || !is_uploaded_file($_FILES['config_file']['tmp_name'])) throw new \DomainException('Chọn file JSON cần nhập.');
        if ((int) ($_FILES['config_file']['size'] ?? 0) > 2 * 1024 * 1024) throw new \DomainException('File JSON không được vượt quá 2 MB.');
        $decoded = json_decode(file_get_contents($_FILES['config_file']['tmp_name']), true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) throw new \DomainException('File không phải JSON hợp lệ.');
        return $decoded;
    }
}
