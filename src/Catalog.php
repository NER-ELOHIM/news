<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * The curated feed catalog, by topic.
 *
 * The catalog lives in code rather than in configuration for one reason: every
 * URL here was checked by hand against the real server. Someone else's feed is
 * infrastructure that breaks without warning — it changes domain, dies, starts
 * demanding a subscription, moves behind Cloudflare. When that happens the fix
 * should be a dated, reviewable commit naming the replacement, not a line
 * edited in a TOML file nobody remembers writing.
 *
 * A one-off source can still be added through the request's `feeds` key,
 * without touching the catalog; it lands under the `general` topic.
 */
final class Catalog
{
    /**
     * Topics, in reading order. The order matters: it is the order of the
     * edition, running from what changes a decision to what builds a
     * repertoire.
     *
     * `window` is how many hours an entry of that topic stays eligible. It
     * varies because the cadence of the sources varies: a market outlet
     * publishes hourly, while a central bank or a journal of exegesis
     * publishes when it has something to say. Under a single day-and-a-half
     * window the study topics — half the reason this exists — would be empty
     * in almost every edition, and only headlines would survive.
     *
     * @var array<string, array{emoji:string, label:string, window:int, feeds:list<string>}>
     */
    private const TOPICS = [
        'markets' => [
            'emoji' => '📈',
            'label' => 'MERCADO',
            'window' => 36,
            'feeds' => [
                'https://www.infomoney.com.br/feed/',
                'https://br.investing.com/rss/news.rss',
                'https://feeds.a.dj.com/rss/RSSMarketsMain.xml',
                'https://www.cnbc.com/id/100003114/device/rss/rss.html',
            ],
        ],
        'economics' => [
            'emoji' => '🏛️',
            'label' => 'ESTUDOS DE MERCADO',
            'window' => 240,
            // Research and primary data, not headlines: a central bank saying
            // what it decided, and an economist showing the arithmetic. This
            // is the topic that outlives the week.
            'feeds' => [
                'https://www.federalreserve.gov/feeds/press_all.xml',
                'https://libertystreeteconomics.newyorkfed.org/feed',
                'https://klementoninvesting.substack.com/feed',
            ],
        ],
        'engineering' => [
            'emoji' => '💻',
            'label' => 'PROGRAMAÇÃO',
            'window' => 120,
            'feeds' => [
                'https://go.dev/blog/feed.atom',
                'https://research.swtch.com/feed.atom',
                'https://www.php.net/feed.atom',
                'https://php.watch/feed/news.xml',
                'https://lwn.net/headlines/newrss',
                'https://martinfowler.com/feed.atom',
                'https://danluu.com/atom.xml',
                'https://brooker.co.za/blog/rss.xml',
            ],
        ],
        'geopolitics' => [
            'emoji' => '🌍',
            'label' => 'GEOPOLÍTICA',
            'window' => 72,
            'feeds' => [
                'https://www.foreignaffairs.com/rss.xml',
                'https://www.crisisgroup.org/rss.xml',
                'https://responsiblestatecraft.org/feed/',
            ],
        ],
        'saas' => [
            'emoji' => '🚀',
            'label' => 'SAAS',
            'window' => 48,
            'feeds' => [
                'https://tomtunguz.com/index.xml',
                'https://www.saastr.com/feed/',
                'https://techcrunch.com/feed/',
                'https://news.ycombinator.com/rss',
                'https://blog.pragmaticengineer.com/rss/',
            ],
        ],
        'scripture' => [
            'emoji' => '📖',
            'label' => 'ESTUDOS BÍBLICOS',
            'window' => 240,
            // Half Portuguese and half English on purpose: scholarly material
            // in Portuguese is scarce, and the English sources cover exegesis
            // and archaeology, which is where the actual study happens.
            'feeds' => [
                'https://voltemosaoevangelho.com/blog/feed/',
                'https://www.monergismo.com/feed/',
                'https://ministeriofiel.com.br/feed/',
                'https://www.9marks.org/feed/',
                'https://academic.logos.com/feed/',
                'https://www.biblicalarchaeology.org/feed/',
            ],
        ],
    ];

    /**
     * Topic assigned to a one-off feed from configuration.
     *
     * Section labels stay in Portuguese: the magazine is written in Brazilian
     * Portuguese for a single reader, so they are user-facing copy rather than
     * identifiers. Everything a developer touches is in English.
     */
    public const GENERAL = 'general';

    /** @return list<string> */
    public static function topics(): array
    {
        return array_keys(self::TOPICS);
    }

    public static function exists(string $topic): bool
    {
        return isset(self::TOPICS[$topic]);
    }

    /**
     * The topic's emoji, used in the index at the top of the edition.
     *
     * It is the only place in the edition where an emoji appears, and it
     * appears because in a list of thirty lines it works as a column marker:
     * the eye finds "the market one" without reading. In body copy it is
     * forbidden, and the ban is written into the editor's prompt.
     */
    public static function emoji(string $topic): string
    {
        return self::TOPICS[$topic]['emoji'] ?? '•';
    }

    /** Section label shown in the edition; for an unknown topic, its own name. */
    public static function label(string $topic): string
    {
        return self::TOPICS[$topic]['label'] ?? strtoupper($topic);
    }

    /** @return list<string> */
    public static function feeds(string $topic): array
    {
        return self::TOPICS[$topic]['feeds'] ?? [];
    }

    /**
     * How many hours an entry of this topic stays eligible. A topic outside
     * the catalog — a one-off feed from configuration — gets the fallback.
     */
    public static function window(string $topic, int $fallback): int
    {
        return self::TOPICS[$topic]['window'] ?? $fallback;
    }

    /**
     * Resolves the requested topics into a url => topic map. A URL listed
     * under two topics stays with the first: the item is one item, and
     * duplicating it across sections would spend two slots of the edition on
     * the same story.
     *
     * @param list<string> $topics
     * @return array<string,string>
     */
    public static function resolve(array $topics): array
    {
        $map = [];
        foreach ($topics as $topic) {
            foreach (self::feeds($topic) as $url) {
                $map[$url] ??= $topic;
            }
        }

        return $map;
    }
}
