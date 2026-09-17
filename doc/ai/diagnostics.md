# RAG: diagnostics page

## Status: implemented; embeddings, search and reranking all verified against real endpoints (2026-09-17/18)

`Admin > Applications > RAG > Diagnostics` (`rag.EGroupware\Rag\Diagnostics.index`, admin only).
Every check is a button and runs server-side, so a production installation can be checked without
shell or database access. The result is plain text in a monospace box, `[OK]` / `[WARN]` / `[FAIL]`
per line, ready to be copied into a ticket.

Purely server-side on purpose: buttons submit the etemplate and the same controller re-renders it
with the report. No app JS, so nothing has to be rebuilt to ship it.

## The checks

| Button | What it proves |
| --- | --- |
| Configuration | the config as `Embedding::initStatic()` reads it, MariaDB version, `egw_rag` and that its vector index is `DISTANCE=cosine` (without it the k-NN query can not use the index), and whether the async job is installed |
| Endpoint and models | `GET <url>/models` reachable + timing, the embedding model is offered, and lists every model |
| Embedding | embeds 3 texts through `Embedding::create()`, checks 1024 dimensions, and that two related texts come out closer than an unrelated one - the texts carry a timestamp so the sha256 cache can not answer instead of the endpoint |
| Reranking | a real `Embedding::rerank()` call with one answering and two unrelated documents (scores, order, timing, or the exact error and url), then a second call with one document of the full `RERANK_MAX_CHARS` |
| Index status | entries/chunks/fulltext rows per app, cached search patterns, and the last 5 entries of `rag-last-errors` |
| Run search | a real `search()`/`searchEmbeddings()`/`searchFulltext()` with per-row distance, relevance, fused score and rerank score, plus the distance of the 5 nearest chunks regardless of the cut-off |
| Index now | runs `embed()` for 30 seconds, scope-limited to embeddings or the fulltext index, so a fresh installation can be tested without waiting for the async job |

### Why "Index now" has a scope

`embed()` always does the fulltext pass first and the runtime budget is shared. On an installation
with a big backlog (265k mails here) the fulltext pass eats all 30 seconds and not a single
embedding is calculated. The scope switches one pass off by handing it an app-list no plugin
matches (`Diagnostics::NO_APP`).

### Why the search reports the nearest chunks

An empty semantic result is almost always `max_distance`, not a broken endpoint. Measured on real
mail: a German query matched its best chunk at 0.4115 while the default cut-off is 0.4 - zero
results, no error, nothing in the log. The report now always prints the 5 nearest distances and
says so when the nearest one is beyond the cut-off; the search box has a `max_distance` override to
try another value without changing the configuration.

## Findings from the first real run (2026-09-17, production config on a dev box)

- Endpoint (Ollama 0.34.0): models 11ms, embeddings 60-70ms for 3 chunks, 1024 dimensions, related
  texts at 0.24 vs. 0.51 for an unrelated one. Embedding side fully working.
- **Reranking is dead against Ollama.** `POST <url>/v1/rerank` -> `404 page not found`, likewise
  `/rerank`, `/api/rerank`, `/v1/reranking`. Ollama serves chat/embeddings/models only; it has no
  cross-encoder endpoint, and having `qllama/bge-reranker-v2-m3` pulled does not add one. A
  reranker needs llama.cpp `--reranking`, vLLM, Jina or HuggingFace TEI. (Resolved the same day by
  a separate llama.cpp on port 8012, see below - the embedding endpoint stays on Ollama.)
  - The failure is handled as designed: the fused order is kept and the search still works, but
    every single hybrid search posts a doomed request, waits for the timeout and writes a 404 into
    `rag-last-errors` - so the config page's error panel fills up with them and hides real errors.
  - Nothing warned about this before: `Embedding::testConfig()` only checks the embedding model, and
    `Hooks::configValidate()` never touched the rerank endpoint. It now probes the reranker on save
    and shows an error message (not a validation error - a dead reranker must not block saving).
- 551 mails / 625 chunks embedded in one 30s run (~18 entries/s incl. the chunk-header context).
- Semantic search works on real multilingual mail: "Steuererklärung für den Monat August einreichen"
  found "FOROI AYGUST 2016 EFS", "Quellensteuererklärungen", "MWSt.-Erklärung August 2016" - Greek
  and German mails sharing no word with the query.
- Hybrid search over 265k fulltext rows + 625 chunks: 420 hits in 1.6s.

## Bug fixed along the way

`Hooks::configValidate()` passed an undefined `$error` to `Api\Etemplate::set_validation_error()`
for codes 1002/1003/1004, so an unreachable endpoint or an unsupported embedding model marked the
field with an empty message. It now passes `$e->getMessage()`.

## Not covered

- No recall@10 / MRR measurement - that needs a labelled query set, see `search-quality.md`.
- The reranker reorders sensibly on spot checks at both 1000 and 2000 chars, but its effect on
  recall@10/MRR is unmeasured - that needs a labelled query set.
- Recall@10/MRR need a labelled query set, see `search-quality.md`.

## A rerank endpoint, and llama.cpp's physical batch size (2026-09-17/18)

