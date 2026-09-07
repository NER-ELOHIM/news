<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Reads RSS 2.0, RSS 1.0 (RDF) and Atom.
 *
 * Real-world feeds arrive broken often: nested CDATA, a wrong encoding in the
 * header, an unclosed tag. So parsing runs with libxml recovery enabled, and a
 * bad feed becomes a warning rather than an error.
 */
final class Feed
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';
    private const MEDIA_NS = 'http://search.yahoo.com/mrss/';

    /**
     * libxml's XML_PARSE_RECOVER, which keeps the parser going through a
     * malformed document instead of giving up on the first error.
     *
     * PHP only exposes it as LIBXML_RECOVER from 8.4 onward. On 8.2 and 8.3
     * the constant is undefined even with libxml loaded, so referencing it
     * there fails with "Undefined constant" — which reads like a namespace
     * problem and points nowhere near the cause. The option itself works on
     * every version: the bitmask is handed straight to libxml.
     *
     * Verified against php:8.2-cli, php:8.3-cli and php:8.4-cli.
     */
    private const RECOVER = 1;
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    /**
     * @return list<Entry>
     * @throws ParseError
     */
    public static function parse(string $xml, string $sourceUrl): array
    {
        if (trim($xml) === '') {
            throw new ParseError('empty response');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $doc = simplexml_load_string(
                $xml,
                \SimpleXMLElement::class,
                LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING | self::RECOVER
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($doc === false) {
            throw new ParseError('unrecoverable XML');
        }

        $name = strtolower($doc->getName());

        return match (true) {
            $name === 'feed' => self::parseAtom($doc, $sourceUrl),
            $name === 'rss' => self::parseRss($doc, $sourceUrl),
            $name === 'rdf' => self::parseRdf($doc, $sourceUrl),
            default => throw new ParseError('root <' . $doc->getName() . '> is not a recognised feed'),
        };
    }

    /** @return list<Entry> */
    private static function parseAtom(\SimpleXMLElement $doc, string $sourceUrl): array
    {
        $entries = [];
        $doc->registerXPathNamespace('a', self::ATOM_NS);

        // Atom feeds without a declared namespace exist; the XPath covers both.
        $nodes = $doc->xpath('//a:entry') ?: $doc->xpath('//entry') ?: [];

        foreach ($nodes as $node) {
            $link = '';
            foreach ($node->link ?? [] as $candidate) {
                $rel = (string) ($candidate['rel'] ?? 'alternate');
                if ($rel === 'alternate' || $rel === '') {
                    $link = (string) ($candidate['href'] ?? '');
                    break;
                }
            }

            $body = trim((string) ($node->content ?? '')) ?: trim((string) ($node->summary ?? ''));
            $when = trim((string) ($node->published ?? '')) ?: trim((string) ($node->updated ?? ''));

            $entries[] = new Entry(
                id: trim((string) ($node->id ?? '')) ?: $link,
                title: self::text((string) ($node->title ?? '')),
                body: self::text($body),
                link: $link,
                author: trim((string) ($node->author->name ?? '')),
                published: Contract::parseTimestamp($when),
                sourceUrl: $sourceUrl,
                image: self::image($node, $body),
            );
        }

        return $entries;
    }

    /** @return list<Entry> */
    private static function parseRss(\SimpleXMLElement $doc, string $sourceUrl): array
    {
        $entries = [];

        foreach ($doc->channel->item ?? [] as $node) {
            $entries[] = self::rssItem($node, $sourceUrl);
        }

        return $entries;
    }

    /** RSS 1.0 puts <item> at the RDF root, not inside <channel>. */
    private static function parseRdf(\SimpleXMLElement $doc, string $sourceUrl): array
    {
        $entries = [];

        foreach ($doc->item ?? [] as $node) {
            $entries[] = self::rssItem($node, $sourceUrl);
        }

        return $entries;
    }

    private static function rssItem(\SimpleXMLElement $node, string $sourceUrl): Entry
    {
        $link = trim((string) ($node->link ?? ''));
        $dc = $node->children(self::DC_NS);

        $when = trim((string) ($node->pubDate ?? ''));
        if ($when === '') {
            $when = trim((string) ($dc->date ?? ''));
        }

        $author = trim((string) ($node->author ?? ''));
        if ($author === '') {
            $author = trim((string) ($dc->creator ?? ''));
        }

        return new Entry(
            id: trim((string) ($node->guid ?? '')) ?: $link,
            title: self::text((string) ($node->title ?? '')),
            body: self::text((string) ($node->description ?? '')),
            link: $link,
            author: $author,
            published: $when !== '' ? Contract::parseTimestamp($when) : null,
            sourceUrl: $sourceUrl,
            image: self::image($node, (string) ($node->description ?? '')
                . (string) ($node->children(self::CONTENT_NS)->encoded ?? '')),
        );
    }

    /**
     * Finds the entry's image, if there is one.
     *
     * Every feed family announces images differently, and none is required:
     * RSS has <enclosure>, the Media RSS extension has <media:content> and
     * <media:thumbnail>, and the rest simply embed an <img> in the description
     * HTML. Half the catalog carries no image at all — which is why the
     * edition has to look finished without one, not only with one.
     */
    private static function image(\SimpleXMLElement $node, string $html): string
    {
        $media = $node->children(self::MEDIA_NS);

        foreach ([$media->content ?? [], $media->thumbnail ?? []] as $candidates) {
            foreach ($candidates as $c) {
                $tipo = (string) ($c['type'] ?? '');
                $medium = (string) ($c['medium'] ?? '');
                if ($tipo !== '' && !str_starts_with($tipo, 'image/')) {
                    continue; // media:content also carries video and audio
                }
                if ($medium !== '' && $medium !== 'image') {
                    continue;
                }
                if ($url = self::imageUrl((string) ($c['url'] ?? ''))) {
                    return $url;
                }
            }
        }

        foreach ($node->enclosure ?? [] as $e) {
            if (str_starts_with((string) ($e['type'] ?? ''), 'image/')
                && $url = self::imageUrl((string) ($e['url'] ?? ''))) {
                return $url;
            }
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            return self::imageUrl(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    /**
     * HTTPS only. An HTTP image inside an email triggers a mixed-content
     * warning in some clients, and an illustration is not worth that.
     */
    private static function imageUrl(string $url): string
    {
        $url = trim($url);

        return str_starts_with(strtolower($url), 'https://') ? $url : '';
    }

    /** Feed descriptions almost always carry HTML. This flattens them to text. */
    private static function text(string $raw): string
    {
        $decoded = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $decoded) ?? $decoded);
    }
}

final class ParseError extends \RuntimeException
{
}
