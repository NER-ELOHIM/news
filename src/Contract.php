<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Implements version 1 of the engine's adapter contract.
 *
 * The adapter reads a JSON request from stdin, writes a JSON response to
 * stdout, and reports the outcome through its exit code.
 */
final class Contract
{
    public const VERSION = 1;

    // Exit codes defined by the adapter contract.
    public const OK = 0;
    public const RETRYABLE = 1;
    public const INCOMPATIBLE = 2;
    public const BAD_CONFIG = 3;

    // Limits the engine enforces; we truncate before it has to.
    public const MAX_TITLE = 500;
    public const MAX_BODY = 100 * 1024;
    public const MAX_META = 16 * 1024;

    /**
     * @return array{project:string,path:string,since:?\DateTimeImmutable,config:array<string,mixed>}
     * @throws ContractError
     */
    public static function readRequest(string $raw): array
    {
        if (trim($raw) === '') {
            throw new ContractError('empty request on stdin', self::BAD_CONFIG);
        }

        try {
            $req = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ContractError('request is not valid JSON: ' . $e->getMessage(), self::BAD_CONFIG);
        }

        if (!is_array($req)) {
            throw new ContractError('request is not a JSON object', self::BAD_CONFIG);
        }

        // An unknown contract version is code 2: the engine disables the
        // adapter rather than retrying it.
        if (($req['contract'] ?? null) !== self::VERSION) {
            throw new ContractError(
                sprintf('incompatible contract %s; this adapter speaks %d', json_encode($req['contract'] ?? null), self::VERSION),
                self::INCOMPATIBLE
            );
        }

        $since = null;
        if (isset($req['since']) && is_string($req['since']) && $req['since'] !== '') {
            $since = self::parseTimestamp($req['since']);
            if ($since === null) {
                throw new ContractError('field since is not RFC 3339: ' . $req['since'], self::BAD_CONFIG);
            }
        }

        return [
            'project' => is_string($req['project'] ?? null) ? $req['project'] : '',
            'path' => is_string($req['path'] ?? null) ? $req['path'] : '',
            'since' => $since,
            'config' => is_array($req['config'] ?? null) ? $req['config'] : [],
        ];
    }

    /**
     * Accepts RFC 3339 and RFC 822 (the RSS pubDate format). Returns null
     * when there is no timezone offset: the contract rejects a timestamp
     * without one, because guessing the zone is how you lose an hour a year.
     */
    public static function parseTimestamp(string $raw): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            $ts = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }

        // Without an explicit offset PHP silently assumes the local zone. We
        // require the original text to carry Z, ±HH:MM, ±HHMM or a zone name.
        if (!preg_match('/(Z|[+-]\d{2}:?\d{2}|\s(GMT|UTC|[A-Z]{3,4}))$/i', $raw)) {
            return null;
        }

        return $ts;
    }

    /** Formats as RFC 3339 with an offset, which is what the contract requires. */
    public static function formatTimestamp(\DateTimeImmutable $ts): string
    {
        return $ts->format(\DateTimeInterface::RFC3339);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<string> $warnings
     */
    public static function writeResponse(array $items, array $warnings): string
    {
        $response = ['contract' => self::VERSION, 'items' => array_values($items)];
        if ($warnings !== []) {
            $response['warnings'] = array_values($warnings);
        }

        return json_encode(
            $response,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /** Truncates to a byte limit without splitting a multibyte character. */
    public static function truncate(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $maxBytes, 'UTF-8');
    }
}

final class ContractError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $exitCode)
    {
        parent::__construct($message);
    }
}
