<?php

declare(strict_types=1);

/**
 * Evaluations for the Editor: did a prompt or model change make the edition
 * better or worse?
 *
 *   echo "$REQ" | bin/news | php evals/run.php snapshot NAME   # freeze real candidates as a case
 *   php evals/run.php live [--case=NAME] [--judge] [--check]   # call the model, record, score
 *   php evals/run.php replay DIR [--judge] [--check]           # re-score a recorded run, free
 *   php evals/run.php replay evals/baseline --update-baseline  # accept a run as the new baseline
 *
 * live spends money (one Editor call per case, one more per case with --judge)
 * and prints the usage. replay reads recorded responses and never touches the
 * network unless --judge is given, so scorer changes can be tested for free and
 * CI can check that the harness itself still works.
 *
 * --check compares against evals/baseline/scores.json and exits 1 on a
 * regression: a hard metric below 1.0, or any other metric more than
 * --tolerance (default 0.10) below its baseline. One run of a
 * non-deterministic model is noisy; the tolerance is there so that noise does
 * not page anyone, and hard metrics are exempt because a single invented id or
 * a selected advertisement is a failure however rarely it happens.
 */

namespace Iode\News\Evals;

use Iode\News\Claude;
use Iode\News\Contract;
use Iode\News\Editor;

require __DIR__ . '/../src/Contract.php';
require __DIR__ . '/../src/Catalog.php';
require __DIR__ . '/../src/Claude.php';
require __DIR__ . '/../src/Editor.php';
require __DIR__ . '/Scorers.php';
require __DIR__ . '/Judge.php';

const CASES = __DIR__ . '/cases';
const RUNS = __DIR__ . '/runs';
const BASELINE = __DIR__ . '/baseline';

exit(main(array_slice($argv, 1)));

/** @param list<string> $args */
function main(array $args): int
{
    [$command, $positional, $opts] = parseArgs($args);

    try {
        return match ($command) {
            'snapshot' => snapshot($positional[0] ?? ''),
            'live' => live($opts),
            'replay' => replay($positional[0] ?? '', $opts),
            default => usage(),
        };
    } catch (\Throwable $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");

        return 2;
    }
}

function usage(): int
{
    fwrite(STDERR, "usage: php evals/run.php snapshot NAME | live [--case=NAME] [--judge] [--check] [--model=ID] [--tolerance=0.10]\n"
        . "       | replay DIR [--judge] [--check] [--update-baseline]\n");

    return 2;
}

/** Freezes the collector's output (stdin) as a case, bodies cut to what the Editor sends. */
function snapshot(string $name): int
{
    if (!preg_match('/^[a-z0-9-]+$/', $name)) {
        throw new \InvalidArgumentException('case name must be lowercase letters, digits and dashes');
    }
    $in = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $items = [];
    foreach ($in['items'] ?? [] as $item) {
        // 600 characters is exactly what Editor sends to the model, so the case
        // loses nothing the model would have seen, and keeps as little third-party
        // text in the repository as possible.
        $item['body'] = Contract::truncate((string) ($item['body'] ?? ''), 600);
        $items[] = $item;
    }
    if ($items === []) {
        throw new \RuntimeException('no items on stdin; pipe bin/news into this command');
    }

    $case = [
        'name' => $name,
        'description' => 'Real candidates from bin/news, frozen on ' . gmdate('Y-m-d') . '.',
        'date' => gmdate('Y-m-d'),
        'full' => true,
        'cap' => 30,
        'topic_cap' => 6,
        'labels' => ['must_include' => [], 'must_exclude' => []],
        'items' => $items,
    ];
    $path = CASES . "/$name.json";
    writeJson($path, $case);
    fwrite(STDERR, sprintf("wrote %s (%d items)\n", relative($path), count($items)));

    return 0;
}

/** @param array<string,string|bool> $opts */
function live(array $opts): int
{
    $key = (string) getenv('IODE_ANTHROPIC_API_KEY');
    $model = (string) ($opts['model'] ?? 'claude-opus-5');
    $claude = new Claude($key, $model);
    $dir = RUNS . '/' . gmdate('Ymd\THis\Z');

    $records = [];
    foreach (loadCases((string) ($opts['case'] ?? '')) as $case) {
        $editor = new Editor($claude, (int) $case['cap'], (int) $case['topic_cap'], (bool) $case['full']);
        fwrite(STDERR, "running {$case['name']} ({$model})…\n");
        $editor->edit($case['items'], new \DateTimeImmutable($case['date']));

        $record = [
            'case' => $case['name'],
            'model' => $model,
            'editor_sha256' => hash_file('sha256', __DIR__ . '/../src/Editor.php'),
            'recorded_at' => gmdate('c'),
            'usage' => $claude->lastUsage(),
            'response' => $editor->lastResponse(),
        ];
        writeJson("$dir/responses/{$case['name']}.json", $record);
        $records[] = $record;
    }
    fwrite(STDERR, 'recorded in ' . relative($dir) . "\n");

    return report($records, $opts, $dir);
}

/** @param array<string,string|bool> $opts */
function replay(string $dir, array $opts): int
{
    if ($dir === '' || !is_dir("$dir/responses")) {
        throw new \InvalidArgumentException('replay needs a run directory containing responses/');
    }
    $records = [];
    foreach (glob("$dir/responses/*.json") ?: [] as $file) {
        $records[] = readJson($file);
    }
    if ($records === []) {
        throw new \RuntimeException("no recorded responses in $dir/responses");
    }

    return report($records, $opts, $dir);
}

