<?php
namespace App\Http\Requests;

final class ImportLogsRequest
{
    const MAX_BYTES = 2097152;
    const MAX_ROWS = 1000;

    public static function fromGlobals()
    {
        $file = $_FILES['logs_file'] ?? null;
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \DomainException('Chọn file log JSON hoặc CSV cần nhập.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) throw new \DomainException('File log không được vượt quá 2 MB.');
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension === 'json') $logs = self::fromJson(file_get_contents($file['tmp_name']));
        elseif ($extension === 'csv') $logs = self::fromCsv($file['tmp_name']);
        else throw new \DomainException('Chỉ hỗ trợ file .json hoặc .csv.');
        if (!$logs || count($logs) > self::MAX_ROWS) throw new \DomainException('File phải có từ 1 đến ' . self::MAX_ROWS . ' dòng log.');
        return $logs;
    }

    private static function fromJson($raw)
    {
        $payload = json_decode((string) $raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new \DomainException('File log không phải JSON hợp lệ.');
        $rows = isset($payload['logs']) ? $payload['logs'] : $payload;
        if (!is_array($rows) || !$rows) throw new \DomainException('JSON cần là danh sách log hoặc object có thuộc tính logs.');
        return array_map([self::class, 'normalize'], $rows);
    }

    private static function fromCsv($path)
    {
        $handle = fopen($path, 'rb');
        if (!$handle) throw new \DomainException('Không đọc được file CSV.');
        $headers = fgetcsv($handle);
        if (!$headers) { fclose($handle); throw new \DomainException('CSV cần có dòng tiêu đề.'); }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headers = array_map(function ($header) { return strtolower(trim((string) $header)); }, $headers);
        if (!in_array('message', $headers, true)) { fclose($handle); throw new \DomainException('CSV cần có cột message.'); }
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($rows) >= self::MAX_ROWS) { fclose($handle); throw new \DomainException('File vượt quá ' . self::MAX_ROWS . ' dòng log.'); }
            if (count($values) === 1 && trim((string) $values[0]) === '') continue;
            $record = [];
            foreach ($headers as $index => $header) if ($header !== '') $record[$header] = $values[$index] ?? '';
            if (isset($record['context']) && trim((string) $record['context']) !== '') {
                $decoded = json_decode($record['context'], true);
                $record['context'] = json_last_error() === JSON_ERROR_NONE ? $decoded : ['text' => (string) $record['context']];
            }
            $rows[] = self::normalize($record);
        }
        fclose($handle);
        return $rows;
    }

    public static function normalize($row)
    {
        if (!is_array($row)) throw new \DomainException('Mỗi log phải là một object.');
        $message = trim((string) ($row['message'] ?? ''));
        if ($message === '' || strlen($message) > 10000) throw new \DomainException('Mỗi log cần message từ 1 đến 10000 ký tự.');
        $level = strtolower(trim((string) ($row['level'] ?? 'info')));
        if (!in_array($level, ['debug','info','notice','warning','error','critical','alert','emergency'], true)) throw new \DomainException('level của log không hợp lệ.');
        $source = trim((string) ($row['source'] ?? ''));
        if (strlen($source) > 160) throw new \DomainException('source không được vượt quá 160 ký tự.');
        $occurredAt = null;
        $timestamp = trim((string) ($row['timestamp'] ?? $row['occurred_at'] ?? ''));
        if ($timestamp !== '') {
            try { $occurredAt = (new \DateTimeImmutable($timestamp))->format('Y-m-d H:i:s'); }
            catch (\Throwable $exception) { throw new \DomainException('timestamp của log không hợp lệ.'); }
        }
        $context = $row['context'] ?? null;
        if ($context !== null && !is_array($context) && !is_scalar($context)) throw new \DomainException('context cần là object, danh sách hoặc giá trị đơn giản.');
        $encodedContext = $context === null ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($context !== null && $encodedContext === false) throw new \DomainException('context có ký tự JSON không hợp lệ.');
        return ['occurred_at' => $occurredAt, 'level' => $level, 'source' => $source !== '' ? $source : null, 'message' => $message, 'context' => $encodedContext];
    }
}
