# news

Adaptador do `iode` que coleta entradas de feeds **RSS 2.0, RSS 1.0 (RDF) e Atom**.

PHP puro, sem Composer, sem framework, sem dependência: só `SimpleXML` e `curl`,
que vêm na instalação padrão. O arquivo é solto e roda em qualquer PHP 8.2+.

## Por que PHP e não Go

Adaptador é executável que lê `stdin` e escreve `stdout` — o contrato do `iode` é
agnóstico de linguagem por desenho. Feed é XML, e XML é onde o PHP é bom desde
sempre. A política de uma-linguagem-só vale para o motor, não para os projetos ao
redor dele. Ver a política de linguagem no `README.md` do `iode`.

Não use Laravel aqui: o motor invoca o adaptador com timeout, e subir framework e
container para imprimir um JSON é a ferramenta errada. Laravel serve no painel,
que consome a API HTTP.

## Uso

```sh
echo '{"contract":1,"project":"noticias","path":"/tmp","since":null,
       "config":{"feeds":["https://exemplo.org/feed.xml"]}}' | bin/news
```

### Configuração

O objeto `config` da requisição aceita:

| chave | tipo | padrão | o que faz |
|---|---|---|---|
| `feeds` | lista de URL | — | **obrigatório**; vazio sai com código 3 |
| `timeout_seconds` | inteiro | 15 | timeout por feed |

### Códigos de saída

| código | quando |
|---|---|
| 0 | sucesso, mesmo com avisos |
| 1 | **todos** os feeds falharam; o motor tenta de novo |
| 2 | versão de contrato desconhecida |
| 3 | `stdin` vazio, JSON inválido, `since` sem offset, ou `feeds` ausente |

Uma fonte quebrada vira aviso, não erro: um feed fora do ar não pode derrubar os
outros. É o princípio 6 do `iode` — falhar alto, sem corromper o resto.

## Instalação como adaptador

```sh
install -D -m 0755 bin/news ~/.config/iode/adapters/news
cp -r src ~/.config/iode/adapters/
```

E no `config.toml` do `iode`:

```toml
[[projects]]
name = "noticias"
path = "~/.local/share/iode/feeds/noticias"   # diretório vazio; ver nota abaixo
adapters = ["news"]
```

> **Nota sobre o contrato.** O `CONTRATOS.md` do `iode` invoca adaptador por
> projeto, com `cwd` no diretório dele, mas prevê `kind = "external"` para fonte
> de RSS e API — que não tem diretório local. O diretório vazio acima contorna
> isso. O conserto limpo seria um bloco `[[sources]]` separado, ou `path`
> opcional quando todos os adaptadores forem externos.

## Testes

```sh
php tests/run.php
```

17 casos, sem rede e sem dependência. Os feeds de teste são strings literais.
