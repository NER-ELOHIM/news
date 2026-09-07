<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Sends the edition over SMTP, speaking the protocol directly on the socket.
 *
 * No PHPMailer and no mail(): the first needs Composer, the second needs a
 * configured MTA that this machine does not have. What is left is the protocol
 * itself, which for one authenticated message is short — EHLO, STARTTLS, AUTH,
 * MAIL FROM, RCPT TO, DATA.
 *
 * The body goes out as multipart/alternative with both text and HTML. A client
 * that does not render HTML shows the text, and spam filters are less
 * suspicious of a message that carries both parts.
 */
final class Mailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $from,
        private readonly int $timeoutSeconds = 30,
    ) {
        foreach (['host' => $host, 'user' => $user, 'password' => $password, 'from' => $from] as $field => $value) {
            if (trim($value) === '') {
                throw new DeliveryError('SMTP: field ' . $field . ' is empty; check the IODE_SMTP_* variables');
            }
        }
    }

    /** @throws DeliveryError */
    public function send(string $to, string $subject, string $text, string $html): void
    {
        $conn = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $error,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
        );

        if ($conn === false) {
            throw new DeliveryError(sprintf('SMTP: could not connect to %s:%d: %s', $this->host, $this->port, $error ?: 'error ' . $errno));
        }
        stream_set_timeout($conn, $this->timeoutSeconds);

        try {
            $this->read($conn, 220);

            $this->command($conn, 'EHLO ' . $this->localHostname(), 250);
            $this->command($conn, 'STARTTLS', 220);

            if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                // Continuing would put the password on the wire in the clear.
                throw new DeliveryError('SMTP: STARTTLS failed; the connection would continue in plaintext, so we stop here');
            }

            // EHLO again after STARTTLS: the extension list advertised before
            // TLS no longer applies, and authentication is usually only
            // offered afterwards.
            $this->command($conn, 'EHLO ' . $this->localHostname(), 250);

            $this->command($conn, 'AUTH LOGIN', 334);
            $this->command($conn, base64_encode($this->user), 334);
            $this->command($conn, base64_encode($this->password), 235);

            $this->command($conn, sprintf('MAIL FROM:<%s>', $this->from), 250);
            $this->command($conn, sprintf('RCPT TO:<%s>', $to), [250, 251]);
            $this->command($conn, 'DATA', 354);

            $this->write($conn, $this->message($to, $subject, $text, $html) . "\r\n.");
            $this->read($conn, 250);

            $this->command($conn, 'QUIT', [221, 250]);
        } finally {
            fclose($conn);
        }
    }

    /** Builds the RFC 5322 message with both parts. */
    private function message(string $to, string $subject, string $text, string $html): string
    {
        $boundary = 'iode' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->from,
            'To: ' . $to,
            // An accented subject needs a MIME encoded-word, or it arrives garbled.
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->senderDomain() . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";
    }

    /** @param int|list<int> $expected */
    private function command($conn, string $line, int|array $expected): string
    {
        $this->write($conn, $line);

        return $this->read($conn, $expected);
    }

    private function write($conn, string $line): void
    {
        if (fwrite($conn, $line . "\r\n") === false) {
            throw new DeliveryError('SMTP: failed to write to the socket');
        }
    }

    /** @param int|list<int> $expected */
    private function read($conn, int|array $expected): string
    {
        $expectedList = is_array($expected) ? $expected : [$expected];
        $reply = '';

        // A multiline reply carries a hyphen in the fourth column until the
        // last line, which carries a space. That is how you know where it ends.
        while (($line = fgets($conn, 2048)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        if ($reply === '') {
            $info = stream_get_meta_data($conn);
            throw new DeliveryError($info['timed_out'] ? 'SMTP: the server did not answer in time' : 'SMTP: connection closed by the server');
        }

        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $expectedList, true)) {
            // The password never reaches an error message: the command that
            // carries it is the AUTH base64, and only the server's reply is
            // reported here.
            throw new DeliveryError(sprintf(
                'SMTP: expected %s, got %d — %s',
                implode('/', $expectedList),
                $code,
                trim($reply)
            ));
        }

        return $reply;
    }

    private function localHostname(): string
    {
        $name = gethostname();

        return is_string($name) && $name !== '' ? $name : 'localhost';
    }

    private function senderDomain(): string
    {
        $parts = explode('@', $this->from);

        return count($parts) === 2 && $parts[1] !== '' ? $parts[1] : 'localhost';
    }
}
