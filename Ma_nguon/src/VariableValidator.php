<?php
declare(strict_types=1);

class VariableValidator {
    public static function extractVariables(string $text): array {
        $variables = self::extractPlaceholders($text);
        sort($variables);
        return $variables;
    }

    public static function validate(string $sourceText, string $translatedText): bool {
        return self::extractVariables($sourceText) === self::extractVariables($translatedText);
    }

    private static function extractPlaceholders(string $text): array {
        $placeholders = [];
        preg_match_all(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_.-]*|\d+)\s*\}\}|\{\s*([a-zA-Z_][a-zA-Z0-9_.-]*|\d+)\s*\}/',
            $text,
            $braceMatches,
            PREG_SET_ORDER
        );
        foreach ($braceMatches as $match) {
            $isDoubleBrace = ($match[1] ?? '') !== '';
            $name = $isDoubleBrace ? $match[1] : ($match[2] ?? '');
            $placeholders[] = ($isDoubleBrace ? '{{' : '{') . $name . ($isDoubleBrace ? '}}' : '}');
        }

        preg_match_all(
            '/(?<!%)%(?:\([a-zA-Z_][a-zA-Z0-9_]*\))?(?:\d+\$)?[-+#0 \']*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/',
            $text,
            $printfMatches
        );
        foreach ($printfMatches[0] ?? [] as $placeholder) {
            $placeholders[] = preg_replace('/\s+/', '', $placeholder) ?? $placeholder;
        }

        preg_match_all('/<\/?[A-Za-z][A-Za-z0-9:-]*(?:\s+[^<>]*?)?\s*\/?>/', $text, $tagMatches);
        foreach ($tagMatches[0] ?? [] as $tag) {
            preg_match('/^<\s*(\/?)\s*([A-Za-z][A-Za-z0-9:-]*)(.*?)(\/?)\s*>$/s', $tag, $parts);
            if ($parts === []) {
                continue;
            }
            $attributes = preg_replace('/\s+/', ' ', trim($parts[3])) ?? trim($parts[3]);
            $attributes = preg_replace('/\s*=\s*/', '=', $attributes) ?? $attributes;
            $placeholders[] = 'html:' . ($parts[1] ?? '') . strtolower($parts[2]) . $attributes;
        }

        return $placeholders;
    }
}
