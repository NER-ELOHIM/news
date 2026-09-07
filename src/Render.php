<?php

declare(strict_types=1);

namespace Iode\News;

/**
 * Renders the edition in both output formats.
 *
 * The HTML targets email clients, not browsers: tables, inline styles, no
 * external CSS, no flexbox, no grid. Gmail and Outlook strip <style> in many
 * contexts and a modern layout arrives broken. That aged badly on the web and
 * is still the right answer here.
 *
 * The Telegram text uses parse_mode HTML rather than MarkdownV2: MarkdownV2
 * requires escaping sixteen characters, and one unescaped dash in a headline
 * rejects the whole message with a 400. HTML needs three.
 */
final class Render
{
    /**
     * The name comes from 1 Chronicles 12:32 — "of the sons of Issachar, men
     * who had understanding of the times, to know what Israel ought to do".
     * It is not decoration: it is the selection criterion written into the
     * editor's prompt, which picks what changes a decision rather than what is
     * merely recent.
     */
    public const NOME = 'Issacar';
    public const LEMA = 'entendimento dos tempos';

    // A sans stack that works in every client: each system picks its own UI
    // font and nothing has to be downloaded. Webfonts in email work on Apple
    // and fail in Gmail, so they are not worth the risk.
    private const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    private const YELLOW = '#ffcf00';
    private const AMBER = '#8a6d00';   // the yellow that is legible as text
    private const DESK = '#eceae6';    // the surface under the edition
    private const PAPER = '#ffffff';
    private const SNOW = '#f4f4f2';    // index background
    private const STRAW = '#fffaea';   // intro card background
    private const SAND = '#fff3c4';    // date band
    private const INK = '#111111';
    private const GRAPHITE = '#6b6b6b'; // credits, labels, footer
    private const RULE = '#e6e6e6';

    /**
     * Desenha a edição.
     *
     * Tables and inline styles, always: Gmail and Outlook strip <style> in
     * many contexts and `flex`/`grid` arrive broken. What is left is what good
     * newsletters have used for twenty years — nested tables, `border-radius`
     * (which degrades to square corners, and that is fine) and no media
     * queries.
     *
     * @param array{intro:string, articles:list<array<string,mixed>>} $edition
     */
    public static function html(array $edition, \DateTimeImmutable $date): string
    {
        $total = count($edition['articles']);
        $blocks = [];

        if ($edition['intro'] !== '') {
            $blocks[] = self::card(
                'intro',
                sprintf(
                    '<div style="font:400 16px/1.65 %s;color:%s">%s</div>',
                    self::FONT,
                    self::INK,
                    self::esc($edition['intro'])
                ),
                self::STRAW
            );
        }

        if ($total > 0) {
            $blocks[] = self::index($edition['articles']);
        }

        $previousTopic = null;
        foreach ($edition['articles'] as $i => $m) {
            $topic = (string) $m['topic'];
            $blocks[] = self::article($m, $topic !== $previousTopic, $i > 0);
            $previousTopic = $topic;
        }

        if ($total === 0) {
            $blocks[] = sprintf(
                '<tr><td style="padding:30px 24px;text-align:center;font:400 16px/1.7 %s;color:%s">'
                . 'Nada relevante o bastante no período.<br>Uma edição vazia é uma escolha, não uma falha.</td></tr>',
                self::FONT,
                self::GRAPHITE
            );
        }

        return sprintf(
            '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light only"><title>%s</title></head>'
            . '<body style="margin:0;padding:0;background:%s">'
            . '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="background:%s">'
            . '<tr><td align="center" style="padding:18px 10px 30px 10px">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:640px;max-width:100%%;background:%s;border-radius:14px">'
            . '%s%s%s'
            . '</table></td></tr></table></body></html>',
            self::esc(self::NOME . ' — ' . $date->format('d/m/Y')),
            self::DESK,
            self::DESK,
            self::PAPER,
            self::masthead($date, $total),
            implode('', $blocks),
            self::footer()
        );
    }

    /** Yellow band carrying the name, the motto and the date. */
    private static function masthead(\DateTimeImmutable $date, int $total): string
    {
        return sprintf(
            '<tr><td align="center" style="padding:34px 24px 30px 24px;background:%s;'
            . 'border-radius:14px 14px 0 0">'
            . '<div style="font:800 46px/1 %s;letter-spacing:-.02em;color:%s">%s</div>'
            . '<div style="padding-top:9px;font:600 11px/1.4 %s;letter-spacing:.19em;'
            . 'text-transform:uppercase;color:%s">%s</div>'
            . '</td></tr>'
            . '<tr><td align="center" style="padding:13px 24px;background:%s">'
            . '<span style="font:500 12px/1.4 %s;letter-spacing:.05em;color:%s">%s &nbsp;·&nbsp; %d %s</span>'
            . '</td></tr>',
            self::YELLOW,
            self::FONT,
            self::INK,
            self::esc(mb_strtolower(self::NOME, 'UTF-8')),
            self::FONT,
            self::AMBER,
            self::esc(self::LEMA),
            self::INK,
            self::FONT,
            self::SAND,
            self::esc(self::longDate($date)),
            $total,
            $total === 1 ? 'matéria' : 'matérias'
        );
    }

