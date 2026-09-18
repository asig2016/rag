# RAG: what changed in September 2026

Everything below was written against a live installation (EGroupware on MariaDB 11.8, an archive of
436k mails, an Ollama endpoint for the embeddings and a llama.cpp one for the reranking) and
verified there. The numbers are measurements from that box, not estimates.

`diagnostics.md` describes the diagnostics page itself. `search-quality.md` holds the older
phase-by-phase work plus the detail of the join and its security review. This file is the summary:
what changed, why, and what each change is worth.

## The commits

| Commit | What |
| --- | --- |
| `6eb202c` | diagnostics page: check a RAG installation end-to-end from the browser |
| `40e0752` | fix the total of a semantic search being wrong |
| `12761e4` | search inside the application's own filters instead of intersecting afterwards |
| `9bbb099` | index status: how much of an application is embedded, in percent |
| `c4dd30a` | index status: each application's own number of entries |
| `e2d2b16` | index status: what the fulltext index really holds, and when it is not maintained |
| `7c68330` | index status: a fulltext percentage next to the fulltext count |

Outside this repo, in the same work: `PATCH CORE: Api\Storage - scope the RAG search to the list's
own filters` (core), `achelper: expand extra_sql_statements before core builds the RAG's id
sub-query`, and `* acemailstor: let the RAG search inside the list's filters, not around them`.

## 1. Two bugs that made searches quietly return the wrong thing

### A selective filter found nothing at all

The RAG picked its best N hits over the whole application and the application's filters were
applied to *that result*. Once a filter is selective, those N hits contain none of its entries, so
the search returns **nothing** - no error, no warning, just an empty list.

Measured: a client with 3 embedded mails, searching "Zahlung an das Finanzamt" - the unscoped
search found none of them; scoped it finds both matching ones, in 4 ms instead of 107 ms.

The filters now go to the RAG as a **sub-query**, joined into the innermost block of its own query.
MariaDB then chooses per query: the vector index while the filter is broad, driving from the
application's own table - exact - once it is selective. Both regimes verified with EXPLAIN.

This does **not** make a broad search exact: the inner `LIMIT k` still cuts. What is fixed is the
selective case.

Worth knowing: this also gave **acilog, infolog, tracker and addressbook** scoping for the first
time. They reach the RAG through `Api\Storage::process_search()`, which passed no scope at all, so
they searched the whole application and intersected afterwards - the same bug, permanently on.

### The total of a semantic search was wrong

`searchEmbeddings()` read `FOUND_ROWS()` after its result loop, and `readEntry()` runs a query
inside that loop for every row whose fulltext title is NULL - so the total became that query's
count. On this installation 3179 acemailstor entries have no title, so a search with 120 matches
reported **0 Total**, breaking the paging of every consumer.

## 2. Being able to see whether any of it works

Before this there was no way to tell a working RAG from a broken one except by searching and
guessing. `Admin > Applications > RAG > Diagnostics` now checks it end-to-end, one button per
check, entirely server-side - so it works on a production installation without shell or database
access.

What it found on the first run says more than the feature description:

- **Reranking was dead.** Ollama has no rerank endpoint at all: `/v1/rerank` answers 404, and
  having `qllama/bge-reranker-v2-m3` pulled does not add one. Every hybrid search had been posting
  a doomed request, waiting for the timeout and logging a 404.
- Once a llama.cpp reranker was set up next to it, **the first real search still failed**: llama.cpp
  refuses any single input longer than its physical batch (`-ub`, 512 by default), and the RAG sends
  2000-character documents. German text costs ~0.35 tokens per character and Greek ~0.44, so most
  real mails exceeded it, and one over-long document fails the whole 50-candidate request.
  `-ub 2048 -b 2048 -c 8192` fixed it; reranking then reordered a live search visibly, 50 candidates
  in ~0.5 s.
- `max_distance` 0.4 was cutting real matches. Of five test queries, four returned **zero**
  semantic hits with the nearest chunk at 0.41-0.44 - including "Intrastat Meldung" against an
  archive that contains mails titled *Intrastat-Meldung August 2016*. 0.5 fixed all of them.

Both failures had the same shape: the search kept working, so nothing was visibly wrong, and the
only trace was a line in a log nobody reads. Saving the configuration now probes the reranker,
**with a document of the length a real search sends** - the first version of that probe sent three
short ones and passed happily while every real search was failing.

## 3. The index status table

It listed embedded entries and fulltext rows, which answers neither question an administrator asks
of it. It now reads:

```
Εφαρμογή          Σύνολο  Entries Embedded %   Chunks   Fulltext Fulltext %   last update
acemailstor       436562    18393     4.2%      20651     436562     100%     28/08/2026
tracker             1071        0      off          0  1071+472*     100%     16/09/2026
```

- **Total** - the application's own entries, counted through each plugin's `TABLE` and
  `NOT_DELETED`, so it works for any plugin without the RAG knowing the application.
- **Embedded %** and **Fulltext %** against that same total, so the two indexes are comparable.
  `off` rather than `0%` for an application that is switched off: it is not failing.
- **Fulltext** counts *entries*, not rows. An entry can have several rows - a tracker item and its
  replies are indexed as separate parts - so the raw count read 1543 against 1071 entries, which
  looks like more is indexed than exists. Parts are appended as `+472`.
- **`*`** - the application is not in the fulltext-apps, so `embed()` skips it and this index is
  frozen: correct until the first entry changes, then drifting silently. On the installation this
  was written against, five of six applications were in that state, all complete and current, all
  frozen.

## Still open

- **Reranking quality is unmeasured.** It reorders sensibly on spot checks; whether it improves
  recall@10/MRR needs a labelled query set.
- **`Embedding\Base::purgeDeleted()` caches on `'purge-'.self::APP`, and `Base::APP` is `''`.**
  `self::` binds to the defining class, so every plugin shares the key `purge-` and only the first
  one in a 24-hour window actually purges; the rest are silently skipped. It should be
  `static::APP`. Found while investigating something else, deliberately not fixed in that moment.
- **A filter value containing `;`** makes `validFilterSubquery()` refuse the sub-query, so that
  search falls back to the id-list path - safe, just less exact. Telling a quoted `;` from a
  statement separator needs a SQL parser.
- The RAG still returns its result to the application as an `IN (...)` of up to
  `search_depth`/`RAG_SEARCH_ROWS` ids. With correct scoping that list is now genuinely "the best N
  of your filtered set", so it degrades gracefully instead of silently missing matches.

## Things worth not re-learning

- **A join into the k-NN block is not the join that was rejected.** Commit `73f1c10` removed
  `searchColumnJoin()` for *"terrible performance"* - that was a correlated
  `(SELECT MIN(VEC_DISTANCE_COSINE(...)))` per application row, which cannot use the vector index.
  A join *inside* the `ORDER BY distance LIMIT k` block keeps it. The dead pair is deleted so the
  two cannot be confused again.
- **A sub-query handed to the RAG has to stay mergeable** - no `GROUP BY`/`LIMIT`/`DISTINCT`/
  `UNION`/`ORDER BY` - or the optimizer materializes it and loses the choice of plan that makes the
  whole thing work.
- **`filter2where()` has to stay the only copy.** The sub-query must select exactly what the list
  selects, and it is `data2db()` plus the column-name mapping that make the difference. Two copies
  would drift, and a drifted copy scopes the search by something other than what the user sees.
- **Opening a mail indexes it.** `Link::notify_update()` reaches the RAG's `notify-all` hook, which
  embeds the entry synchronously - so user activity, not only the async job, drives load on the
  embedding endpoint.
