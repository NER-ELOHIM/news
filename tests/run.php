<?php

declare(strict_types=1);

/**
 * Suíte sem dependência externa: o adaptador precisa ser um arquivo solto que
 * roda em qualquer PHP 8.2+, então os testes também não puxam Composer.
 *
 * Nenhum teste acessa a rede. Os feeds são strings literais aqui embaixo.
 */

namespace Iode\News;

require __DIR__ . '/../src/Contract.php';
require __DIR__ . '/../src/Entry.php';
require __DIR__ . '/../src/Feed.php';
require __DIR__ . '/../src/Fetcher.php';

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
        fwrite(STDOUT, "  FALHA $name\n        " . $e->getMessage() . "\n");
    }
}

function assertSame(mixed $want, mixed $got, string $what = ''): void
{
    if ($want !== $got) {
        throw new \RuntimeException(sprintf(
            '%sesperado %s, veio %s',
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

check('requisição válida é aceita', function () {
    $req = Contract::readRequest('{"contract":1,"project":"n","path":"/tmp","since":null,"config":{"feeds":["x"]}}');
    assertSame('n', $req['project']);
    assertSame(null, $req['since']);
    assertSame(['x'], $req['config']['feeds']);
});

check('versão errada de contrato sai com código 2', function () {
    try {
        Contract::readRequest('{"contract":99}');
        throw new \RuntimeException('deveria ter lançado');
    } catch (ContractError $e) {
        assertSame(Contract::INCOMPATIBLE, $e->exitCode);
    }
});

check('JSON inválido sai com código 3', function () {
    try {
        Contract::readRequest('{isso não é json');
        throw new \RuntimeException('deveria ter lançado');
    } catch (ContractError $e) {
        assertSame(Contract::BAD_CONFIG, $e->exitCode);
    }
});

check('stdin vazio sai com código 3', function () {
    try {
        Contract::readRequest('   ');
        throw new \RuntimeException('deveria ter lançado');
    } catch (ContractError $e) {
        assertSame(Contract::BAD_CONFIG, $e->exitCode);
    }
});

check('since com offset é aceito', function () {
    $req = Contract::readRequest('{"contract":1,"since":"2026-08-28T00:00:00-03:00","config":{}}');
    assertTrue($req['since'] instanceof \DateTimeImmutable, 'since deveria virar DateTimeImmutable');
    assertSame('2026-08-28T00:00:00-03:00', Contract::formatTimestamp($req['since']));
});

check('since sem offset é rejeitado', function () {
    try {
        Contract::readRequest('{"contract":1,"since":"2026-08-28T00:00:00","config":{}}');
        throw new \RuntimeException('deveria ter lançado');
    } catch (ContractError $e) {
        assertSame(Contract::BAD_CONFIG, $e->exitCode);
    }
});

check('truncate não parte caractere multibyte', function () {
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

check('lê RSS 2.0 e limpa o HTML da descrição', function () {
    $entries = Feed::parse(RSS, 'https://exemplo.org/feed');
    assertSame(1, count($entries));
    assertSame('Primeira', $entries[0]->title);
    assertSame('Corpo com HTML', $entries[0]->body);
    assertSame('https://exemplo.org/1', $entries[0]->link);
    assertSame('2026-08-28T14:32:10-03:00', Contract::formatTimestamp($entries[0]->published));
});

check('lê Atom com namespace e autor', function () {
    $entries = Feed::parse(ATOM, 'https://exemplo.org/atom');
    assertSame(1, count($entries));
    assertSame('Entrada Atom', $entries[0]->title);
    assertSame('Autora', $entries[0]->author);
    assertSame('https://exemplo.org/a1', $entries[0]->link);
});

check('XML que não é feed vira ParseError', function () {
    try {
        Feed::parse('<html><body>oi</body></html>', 'https://exemplo.org/x');
        throw new \RuntimeException('deveria ter lançado');
    } catch (ParseError) {
        // esperado
    }
});

check('resposta vazia vira ParseError', function () {
    try {
        Feed::parse('', 'https://exemplo.org/x');
        throw new \RuntimeException('deveria ter lançado');
    } catch (ParseError) {
        // esperado
    }
});

// ── item do contrato ────────────────────────────────────────────────────────

check('a chave é estável entre coletas', function () {
    $a = Feed::parse(RSS, 'https://exemplo.org/feed')[0]->toItem(new \DateTimeImmutable());
    $b = Feed::parse(RSS, 'https://exemplo.org/feed')[0]->toItem(new \DateTimeImmutable());
    assertSame($a['key'], $b['key']);
    assertSame('external', $a['kind']);
});

check('a mesma entrada em feeds diferentes tem chaves diferentes', function () {
    $a = Feed::parse(RSS, 'https://exemplo.org/feed')[0]->toItem(new \DateTimeImmutable());
    $b = Feed::parse(RSS, 'https://outro.org/feed')[0]->toItem(new \DateTimeImmutable());
    assertTrue($a['key'] !== $b['key'], 'chaves deveriam diferir por origem');
});

check('entrada sem data é marcada como estimada', function () {
    $semData = str_replace('<pubDate>Fri, 28 Aug 2026 14:32:10 -0300</pubDate>', '', RSS);
    $item = Feed::parse($semData, 'https://exemplo.org/feed')[0]->toItem(new \DateTimeImmutable());
    assertSame(true, $item['meta']['ts_estimado']);
});

check('a resposta sai no formato do contrato', function () {
    $item = Feed::parse(ATOM, 'https://exemplo.org/atom')[0]->toItem(new \DateTimeImmutable());
    $decoded = json_decode(Contract::writeResponse([$item], ['um aviso']), true, 512, JSON_THROW_ON_ERROR);
    assertSame(1, $decoded['contract']);
    assertSame(1, count($decoded['items']));
    assertSame(['um aviso'], $decoded['warnings']);
});

check('sem avisos a chave warnings é omitida', function () {
    $decoded = json_decode(Contract::writeResponse([], []), true, 512, JSON_THROW_ON_ERROR);
    assertTrue(!array_key_exists('warnings', $decoded), 'warnings não deveria estar presente');
});

// ── rede ────────────────────────────────────────────────────────────────────

check('esquema não-HTTP é recusado sem tocar a rede', function () {
    try {
        (new Fetcher())->get('file:///etc/passwd');
        throw new \RuntimeException('deveria ter lançado');
    } catch (FetchError $e) {
        assertTrue(str_contains($e->getMessage(), 'esquema'), 'mensagem inesperada: ' . $e->getMessage());
    }
});

fwrite(STDOUT, sprintf("\n%d passaram, %d falharam\n", $passed, $failed));
exit($failed === 0 ? 0 : 1);