    /**
     * The index at the top: one line per article, with the topic's emoji.
     *
     * It is the most-read part of any newsletter — a reader in a hurry reads
     * only this and decides whether to open the rest. That is why the line
     * comes from the `blurb`, written to be a short promise, rather than from
     * the title, which is long by nature.
     *
     * @param list<array<string,mixed>> $materias
     */
    private static function index(array $materias): string
    {
        $lines = [];
        foreach ($materias as $m) {
            $texto = trim((string) ($m['blurb'] ?? '')) ?: (string) $m['title'];
            $link = (string) $m['link'];
            $label = self::esc($texto);

            $lines[] = sprintf(
                '<tr><td width="26" valign="top" style="padding:5px 0;font:400 15px/1.5 %s">%s</td>'
                . '<td valign="top" style="padding:5px 0;font:400 15px/1.5 %s;color:%s">%s</td></tr>',
                self::FONT,
                Catalog::emoji((string) $m['topic']),
                self::FONT,
                self::INK,
                $link !== ''
                    ? sprintf('<a href="%s" style="color:%s;text-decoration:none">%s</a>', self::esc($link), self::INK, $label)
                    : $label
            );
        }

        return self::card(
            'nesta edição',
            sprintf(
                '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0">%s</table>',
                implode('', $lines)
            ),
            self::SNOW
        );
    }

    /**
     * A rounded card with a small-caps heading. It is the layout's unit of
     * composition: the intro and the index use the same box.
     */
    private static function card(string $title, string $content, string $background, string $border = ''): string
    {
        return sprintf(
            '<tr><td style="padding:20px 22px 0 22px">'
            . '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="background:%s;border-radius:12px%s">'
            . '<tr><td style="padding:20px 22px 22px 22px">'
            . '<div style="padding-bottom:12px;font:700 11.5px/1 %s;letter-spacing:.17em;'
            . 'text-transform:uppercase;color:%s">%s</div>%s'
            . '</td></tr></table></td></tr>',
            $background,
            $border !== '' ? ';border-left:4px solid ' . $border : '',
            self::FONT,
            self::GRAPHITE,
            self::esc($title),
            $content
        );
    }

    /**
     * One article: topic label, headline, image when there is one, body and
     * source. Half the catalog publishes no image, so an article has to look
     * right without one — a missing image removes a block rather than leaving
     * a hole.
     *
     * @param array<string,mixed> $m
     */
    private static function article(array $m, bool $opensSection, bool $withRule): string
    {
        $topic = (string) $m['topic'];
        $link = (string) $m['link'];
        $title = self::esc((string) $m['title']);
        $parts = [];

        if ($withRule) {
            $parts[] = sprintf(
                '<tr><td style="padding:26px 22px 0 22px"><div style="border-top:1px solid %s;'
                . 'font-size:0;line-height:0">&nbsp;</div></td></tr>',
                self::RULE
            );
        }

        if ($opensSection) {
            $parts[] = sprintf(
                '<tr><td style="padding:22px 24px 0 24px;font:800 11.5px/1 %s;letter-spacing:.17em;'
                . 'text-transform:uppercase;color:%s">%s</td></tr>',
                self::FONT,
                self::AMBER,
                self::esc(Catalog::label($topic))
            );
        }

        $parts[] = sprintf(
            '<tr><td style="padding:10px 24px 0 24px;font:800 26px/1.24 %s;letter-spacing:-.015em;'
            . 'color:%s">%s</td></tr>',
            self::FONT,
            self::INK,
            $link !== ''
                ? sprintf('<a href="%s" style="color:%s;text-decoration:none">%s</a>', self::esc($link), self::INK, $title)
                : $title
        );

        $image = trim((string) ($m['image'] ?? ''));
        if ($image !== '') {
            // A fixed width alongside the CSS: Outlook ignores `max-width` on
            // <img> and blows the layout out to the image's native size.
            $parts[] = sprintf(
                '<tr><td style="padding:16px 24px 0 24px">'
                . '<img src="%s" alt="" width="592" style="display:block;width:100%%;max-width:592px;'
                . 'height:auto;border-radius:10px;border:0" border="0"></td></tr>',
                self::esc($image)
            );
        }

        $parts[] = sprintf(
            '<tr><td style="padding:14px 24px 0 24px;font:400 16px/1.68 %s;color:%s">%s</td></tr>'
            . '<tr><td style="padding:12px 24px 0 24px;font:500 12px/1.4 %s;letter-spacing:.05em;'
            . 'text-transform:uppercase;color:%s">%s</td></tr>',
            self::FONT,
            self::INK,
            self::esc((string) $m['summary']),
            self::FONT,
            self::GRAPHITE,
            self::esc(self::domain((string) $m['feed']))
        );

        return implode('', $parts);
    }

