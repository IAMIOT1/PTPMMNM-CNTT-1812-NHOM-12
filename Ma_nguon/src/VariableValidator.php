<?php
declare(strict_types=1);

class VariableValidator {
    /**
     * Trích xuất tất cả các biến dạng {var_name} trong chuỗi
     */
    public static function extractVariables(string $text): array {
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_.-]*)\}/', $text, $matches);
        $vars = $matches[1] ?? [];
        sort($vars);
        return $vars;
    }

    /**
     * Kiểm tra xem bản dịch có khớp các biến với chuỗi nguồn hay không
     */
    public static function validate(string $sourceText, string $translatedText): bool {
        $sourceVars = self::extractVariables($sourceText);
        $translatedVars = self::extractVariables($translatedText);

        // So sánh danh sách các biến trong nguồn và bản dịch
        return $sourceVars === $translatedVars;
    }
}