/**
 * Scores the records, prints the table, writes scores.json next to them, and
 * applies --check / --update-baseline.
 *
 * @param list<array<string,mixed>> $records
 * @param array<string,string|bool> $opts
 */
function report(array $records, array $opts, string $dir): int
{
    $cases = [];
    foreach (loadCases('') as $case) {
        $cases[$case['name']] = $case;
    }
    $judge = isset($opts['judge'])
        ? new Judge(new Claude((string) getenv('IODE_ANTHROPIC_API_KEY'), (string) ($opts['judge-model'] ?? 'claude-opus-5')))
        : null;

    // Judge verdicts depend only on the recorded response, so a replay without
    // --judge keeps the ones already saved next to it instead of dropping them.
    $previous = is_file("$dir/scores.json") ? readJson("$dir/scores.json") : ['scores' => [], 'details' => []];

    $scores = [];
    $details = [];
    foreach ($records as $record) {
        $name = (string) $record['case'];
        $case = $cases[$name] ?? throw new \RuntimeException("recorded case $name has no case file");
        $scores[$name] = Scorers::score($case, (array) $record['response']);
        if ($judge !== null) {
            $verdict = $judge->grounding($case, (array) $record['response']);
            $scores[$name]['grounded_judge'] = $verdict['score'];
            $details[$name]['judge'] = $verdict['verdicts'];
        } elseif (array_key_exists('grounded_judge', $previous['scores'][$name] ?? [])) {
            $scores[$name]['grounded_judge'] = $previous['scores'][$name]['grounded_judge'];
            $details[$name]['judge'] = $previous['details'][$name]['judge'] ?? [];
        }
    }
    ksort($scores);
    writeJson("$dir/scores.json", ['scores' => $scores, 'details' => $details]);

    $baseline = is_file(BASELINE . '/scores.json') ? readJson(BASELINE . '/scores.json')['scores'] : [];
    $tolerance = (float) ($opts['tolerance'] ?? 0.10);
    $failures = printTable($scores, $baseline, $tolerance);

    if (isset($opts['update-baseline'])) {
        updateBaseline($dir, $scores, $details);
    }
    if (isset($opts['check']) && $failures !== []) {
        fwrite(STDOUT, "\nREGRESSION\n" . implode("\n", array_map(static fn (string $f): string => "  - $f", $failures)) . "\n");

        return 1;
    }

    return 0;
}

/**
 * @param array<string,array<string,float|null>> $scores
 * @param array<string,array<string,float|null>> $baseline
 * @return list<string> failures
 */
function printTable(array $scores, array $baseline, float $tolerance): array
{
    $failures = [];
    foreach ($scores as $case => $metrics) {
        fwrite(STDOUT, "\n$case\n");
        foreach ($metrics as $metric => $value) {
            $base = $baseline[$case][$metric] ?? null;
            $flag = '';
            if ($value !== null && in_array($metric, Scorers::HARD, true) && $value < 1.0) {
                $flag = 'FAIL (hard metric)';
            } elseif ($value !== null && $base !== null && $value < $base - $tolerance) {
                $flag = sprintf('FAIL (more than %.2f below baseline)', $tolerance);
            } elseif ($value === null && $base !== null) {
                $flag = 'no longer measured';
            }
            if (str_starts_with($flag, 'FAIL')) {
                $failures[] = "$case / $metric: " . fmt($value) . ' (baseline ' . fmt($base) . ')';
            }
            fwrite(STDOUT, sprintf("  %-20s %7s   baseline %7s   %s\n", $metric, fmt($value), fmt($base), $flag));
        }
    }

    return $failures;
}

/**
 * @param array<string,array<string,float|null>> $scores
 * @param array<string,mixed> $details
 */
function updateBaseline(string $dir, array $scores, array $details): void
{
    if (realpath($dir) !== realpath(BASELINE)) {
        foreach (glob("$dir/responses/*.json") ?: [] as $file) {
            writeJson(BASELINE . '/responses/' . basename($file), readJson($file));
        }
    }
    writeJson(BASELINE . '/scores.json', ['scores' => $scores, 'details' => $details]);
    fwrite(STDERR, "baseline updated\n");
}

/** @return list<array<string,mixed>> */
function loadCases(string $only): array
{
    $cases = [];
    foreach (glob(CASES . '/*.json') ?: [] as $file) {
        $case = readJson($file);
        if ($only === '' || $case['name'] === $only) {
            $cases[] = $case;
        }
    }
    if ($cases === []) {
        throw new \RuntimeException($only === '' ? 'no cases in evals/cases' : "no case named $only");
    }

    return $cases;
}

/**
 * @param list<string> $args
 * @return array{0:string, 1:list<string>, 2:array<string,string|bool>}
 */
function parseArgs(array $args): array
{
    $command = array_shift($args) ?? '';
    $positional = [];
    $opts = [];
    foreach ($args as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
            $opts[$m[1]] = $m[2] ?? true;
        } else {
            $positional[] = $arg;
        }
    }

    return [$command, $positional, $opts];
}

function fmt(?float $value): string
{
    return $value === null ? '—' : number_format($value, 2);
}

/** @return array<string,mixed> */
function readJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function writeJson(string $path, mixed $data): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        throw new \RuntimeException('cannot create ' . dirname($path));
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

function relative(string $path): string
{
    return str_replace(dirname(__DIR__) . '/', '', $path);
}
