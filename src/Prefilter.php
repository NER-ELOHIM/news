<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Cuts the volume down before any model sees the material.
 *
 * The order is deliberate. Twenty-nine feeds return well over a thousand
 * entries; sending all of them to a model is expensive and, worse, dilutes the
 * choice in a sea of repetition. Everything decidable by counting — the date,
 * an empty title, the same story from two outlets, one high-volume feed taking
 * over the whole edition — is decided here, for free and reproducibly. The
 * model then receives a short list and does only what it does better: judging
 * relevance and writing.
 *
 * Nothing here touches the network, reads its own clock, or uses randomness:
 * same input, same output, always.
 */
final class Prefilter
{
    public function __construct(
        /**
         * Default window, in hours. Each catalog topic declares its own and
         * that one wins; this applies to a one-off feed, which has no topic.
         */
        private readonly int $windowHours = 36,
        /** Per-feed cap, so a high-volume outlet cannot dominate. */
        private readonly int $perFeedCap = 8,
        /** Per-topic cap on what reaches the model, not on the final edition. */
        private readonly int $perTopicCap = 25,
        /**
         * Floor, in hours, applied over every topic's own window.
         *
         * This is what the edition's cadence controls. In a weekly edition, a
         * topic with a 36-hour window would see only the last day and a half
         * of the week, and the edition would report the weekend as if it were
         * the week. With a 168-hour floor every topic covers all seven days;
         * topics that already had a wider window, like the study ones, keep
         * theirs.
         */
        private readonly int $windowFloor = 0,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $items items in contract format
     * @return array{items:list<array<string,mixed>>, dropped:array<string,int>}
     */
    public function apply(array $items, \DateTimeImmutable $now): array
    {
        $dropped = ['stale' => 0, 'incomplete' => 0, 'duplicate' => 0, 'feed_cap' => 0, 'topic_cap' => 0];

        // One cutoff per topic, computed once. See the note on source cadence
        // in Catalog: headlines and research age at different rates, and a
        // single window would serve both badly.
        $cutoffs = [];
        $cutoffFor = function (string $topic) use (&$cutoffs, $now): \DateTimeImmutable {
            return $cutoffs[$topic] ??= $now->modify(
                '-' . max(Catalog::window($topic, $this->windowHours), $this->windowFloor) . ' hours'
            );
        };

        $candidates = [];
        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $link = trim((string) ($item['meta']['link'] ?? ''));

            // With no title or no link the item is useless in an edition:
            // nothing to display, nowhere to send the reader.
            if ($title === '' || $link === '') {
                $dropped['incomplete']++;
                continue;
            }

            $ts = Contract::parseTimestamp((string) ($item['ts'] ?? ''));
            if ($ts === null || $ts < $cutoffFor((string) ($item['meta']['topic'] ?? Catalog::GENERAL))) {
                $dropped['stale']++;
                continue;
            }

            $candidates[] = $item;
        }

        // Newest first. The caps below cut the tail, so this ordering decides
        // what survives, and the criterion is the publication date.
        usort($candidates, static function (array $a, array $b): int {
            return strcmp((string) $b['ts'], (string) $a['ts']);
        });

        $seen = [];
        $byFeed = [];
        $byTopic = [];
        $out = [];

        foreach ($candidates as $item) {
            $signature = self::signature((string) $item['title']);
            if (isset($seen[$signature])) {
                // The same story runs in several outlets on the same day. The
                // first one stays, which by the ordering above is the newest.
                $dropped['duplicate']++;
                continue;
            }

            $feed = (string) ($item['meta']['feed'] ?? '');
            if (($byFeed[$feed] ?? 0) >= $this->perFeedCap) {
                $dropped['feed_cap']++;
                continue;
            }

            $topic = (string) ($item['meta']['topic'] ?? Catalog::GENERAL);
            if (($byTopic[$topic] ?? 0) >= $this->perTopicCap) {
                $dropped['topic_cap']++;
                continue;
            }

            $seen[$signature] = true;
            $byFeed[$feed] = ($byFeed[$feed] ?? 0) + 1;
            $byTopic[$topic] = ($byTopic[$topic] ?? 0) + 1;
            $out[] = $item;
        }

        return ['items' => $out, 'dropped' => $dropped];
    }

    /**
     * A headline signature, used to spot the same story across outlets.
     * Lowercased, unaccented, unpunctuated, and stripped of short connecting
     * words, so "Fed corta juros em 0,25 p.p." and "Fed corta os juros em
     * 0,25 pp" collapse onto the same key.
     *
     * It does not try to be semantic. A genuinely rewritten headline gets
     * through, and it is the model that notices the repetition when choosing.
     */
    public static function signature(string $title): string
    {
        $t = mb_strtolower($title, 'UTF-8');
        $t = self::unaccent($t);
        $t = preg_replace('/[^a-z0-9 ]+/', ' ', $t) ?? $t;

        $words = array_filter(
            preg_split('/\s+/', trim($t)) ?: [],
            static fn(string $w): bool => mb_strlen($w) > 3
        );
        sort($words);

        return implode(' ', $words);
    }

    private static function unaccent(string $s): string
    {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);

        return $normalized === false ? $s : $normalized;
    }
}
