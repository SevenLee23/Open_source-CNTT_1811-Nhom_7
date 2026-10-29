<?php
namespace App\Support;

final class Config
{
    private static $values = [];

    public static function load($root)
    {
        $file = $root . DIRECTORY_SEPARATOR . '.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
                    $value = substr($value, 1, -1);
                }
                if (getenv($key) === false) putenv($key . '=' . $value);
            }
        }
        self::$values = [
            'app' => require $root . '/config/app.php',
            'database' => require $root . '/config/database.php',
            'root' => $root,
        ];
    }

    public static function get($key, $default = null)
    {
        $value = self::$values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return $default;
            $value = $value[$part];
        }
        return $value;
    }
}
