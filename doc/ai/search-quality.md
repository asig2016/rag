# RAG: search quality

## Status: phases 1-3 implemented (2026-09-15/16), not yet run against a real embedding endpoint

| Phase | Topic | Status |
|---|---|---|
| 1 | Bug fixes + schema (vector index, cache table, parts) | implemented, see "Phase 1 notes" |
| 2 | Hybrid ranking with Reciprocal Rank Fusion | implemented, see "Phase 2 notes" |
| 3 | Chunking + chunk context (forces a re-index) | implemented, see "Phase 3 notes" |
| 4 | Fulltext tightening for multi-word queries (optional) | open |
| 5 | Cross-encoder reranker (optional per install) | open |

Goal: better results from the hybrid (embeddings + InnoDB fulltext) search used by
`Api\Storage::process_search()` → `Embedding::search2criteria()` and by plugins calling
`Embedding::search()` directly. The design has to keep room for indexing **files of any type** later
(see "Future: file indexing").

Every finding below was verified against the code on 2026-09-15 (line numbers as of `399505e`).

## Findings

### Blockers - no embeddings are written at all
1. **`create()` never assigns new embeddings** (`src/Embedding.php:495-509`). The loop reads
   `$chunkKeys[$n]`, which is never defined, so it `continue`s for every returned embedding. `embed()`
   then inserts NULL into `rag_embedding NOT NULL`, the exception lands in the per-app catch and the
   whole app is skipped. `searchEmbeddings()` has the same problem for any query not yet cached.
   Introduced by the "truncating the first 1024 values" commits (75e338c, 399505e).
   Symptom on test DBs: `egw_rag` has 0 rows while `egw_rag_fulltext` is populated.
2. **Max runtime is 0 unless configured** (`initStatic()`, line 199):
   `(int) $config['async_maxruntime'] ?? 285` casts first, `(int)null` is 0, and `??` never applies.
   `embed()` then breaks after the first entry and the async job never removes itself.
3. **Failing rows stall an app** (`Base::getUpdated()`). It always re-selects
   `ORDER BY modified ASC LIMIT 10` from offset 0 and relies on the LEFT JOIN to skip rows already
   indexed. A row that fails every time (or whose modified time is NULL and therefore never compares)
   is selected again on every pass; ten of them and the loop spins until `max_runtime`.

### Vector search
4. **The vector index is probably never used.** `setup/tables_current.inc.php` creates a bare index on
   `rag_embedding`, the server default is `mhnsw_default_distance=euclidean`, but every query uses
   `VEC_DISTANCE_COSINE`. MariaDB only uses the index when the distance function matches it and the
   query is `ORDER BY <distance> LIMIT n`. The `SQL_CALC_FOUND_ROWS … HAVING distance<.4` shape
   defeats that too. The query-embedding cache (`rag_app='*cache*'`) lives in the same table and index.
5. **LIMIT applies to chunks, not entries** (`searchEmbeddings()`, lines 873-907). An entry with 15
   matching chunks takes 15 of the requested rows; de-duplication happens in PHP afterwards, and
   `total` counts chunks.
6. **`chunkSplit()` is byte-based** (`substr`/`strlen`, line 1106) and cuts multibyte UTF-8 characters
   in half - the likely reason for the "Malformed UTF-8" retry in `create()`, which then drops those
   characters from the embedding.
7. **Unscoped plugin query for `$return_all`** (lines 884-897). For apps without fulltext rows it calls
   `getUpdated(true, ['data' => ['app'=>…, 'id'=>…]])`, but `Base::getUpdated()` only scopes on the
   top-level `app`/`id` keys, so it iterates every stale row of that app. It also merges plugin column
   names (`tr_summary`, …) while the code reads `title`, so titles come back empty.

### Ranking
8. **Hybrid merge puts every vector hit first** (`search()`, line 743:
   `$embedding_matches + $fulltext_matches`). An exact keyword hit ranks behind any semantic hit under
   `max_distance`. `search2criteria()` asks for only 200 ids, so on larger installs exact keyword hits
   can drop out of the id list entirely.

### What gets embedded
9. **Chunks lack context.** With `minimize_chunks` the title is only in chunk 0; later chunks carry no
   hint which entry they belong to. `Embedding\Addressbook::processRow()` removes `n_fileas` for RAG,
   so notes are embedded without the contact's name. Tracker replies are concatenated into one stream.
   500 characters is small for bge-m3 (8k token context).

