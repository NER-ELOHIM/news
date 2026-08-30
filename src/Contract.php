<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Implementa o contrato de adaptador do iode, versão 1.
 *
 * Ver docs/CONTRATOS.md no repositório do motor. O adaptador lê uma requisição
 * JSON do stdin, escreve uma resposta JSON no stdout, e comunica o resultado
 * pelo código de saída.
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
            throw new ContractError('requisição vazia no stdin', self::BAD_CONFIG);
        }

        try {
            $req = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ContractError('requisição não é JSON válido: ' . $e->getMessage(), self::BAD_CONFIG);
        }

        if (!is_array($req)) {
            throw new ContractError('requisição não é um objeto JSON', self::BAD_CONFIG);
        }

        // Versão desconhecida do contrato é código 2: o motor desabilita o
        // adaptador em vez de tentar de novo.
        if (($req['contract'] ?? null) !== self::VERSION) {
            throw new ContractError(
                sprintf('contrato %s incompatível, este adaptador fala %d', json_encode($req['contract'] ?? null), self::VERSION),
                self::INCOMPATIBLE
            );
        }

        $since = null;
        if (isset($req['since']) && is_string($req['since']) && $req['since'] !== '') {
            $since = self::parseTimestamp($req['since']);
            if ($since === null) {
                throw new ContractError('campo since não é RFC 3339: ' . $req['since'], self::BAD_CONFIG);
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
     * parseTimestamp aceita RFC 3339 e RFC 822 (o formato de pubDate do RSS).
     * Devolve null quando não há offset de fuso: o contrato rejeita horário
     * sem offset, porque adivinhar fuso é como se perde uma hora por ano.
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

        // Sem offset explícito o PHP assume o fuso local em silêncio. Exigimos
        // que o texto original traga Z, ±HH:MM, ±HHMM ou um nome de fuso.
        if (!preg_match('/(Z|[+-]\d{2}:?\d{2}|\s(GMT|UTC|[A-Z]{3,4}))$/i', $raw)) {
            return null;
        }

        return $ts;
    }

    /** Formata em RFC 3339 com offset, que é o que o contrato exige. */
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

    /** Corta respeitando limite de bytes sem quebrar caractere multibyte. */
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
