<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Fetches many feed bodies over HTTP, concurrently.
 *
 * Why concurrent: the catalog holds twenty-nine sources. In series, at the 8s
 * timeout the configuration uses, the worst case runs past four minutes — and
 * the engine kills an adapter at 60s, losing the entire collection because of
 * half a dozen slow servers. With `curl_multi` the worst case is the slowest
 * single feed rather than the sum of all of them.
 *
 * Two limits, doing different jobs: `timeoutSeconds` is per request, and
 * `budgetSeconds` caps the whole batch. The second exists because the first
 * does not protect against thirty slow feeds at once; when the budget runs
 * out, whatever has not arrived becomes a warning and the edition ships with
 * what did.
 */
final class Fetcher
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxParallel = 8,
        private readonly int $budgetSeconds = 45,
        private readonly string $userAgent = 'iode-adapter-news/2.0 (+https://github.com/NER-ELOHIM/news)',
    ) {
    }

    /** @throws FetchError */
    public function get(string $url): string
    {
        $result = $this->getMany([$url])[$url] ?? new FetchError('no response');
        if ($result instanceof FetchError) {
            throw $result;
        }

        return $result;
    }

    /**
     * Fetches every URL and returns a url => body map, or url => FetchError
     * for the ones that failed. It never throws: a broken source is a warning,
     * not an error — principle 6 of the engine.
     *
     * @param list<string> $urls
     * @return array<string, string|FetchError>
     */
    public function getMany(array $urls): array
    {
        $results = [];
        $queue = [];

        foreach ($urls as $url) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($scheme !== 'http' && $scheme !== 'https') {
                // Rejected before any socket is opened: file:// and gopher://
                // are schemes curl accepts and that have no business here.
                $results[$url] = new FetchError('unsupported scheme: ' . ($scheme ?: '(missing)'));
                continue;
            }
            $queue[] = $url;
        }

        if ($queue === []) {
            return $results;
        }

        $multi = curl_multi_init();
        $inFlight = [];
        $next = 0;
        $limit = max(1, $this->maxParallel);
        $deadline = microtime(true) + $this->budgetSeconds;

        $dispatch = function () use ($multi, $queue, &$next, &$inFlight): bool {
            if ($next >= count($queue)) {
                return false;
            }
            $url = $queue[$next++];
            $handle = $this->prepareHandle($url);
            curl_multi_add_handle($multi, $handle);
            $inFlight[spl_object_id($handle)] = [$url, $handle];

            return true;
        };

        for ($i = 0; $i < $limit; $i++) {
            if (!$dispatch()) {
                break;
            }
        }

        while ($inFlight !== []) {
            $active = 0;
            do {
                $state = curl_multi_exec($multi, $active);
            } while ($state === CURLM_CALL_MULTI_PERFORM);

            while (($info = curl_multi_info_read($multi)) !== false) {
                $handle = $info['handle'];
                $id = spl_object_id($handle);
                [$url] = $inFlight[$id];

                $results[$url] = $this->interpret($handle, (int) $info['result']);

                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
                unset($inFlight[$id]);
                $dispatch();
            }

            if ($inFlight === []) {
                break;
            }

            if (microtime(true) >= $deadline) {
                // Budget exhausted. Whatever is still in flight is abandoned with
                // a warning, and so is whatever was never dispatched: an
                // incomplete edition on time beats no edition at all.
                foreach ($inFlight as [$url, $handle]) {
                    $results[$url] = new FetchError('batch budget of ' . $this->budgetSeconds . 's exhausted');
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                }
                for (; $next < count($queue); $next++) {
                    $results[$queue[$next]] = new FetchError('batch budget of ' . $this->budgetSeconds . 's exhausted');
                }
                break;
            }

            if ($active > 0) {
                // Block until there is activity instead of spinning. The short
                // timeout guarantees the deadline above is still checked even
                // when every connection has stalled.
                curl_multi_select($multi, 0.5);
            }
        }

        curl_multi_close($multi);

        return $results;
    }

    private function prepareHandle(string $url): \CurlHandle
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('curl_init failed for ' . $url);
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
            // Abort the download as soon as it exceeds the cap, instead of
            // reading it all and discarding afterwards.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn($_, $downloadSize) => $downloadSize > self::MAX_BYTES ? 1 : 0,
        ]);

        return $handle;
    }

    private function interpret(\CurlHandle $handle, int $errno): string|FetchError
    {
        $body = curl_multi_getcontent($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($errno === CURLE_ABORTED_BY_CALLBACK) {
            return new FetchError('feed larger than ' . self::MAX_BYTES . ' bytes');
        }
        if ($errno !== 0) {
            return new FetchError('network error: ' . (curl_error($handle) ?: 'code ' . $errno));
        }
        if ($status < 200 || $status >= 300) {
            return new FetchError('HTTP ' . $status);
        }
        if ($body === null || $body === '') {
            return new FetchError('empty response');
        }

        return $body;
    }
}

final class FetchError extends \RuntimeException
{
}
