<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Keeps past editions so a later step (the weekly LinkedIn reflection) can
 * read what was published, without the archive growing without bound.
 *
 * - One gzipped JSON per edition, named by date: 2026-10-19.json.gz (~8 KB).
 * - Editions older than RETAIN_WEEKS are folded into index.jsonl, one line per
 *   article with only date, topic, title and link, and the full file is
 *   deleted. A year of index lines is well under 1 MB.
 * - Pruning runs on every save, so there is nothing to schedule or maintain.
 *
 * The archive lives outside the repository: by default
 * ~/.local/share/issacar/editions, or IODE_NEWS_ARCHIVE.
 */
final class Archive
{
    public const RETAIN_WEEKS = 12;

    public function __construct(private readonly string $dir)
    {
    }

    public static function fromEnv(): self
    {
        $dir = (string) getenv('IODE_NEWS_ARCHIVE');
        if ($dir === '') {
            $home = (string) (getenv('HOME') ?: sys_get_temp_dir());
            $dir = $home . '/.local/share/issacar/editions';
        }

        return new self($dir);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Saves the edition and prunes old ones. Returns the path written.
     *
     * @param array{intro?: string, articles: list<array<string,mixed>>} $edition
     * @throws \RuntimeException
     */
    public function save(array $edition, \DateTimeImmutable $now): string
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('cannot create archive directory ' . $this->dir);
        }
        $record = [
            'date' => $now->format('Y-m-d'),
            'intro' => (string) ($edition['intro'] ?? ''),
            'articles' => array_map(static fn (array $a): array => [
                'topic' => (string) ($a['topic'] ?? ''),
                'title' => (string) ($a['title'] ?? ''),
                'original_title' => (string) ($a['original_title'] ?? ''),
                'summary' => (string) ($a['summary'] ?? ''),
                'link' => (string) ($a['link'] ?? ''),
                'feed' => (string) ($a['feed'] ?? ''),
                'ts' => (string) ($a['ts'] ?? ''),
            ], $edition['articles']),
        ];
        $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $this->dir . '/' . $record['date'] . '.json.gz';
        if (@file_put_contents($path, gzencode($json, 9)) === false) {
            throw new \RuntimeException('cannot write ' . $path);
        }
        $this->prune($now);

        return $path;
    }

    /**
     * Folds editions older than RETAIN_WEEKS into index.jsonl and removes them.
     */
    public function prune(\DateTimeImmutable $now): int
    {
        $cutoff = $now->modify('-' . self::RETAIN_WEEKS . ' weeks')->format('Y-m-d');
        $folded = 0;
        foreach (glob($this->dir . '/*.json.gz') ?: [] as $file) {
            $date = basename($file, '.json.gz');
            if ($date >= $cutoff) {
                continue;
            }
            $record = json_decode((string) @gzdecode((string) file_get_contents($file)), true);
            $lines = '';
            foreach ((array) ($record['articles'] ?? []) as $a) {
                $lines .= json_encode([
                    'date' => $date,
                    'topic' => (string) ($a['topic'] ?? ''),
                    'title' => (string) ($a['title'] ?? ''),
                    'link' => (string) ($a['link'] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            }
            if (@file_put_contents($this->dir . '/index.jsonl', $lines, FILE_APPEND) === false) {
                continue; // keep the full file rather than lose it
            }
            unlink($file);
            $folded++;
        }

        return $folded;
    }

    /**
     * The most recent full edition, or null when the archive is empty.
     *
     * @return array<string,mixed>|null
     */
    public function latest(): ?array
    {
        $files = glob($this->dir . '/*.json.gz') ?: [];
        if ($files === []) {
            return null;
        }
        sort($files);
        $record = json_decode((string) @gzdecode((string) file_get_contents(end($files))), true);

        return is_array($record) ? $record : null;
    }
}
