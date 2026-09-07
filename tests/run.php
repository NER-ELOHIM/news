<?php

declare(strict_types=1);

/**
 * A dependency-free suite: the adapter has to be loose files that run on any
 * PHP 8.2+, so the tests do not pull in Composer either.
 *
 * No test touches the network. Feed fixtures are string literals below, and
 * the Anthropic API is answered by a fake server on loopback — see
 * tests/fake-api.php — so the real HTTP client is exercised rather than a stub.
 */

namespace Iode\News;

require __DIR__ . '/../src/Contract.php';
require __DIR__ . '/../src/Catalog.php';
require __DIR__ . '/../src/Entry.php';
require __DIR__ . '/../src/Feed.php';
require __DIR__ . '/../src/Fetcher.php';
require __DIR__ . '/../src/Prefilter.php';
require __DIR__ . '/../src/Claude.php';
require __DIR__ . '/../src/Editor.php';
require __DIR__ . '/../src/Render.php';
require __DIR__ . '/../src/Telegram.php';

$passed = 0;
$failed = 0;

function check(string $name, callable $body): void
{
    global $passed, $failed;
    try {
        $body();
        $passed++;
        fwrite(STDOUT, "  ok    $name\n");
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDOUT, "  FAIL  $name\n        " . $e->getMessage() . "\n");
    }
}

function assertSame(mixed $want, mixed $got, string $what = ''): void
{
    if ($want !== $got) {
        throw new \RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $what !== '' ? $what . ': ' : '',
            var_export($want, true),
            var_export($got, true)
        ));
    }
}

function assertTrue(bool $cond, string $what): void
{
    if (!$cond) {
        throw new \RuntimeException($what);
    }
}

// ── contrato ────────────────────────────────────────────────────────────────

check('a valid request is accepted', function () {
    $req = Contract::readRequest('{"contract":1,"project":"n","path":"/tmp","since":null,"config":{"feeds":["x"]}}');
    assertSame('n', $req['project']);
    assertSame(null, $req['since']);
    assertSame(['x'], $req['config']['feeds']);
});

check('a wrong contract version exits with code 2', function () {
    try {
        Contract::readRequest('{"contract":99}');
        throw new \RuntimeException('should have thrown');
    } catch (ContractError $e) {
        assertSame(Contract::INCOMPATIBLE, $e->exitCode);
    }
});

check('invalid JSON exits with code 3', function () {
    try {
        Contract::readRequest('{isso não é json');
        throw new \RuntimeException('should have thrown');
    } catch (ContractError $e) {
        assertSame(Contract::BAD_CONFIG, $e->exitCode);
    }
});

check('empty stdin exits with code 3', function () {
    try {
        Contract::readRequest('   ');
        throw new \RuntimeException('should have thrown');
    } catch (ContractError $e) {
        assertSame(Contract::BAD_CONFIG, $e->exitCode);
    }
});

check('since with an offset is accepted', function () {
    $req = Contract::readRequest('{"contract":1,"since":"2026-08-28T00:00:00-03:00","config":{}}');
    assertTrue($req['since'] instanceof \DateTimeImmutable, 'since deveria virar DateTimeImmutable');
    assertSame('2026-08-28T00:00:00-03:00', Contract::formatTimestamp($req['since']));
});

check('since without an offset is rejected', function () {
    try {
        Contract::readRequest('{"contract":1,"since":"2026-08-28T00:00:00","config":{}}');
        throw new \RuntimeException('should have thrown');
    } catch (ContractError $e) {
        assertSame(Contract::BAD_CONFIG, $e->exitCode);
    }
});

check('truncate never splits a multibyte character', function () {
    $got = Contract::truncate('ação', 3); // "a" + 2 bytes do "ç"
    assertTrue(mb_check_encoding($got, 'UTF-8'), 'saiu UTF-8 inválido: ' . bin2hex($got));
    assertTrue(strlen($got) <= 3, 'passou do limite de bytes');
});

// ── parse de feed ───────────────────────────────────────────────────────────

const RSS = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel>
  <title>Exemplo</title>
  <item>
    <title>Primeira</title>
    <link>https://exemplo.org/1</link>
    <description><![CDATA[<p>Corpo com <b>HTML</b></p>]]></description>
    <pubDate>Fri, 28 Aug 2026 14:32:10 -0300</pubDate>
    <guid>https://exemplo.org/1</guid>
  </item>
</channel></rss>
XML;