    private static function footer(): string
    {
        return sprintf(
            '<tr><td style="padding:26px 24px 30px 24px;text-align:center;font:400 11.5px/1.75 %s;color:%s">'
            . '<b style="color:%s">%s</b> &middot; 1 Crônicas 12:32<br>'
            . 'Composta pelo iode a partir de %d fontes verificadas, em %d temas.<br>'
            . 'A seleção e os textos são de um modelo de linguagem, escritos sobre os trechos publicados '
            . 'pelos próprios veículos.</td></tr>',
            self::FONT,
            self::GRAPHITE,
            self::INK,
            self::esc(self::NOME),
            self::feedCount(),
            count(Catalog::topics())
        );
    }

    /**
     * Texto para o Telegram, em parse_mode HTML.
     *
     * @param array{intro:string, articles:list<array<string,mixed>>} $edition
     * @return list<string> uma ou mais mensagens, já dentro do limite da Bot API
     */
    public static function telegram(array $edition, \DateTimeImmutable $date): array
    {
        $parts = [sprintf(
            "<b>%s</b> — %s\n<i>%s</i>",
            self::esc(self::NOME),
            self::esc(self::longDate($date)),
            self::esc(self::LEMA)
        )];

        if ($edition['intro'] !== '') {
            $parts[] = '<i>' . self::esc($edition['intro']) . '</i>';
        }

        if ($edition['articles'] === []) {
            $parts[] = 'Nada relevante o bastante no período.';
        }

        $previousTopic = null;
        foreach ($edition['articles'] as $m) {
            $topic = (string) $m['topic'];
            if ($topic !== $previousTopic) {
                $parts[] = '<b>' . self::esc(Catalog::label($topic)) . '</b>';
                $previousTopic = $topic;
            }

            $title = self::esc((string) $m['title']);
            $link = (string) $m['link'];
            $parts[] = sprintf(
                "%s\n%s",
                $link !== '' ? sprintf('<a href="%s">%s</a>', self::esc($link), $title) : $title,
                self::esc((string) $m['summary'])
            );
        }

        return self::pack($parts, Telegram::MAX_CHARS);
    }

    /**
     * Packs blocks into messages that fit the limit, breaking between blocks
     * and never inside a tag — cutting within an <a href> produces invalid
     * HTML and the Bot API rejects the whole message.
     *
     * @param list<string> $blocks
     * @return list<string>
     */
    private static function pack(array $blocks, int $limite): array
    {
        $messages = [];
        $current = '';

        foreach ($blocks as $bloco) {
            $candidate = $current === '' ? $bloco : $current . "\n\n" . $bloco;
            if (mb_strlen($candidate) <= $limite) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $messages[] = $current;
            }
            // A single block over the limit: cut by character and accept the
            // ugliness. It should not happen with a few-sentence summary.
            $current = mb_strlen($bloco) <= $limite ? $bloco : mb_substr($bloco, 0, $limite);
        }

        if ($current !== '') {
            $messages[] = $current;
        }

        return $messages === [] ? [''] : $messages;
    }

    public static function subject(array $edition, \DateTimeImmutable $date): string
    {
        $n = count($edition['articles']);

        return sprintf('%s — %s · %d %s', self::NOME, $date->format('d/m'), $n, $n === 1 ? 'matéria' : 'matérias');
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function domain(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return $host !== '' ? preg_replace('/^www\./', '', $host) ?? $host : '';
    }

    private static function feedCount(): int
    {
        $total = 0;
        foreach (Catalog::topics() as $topic) {
            $total += count(Catalog::feeds($topic));
        }

        return $total;
    }

    private static function longDate(\DateTimeImmutable $date): string
    {
        $days = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
        $months = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                  'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        // Written by hand because IntlDateFormatter needs the intl extension,
        // and this repository depends on nothing outside the default build.
        return sprintf(
            '%s, %d de %s de %d',
            $days[(int) $date->format('w')],
            (int) $date->format('j'),
            $months[(int) $date->format('n') - 1],
            (int) $date->format('Y')
        );
    }
}
