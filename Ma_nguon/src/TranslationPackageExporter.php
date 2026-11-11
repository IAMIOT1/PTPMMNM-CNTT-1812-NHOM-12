<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JsonParser.php';

final class TranslationPackageExporter
{
    public function __construct(private PgSql\Connection $connection)
    {
    }

    public function export(int $versionId, string $locale, string $format = 'json'): string
    {
        $result = queryParams(
            $this->connection,
            "SELECT s.key_path, COALESCE(NULLIF(t.translated_text, ''), s.source_text) AS text
             FROM strings s
             LEFT JOIN translations t ON t.string_id = s.id AND t.locale = $2
             WHERE s.source_version_id = $1
             ORDER BY s.key_path",
            [$versionId, $locale]
        );
        $flat = [];
        while ($row = pg_fetch_assoc($result)) {
            $flat[$row['key_path']] = $row['text'];
        }
        return match ($format) {
            'json' => json_encode(
                JsonParser::unflatten($flat),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ),
            'csv' => $this->csv($flat),
            'po' => $this->po($flat),
            'xliff' => $this->xliff($flat),
            'android_xml' => $this->androidXml($flat),
            default => throw new InvalidArgumentException('EXPORT_FORMAT_UNSUPPORTED'),
        };
    }

    public function extensionFor(string $format): string
    {
        return ['json' => 'json', 'csv' => 'csv', 'po' => 'po', 'xliff' => 'xlf', 'android_xml' => 'xml'][$format]
            ?? throw new InvalidArgumentException('EXPORT_FORMAT_UNSUPPORTED');
    }

    public function contentTypeFor(string $format): string
    {
        return [
            'json' => 'application/json',
            'csv' => 'text/csv',
            'po' => 'text/x-gettext-translation',
            'xliff' => 'application/xliff+xml',
            'android_xml' => 'application/xml',
        ][$format] ?? throw new InvalidArgumentException('EXPORT_FORMAT_UNSUPPORTED');
    }

    private function csv(array $flat): string
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('EXPORT_OUTPUT_FAILED');
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['key', 'text']);
        foreach ($flat as $key => $text) {
            fputcsv($handle, [$key, $text]);
        }
        rewind($handle);
        return stream_get_contents($handle) ?: '';
    }

    private function po(array $flat): string
    {
        $output = "# Translation export\n\n";
        foreach ($flat as $key => $text) {
            $output .= 'msgctxt "' . addcslashes((string)$key, "\\\"") . "\"\n";
            $output .= 'msgid "' . addcslashes((string)$key, "\\\"") . "\"\n";
            $output .= 'msgstr "' . addcslashes((string)$text, "\\\"") . "\"\n\n";
        }
        return $output;
    }

    private function xliff(array $flat): string
    {
        if (!class_exists('XMLWriter')) {
            $output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<xliff version=\"1.2\"><file source-language=\"und\"><body>";
            foreach ($flat as $key => $text) {
                $output .= '<trans-unit id="' . htmlspecialchars((string)$key, ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
                    . '<source>' . htmlspecialchars((string)$key, ENT_XML1, 'UTF-8') . '</source>'
                    . '<target>' . htmlspecialchars((string)$text, ENT_XML1, 'UTF-8') . '</target></trans-unit>';
            }
            return $output . '</body></file></xliff>';
        }
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('xliff');
        $xml->writeAttribute('version', '1.2');
        $xml->startElement('file');
        $xml->writeAttribute('source-language', 'und');
        $xml->startElement('body');
        foreach ($flat as $key => $text) {
            $xml->startElement('trans-unit');
            $xml->writeAttribute('id', (string)$key);
            $xml->writeElement('source', (string)$key);
            $xml->writeElement('target', (string)$text);
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        return $xml->outputMemory();
    }

    private function androidXml(array $flat): string
    {
        if (!class_exists('XMLWriter')) {
            $output = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<resources>";
            foreach ($flat as $key => $text) {
                $name = preg_replace('/[^A-Za-z0-9_]/', '_', (string)$key) ?: 'translation';
                $output .= '<string name="' . htmlspecialchars($name, ENT_QUOTES | ENT_XML1, 'UTF-8') . '">'
                    . htmlspecialchars((string)$text, ENT_XML1, 'UTF-8') . '</string>';
            }
            return $output . '</resources>';
        }
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('resources');
        foreach ($flat as $key => $text) {
            $xml->startElement('string');
            $xml->writeAttribute('name', preg_replace('/[^A-Za-z0-9_]/', '_', (string)$key) ?: 'translation');
            $xml->text((string)$text);
            $xml->endElement();
        }
        $xml->endElement();
        return $xml->outputMemory();
    }
}
