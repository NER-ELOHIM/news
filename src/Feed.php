<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Lê RSS 2.0, RSS 1.0 (RDF) e Atom.
 *
 * Feed do mundo real vem quebrado com frequência: CDATA aninhado, encoding
 * errado no cabeçalho, tag não fechada. Por isso o parse roda com recuperação
 * do libxml ligada, e um feed ruim vira aviso, não erro.
 */
final class Feed
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    /**
     * @return list<Entry>
     * @throws ParseError
     */
    public static function parse(string $xml, string $sourceUrl): array
    {
        if (trim($xml) === '') {
            throw new ParseError('resposta vazia');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $doc = simplexml_load_string(
                $xml,
                \SimpleXMLElement::class,
                LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_RECOVER
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($doc === false) {
            throw new ParseError('XML irrecuperável');
        }

        $name = strtolower($doc->getName());

        return match (true) {
            $name === 'feed' => self::parseAtom($doc, $sourceUrl),
            $name === 'rss' => self::parseRss($doc, $sourceUrl),
            $name === 'rdf' => self::parseRdf($doc, $sourceUrl),
            default => throw new ParseError('raiz <' . $doc->getName() . '> não é feed reconhecido'),
        };
    }

    /** @return list<Entry> */
    private static function parseAtom(\SimpleXMLElement $doc, string $sourceUrl): array
    {
        $entries = [];
        $doc->registerXPathNamespace('a', self::ATOM_NS);

        // Feed Atom sem namespace declarado existe; o XPath cobre os dois casos.
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

    /** RSS 1.0 põe os <item> na raiz do RDF, não dentro do <channel>. */
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
        );
    }

    /** Descrição de feed quase sempre vem com HTML dentro. Vira texto puro. */
    private static function text(string $raw): string
    {
        $decoded = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $decoded) ?? $decoded);
    }
}

final class ParseError extends \RuntimeException
{
}
