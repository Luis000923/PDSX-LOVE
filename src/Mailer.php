<?php
declare(strict_types=1);

/**
 * Envío de correo por SMTP (cuenta de soporte de Hostinger: smtp.hostinger.com:465 SSL) sin dependencias externas.
 * Credenciales solo desde .env (SMTP_*, MAIL_FROM_*); nunca se registran en logs. Los mensajes son texto plano UTF-8.
 * `setTransport()` permite a las pruebas capturar los envíos sin abrir sockets.
 */
final class Mailer
{
    private static ?Closure $transport = null;

    /** @param (Closure(string,string,string):bool)|null $t */
    public static function setTransport(?Closure $t): void
    {
        self::$transport = $t;
    }

    /** ¿Hay datos suficientes para enviar? (host, usuario y contraseña). */
    public static function configured(): bool
    {
        return self::$transport !== null
            || ((string) env('SMTP_HOST') !== '' && (string) env('SMTP_USERNAME') !== '' && (string) env('SMTP_PASSWORD') !== '');
    }

    public static function supportEmail(): string
    {
        return (string) env('MAIL_SUPPORT_EMAIL', (string) env('SMTP_USERNAME', ''));
    }

    /** Envía un correo de texto plano. false (con el motivo en el log) si no se pudo. */
    public static function send(string $to, string $subject, string $text): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject) === 1) {
            return false;
        }
        if (self::$transport !== null) {
            return (bool) (self::$transport)($to, $subject, $text);
        }
        try {
            self::smtp($to, $subject, $text);
            return true;
        } catch (Throwable $e) {
            error_log('Mailer: ' . $e->getMessage());
            return false;
        }
    }

    private static function smtp(string $to, string $subject, string $text): void
    {
        $host = (string) env('SMTP_HOST');
        $port = (int) env('SMTP_PORT', '465');
        $enc  = strtolower((string) env('SMTP_ENCRYPTION', 'ssl'));
        $user = (string) env('SMTP_USERNAME');
        $pass = (string) env('SMTP_PASSWORD');
        $from = (string) env('MAIL_FROM_EMAIL', $user);
        $name = (string) env('MAIL_FROM_NAME', 'LovePages');
        if ($host === '' || $user === '' || $pass === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP no configurado.');
        }

        $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10);
        if (!is_resource($fp)) {
            throw new RuntimeException("no se pudo conectar a $host:$port ($errstr)");
        }
        stream_set_timeout($fp, 15);
        try {
            self::expect($fp, [220]);
            $ehlo = (string) (parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: 'localhost');
            self::cmd($fp, "EHLO $ehlo", [250]);
            if ($enc === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('falló STARTTLS');
                }
                self::cmd($fp, "EHLO $ehlo", [250]);
            }
            self::cmd($fp, 'AUTH LOGIN', [334]);
            self::cmd($fp, base64_encode($user), [334]);
            self::cmd($fp, base64_encode($pass), [235], true);
            self::cmd($fp, "MAIL FROM:<$from>", [250]);
            self::cmd($fp, "RCPT TO:<$to>", [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            $headers = [
                'Date: ' . date('r'),
                'From: ' . self::encode($name) . " <$from>",
                "To: <$to>",
                'Subject: ' . self::encode($subject),
                'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $ehlo . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            // Cuerpo en base64: sin líneas que empiecen por "." ni saltos raros que rompan el protocolo.
            self::cmd($fp, implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($text), 76, "\r\n") . '.', [250]);
            @fwrite($fp, "QUIT\r\n");
        } finally {
            fclose($fp);
        }
    }

    private static function encode(string $s): string
    {
        return preg_match('/^[\x20-\x7E]*$/', $s) === 1 ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    /** @param resource $fp @param list<int> $ok */
    private static function cmd($fp, string $line, array $ok, bool $secret = false): void
    {
        fwrite($fp, $line . "\r\n");
        self::expect($fp, $ok, $secret ? '(credenciales)' : strtok($line, "\r\n"));
    }

    /** @param resource $fp @param list<int> $ok */
    private static function expect($fp, array $ok, string $after = 'conexión'): void
    {
        $reply = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $reply .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        if (!in_array((int) substr($reply, 0, 3), $ok, true)) {
            throw new RuntimeException('SMTP respondió "' . trim($reply) . '" tras ' . $after);
        }
    }
}
