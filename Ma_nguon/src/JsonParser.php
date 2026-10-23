<?php
declare(strict_types=1);

class JsonParser {
    public static function flatten(array $data, string $prefix = ''): array {
        $result = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $result = array_merge($result, self::flatten($value, $fullKey));
            } else {
                $result[$fullKey] = (string)$value;
            }
        }
        return $result;
    }

    public static function unflatten(array $flatArray): array {
        $result = [];
        foreach ($flatArray as $key => $value) {
            $keys = explode('.', $key);
            $current = &$result;
            foreach ($keys as $i => $k) {
                if ($i === count($keys) - 1) {
                    $current[$k] = $value;
                } else {
                    if (!isset($current[$k]) || !is_array($current[$k])) {
                        $current[$k] = [];
                    }
                    $current = &$current[$k];
                }
            }
        }
        return $result;
    }
}