<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * The curation step: takes what the collector gathered and returns an edition.
 *
 * The division of labour with Prefilter is deliberate. There, everything
 * decidable by counting is decided — dates, empty titles, literal repetition,
 * one feed taking over the edition. Here, what needs judgment: out of the
 * hundred and fifty items that survived, which thirty change the reader's
 * week, and how to say each one in a few sentences.
 *
 * The model's output is text and choices, never instructions. It is validated
 * against what was sent — an id that was not in the input is discarded, never
 * fetched — because content from the internet passed through here, and content
 * from the internet does not get to steer the program.
 *
 * The prompt below is written in Portuguese on purpose: the magazine is
 * written in Brazilian Portuguese, and instructions in the target language
 * produce noticeably better copy than instructions in English asking for
 * Portuguese.
 */
final class Editor
{
    public function __construct(
        private readonly Claude $claude,
        private readonly int $editionCap = 12,
        private readonly int $perTopicCap = 2,
        /**
         * Full edition: longer summaries and a fuller read per section.
         *
         * This is the weekly magazine's mode. A daily edition competes with
         * hurry and wins by being short; the weekly one is read sitting down
         * on a Sunday, and there a two-line summary becomes the bottleneck —
         * the reader has time, and what is missing is depth, not brevity.
         */
        private readonly bool $full = false,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $items itens no formato do contrato
     * @return array{intro:string, articles:list<array<string,mixed>>}
     * @throws ClaudeError
     */
    public function edit(array $items, \DateTimeImmutable $date): array
    {
        if ($items === []) {
            return ['intro' => '', 'articles' => []];
        }

        // Indexed by a short id: the prompt carries "m7" rather than a
        // sha256, which saves tokens and makes the response readable when
        // something goes wrong.
        $byId = [];
        $catalogue = [];
        foreach (array_values($items) as $i => $item) {
            $id = 'm' . $i;
            $byId[$id] = $item;
            $catalogue[] = [
                'id' => $id,
                'topic' => (string) ($item['meta']['topic'] ?? Catalog::GENERAL),
                'data' => substr((string) ($item['ts'] ?? ''), 0, 10),
                'title' => (string) ($item['title'] ?? ''),
                // The body is truncated: the model needs enough to judge
                // relevance and summarise, not the whole article. A hundred
                // and fifty full bodies would be tens of thousands of tokens
                // per edition, every week, to pick thirty.
                'trecho' => Contract::truncate((string) ($item['body'] ?? ''), 600),
            ];
        }

        $response = $this->claude->structured(
            $this->systemPrompt(),
            $this->buildPrompt($catalogue, $date),
            $this->schema(),
            $this->full ? 32000 : 16000,
        );

        return $this->validate($response, $byId);
    }

    private const SYSTEM = <<<'TXT'
    Você é o editor de uma {{VEICULO}}, escrita em português do
    Brasil para um único leitor: um desenvolvedor de software que investe, lê
    sobre geopolítica, acompanha o mercado de SaaS e estuda a Bíblia.

    Seu trabalho é escolher e escrever. {{TEMPO}} O pior resultado possível é
    uma edição que ele não termina. Uma edição curta com quatro matérias boas é
    melhor que uma cheia de medianas: se o material do período não presta,
    entregue menos. Nunca preencha vaga por preencher.

    Como escolher, em ordem:
    1. O que muda uma decisão dele — de investimento, de arquitetura, de leitura.
    2. O que ele não veria sozinho: pesquisa, dado primário, análise de fundo.
    3. O que tem consequência durável, e não o que só é recente.

    Descarte sem dó, e sem citar o motivo na edição: publicidade e conteúdo
    patrocinado; concurso, sorteio, promoção e boletim administrativo; nota de
    versão sem impacto; manchete que é só uma cotação do dia; texto que só
    repete o que outro item já disse; e qualquer coisa cujo trecho não permita
    escrever duas frases honestas.

    Como escrever cada resumo:
    - {{EXTENSAO}}
    - A primeira frase diz o que aconteceu. As seguintes dizem por que importa
      para ele, e o que muda na prática.
    - Nomes, números e datas exatamente como aparecem no trecho.
    - Nunca invente fato, número, citação ou nome que não esteja no trecho. Se
      o trecho for insuficiente, não selecione o item.
    - Sem "neste artigo", sem "o autor argumenta", sem pergunta retórica, sem
      exclamação, sem emoji.

    Cada item tem três textos, e eles não se repetem:
    - titulo: a manchete, reescrita em português. Direta, sem ponto final.
    - chamada: UMA linha para o índice do topo da edição, no máximo 70
      caracteres. É a promessa do que a pessoa vai ler, não um resumo do
      resumo. Escreva como quem cutuca: o fato mais concreto da matéria.
    - resumo: o texto da matéria, conforme a regra de extensão acima.

    A abertura é {{ABERTURA}} sobre o fio que liga o período. Se não houver
    fio, devolva string vazia — abertura forçada é pior que nenhuma.
    TXT;

    /** Swaps the instructions that differ between the short and full editions. */
    private function systemPrompt(): string
    {
        return strtr(self::SYSTEM, [
            '{{EXTENSAO}}' => $this->full
                ? 'Quatro a seis frases, no indicativo, em português do Brasil. '
                    . 'Há espaço para contexto: o que veio antes, quem está do outro lado, que número '
                    . 'sustenta a afirmação. Não encha linguiça para chegar ao tamanho — se o trecho '
                    . 'só dá para três frases honestas, escreva três.'
                : 'Duas ou três frases, no indicativo, em português do Brasil.',
            '{{ABERTURA}}' => $this->full
                ? 'um parágrafo de três ou quatro frases'
                : 'uma frase única',
            '{{VEICULO}}' => $this->full
                ? 'revista semanal pessoal'
                : 'newsletter diária pessoal',
            '{{TEMPO}}' => $this->full
                ? 'O leitor abre esta edição no domingo, sentado, com tempo — '
                    . 'ela pode e deve ser densa.'
                : 'O leitor tem cinco minutos.',
        ]);
    }

    /** @param list<array<string,string>> $catalogue */
    private function buildPrompt(array $catalogue, \DateTimeImmutable $date): string
    {
        $lines = [];
        foreach ($catalogue as $c) {
            $lines[] = sprintf(
                "[%s] tema=%s data=%s\ntítulo: %s\ntrecho: %s",
                $c['id'],
                $c['topic'],
                $c['data'],
                $c['title'],
                $c['trecho'] !== '' ? $c['trecho'] : '(sem trecho)'
            );
        }

        return sprintf(
            "Edição de %s.\n\nEscolha no máximo %d itens no total e no máximo %d por tema.\n"
            . "Use apenas os ids listados abaixo. Ordene do mais importante para o menos.\n\n"
            . "=== CANDIDATOS (%d) ===\n\n%s",
            $date->format('d/m/Y'),
            $this->editionCap,
            $this->perTopicCap,
            count($catalogue),
            implode("\n\n", $lines)
        );
    }

    /** @return array<string,mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'intro' => ['type' => 'string'],
                'selected' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'blurb' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'title', 'blurb', 'summary'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['intro', 'selected'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Checks the model's response against what was sent.
     *
     * The caps are re-applied here on purpose. The prompt asks, but asking is
     * not enforcing, and the cost of an edition twice the intended size is the
     * reader giving up on it. Whatever a deterministic rule can enforce, it
     * enforces.
     *
     * @param array<string,mixed> $response
     * @param array<string,array<string,mixed>> $byId
     * @return array{intro:string, articles:list<array<string,mixed>>}
     */
    private function validate(array $response, array $byId): array
    {
        $articles = [];
        $byTopic = [];

        foreach ($response['selected'] ?? [] as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $id = (string) ($choice['id'] ?? '');
            $original = $byId[$id] ?? null;
            if ($original === null) {
                // An id that was not in the input: the model hallucinated it or
                // repeated one. Dropped silently; nothing is ever fetched by an
                // id that came from here.
                continue;
            }
            unset($byId[$id]); // one slot per item, even if it comes back twice

            $summary = trim((string) ($choice['summary'] ?? ''));
            if ($summary === '') {
                continue;
            }

            $topic = (string) ($original['meta']['topic'] ?? Catalog::GENERAL);
            if (($byTopic[$topic] ?? 0) >= $this->perTopicCap) {
                continue;
            }
            $byTopic[$topic] = ($byTopic[$topic] ?? 0) + 1;

            $articles[] = [
                'topic' => $topic,
                // With no blurb the index falls back to the title: a long
                // line beats a hole in the first thing the reader sees.
                'blurb' => trim((string) ($choice['blurb'] ?? '')) ?: trim((string) ($choice['title'] ?? '')),
                'image' => (string) ($original['meta']['image'] ?? ''),
                // The model's title is a Portuguese rewrite; the original is
                // kept alongside because that is what matches the link.
                'title' => trim((string) ($choice['title'] ?? '')) ?: (string) $original['title'],
                'original_title' => (string) $original['title'],
                'summary' => $summary,
                'link' => (string) ($original['meta']['link'] ?? ''),
                'feed' => (string) ($original['meta']['feed'] ?? ''),
                'ts' => (string) ($original['ts'] ?? ''),
            ];

            if (count($articles) >= $this->editionCap) {
                break;
            }
        }

        return [
            'intro' => trim((string) ($response['intro'] ?? '')),
            'articles' => $articles,
        ];
    }
}
