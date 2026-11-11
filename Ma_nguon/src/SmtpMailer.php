<?php
declare(strict_types=1);
require_once __DIR__ . '/EnvLoader.php';

final class SmtpMailer
{
    public string $errorInfo = '';
    private $socket = null;

    public function send(string $to, string $subject, string $body): bool
    {
        $host = getenv('TMS_SMTP_HOST') ?: 'smtp.gmail.com';
        $port = (int)(getenv('TMS_SMTP_PORT') ?: 587);
        $username = trim((string)(getenv('TMS_SMTP_USERNAME') ?: ''));
        $password = (string)(getenv('TMS_SMTP_PASSWORD') ?: '');
        $from = trim((string)(getenv('TMS_MAIL_FROM') ?: $username));
        if ($username === '' || $password === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('SMTP credentials or TMS_MAIL_FROM are not configured.');
        }

        $this->socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $error,
            15,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($this->socket)) {
            return $this->fail("Connection failed ({$errno}): {$error}");
        }
        stream_set_timeout($this->socket, 15);
        try {
            $this->expect(220);
            $this->command('EHLO tms.local', 250);
            $this->command('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS negotiation failed.');
            }
            $this->command('EHLO tms.local', 250);
            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($username), 334);
            $this->command(base64_encode($password), 235);
            $this->command('MAIL FROM:<' . $from . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', 250, 251);
            $this->command('DATA', 354);
            $headers = [
                'From: ' . $from,
                'To: ' . $to,
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];
            $this->write(implode("\r\n", $headers) . "\r\n\r\n" . $this->dotStuff($body) . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
            fclose($this->socket);
            return true;
        } catch (Throwable $exception) {
            $this->errorInfo = $exception->getMessage();
            if (is_resource($this->socket)) fclose($this->socket);
            return false;
        }
    }

    private function command(string $command, int ...$expected): void
    {
        $this->write($command);
        $this->expect(...$expected);
    }

    private function write(string $value): void
    {
        if (fwrite($this->socket, $value . "\r\n") === false) {
            throw new RuntimeException('SMTP write failed.');
        }
    }

    private function expect(int ...$expected): void
    {
        $response = '';
        while (($line = fgets($this->socket)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException("SMTP {$code}: " . trim(preg_replace('/[\r\n]+/', ' ', $response)));
        }
    }

    private function fail(string $message): bool
    {
        $this->errorInfo = $message;
        return false;
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function dotStuff(string $value): string
    {
        return preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $value)) ?? $value;
    }
}