const ATOM = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Exemplo</title>
  <entry>
    <id>tag:exemplo.org,2026:1</id>
    <title>Entrada Atom</title>
    <link rel="alternate" href="https://exemplo.org/a1"/>
    <summary>resumo</summary>
    <published>2026-08-28T14:32:10-03:00</published>
    <author><name>Autora</name></author>
  </entry>
</feed>
XML;

check('reads RSS 2.0 and strips HTML from the description', function () {
    $entries = Feed::parse(RSS, 'https://exemplo.org/feed');
    assertSame(1, count($entries));
    assertSame('Primeira', $entries[0]->title);
    assertSame('Corpo com HTML', $entries[0]->body);
    assertSame('https://exemplo.org/1', $entries[0]->link);
    assertSame('2026-08-28T14:32:10-03:00', Contract::formatTimestamp($entries[0]->published));
});

check('reads Atom with a namespace and an author', function () {
    $entries = Feed::parse(ATOM, 'https://exemplo.org/atom');
    assertSame(1, count($entries));
    assertSame('Entrada Atom', $entries[0]->title);
    assertSame('Autora', $entries[0]->author);
    assertSame('https://exemplo.org/a1', $entries[0]->link);
});

check('XML that is not a feed becomes a ParseError', function () {
    try {
        Feed::parse('<html><body>oi</body></html>', 'https://exemplo.org/x');
        throw new \RuntimeException('should have thrown');
    } catch (ParseError) {
        // esperado
    }
});

check('an empty response becomes a ParseError', function () {
    try {
        Feed::parse('', 'https://exemplo.org/x');
        throw new \RuntimeException('should have thrown');
    } catch (ParseError) {
        // esperado
    }
});

// ── item do contrato ────────────────────────────────────────────────────────

check('the key is stable across collections', function () {
    $a = Feed::parse(RSS, 'https://exemplo.org/feed')[0]->toItem(new \DateTimeImmutable());
    $b = Feed::parse(RSS, 'https://exemplo.org/feed')[0]->toItem(new \DateTimeImmutable());
    assertSame($a['key'], $b['key']);
    assertSame('external', $a['kind']);
});

check('the same entry from different feeds gets different keys', function () {
    $a = Feed::parse(RSS, 'https://exemplo.org/feed')[0]->toItem(new \DateTimeImmutable());
    $b = Feed::parse(RSS, 'https://outro.org/feed')[0]->toItem(new \DateTimeImmutable());
    assertTrue($a['key'] !== $b['key'], 'chaves deveriam diferir por origem');
});

check('an entry with no date is flagged as estimated', function () {
    $semData = str_replace('<pubDate>Fri, 28 Aug 2026 14:32:10 -0300</pubDate>', '', RSS);
    $item = Feed::parse($semData, 'https://exemplo.org/feed')[0]->toItem(new \DateTimeImmutable());
    assertSame(true, $item['meta']['ts_estimado']);
});

check('the response matches the contract format', function () {
    $item = Feed::parse(ATOM, 'https://exemplo.org/atom')[0]->toItem(new \DateTimeImmutable());
    $decoded = json_decode(Contract::writeResponse([$item], ['um aviso']), true, 512, JSON_THROW_ON_ERROR);
    assertSame(1, $decoded['contract']);
    assertSame(1, count($decoded['items']));
    assertSame(['um aviso'], $decoded['warnings']);
});

check('with no warnings the warnings key is omitted', function () {
    $decoded = json_decode(Contract::writeResponse([], []), true, 512, JSON_THROW_ON_ERROR);
    assertTrue(!array_key_exists('warnings', $decoded), 'warnings não deveria estar presente');
});

// ── network ────────────────────────────────────────────────────────────────────

check('a non-HTTP scheme is refused without touching the network', function () {
    try {
        (new Fetcher())->get('file:///etc/passwd');
        throw new \RuntimeException('should have thrown');
    } catch (FetchError $e) {
        assertTrue(str_contains($e->getMessage(), 'scheme'), 'unexpected message: ' . $e->getMessage());
    }
});

check('getMany refuses a non-HTTP scheme without opening a connection', function () {
    $r = (new Fetcher())->getMany(['file:///etc/passwd', 'gopher://exemplo.org']);
    assertSame(2, count($r));
    foreach ($r as $url => $erro) {
        assertTrue($erro instanceof FetchError, $url . ' should have failed');
    }
});

