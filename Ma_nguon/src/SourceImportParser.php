<?php
declare(strict_types=1);

final class SourceImportParser
{
    public static function parse(string $path, string $extension): array
    {
        return match (strtolower($extension)) {
            'json' => RemoteContentService::parseJson((string)file_get_contents($path)),
            'csv' => self::parseCsv($path),
            'xlsx' => self::parseXlsx($path),
            'po' => self::parsePo($path),
            'xlf', 'xliff' => self::parseXliff($path),
            'xml' => self::parseAndroidXml($path),
            default => throw new InvalidArgumentException('IMPORT_FORMAT_UNSUPPORTED'),
        };
    }

    private static function parsePo(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new InvalidArgumentException('IMPORT_FILE_UNREADABLE');
        }
        preg_match_all('/msgid\s+"((?:\\\\.|[^"])*)"(?:\s+"((?:\\\\.|[^"])*)")*\s+msgstr\s+"((?:\\\\.|[^"])*)"/m', $content, $matches);
        $data = [];
        foreach ($matches[1] as $index => $msgid) {
            $source = stripcslashes($msgid);
            $target = stripcslashes($matches[3][$index] ?? '');
            if ($source !== '' && $target !== '') {
                $data[$source] = $target;
            }
        }
        if ($data === []) {
            throw new InvalidArgumentException('IMPORT_FILE_EMPTY');
        }
        return $data;
    }

    private static function parseXliff(string $path): array
    {
        return self::parseXmlTranslations($path, 'trans-unit');
    }

    private static function parseAndroidXml(string $path): array
    {
        if (!function_exists('simplexml_load_file')) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new InvalidArgumentException('IMPORT_FILE_UNREADABLE');
            }
            preg_match_all('/<string\b[^>]*\bname="([^"]+)"[^>]*>(.*?)<\/string>/is', $content, $matches, PREG_SET_ORDER);
            $data = [];
            foreach ($matches as $match) {
                $data[$match[1]] = html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            if ($data !== []) {
                return $data;
            }
        }
        $xml = self::loadXml($path);
        $data = [];
        foreach ($xml->string as $string) {
            $name = trim((string)$string['name']);
            $value = trim((string)$string);
            if ($name !== '' && $value !== '') {
                $data[$name] = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        if ($data === []) {
            throw new InvalidArgumentException('IMPORT_FILE_EMPTY');
        }
        return $data;
    }

    private static function parseXmlTranslations(string $path, string $unitName): array
    {
        if (!function_exists('simplexml_load_file')) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new InvalidArgumentException('IMPORT_FILE_UNREADABLE');
            }
            preg_match_all('/<(?:[a-zA-Z0-9_-]+:)?trans-unit\b([^>]*)>(.*?)<\/(?:[a-zA-Z0-9_-]+:)?trans-unit>/is', $content, $units, PREG_SET_ORDER);
            $data = [];
            foreach ($units as $unit) {
                preg_match('/\bid="([^"]*)"/i', $unit[1], $id);
                preg_match('/<(?:[a-zA-Z0-9_-]+:)?source\b[^>]*>(.*?)<\/(?:[a-zA-Z0-9_-]+:)?source>/is', $unit[2], $source);
                preg_match('/<(?:[a-zA-Z0-9_-]+:)?target\b[^>]*>(.*?)<\/(?:[a-zA-Z0-9_-]+:)?target>/is', $unit[2], $target);
                $key = trim((string)($id[1] ?? strip_tags($source[1] ?? '')));
                $value = trim(strip_tags($target[1] ?? $source[1] ?? ''));
                if ($key !== '' && $value !== '') {
                    $data[$key] = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            if ($data !== []) {
                return $data;
            }
        }
        $xml = self::loadXml($path);
        $data = [];
        foreach ($xml->file->body->{$unitName} as $unit) {
            $key = trim((string)$unit['id']);
            $source = trim((string)$unit->source);
            $target = trim((string)$unit->target);
            if ($key === '') {
                $key = $source;
            }
            if ($key !== '' && ($target !== '' || $source !== '')) {
                $data[$key] = $target !== '' ? $target : $source;
            }
        }
        if ($data === []) {
            throw new InvalidArgumentException('IMPORT_FILE_EMPTY');
        }
        return $data;
    }

    private static function loadXml(string $path): SimpleXMLElement
    {
        if (!function_exists('simplexml_load_file')) {
            throw new RuntimeException('IMPORT_XML_EXTENSION_MISSING');
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        if (!$xml) {
            throw new InvalidArgumentException('IMPORT_XML_INVALID');
        }
        return $xml;
    }

    private static function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('IMPORT_FILE_UNREADABLE');
        }
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            throw new InvalidArgumentException('IMPORT_FILE_EMPTY');
        }
        $header = array_map(static fn($value): string => strtolower(trim((string)$value)), $header);
        $keyIndex = array_search('key', $header, true);
        $textIndex = array_search('source_text', $header, true);
        $textIndex = $textIndex === false ? array_search('text', $header, true) : $textIndex;
        if ($keyIndex === false || $textIndex === false) {
            fclose($handle);
            throw new InvalidArgumentException('IMPORT_CSV_COLUMNS_REQUIRED');
        }
        $data = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (($row[$keyIndex] ?? '') !== '') {
                $data[(string)$row[$keyIndex]] = (string)($row[$textIndex] ?? '');
            }
        }
        fclose($handle);
        if ($data === []) {
            throw new InvalidArgumentException('IMPORT_FILE_EMPTY');
        }
        return $data;
    }

    private static function parseXlsx(string $path): array
    {
        if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
            throw new RuntimeException('IMPORT_XLSX_EXTENSION_MISSING');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('IMPORT_FILE_UNREADABLE');
        }
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            foreach ($xml->si as $item) {
                $shared[] = (string)($item->t ?? implode('', array_map('strval', $item->r->t ?? [])));
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) {
            throw new InvalidArgumentException('IMPORT_XLSX_SHEET_MISSING');
        }
        $sheet = simplexml_load_string($sheetXml);
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $value = (string)$cell->v;
                $cells[] = ((string)$cell['t'] === 's') ? ($shared[(int)$value] ?? '') : $value;
            }
            $rows[] = $cells;
        }
        $header = array_map(static fn($value): string => strtolower(trim((string)$value)), $rows[0] ?? []);
        $keyIndex = array_search('key', $header, true);
        $textIndex = array_search('source_text', $header, true);
        $textIndex = $textIndex === false ? array_search('text', $header, true) : $textIndex;
        if ($keyIndex === false || $textIndex === false) {
            throw new InvalidArgumentException('IMPORT_XLSX_COLUMNS_REQUIRED');
        }
        $data = [];
        foreach (array_slice($rows, 1) as $row) {
            if (($row[$keyIndex] ?? '') !== '') {
                $data[(string)$row[$keyIndex]] = (string)($row[$textIndex] ?? '');
            }
        }
        if ($data === []) {
            throw new InvalidArgumentException('IMPORT_FILE_EMPTY');
        }
        return $data;
    }
}
