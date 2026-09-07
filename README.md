# news

A personal weekly magazine. It collects **29 curated feeds** across six topics,
picks what is worth reading, writes it up, and delivers it by email and Telegram.

Plain PHP 8.2+. No Composer, no framework, no dependencies — only `SimpleXML`
and `curl`, which ship with the standard installation. It is a set of loose
files that run anywhere PHP does.

```
29 feeds  →  1,701 entries  →  151 candidates  →  30 articles
             (raw)             (deterministic     (editorial
                                filter, free)      selection, one LLM call)
```

Measured on a real run: **3.5 seconds** to fetch every feed.

---

## Two commands, and why

| | what it does | when it runs |
|---|---|---|
| `bin/news` | fetches feeds, filters noise, prints JSON | as an [iode](https://github.com/NER-ELOHIM/iode) adapter, on every `sync` |
| `bin/digest` | selects, writes and **delivers** the edition | on a weekly timer, or by hand |

The split is not tidiness — it is correctness. An adapter must be idempotent,
because `iode sync` may run twice, and **sending email is not idempotent**. If
delivery lived inside the adapter, a repeated sync would mail the edition
again. So delivery lives in a command that only runs when told to, and **no
send flag means nothing leaves the machine**.

```sh
bin/news --temas                                 # list catalog topics
echo "$REQ" | bin/news | bin/digest --estimar    # cost, before spending
echo "$REQ" | bin/news | bin/digest --json       # edition to stdout, no delivery
bin/revista                                      # the whole thing, one command
```

---

## How the noise is cut

In two stages, and the order is what keeps the cost down.

### 1. Deterministic — free, reproducible

[`src/Prefilter.php`](src/Prefilter.php) drops anything decidable by counting:
outside the time window, no title, no link, the same headline from two outlets,
too many items from one feed, too many from one topic. On a real run this takes
**1,701 entries down to 151**.

Same input, same output, always. No network, no clock of its own, no randomness.

### 2. Editorial — one model call

[`src/Editor.php`](src/Editor.php) receives only what survived and does the part
that needs judgment: which 30 of those 151 change the reader's week, and how to
say each one in four to six sentences.

**Whatever can be decided by counting is decided by counting.** The model never
sees the other 1,550.

### The model proposes; the program does not obey

The model's output is text and choices, never instructions. It is validated
against what was sent: an id that was not in the input is **discarded, not
fetched**, and the per-topic caps are re-applied to the response. This program
reads hostile content from the internet every week, and content from the
internet does not get to steer it.

---

## Topics

The catalog lives in code ([`src/Catalog.php`](src/Catalog.php)), not in
configuration, because every URL was verified by hand against the real server.
Someone else's feed breaks without warning — it moves, dies, goes behind
Cloudflare. When that happens the fix is a dated, reviewable commit, not a line
edited in a TOML file nobody remembers writing.

| topic | feeds | window | why that window |
|---|---|---|---|
| `mercado-financeiro` | 4 | 36h | market headlines age in hours |
| `estudos-mercado` | 3 | 240h | central banks publish when they have something to say |
| `programacao` | 8 | 120h | a good technical post is still good next week |
| `geopolitica` | 3 | 72h | analysis, not breaking news |
| `saas` | 5 | 48h | |
| `estudos-biblicos` | 6 | 240h | half Portuguese, half English — scholarly material in Portuguese is scarce |

The window is **per topic** on purpose. With a single 36-hour window, the two
study topics would be empty in almost every edition — and they are half the
reason this exists. A weekly edition (`cadencia = "semanal"`) raises every
window to a 168-hour floor, so nothing between two editions falls through.

---

## Why the fetching is concurrent

29 feeds in series, at an 8-second timeout, is a **four-minute** worst case —
and the engine kills an adapter at 60 seconds, losing the whole collection
because of half a dozen slow servers.

[`src/Fetcher.php`](src/Fetcher.php) uses `curl_multi`, so the worst case is the
slowest single feed rather than the sum of all of them. Two independent limits:
`timeout_seconds` is per request, `budget_seconds` caps the whole batch. The
second exists because the first does not protect you from thirty slow feeds at
once — when the budget runs out, whatever has not arrived becomes a warning and
the edition ships with what did.

---

## Configuration

The request's `config` object accepts:

| key | type | default | what it does |
|---|---|---|---|
| `categories` | list | — | catalog topics; an unknown topic exits with code 3 |
| `feeds` | list of URL | — | one-off sources outside the catalog, under topic `geral` |
| `cadencia` | `diaria`/`semanal` | `diaria` | `semanal` puts a 168h floor under every window |
| `timeout_seconds` | int | 15 | per feed |
| `budget_seconds` | int | 45 | for the whole batch |
| `max_parallel` | int | 8 | concurrent requests |
| `janela_horas` | int | 36 | window for one-off feeds, which have no topic |
| `teto_por_feed` | int | 8 | |
| `teto_por_tema` | int | 25 | how many reach the model, not how many ship |

At least one of `categories` or `feeds` is required.

### Credentials

Everything through the environment. The engine forwards anything prefixed
`IODE_` to the subprocess, and never reads a credential file on its own.

| variable | for |
|---|---|
| `IODE_ANTHROPIC_API_KEY` | selection and summaries |
| `IODE_TELEGRAM_TOKEN`, `IODE_TELEGRAM_CHAT_ID` | Telegram delivery |
| `IODE_SMTP_HOST`, `IODE_SMTP_PORT` | defaults to `smtp.gmail.com:587` |
| `IODE_SMTP_USER`, `IODE_SMTP_PASS` | **an app password**, never the account password |
| `IODE_NEWS_EMAIL` | recipient |

[`src/Mailer.php`](src/Mailer.php) speaks SMTP directly over a socket, with
`STARTTLS` mandatory: if `STARTTLS` fails the send aborts, because continuing
would mean putting the password on the wire in the clear.

---

## Cost

`--estimar` computes it before you spend anything, from a real collection:

```
model                per edition   per month   per year
claude-opus-5           $ 0.546      $ 2.37     $ 28.39
claude-sonnet-5         $ 0.218      $ 0.95     $ 11.36
claude-haiku-4-5        $ 0.109      $ 0.47     $  5.68
```

Weekly cadence, 52 editions a year. Numbers are **measured** — 22,605 input and
17,323 output tokens on a real edition — not guessed. The first version of the
estimator hardcoded 1,800 output tokens and was wrong by a factor of seven; on a
long edition the output dominates the bill, because it costs five times the
input per token.

---

## Exit codes

| code | when |
|---|---|
| 0 | success, warnings included |
| 1 | **every** feed failed; the engine will retry |
| 2 | unknown contract version |
| 3 | empty stdin, invalid JSON, `since` without offset, or bad config |

A broken source is a warning, not an error: one dead feed must not take the
others down. The same holds at delivery — if SMTP is down, the edition still
reaches Telegram.

---

## Install as an adapter

```sh
install -D -m 0755 bin/news ~/.config/iode/adapters/news
cp -r src ~/.config/iode/adapters/
```

Then in the engine's `config.toml`:

```toml
[[sources]]
name = "noticias"
adapters = ["news"]

[sources.config]
categories = ["mercado-financeiro", "estudos-mercado", "programacao",
              "geopolitica", "saas", "estudos-biblicos"]
timeout_seconds = 8
budget_seconds = 45
```

> **An external source, not a project.** A feed has no local directory, so it
> goes under `[[sources]]` — no `path`, no `writable`. In the request, `path`
> arrives as an empty string.
>
> That block feeds the archive and the panel. The weekly edition is built by
> `bin/digest`, which does its own collection and does not go through `sync`.

---

## Tests

```sh
php tests/run.php
```

39 cases, no dependencies. Feed fixtures are string literals; calls to the
Anthropic API hit a fake server on loopback ([`tests/fake-api.php`](tests/fake-api.php))
so the real HTTP client is exercised instead of a stub. Nothing leaves the
machine.

The fake server covers the paths that are easy to get wrong and hard to notice:
policy refusal, truncation at `max_tokens`, a non-JSON body, and that a **400 is
not retried** — retrying a billing error only costs money and delay.

---

## Design decisions

Each of these was a fork in the road, and the reasoning matters more than the
outcome.

**No Composer, and therefore raw HTTP to the Anthropic API.** The official SDK
requires a package manager, and this repository is deliberately dependency-free.
The API surface used here is one route and fits in a hundred lines; pulling in a
dependency manager for it would trade the project's rule for convenience that
does not pay for itself.

**The catalog is code, not configuration.** Every URL was checked by hand.
Someone else's infrastructure breaks silently, and a dated commit is a better
record of that than an edited config line.

**Collection and delivery are separate programs.** Idempotency, as above.

**Email HTML is tables and inline styles.** Gmail and Outlook strip `<style>` in
many contexts and render `flex`/`grid` wrong. What survives is what newsletters
have used for twenty years.

**Telegram uses `parse_mode: HTML`, not MarkdownV2.** MarkdownV2 requires
escaping sixteen characters, and one unescaped dash in a headline rejects the
entire message with a 400. HTML needs three.

**Images are optional by design.** Half the catalog publishes no image at all,
and each feed family announces one differently — `media:content`, `enclosure`,
or an `<img>` buried in the description HTML. An article had to look finished
without a picture, so a missing image removes a block rather than leaving a
hole.