check('getMany returns one result per requested URL, offline', function () {
    // Porta fechada em loopback: falha de conexão, não de DNS, e é instantânea.
    $r = (new Fetcher(1, 4, 5))->getMany(['http://127.0.0.1:1/a', 'http://127.0.0.1:1/b']);
    assertSame(['http://127.0.0.1:1/a', 'http://127.0.0.1:1/b'], array_keys($r));
    assertTrue($r['http://127.0.0.1:1/a'] instanceof FetchError, 'should have failed');
});

// ── catalog ────────────────────────────────────────────────────────────────

check('every catalog topic has feeds, and all are HTTPS', function () {
    $temas = Catalog::topics();
    assertTrue(count($temas) === 6, 'esperados 6 temas, vieram ' . count($temas));
    foreach ($temas as $tema) {
        $feeds = Catalog::feeds($tema);
        assertTrue($feeds !== [], "tema $tema está sem feeds");
        foreach ($feeds as $url) {
            assertTrue(str_starts_with($url, 'https://'), "feed não-HTTPS em $tema: $url");
        }
        assertTrue(Catalog::label($tema) !== '', "tema $tema está sem rótulo");
    }
});

check('resolve maps each URL to one topic, with no duplicates', function () {
    $mapa = Catalog::resolve(['saas', 'engineering']);
    assertSame(count(array_unique(array_keys($mapa))), count($mapa), 'URL repetida no mapa');
    assertSame('saas', $mapa['https://tomtunguz.com/index.xml']);
    assertSame('engineering', $mapa['https://go.dev/blog/feed.atom']);
});

check('an unknown topic resolves to nothing', function () {
    assertTrue(!Catalog::exists('astrologia'), 'astrologia não deveria existir');
    assertSame([], Catalog::resolve(['astrologia']));
});

check('the item carries the topic it was collected under', function () {
    $e = new Entry('id', 'T', 'B', 'https://x.org/1', '', new \DateTimeImmutable('2026-09-07T10:00:00-03:00'), 'https://x.org/f');
    $item = $e->toItem(new \DateTimeImmutable('now'), 'geopolitics');
    assertSame('geopolitics', $item['meta']['topic']);
    assertSame(Catalog::GENERAL, $e->toItem(new \DateTimeImmutable('now'))['meta']['topic']);
});

// ── prefilter ──────────────────────────────────────────────────────────────

/** Headlines with no long word in common, so dedup does not fire before the caps. */
const TITULOS = ['Companhia anuncia recompra', 'Bolsa fecha estavel', 'Juros futuros disparam',
                 'Petroleo recua nova york', 'Varejo surpreende trimestre', 'Cambio oscila sessao',
                 'Exportacoes crescem julho', 'Industria desacelera ritmo', 'Credito encolhe bancos',
                 'Emprego formal avanca'];

function itemDeTeste(string $titulo, string $ts, string $feed = 'https://f.org/1', string $tema = 'saas', string $link = 'https://x.org/a'): array
{
    return ['key' => hash('sha256', $titulo . $ts . $feed), 'kind' => 'external', 'ts' => $ts, 'title' => $titulo,
            'body' => '', 'meta' => ['feed' => $feed, 'topic' => $tema, 'link' => $link]];
}

check('the prefilter drops what falls outside the window', function () {
    $agora = new \DateTimeImmutable('2026-09-07T12:00:00-03:00');
    $r = (new Prefilter(36))->apply([
        itemDeTeste('Recente', '2026-09-07T09:00:00-03:00'),
        itemDeTeste('Antigo', '2026-09-01T09:00:00-03:00'),
    ], $agora);
    assertSame(1, count($r['items']));
    assertSame('Recente', $r['items'][0]['title']);
    assertSame(1, $r['dropped']['stale']);
});

check('a topic window beats the default window', function () {
    $agora = new \DateTimeImmutable('2026-09-07T12:00:00-03:00');
    // Cinco dias atrás: fora da janela de 36h do mercado, dentro da de 240h
    // dos estudos. O mesmo instante, dois destinos, e é isso que se testa.
    $velho = '2026-09-02T09:00:00-03:00';
    $r = (new Prefilter(36))->apply([
        itemDeTeste('Estudo do Fed sobre credito', $velho, 'https://a.org/f', 'economics'),
        itemDeTeste('Bolsa fecha em alta hoje', $velho, 'https://b.org/f', 'markets'),
    ], $agora);
    assertSame(1, count($r['items']));
    assertSame('economics', $r['items'][0]['meta']['topic']);
    assertSame(1, $r['dropped']['stale']);
});

