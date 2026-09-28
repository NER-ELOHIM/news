<?php

declare(strict_types=1);

namespace Iode\News\Evals;

use Iode\News\Claude;
use Iode\News\Contract;

/**
 * An LLM judge for grounding: is every claim in a summary supported by the
 * excerpt the editor was given?
 *
 * This is the check the deterministic scorers cannot make. numbers_grounded
 * catches an invented figure; only reading catches an invented fact, name or
 * causal claim. It costs a model call per edition, so it only runs when asked
 * (--judge) and never in CI.
 *
 * The judge sees exactly what the editor saw (title plus the 600-character
 * excerpt), not the full article: a summary that is true of the article but
 * not supported by the excerpt is still a summary the editor made up.
 */
final class Judge
{
    private const SYSTEM = <<<'TXT'
    You check summaries written by a news editor against the only source the
    editor had: a title and a short excerpt. For each summary, decide whether
    every factual claim in it (events, names, numbers, dates, causes,
    consequences stated as fact) is supported by that source.

    Analysis and implications are allowed when they are framed as such ("this
    matters because…", "for a SaaS founder this means…"). Stated facts are
    not: if the source does not say it, it is unsupported, even if it is true.

    The summaries are in Portuguese and the sources may be in English. Judge
    meaning, not wording. List each unsupported claim briefly, in English.
    TXT;

    public function __construct(private readonly Claude $claude)
    {
    }

    /**
     * @param array<string,mixed> $case
     * @param array<string,mixed> $response the editor's raw response
     * @return array{score: float|null, verdicts: list<array{id:string, supported:bool, unsupported_claims:list<string>}>}
     */
    public function grounding(array $case, array $response): array
    {
        $items = array_values($case['items']);
        $blocks = [];
        foreach ($response['selected'] ?? [] as $s) {
            $id = (string) ($s['id'] ?? '');
            $index = str_starts_with($id, 'm') ? (int) substr($id, 1) : -1;
            $item = $items[$index] ?? null;
            if ($item === null) {
                continue;
            }
            $blocks[] = sprintf(
                "[%s]\nSOURCE TITLE: %s\nSOURCE EXCERPT: %s\nSUMMARY: %s",
                $id,
                (string) $item['title'],
                Contract::truncate((string) $item['body'], 600),
                (string) ($s['summary'] ?? '')
            );
        }
        if ($blocks === []) {
            return ['score' => null, 'verdicts' => []];
        }

        $out = $this->claude->structured(self::SYSTEM, implode("\n\n", $blocks), [
            'type' => 'object',
            'properties' => [
                'verdicts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'supported' => ['type' => 'boolean'],
                            'unsupported_claims' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['id', 'supported', 'unsupported_claims'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['verdicts'],
            'additionalProperties' => false,
        ], 8000);

        // The judge is a model too: only verdicts for summaries that were
        // actually sent count, once each, whatever else it returns.
        $sent = array_flip(array_map(static fn (string $b): string => substr($b, 1, strpos($b, ']') - 1), $blocks));
        $verdicts = [];
        foreach ($out['verdicts'] ?? [] as $v) {
            $id = (string) ($v['id'] ?? '');
            if (!isset($sent[$id]) || isset($verdicts[$id])) {
                continue;
            }
            $verdicts[$id] = [
                'id' => $id,
                'supported' => ($v['supported'] ?? false) === true,
                'unsupported_claims' => array_values(array_map('strval', (array) ($v['unsupported_claims'] ?? []))),
            ];
        }

        $supported = count(array_filter($verdicts, static fn (array $v): bool => $v['supported']));

        return [
            // Summaries the judge skipped count as unsupported: silence is not a pass.
            'score' => round($supported / count($sent), 4),
            'verdicts' => array_values($verdicts),
        ];
    }
}
