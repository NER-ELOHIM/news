<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Delivery through the Telegram Bot API.
 *
 * Credentials come from the environment, never from a file. The engine
 * forwards anything prefixed IODE_ to the subprocess and never reads a
 * credential file on its own.
 */
final class Telegram
{
    /** Single-message limit of the Bot API. */
    public const MAX_CHARS = 4096;

    public function __construct(
        private readonly string $token,
        private readonly string $chatId,
        private readonly int $timeoutSeconds = 30,
        private readonly string $base = 'https://api.telegram.org',
    ) {
        if (trim($this->token) === '' || trim($this->chatId) === '') {
            throw new DeliveryError('Telegram: set IODE_TELEGRAM_TOKEN and IODE_TELEGRAM_CHAT_ID');
        }
    }

    /**
     * @param list<string> $messages
     * @throws DeliveryError
     */
    public function send(array $messages): void
    {
        foreach ($messages as $i => $text) {
            if (trim($text) === '') {
                continue;
            }

            $this->call('sendMessage', [
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                // Link previews would turn a thirty-link edition into a reel
                // of cards. The text already says what each one is.
                'link_preview_options' => json_encode(['is_disabled' => true]),
                'disable_notification' => $i > 0, // only the first one pings
            ]);
        }
    }

    /** @param array<string,mixed> $params */
    private function call(string $method, array $params): void
    {
        $url = sprintf('%s/bot%s/%s', rtrim($this->base, '/'), $this->token, $method);

        $handle = curl_init($url);
        if ($handle === false) {
            throw new DeliveryError('Telegram: curl_init failed');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($errno !== 0) {
            throw new DeliveryError('Telegram: network error: ' . ($error ?: 'code ' . $errno));
        }

        $data = json_decode((string) $body, true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            // The Bot API puts the reason in `description`, and it is
            // specific: "chat not found", "can't parse entities". Worth
            // forwarding verbatim.
            $reason = is_array($data) ? (string) ($data['description'] ?? 'no description') : 'unreadable response';
            throw new DeliveryError('Telegram refused: ' . $reason);
        }
    }
}

final class DeliveryError extends \RuntimeException
{
}
