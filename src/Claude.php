<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * A minimal client for the Anthropic Messages API.
 *
 * Raw HTTP rather than the official SDK, because the SDK needs Composer and
 * this repository is deliberately dependency-free — loose files that run on
 * any PHP 8.2+. The surface used here is a single route and fits in a hundred
 * lines; pulling in a package manager for that would trade the project's rule
 * for convenience that does not pay for itself.
 *
 * What it does with the model's response is text, and only text: a headline, a
 * summary, and a choice of what belongs in the edition. Nothing from here
 * becomes an executed command. That is principle 5 of the engine — the model
 * proposes, the program does not obey.
 */
final class Claude
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    public const VERSAO_API = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'claude-opus-5',
        /**
         * Ten minutes, not three: the first full edition took 3m01s against a
         * 180s timeout. It cleared by one second. An edition with thirty long
         * summaries emits over twelve thousand output tokens, and that takes
         * minutes — the ceiling belongs well above the observed case, not
         * flush against it.
         */
        private readonly int $timeoutSeconds = 600,
        private readonly int $attempts = 3,
        private readonly string $endpoint = self::ENDPOINT,
    ) {
        if (trim($this->apiKey) === '') {
            throw new ClaudeError('no API key: set IODE_ANTHROPIC_API_KEY');
        }
    }

    /**
     * Asks for a JSON response conforming to the schema and returns it decoded.
     *
     * @param array<string,mixed> $schema JSON Schema of the response object
     * @return array<string,mixed>
     * @throws ClaudeError
     */
    public function structured(string $system, string $prompt, array $schema, int $maxTokens = 16000): array
    {
        $response = $this->post([
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ]);

        // A policy refusal arrives as HTTP 200 with stop_reason "refusal" and
        // empty content. Without this check the failure would surface later as
        // "not JSON", which points at the wrong place entirely.
        if (($response['stop_reason'] ?? null) === 'refusal') {
            $category = $response['stop_details']['category'] ?? 'unspecified';
            throw new ClaudeError('the model refused the request (category: ' . $category . ')');
        }
        if (($response['stop_reason'] ?? null) === 'max_tokens') {
            throw new ClaudeError('response truncated at max_tokens; raise the limit or shrink the input');
        }

        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }
        if (trim($text) === '') {
            throw new ClaudeError('the model returned an empty response');
        }

        try {
            $data = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ClaudeError('the model response is not JSON: ' . $e->getMessage());
        }
        if (!is_array($data)) {
            throw new ClaudeError('the model response is not a JSON object');
        }

        return $data;
    }

    /**
     * Usage from the last call, so the command can report what it cost.
     *
     * @var array{input_tokens:int,output_tokens:int}|null
     */
    private ?array $lastUsage = null;

    /** @return array{input_tokens:int,output_tokens:int}|null */
    public function lastUsage(): ?array
    {
        return $this->lastUsage;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws ClaudeError
     */
    private function post(array $body): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $lastError = 'no attempt made';

        for ($attempt = 1; $attempt <= max(1, $this->attempts); $attempt++) {
            $handle = curl_init($this->endpoint);
            if ($handle === false) {
                throw new ClaudeError('curl_init failed');
            }

            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'content-type: application/json',
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: ' . self::VERSAO_API,
                ],
            ]);

            $body = curl_exec($handle);
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);

            if ($errno !== 0) {
                $lastError = 'network error: ' . ($error ?: 'code ' . $errno);
            } elseif ($status === 200) {
                try {
                    $data = json_decode((string) $body, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new ClaudeError('the HTTP response is not JSON: ' . $e->getMessage());
                }
                $this->lastUsage = [
                    'input_tokens' => (int) ($data['usage']['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($data['usage']['output_tokens'] ?? 0),
                ];

                return is_array($data) ? $data : [];
            } else {
                $detail = self::errorMessage((string) $body);
                // A 4xx other than 429 is our own mistake: retrying only
                // spends money and adds delay. Fail immediately.
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    throw new ClaudeError(sprintf('the API returned HTTP %d: %s', $status, $detail));
                }
                $lastError = sprintf('HTTP %d: %s', $status, $detail);
            }

            if ($attempt < $this->attempts) {
                // Plain exponential backoff. Only 429 and 5xx reach here.
                sleep(2 ** $attempt);
            }
        }

        throw new ClaudeError('the API failed after ' . $this->attempts . ' attempts; last: ' . $lastError);
    }

    private static function errorMessage(string $body): string
    {
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['error']['message'])) {
            return (string) $data['error']['message'];
        }

        return Contract::truncate(trim($body), 300) ?: '(empty body)';
    }
}

final class ClaudeError extends \RuntimeException
{
}
