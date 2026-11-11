<?php
declare(strict_types=1);

class JsonParser {
    public static function flatten(array $data, string $prefix = ''): array {
        $result = [];
        foreach ($data as $key => $value) {
            $segment = str_replace(['\\', '.'], ['\\\\', '\\.'], (string)$key);
            $fullKey = $prefix === '' ? $segment : $prefix . '.' . $segment;
            if (is_array($value)) {
                foreach (self::flatten($value, $fullKey) as $nestedKey => $nestedValue) {
                    $result[$nestedKey] = $nestedValue;
                }
            } else {
                $result[$fullKey] = (string)$value;
            }
        }
        return $result;
    }

    public static function unflatten(array $flatArray): array {
        $result = [];
        foreach ($flatArray as $key => $value) {
            $keys = self::splitPath((string)$key);
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

    private static function splitPath(string $path): array {
        $segments = [];
        $segment = '';
        $escaped = false;
        for ($i = 0, $length = strlen($path); $i < $length; $i++) {
            $character = $path[$i];
            if ($escaped) {
                $segment .= $character;
                $escaped = false;
            } elseif ($character === '\\') {
                $escaped = true;
            } elseif ($character === '.') {
                $segments[] = $segment;
                $segment = '';
            } else {
                $segment .= $character;
            }
        }
        if ($escaped) {
            $segment .= '\\';
        }
        $segments[] = $segment;
        return $segments;
    }
}