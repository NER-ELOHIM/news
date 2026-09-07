<?php

declare(strict_types=1);

namespace Iode\News;

/** A feed entry, before it becomes a contract item. */
final class Entry
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $body,
        public readonly string $link,
        public readonly string $author,
        public readonly ?\DateTimeImmutable $published,
        public readonly string $sourceUrl,
        public readonly string $image = '',
    ) {
    }

    /**
     * `key` is the natural key, unique within the project + adapter pair. It
     * combines the source URL with the entry id: an item republished with
     * edited text keeps its key, which is what makes sync idempotent.
     *
     * @return array<string,mixed>
     */
    public function toItem(\DateTimeImmutable $fallbackTs, string $topic = Catalog::GENERAL): array
    {
        $ts = $this->published ?? $fallbackTs;

        // The topic comes from the caller, not the parser: it is a property
        // of the source in the catalog, not of the entry. The same XML served
        // under another topic would produce the same Entry.
        $meta = ['feed' => $this->sourceUrl, 'topic' => $topic];
        if ($this->link !== '') {
            $meta['link'] = $this->link;
        }
        if ($this->author !== '') {
            $meta['author'] = Contract::truncate($this->author, 200);
        }
        if ($this->image !== '') {
            $meta['image'] = $this->image;
        }
        if ($this->published === null) {
            // No usable date on the entry; whoever reads the item must know.
            $meta['ts_estimado'] = true;
        }

        return [
            'key' => hash('sha256', $this->sourceUrl . "\0" . $this->id),
            'kind' => 'external',
            'ts' => Contract::formatTimestamp($ts),
            'title' => Contract::truncate($this->title, Contract::MAX_TITLE),
            'body' => Contract::truncate($this->body, Contract::MAX_BODY),
            'meta' => $meta,
        ];
    }
}