check('the prefilter drops items with no link or no title', function () {
    $agora = new \DateTimeImmutable('2026-09-07T12:00:00-03:00');
    $r = (new Prefilter())->apply([
        itemDeTeste('Sem link', '2026-09-07T09:00:00-03:00', link: ''),
        itemDeTeste('', '2026-09-07T09:00:00-03:00'),
        itemDeTeste('Boa', '2026-09-07T09:00:00-03:00'),
    ], $agora);
    assertSame(1, count($r['items']));
    assertSame(2, $r['dropped']['incomplete']);
});

check('the prefilter collapses the same headline from two outlets', function () {
    $agora = new \DateTimeImmutable('2026-09-07T12:00:00-03:00');
    $r = (new Prefilter())->apply([
        itemDeTeste('Fed corta juros em 0,25 p.p.', '2026-09-07T10:00:00-03:00', 'https://a.org/f'),
        itemDeTeste('Fed corta os juros em 0,25 pp', '2026-09-07T09:00:00-03:00', 'https://b.org/f'),
    ], $agora);
    assertSame(1, count($r['items']));
    assertSame(1, $r['dropped']['duplicate']);
    // Fica o mais recente, que é o critério de ordenação declarado.
    assertSame('Fed corta juros em 0,25 p.p.', $r['items'][0]['title']);
});

check('the prefilter stops a high-volume feed from dominating', function () {
    $agora = new \DateTimeImmutable('2026-09-07T12:00:00-03:00');
    $itens = [];
    for ($i = 0; $i < 10; $i++) {
        $itens[] = itemDeTeste(TITULOS[$i], '2026-09-07T09:00:00-03:00', 'https://barulho.org/f');
    }
    $r = (new Prefilter(36, 3))->apply($itens, $agora);
    assertSame(3, count($r['items']));
    assertSame(7, $r['dropped']['feed_cap']);
});

check('the prefilter honours the per-topic cap', function () {
    $agora = new \DateTimeImmutable('2026-09-07T12:00:00-03:00');
    $itens = [];
    for ($i = 0; $i < 8; $i++) {
        $itens[] = itemDeTeste(TITULOS[$i], '2026-09-07T09:00:00-03:00', "https://f$i.org/f", 'saas');
    }
    $r = (new Prefilter(36, 8, 2))->apply($itens, $agora);
    assertSame(2, count($r['items']));
    assertSame(6, $r['dropped']['topic_cap']);
});

check('the signature ignores accents, punctuation and word order', function () {
    assertSame(
        Prefilter::signature('Inflação recua após decisão do Copom'),
        Prefilter::signature('decisao do copom: apos, INFLACAO recua!')
    );
    assertTrue(
        Prefilter::signature('Dólar sobe') !== Prefilter::signature('Dólar cai'),
        'manchetes opostas não deveriam colidir'
    );
});


// ── API client, editor and renderer ────────────────────────────────────────
//
// The fake server binds to loopback. That is not "the network" in the sense
// the rule cares about — nothing leaves the machine — and it is the same
// technique the sibling `bounty` adapter uses to exercise the real HTTP client
// instead of a stand-in.

/** @return array{0:string,1:callable} base e o encerrador */
function subirFakeApi(?string $payload = null): array
{
    $porta = 0;
    for ($tentativa = 0; $tentativa < 20; $tentativa++) {
        $candidata = random_int(20000, 60000);
        $teste = @stream_socket_server("tcp://127.0.0.1:$candidata", $e1, $e2);
        if ($teste !== false) {
            fclose($teste);
            $porta = $candidata;
            break;
        }
    }
    if ($porta === 0) {
        throw new \RuntimeException('no free port found');
    }

    $env = $payload !== null ? ['IODE_FAKE_PAYLOAD' => $payload] : [];
    $descritores = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $proc = proc_open(
        ['php', '-S', "127.0.0.1:$porta", '-t', __DIR__, __DIR__ . '/fake-api.php'],
        $descritores,
        $pipes,
        null,
        $env + ['PATH' => getenv('PATH')]
    );
    if (!is_resource($proc)) {
        throw new \RuntimeException('proc_open failed');
    }

    // Espera a porta aceitar conexão, em vez de dormir um tempo fixo.
    for ($i = 0; $i < 100; $i++) {
        $c = @fsockopen('127.0.0.1', $porta, $errno, $erro, 0.2);
        if ($c !== false) {
            fclose($c);
            break;
        }
        usleep(50_000);
    }

    return ["http://127.0.0.1:$porta", static function () use ($proc): void {
        proc_terminate($proc);
        proc_close($proc);
    }];
}

