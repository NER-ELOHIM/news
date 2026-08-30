<?php

declare(strict_types=1);

namespace Iode\News;

/** Uma entrada de feed, antes de virar item do contrato. */
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
    ) {
    }

    /**
     * key é a chave natural, única dentro do par projeto + adaptador. Usamos a
     * origem mais o id da entrada: o mesmo item republicado com texto editado
     * mantém a chave, o que deixa o sync idempotente.
     *
     * @return array<string,mixed>
     */
    public function toItem(\DateTimeImmutable $fallbackTs): array
    {
        $ts = $this->published ?? $fallbackTs;

        $meta = ['feed' => $this->sourceUrl];
        if ($this->link !== '') {
            $meta['link'] = $this->link;
        }
        if ($this->author !== '') {
            $meta['author'] = Contract::truncate($this->author, 200);
        }
        if ($this->published === null) {
            // Sem data utilizável na entrada; quem lê o item precisa saber.
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