A llama.cpp `--reranking` server was added next to Ollama (`http://10.255.255.104:8012`, the
embedding endpoint stays on Ollama). It answers both shapes - `/v1/rerank` with
`{model, query, documents}` and `/rerank` with `{query, texts}` - so either `rerank_api` works;
`rerank_url` is `http://10.255.255.104:8012/v1` and `rerank()` appends `/rerank` itself.

The check passed in 18ms (answering document +4.50, distractors -11.02/-11.04), **but the first real
search still failed**:

```
HTTP 500: input (697 tokens) is too large to process.
          increase the physical batch size (current batch size: 512)
```

`RERANK_MAX_CHARS` is 2000, and llama.cpp refuses any single input longer than the physical batch
(`-ub`, default 512). Measured against real mail in the index:

| Text | works | fails |
| --- | --- | --- |
| German | 1200 chars = 426 tokens | 1500 chars = 514 tokens |
| Greek | 800 chars = 349 tokens | 1200 chars = 522 tokens |

So ~0.35 tokens/char for German and ~0.44 for Greek: most real mails exceed 512 tokens at 2000
chars. Document *count* is not the problem (50 short documents answer fine) - but the whole batch
goes through as **one** request, so a single over-long document 500s all 50 candidates, and the
search silently keeps the fused order and logs the 500. Same user-visible symptom as the Ollama 404,
different cause - which is exactly why the rerank check reports the endpoint's own error text.

Fix: restart llama-server with a bigger physical batch, e.g. `-ub 2048 -b 2048 -c 8192`
(bge-reranker-v2-m3 takes 8192 tokens; 2000 chars is at most ~900 tokens, so `-ub 1024` already
suffices). **Done on 2026-09-18 and it resolves it**: German 2000 chars = 697 tokens and Greek
2000 chars = 765 tokens both answer 200, and a hybrid search reranks with no error logged.

Latency at the real payload: 50 mixed German/Greek documents of 2000 chars (36,674 prompt tokens)
rerank in **0.49-0.50s**, repeatably - so the 3s `rerank_timeout` has plenty of headroom on this
GPU, and the 1.9s of the whole hybrid search is mostly the fulltext/DB side, not the reranker.

Final order for "Umsatzsteuer Voranmeldung" at the full 2000 chars (compare the 1000-char table
below - the cross-encoder pulls a different set to the top again, and relevance no longer predicts
the order at all):

| ID | relevance | rerank |
| --- | --- | --- |
| acemailstor:223118 | 49.1435 | 3.5454 |
| acemailstor:44171 | 40.5431 | 3.4106 |
| acemailstor:320895 | 33.1722 | 3.2680 |
| acemailstor:281529 | 30.5393 | 3.1938 |
| acemailstor:222056 | 33.8739 | 3.0825 |

**Proven to work and to improve the order** with `RERANK_MAX_CHARS` temporarily at 1000 (change
reverted afterwards), hybrid search for "Umsatzsteuer Voranmeldung" over 265k fulltext rows + 625
chunks, 685ms for the whole search incl. reranking 50 candidates - well inside the 3s timeout:

| ID | relevance | rerank |
| --- | --- | --- |
| acemailstor:222056 | 32.2532 | 3.0985 |
| acemailstor:320895 | 31.6150 | 3.0883 |
| acemailstor:44171 | 38.5816 | 2.6998 |
| acemailstor:223118 | 46.7978 | 2.5562 |

The fused top hit (relevance 62.9) is gone from the top 4 and relevance-32 rows now outrank
relevance-47 ones: the cross-encoder really re-sorts, it does not pass the order through.

Note the scores are raw cross-encoder logits (roughly -11 .. +11 here), **not** 0..1 - so
`rerank_min_score` is a logit threshold, and the config field's `max="1"` is misleading. 0 is a
sensible cut-off (drop everything the model scores negative); the default 0 disables the cut-off
entirely.

## The length probe (2026-09-18)

The first version of both probes sent three short test documents, so they reported success for the
whole time real searches were 500-ing on the batch size - the failure mode that cost the most time
here was the one the tool could not see. Both now also send one document of `RERANK_MAX_CHARS`,
built by `Diagnostics::rerankProbeDocument()` from mixed German/Greek text: with bge-m3's tokenizer
Greek costs ~0.44 and German ~0.35 tokens per character, so a Latin-only probe understates a real
mail and would pass against an endpoint every search then fails on.

- The diagnostics check reports it as its own `Document length` line, so "scores correctly" and
  "takes our documents" are separate verdicts.
- `Hooks::configValidate()` sends the short documents *plus* the long one; if that fails it retries
  with the short ones alone, and says "refuses a document of N characters, as every search sends"
  rather than "is not usable" when only the length is the problem.

Both paths tested against the live endpoint by temporarily raising `RERANK_MAX_CHARS` to 8000 (over
the server's `-ub 2048`): the check reported `[OK] Order` next to `[FAIL] Document length`, and
saving the config produced the length-specific message. Reverted afterwards; at the real 2000 the
probe passes in 14-15ms.