### Fulltext
10. **Multi-word queries match any word.** `fulltext_match_wordstart` turns `foo bar` into `foo* bar*`,
    which in BOOLEAN MODE is an OR. Together with `min_relevance` = 5 % of the best score and the app's
    own sort order, entries with only one of the words flood the list.

## Future: file indexing (seam built in phase 1, extractors later)

An entry should be able to contribute several independently indexed **parts** - its own text, replies,
attached or linked files.

- **Schema**:
  - `egw_rag.rag_part` ascii(64): `''` = the entry's own text, later `reply:<id>`, `file:<fs_id>`.
    Unique key becomes `(rag_app, rag_app_id, rag_part, rag_chunk)`.
  - `egw_rag_fulltext.ft_part`, same values; unique key `(ft_app, ft_app_id, ft_part)`.
  - Search still groups by `(app, app_id)`, so a hit in an attachment finds its entry. It can also
    return the best-matching part, so a UI can say "matched in file X".
- **Plugin hook** `Base::getParts(array $row, bool $fulltext) : iterable` yields
  `['part' => …, 'title' => …, 'text' => …, 'modified' => …]`. The default yields nothing. Tracker
  replies are the first user (phase 3); VFS attachments of any app follow later.
- **Text extractors**: `Rag\Extractor` registry keyed by mime type - text/*, html and message/rfc822
  first; PDF/DOCX/ODT later through pure-PHP parsers (FPM installs commonly disable `exec`, so shelling
  out to pdftotext is not an option). Extraction runs only in the async job, with a size cap, and a
  content sha256 skips unchanged files.
- Doing the schema part in phase 1 means the phase-3 re-index is the only one needed.

## Phase 1: bug fixes + schema

`src/Embedding.php`, `src/Embedding/Base.php`, `setup/`

- `create()`: keep `$keys = array_keys($chunks)` before `array_values()`; response embedding `$n`
  belongs to `$responses[…]` with `->n === $keys[$n]`; keep the `array_slice(…, 0, 1024)`.
- `initStatic()`: `self::$max_runtime = (int)($config['async_maxruntime'] ?? 285) ?: 285;`
- `embed()` collects the ids that failed per app/pass and hands them to
  `getUpdated($fulltext, $hook_data, $exclude_ids)`, which adds `ID NOT IN (…)`. Rows with a NULL
  modified time fall back to `CREATED`, or are excluded when the plugin has none.
- `chunkSplit()`: `mb_substr`/`mb_strlen` (replaced completely in phase 3).
- `searchEmbeddings()` query:
  ```sql
  SELECT rag_app, rag_app_id, MIN(distance) AS distance
  FROM (
    SELECT rag_app, rag_app_id, VEC_DISTANCE_COSINE(rag_embedding, :q) AS distance
    FROM egw_rag
    WHERE rag_app IN (:apps) [AND rag_app_id IN (:ids)]
    ORDER BY distance LIMIT :k            -- k = max(500, (start+num_rows)*10)
  ) knn
  WHERE distance < :max_distance
  GROUP BY rag_app, rag_app_id
  ORDER BY distance
  LIMIT :start, :num_rows
  ```
  `total` = grouped count over the same inner set. Ids are cast to int.
- Query cache moves to its own table `egw_rag_cache` (hash PK, vector, updated; no vector index).
  `create()` looks hashes up in both tables.
- `$return_all` lookup: call `getUpdated(true, ['app'=>…, 'id'=>…, 'data'=>[]])` and map the row with
  `array_values()` (id, modified, title, description, extra) the way `embed()` does.
- `setup/tables_update.inc.php` + version bump in `setup/setup.inc.php`:
  - drop and re-create the vector index as `VECTOR INDEX (rag_embedding) M=16 DISTANCE=cosine`
    (raw SQL; check the actual index name with `SHOW CREATE TABLE egw_rag`)
  - create `egw_rag_cache`, move the `*cache*` rows, delete them from `egw_rag`
  - add `rag_part` / `ft_part` and the new unique keys

### Phase 1 notes (2026-09-15)

- Paging in `Base::getUpdated()` is by id (`TABLE.ID > last id ORDER BY TABLE.ID`), not by the
  modified time as planned: keyset paging on a nullable modified column is not reliable, and the id
  keyset skips failing rows without collecting them in `embed()`.
- `Embedding::createVectorIndex()` rebuilds the index with raw SQL, called by the update and by
  `setup/default_records.inc.php` - the schema array cannot pass `M=`/`DISTANCE=`.
- `searchEmbeddings()` reads texts of entries without fulltext row through the new `readEntry()`.
- The k-NN depth is `min(max(500, 5 * (start + num_rows)), 10000)` chunks.
- Tested: migration SQL + `EXPLAIN` on a scratch copy of the old schema (MariaDB 11.8.8, the inner
  query uses `egw_rag_embedding`, results come back one row per entry); `create()` against a stubbed
  OpenAI HTTP transport, in request order and in reversed response order (the committed code fails the
  same test); `chunkSplit()` with German/Greek text. Not yet tested: a real `asyncJob()` run and the
  setup update on an installed instance.

## Phase 2: Reciprocal Rank Fusion

- `protected static function rrf(array $lists, int $k = 60) : array` - `id => Σ 1/(k + rank)`,
  best first.
- `search()` runs both sub-searches in their native order with depth `max($start+$num_rows, 100)`,
  fuses them, merges rows with `add_rows()`, stores the fused value as `score`. The user's order
  (`modified`, …) is applied only after fusion, then the page is sliced.
- New config: `max_distance` (default .4, calibrate on real data) and `search_depth` (replaces the
  hard-coded 200 in `search2criteria()`).
- `search2criteria()` takes an optional `?array $app_ids`, so plugins with their own list code can
  reuse it and `orderByIds()` / `distanceById()` instead of copying them.

### Phase 2 notes (2026-09-16)

- Depth of both sub-searches: `max($start + $num_rows, 100)`; `total` = both totals minus the overlap
  of the fetched rows (still an estimate).
- `search()` passed `$app_ids` only in the fulltext-only branch before; both sub-searches get it now.
- An exception of the embedding endpoint is logged and the hybrid search returns the fulltext result
  alone (SQL errors still throw).
- Order `default` = fused score. `distance`, `relevance` and `modified` sort the fused rows (only with
  `$return_all`); rows without that value, e.g. no distance for a fulltext-only match, go to the end.
  Rows carry `distance`, `relevance` and `score`.
- `$return_all=false` returns the fused score, so the `distance` extra column `search2criteria()` adds
  is a score (higher is better) for hybrid searches.
- Config page: `max_distance` (default .4) and `search_depth` (default 200), en/de translations.
- Tested with stubbed sub-searches: fused order, paging, total, sort by modified/distance/relevance,
  `$app_ids` pass-through, endpoint failure fallback, stable order of equal scores.

## Phase 3: chunking + context (re-index)

- `chunkSplit(string $text, string $header, array $chunks = [])`: split on paragraphs, then sentences
  (`/(?<=[.!?])\s+|\n{2,}/u`); parts longer than the budget get a hard `mb_substr` cut; overlap in
  characters. Default `chunk_size` 1500 (update the placeholder in `templates/default/config.xet`).
- Every chunk is prefixed with a header; new `Base::chunkHeader(array $row) : string`, default
  `"[{App}] {title}\n"`. Plugins override it to add context (category, sender, …).
- Addressbook keeps `n_fileas` for RAG so it ends up in the header.
- Tracker replies become parts (`reply:<id>`), each with the ticket header.
- Update step truncates `egw_rag` (all chunks change, the hash cache cannot help).

### Phase 3 notes (2026-09-16)

- `chunkSplit($text, $header, $chunks)` splits on `(?<=[.!?;:])\s+|\n+`, fills up to `chunk_size`
  characters (header included), overlaps by `chunk_overlap` characters and cuts a single over-long
  sentence hard. Defaults: 1500 / 100.
- Two new plugin hooks in `Embedding\Base`:
  - `chunkHeader(array $row) : string`, default `"[<app>] <title>\n"`, prefixed to every chunk.
    Changing it re-embeds the whole app, so keep it short and stable.
  - `getParts(array $row, bool $fulltext) : iterable` yields `['part', 'text', 'title'?, 'modified'?,
    'header'?]`; the entry's own text is always part `''`. Parts no longer returned are deleted from
    both indexes. This is the seam the file indexing will use.
- Tracker replies are parts (`reply:<id>`) with the ticket's header, no longer concatenated into
  `ft_extra`. Empty replies are skipped. `processRow()` remembers `tr_edit_mode` for `getParts()`.
- Addressbook keeps `n_fileas` for RAG, so the note's chunks carry the contact's name.
- `embed()` sends all chunks of all parts of an entry in one request.
- `searchFulltext()` had to become entry-level too (`MAX(relevance) … GROUP BY ft_app, ft_app_id`,
  texts joined from part `''`), because an entry now has several fulltext rows.
- Fixed on the way: `Base::getUpdated()` used `$entries ?` instead of `isset($entries)`, which warned
  on every row of the async job.
- Update 26.1.003 empties `egw_rag` and drops the tracker fulltext rows (replies moved out of
  `ft_extra`), then installs the async job. Tracker's fulltext search is degraded until it has run.
- Tested with stubs: a ticket with two replies produces the expected fulltext and embedding rows per
  part, excess chunks and vanished parts are deleted, one endpoint request per entry; the splitter
  keeps the header on every chunk, respects the size, overlaps, never produces invalid UTF-8 and cuts
  over-long sentences. Entry-level fulltext grouping over parts verified in SQL on a scratch DB.

## Phase 4 (optional): fulltext tightening

- When the user typed no operators: `+term*` for unquoted terms of 3+ characters (shorter ones and
  stopwords are not in the index and would make the query match nothing), `+"phrase"` for quoted
  ones, dashed terms quoted as phrases.
- No result → rerun with the old OR pattern.
- Also consider a custom stopword table for non-English installs and `innodb_ft_min_token_size=2`.

## Phase 5 (optional per install): cross-encoder reranker

Comes after phases 2 and 3: it re-sorts the fused candidates, needs chunks that carry their context,
and needs the evaluation set to prove it helps (it adds a second endpoint and latency).

- Config (`templates/default/config.xet`, `Embedding::initStatic()`):
  - `rerank_model` - empty = off, e.g. `bge-reranker-v2-m3` (multilingual, same family as bge-m3)
  - `rerank_url` - defaults to `url`
  - `rerank_api` - `cohere` (llama.cpp `/v1/rerank`, vLLM, Jina) or `tei` (HF TEI `/rerank`)
  - `rerank_depth` (default 50), `rerank_min_score` (default 0 = no cut-off), `rerank_timeout`
    (default 3 s)
- HTTP through `symfony/http-client`, already in `vendor/` via openai-php, which has no rerank call.
- `Embedding::rerank(string $query, array $candidates) : array` returns `id => score`; the adapter
  maps `results[{index, relevance_score}]` (cohere) or `[{index, score}]` (tei). A timeout or error
  is logged through `logError()` and the fused order is kept - search never fails because of it.
- `rerank_min_score` is a real relevance cut-off, which the vector distance cannot give; it matters
  most for the id lists `search2criteria()` hands to app list searches.
- Document text: `egw_rag` stores no chunk text. The query returns the best chunk's `rag_chunk`, and
  its text is rebuilt with the same deterministic `chunkSplit()` from the fulltext row (or
  `readEntry()`); fulltext-only hits send the chunk header plus the first ~2000 characters.
- Runs in `search()` after the fusion and before the user's sort, and only when the order is
  relevance or `rerank_min_score > 0` - a list sorted by date throws the new order away.
- Latency: roughly 1-3 s for 50 candidates of ~500 tokens on CPU, far less on a GPU; say so on the
  config page.

## Verification

- Inspect the DB with the `mariadb` client. Do **not** bootstrap `header.inc.php` from a PHP CLI
  one-liner: the `Egw` destructor runs due async jobs (`Asyncservice::check_run('fallback')`).
- Phase 1, on a scratch DB with an embedding endpoint configured:
  - one `Embedding::asyncJob()` run fills `egw_rag` for every enabled app; `rag-last-errors` stays
    clean
  - `EXPLAIN` of the k-NN query shows the vector index; `SHOW CREATE TABLE egw_rag` has
    `DISTANCE=cosine`
  - chunks of a German/Greek text all pass `mb_check_encoding()`
  - a row forced to fail does not block the rows after it
- Phase 5: same query set, fused vs reranked order (recall@10, MRR) plus latency per search; adapter
  tested with stubbed cohere and tei responses, a timeout keeps the fused order. Needs a rerank
  endpoint, e.g. llama.cpp `--reranking` with a bge-reranker-v2-m3 GGUF.
- Phases 2-4: 30-50 real queries with known expected entries; compare recall@10 and MRR before and
  after each phase. An exact keyword match must appear in the top 10.
- UI: list search in Tracker / InfoLog in hybrid mode - sane counts, sorting by date and by relevance
  both work.
