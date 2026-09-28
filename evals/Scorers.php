<?php

declare(strict_types=1);

namespace Iode\News\Evals;

/**
 * Deterministic scorers for one edition.
 *
 * Each scorer checks a rule the Editor's prompt already states, against the
 * model's raw response (before Editor::validate() repairs it), so the score
 * says how well the model followed the prompt rather than how well the
 * program cleaned up after it. They cost nothing and read nothing from the
 * network, so they run on every replay and in CI.
 *
 * A score is a float in [0, 1], or null when the metric does not apply to the
 * case (for example, no labels to check against). Null is not zero: a case
 * without labels has no opinion about recall.
 */
final class Scorers
{
    /** Metrics that must be perfect. Anything less is a regression, whatever the baseline was. */
    public const HARD = ['ids_valid', 'no_duplicates', 'excluded_respected'];

    /**
     * @param array<string,mixed> $case     the case file (items, labels, caps)
     * @param array<string,mixed> $response the model's raw response
     * @return array<string,float|null>
     */
    public static function score(array $case, array $response): array
    {
        $items = array_values($case['items']);
        $full = (bool) ($case['full'] ?? true);
        $selected = array_values(array_filter(
            is_array($response['selected'] ?? null) ? $response['selected'] : [],
            'is_array'
        ));

        $byShortId = [];
        foreach ($items as $i => $item) {
            $byShortId['m' . $i] = $item;
        }

        $ids = array_map(static fn (array $s): string => (string) ($s['id'] ?? ''), $selected);
        $known = array_values(array_filter($ids, static fn (string $id): bool => isset($byShortId[$id])));
        $keys = array_map(static fn (string $id): string => (string) $byShortId[$id]['key'], array_unique($known));

        return [
            'ids_valid' => self::ratio(count($known), count($ids)),
            'no_duplicates' => $ids === [] ? null : (count(array_unique($ids)) === count($ids) ? 1.0 : 0.0),
            'caps_obeyed' => self::capsObeyed($selected, $byShortId, $case),
            'blurb_length' => self::share($selected, static fn (array $s): bool => mb_strlen(trim((string) ($s['blurb'] ?? ''))) <= 70),
            'summary_length' => self::share($selected, static function (array $s) use ($full): bool {
                $n = self::sentences((string) ($s['summary'] ?? ''));
                // Full: "four to six sentences… if the excerpt only gives three
                // honest ones, write three". Short: "two or three sentences".
                return $full ? ($n >= 3 && $n <= 6) : ($n >= 2 && $n <= 3);
            }),
            'numbers_grounded' => self::numbersGrounded($selected, $byShortId),
            'excluded_respected' => self::excludedRespected($keys, $case),
            'included_recall' => self::includedRecall($keys, $case),
        ];
    }

    /**
     * Share of summaries whose every number also appears in the item's title or
     * excerpt. The prompt says "names, numbers and dates exactly as they appear
     * in the excerpt" and "never invent a number". A number the model wrote that
     * is not in the source is the cheapest hallucination signal available.
     *
     * @param list<array<string,mixed>> $selected
     * @param array<string,array<string,mixed>> $byShortId
     */
    private static function numbersGrounded(array $selected, array $byShortId): ?float
    {
        $checked = 0;
        $grounded = 0;
        foreach ($selected as $s) {
            $item = $byShortId[(string) ($s['id'] ?? '')] ?? null;
            if ($item === null) {
                continue; // an invented id is ids_valid's problem, not this one
            }
            $checked++;
            // The item's date is sent to the model too (data=…), so it counts as source.
            $source = self::numbers(
                (string) $item['title'] . ' ' . (string) $item['body'] . ' ' . (string) ($item['ts'] ?? '')
            );
            $claimed = self::numbers((string) ($s['title'] ?? '') . ' ' . (string) ($s['summary'] ?? ''));
            if (array_diff($claimed, $source) === []) {
                $grounded++;
            }
        }

        return self::ratio($grounded, $checked);
    }

    /**
     * Numbers normalised so "1.701", "1,701" and "1701" match, and so the
     * decimal separator does not matter ("2,5" and "2.5").
     *
     * Single digits are ignored: "três" written as "3", list positions and
     * the like make them too noisy to call a hallucination.
     *
     * @return list<string>
     */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d[\d.,]*\d|\d/u', $text, $m);
        $out = [];
        foreach ($m[0] as $raw) {
            $digits = preg_replace('/\D/', '', $raw);
            if (strlen($digits) >= 2) {
                $out[] = $digits;
            }
        }

        return array_values(array_unique($out));
    }

    /** Sentences, counted by terminal punctuation followed by space or end. */
    public static function sentences(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        // Decimal points and common abbreviations would each count as a
        // sentence end; neutralise them first.
        $text = preg_replace('/(\d)[.,](\d)/u', '$1$2', $text);
        $text = preg_replace('/\b(Sr|Sra|Dr|Dra|EUA|etc|vs|p\.ex|e\.g|i\.e)\./iu', '$1', $text);

        return max(1, preg_match_all('/[.!?…]+(\s|$)/u', $text));
    }

    /**
     * @param list<array<string,mixed>> $selected
     * @param array<string,array<string,mixed>> $byShortId
     * @param array<string,mixed> $case
     */
    private static function capsObeyed(array $selected, array $byShortId, array $case): ?float
    {
        if ($selected === []) {
            return null;
        }
        if (count($selected) > (int) $case['cap']) {
            return 0.0;
        }
        $perTopic = [];
        foreach ($selected as $s) {
            $topic = (string) ($byShortId[(string) ($s['id'] ?? '')]['meta']['topic'] ?? '?');
            $perTopic[$topic] = ($perTopic[$topic] ?? 0) + 1;
        }

        return max($perTopic) <= (int) $case['topic_cap'] ? 1.0 : 0.0;
    }

    /**
     * @param list<string> $keys
     * @param array<string,mixed> $case
     */
    private static function excludedRespected(array $keys, array $case): ?float
    {
        $excluded = $case['labels']['must_exclude'] ?? [];
        if ($excluded === []) {
            return null;
        }

        return 1.0 - count(array_intersect($keys, $excluded)) / count($excluded);
    }

    /**
     * @param list<string> $keys
     * @param array<string,mixed> $case
     */
    private static function includedRecall(array $keys, array $case): ?float
    {
        $included = $case['labels']['must_include'] ?? [];
        if ($included === []) {
            return null;
        }

        return count(array_intersect($keys, $included)) / count($included);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):bool $ok
     */
    private static function share(array $rows, callable $ok): ?float
    {
        return self::ratio(count(array_filter($rows, $ok)), count($rows));
    }

    private static function ratio(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole, 4);
    }
}
