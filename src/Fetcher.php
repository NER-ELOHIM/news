<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Busca o corpo de um feed por HTTP.
 *
 * Todo timeout é explícito. Sem timeout, um feed pendurado trava o adaptador
 * até o motor matá-lo, e aí o job inteiro conta como falho por causa de uma
 * fonte só.
 */
final class Fetcher
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly string $userAgent = 'iode-adapter-news/1.0 (+https://github.com/NER-ELOHIM/news)',
    ) {
    }

    /** @throws FetchError */
    public function get(string $url): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new FetchError('esquema não suportado: ' . ($scheme ?: '(ausente)'));
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new FetchError('curl_init falhou');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/atom+xml, application/rss+xml, application/xml;q=0.9, */*;q=0.8'],
            // Corta o download assim que passa do teto, em vez de ler tudo e
            // descartar depois.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn($_, $downloadSize) => $downloadSize > self::MAX_BYTES ? 1 : 0,
        ]);

        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($errno === CURLE_ABORTED_BY_CALLBACK) {
            throw new FetchError('feed maior que ' . self::MAX_BYTES . ' bytes');
        }
        if ($errno !== 0 || $body === false) {
            throw new FetchError('erro de rede: ' . ($error ?: 'código ' . $errno));
        }
        if ($status < 200 || $status >= 300) {
            throw new FetchError('HTTP ' . $status);
        }

        return (string) $body;
    }
}

final class FetchError extends \RuntimeException
{
}