check('the client reads the response and records token usage', function () {
    $arquivo = tempnam(sys_get_temp_dir(), 'iode');
    file_put_contents($arquivo, '{"intro":"Um fio","selected":[]}');
    [$base, $parar] = subirFakeApi($arquivo);
    try {
        $c = new Claude('chave-de-teste', 'claude-opus-5', 10, 1, $base . '/ok');
        $r = $c->structured('sistema', 'prompt', ['type' => 'object']);
        assertSame('Um fio', $r['intro']);
        assertSame(1234, $c->lastUsage()['input_tokens']);
        assertSame(567, $c->lastUsage()['output_tokens']);
    } finally {
        $parar();
        @unlink($arquivo);
    }
});

check('refusal, truncation and non-JSON each fail with a named cause', function () {
    [$base, $parar] = subirFakeApi();
    try {
        $casos = ['/recusa' => 'refused', '/truncado' => 'truncated', '/nao-json' => 'not JSON'];
        foreach ($casos as $rota => $trecho) {
            try {
                (new Claude('k', 'claude-opus-5', 10, 1, $base . $rota))->structured('s', 'p', []);
                throw new \RuntimeException("$rota deveria ter lançado");
            } catch (ClaudeError $e) {
                assertTrue(str_contains($e->getMessage(), $trecho), "$rota: got " . $e->getMessage());
            }
        }
    } finally {
        $parar();
    }
});

check('a 4xx is not retried, and the API message survives', function () {
    [$base, $parar] = subirFakeApi();
    try {
        $inicio = microtime(true);
        try {
            // 3 tentativas com backoff levariam 6s; um 400 tem de falhar já.
            (new Claude('k', 'claude-opus-5', 10, 3, $base . '/erro-cliente'))->structured('s', 'p', []);
            throw new \RuntimeException('should have thrown');
        } catch (ClaudeError $e) {
            assertTrue(str_contains($e->getMessage(), 'credit balance'), 'message lost: ' . $e->getMessage());
            assertTrue(microtime(true) - $inicio < 3.0, 'retried a 400 instead of failing fast');
        }
    } finally {
        $parar();
    }
});

check('the editor drops an invented id and honours the per-topic cap', function () {
    $arquivo = tempnam(sys_get_temp_dir(), 'iode');
    file_put_contents($arquivo, json_encode(['intro' => 'O dia', 'selected' => [
        ['id' => 'm0', 'title' => 'Um', 'summary' => 'Resumo um.'],
        ['id' => 'm1', 'title' => 'Dois', 'summary' => 'Resumo dois.'],
        ['id' => 'm2', 'title' => 'Tres', 'summary' => 'Resumo tres.'],
        ['id' => 'INVENTADO', 'title' => 'Fantasma', 'summary' => 'Nao existe.'],
        ['id' => 'm0', 'title' => 'Um de novo', 'summary' => 'Repetido.'],
    ]], JSON_UNESCAPED_UNICODE));

    [$base, $parar] = subirFakeApi($arquivo);
    try {
        $itens = [
            itemDeTeste('Um', '2026-09-07T10:00:00-03:00', 'https://a.org/f', 'saas'),
            itemDeTeste('Dois', '2026-09-07T10:00:00-03:00', 'https://b.org/f', 'saas'),
            itemDeTeste('Tres', '2026-09-07T10:00:00-03:00', 'https://c.org/f', 'saas'),
        ];
        $editor = new Editor(new Claude('k', 'claude-opus-5', 10, 1, $base . '/ok'), 12, 2);
        $edicao = $editor->edit($itens, new \DateTimeImmutable('2026-09-07T12:00:00-03:00'));

        assertSame('O dia', $edicao['intro']);
        // Três chegaram do mesmo tema, mas o teto é 2; o inventado e o
        // repetido somem sem derrubar a edição.
        assertSame(2, count($edicao['articles']));
        assertSame('Um', $edicao['articles'][0]['title']);
        assertSame('https://x.org/a', $edicao['articles'][0]['link']);
    } finally {
        $parar();
        @unlink($arquivo);
    }
});

check('an empty edition never calls the API', function () {
    // Endpoint que não existe: se o editor tentasse falar, isto explodiria.
    $editor = new Editor(new Claude('k', 'claude-opus-5', 1, 1, 'http://127.0.0.1:1/'), 12, 2);
    $r = $editor->edit([], new \DateTimeImmutable('now'));
    assertSame([], $r['articles']);
    assertSame('', $r['intro']);
});

// ── renderer ──────────────────────────────────────────────────────────────────

function edicaoDeTeste(): array
{
    return ['intro' => 'O dia foi de <juros> & inflação.', 'articles' => [
        ['topic' => 'markets', 'title' => 'Copom mantém a Selic', 'original_title' => 'Copom holds',
         'summary' => 'O comitê manteve a taxa. Isso trava o custo do seu crédito por mais 45 dias.',
         'link' => 'https://exemplo.org/a?x=1&y=2', 'feed' => 'https://www.infomoney.com.br/feed/', 'ts' => '2026-09-07T10:00:00-03:00'],
        ['topic' => 'scripture', 'title' => 'Paulo em Filipos', 'original_title' => 'Paul in Philippi',
         'summary' => 'Uma leitura do episódio da escrava vidente. Muda como se lê Atos 16.',
         'link' => 'https://exemplo.org/b', 'feed' => 'https://www.9marks.org/feed/', 'ts' => '2026-09-06T10:00:00-03:00'],
    ]];
}

check('the HTML escapes third-party text and leaves no tag unclosed', function () {
    $html = Render::html(edicaoDeTeste(), new \DateTimeImmutable('2026-09-07T08:00:00-03:00'));
    assertTrue(!str_contains($html, '<juros>'), 'texto de terceiro entrou sem escapar');
    assertTrue(str_contains($html, '&lt;juros&gt;'), 'a abertura deveria estar escapada');
    assertTrue(str_contains($html, 'x=1&amp;y=2'), 'o & do link deveria estar escapado');
    assertTrue(str_contains($html, 'MERCADO') && str_contains($html, 'ESTUDOS BÍBLICOS'), 'faltou rótulo de tema');
    assertSame(substr_count($html, '<tr'), substr_count($html, '</tr>'), 'tr aberta sem fechar');
    assertSame(substr_count($html, '<table'), substr_count($html, '</table>'), 'table aberta sem fechar');
});

check('an empty edition renders HTML that explains itself', function () {
    $html = Render::html(['intro' => '', 'articles' => []], new \DateTimeImmutable('2026-09-07T08:00:00-03:00'));
    assertTrue(str_contains($html, 'Nada relevante'), 'faltou o texto de edição vazia');
    assertTrue(str_contains($html, '0 matérias'), 'a contagem deveria aparecer');
});

check('the Telegram text fits the Bot API limit', function () {
    $edicao = edicaoDeTeste();
    for ($i = 0; $i < 60; $i++) {
        $edicao['articles'][] = ['topic' => 'saas', 'title' => "Materia $i", 'original_title' => '',
            'summary' => str_repeat('Frase de resumo com tamanho realista. ', 4),
            'link' => "https://exemplo.org/$i", 'feed' => 'https://f.org/f', 'ts' => '2026-09-07T10:00:00-03:00'];
    }
    $msgs = Render::telegram($edicao, new \DateTimeImmutable('2026-09-07T08:00:00-03:00'));
    assertTrue(count($msgs) > 1, 'uma edição longa deveria ser fatiada');
    foreach ($msgs as $m) {
        assertTrue(mb_strlen($m) <= Telegram::MAX_CHARS, 'mensagem de ' . mb_strlen($m) . ' passa do limite');
        // Fatiar no meio de uma tag produz HTML inválido e a API recusa tudo.
        assertSame(substr_count($m, '<a href'), substr_count($m, '</a>'), 'âncora cortada ao meio');
    }
});

check('the email subject agrees in number', function () {
    $d = new \DateTimeImmutable('2026-09-07T08:00:00-03:00');
    assertTrue(str_contains(Render::subject(edicaoDeTeste(), $d), '2 matérias'), 'plural errado');
    assertTrue(str_contains(Render::subject(['intro' => '', 'articles' => [['topic' => 'saas', 'title' => 'x',
        'summary' => 'y', 'link' => '', 'feed' => '', 'ts' => '']]], $d), '1 matéria'), 'singular errado');
});


fwrite(STDOUT, sprintf("\n%d passed, %d failed\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
