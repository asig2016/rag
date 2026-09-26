<?php
/**
 * EGroupware RAG system - diagnostics
 *
 * An admin page that checks a RAG installation end-to-end: the endpoint and its models, a real
 * embedding request, a real rerank request, a search, and the state of both indexes. Every check
 * is a button, so it can be run on a production installation without shell access.
 *
 * @package rag
 * @link https://www.egroupware.org
 * @author Alexandros Sigalas <asig@sigalas.eu>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;

class Diagnostics
{
	const APP = 'rag';

	/**
	 * Methods callable via menuaction GET parameter
	 *
	 * @var array
	 */
	public $public_functions = [
		'index' => true,
	];

	/**
	 * Query and documents of the rerank check: the first document answers the query, the others do not
	 */
	const RERANK_QUERY = 'When is the invoice due?';
	const RERANK_DOCUMENTS = [
		'The invoice is due 30 days after the delivery date.',
		'Our office is closed between Christmas and New Year.',
		'The new coffee machine is in the kitchen on the second floor.',
	];

	/**
	 * Text the full-length probe document is built from
	 *
	 * Mixed scripts on purpose: with bge-m3's tokenizer Greek costs about 0.44 and German about 0.35
	 * tokens per character, so a Latin-only probe understates what a real mail produces and would
	 * pass against an endpoint that then fails every actual search.
	 */
	const RERANK_PROBE_TEXT = 'Sehr geehrte Damen und Herren, anbei die Umsatzsteuer-Voranmeldung für den Monat August. '.
		'Παρακαλώ βρείτε συνημμένη την περιοδική δήλωση Φ.Π.Α. για τον μήνα Αύγουστο. ';

	/**
	 * One document of the length rerank() really sends
	 *
	 * An endpoint can score three short test documents perfectly and still refuse a real one:
	 * llama.cpp rejects any single input longer than its physical batch (`-ub`, 512 by default),
	 * which kills the whole 50-candidate request of every search.
	 *
	 * @return string
	 */
	public static function rerankProbeDocument() : string
	{
		return mb_substr(str_repeat(self::RERANK_PROBE_TEXT,
			(int)ceil(Embedding::RERANK_MAX_CHARS / mb_strlen(self::RERANK_PROBE_TEXT))), 0, Embedding::RERANK_MAX_CHARS);
	}

	/**
	 * Max. seconds one "index now" run is allowed to take
	 */
	const INDEX_NOW_RUNTIME = 30;

	/**
	 * App-name no plugin has, to switch one of embed()'s two passes off
	 */
	const NO_APP = '*none*';

	/**
	 * @var array RAG configuration
	 */
	protected array $config;

	public function __construct()
	{
		if (empty($GLOBALS['egw_info']['user']['apps']['admin']))
		{
			throw new Api\Exception\NoPermission\Admin();
		}
		$this->config = Api\Config::read(self::APP);
	}

	/**
	 * Diagnostics page
	 *
	 * @param ?array $content
	 */
	public function index(?array $content=null)
	{
		$output = null;
		if (is_array($content) && !empty($content['button']))
		{
			$button = key($content['button']);
			$output = $this->run($button, $content);
		}
		$content = [
			'search_pattern' => $content['search_pattern'] ?? '',
			'search_type'    => $content['search_type'] ?? 'hybrid',
			'search_app'     => $content['search_app'] ?? '',
			'search_max_distance' => $content['search_max_distance'] ?? '',
			'index_scope'    => $content['index_scope'] ?? 'rag',
			'output'         => $output ?? $this->report(lang('Configuration'), $this->checkConfig()),
		];
		$apps = array_keys(Embedding::plugins());
		$sel_options = [
			'search_type' => [
				'hybrid'   => lang('Hybrid search (RAG+Fulltext)'),
				'rag'      => lang('RAG / Semantic search'),
				'fulltext' => lang('Fulltext search'),
			],
			'search_app' => array_combine($apps, array_map('lang', $apps)),
			'index_scope' => [
				'rag'      => lang('Embeddings only'),
				'fulltext' => lang('Fulltext index only'),
				'both'     => lang('Both indexes'),
			],
		];
		$tpl = new Api\Etemplate(self::APP.'.diagnostics');
		$tpl->exec(self::APP.'.'.self::class.'.index', $content, $sel_options);
	}

	/**
	 * Run one check and return its report
	 *
	 * @param string $button
	 * @param array $content
	 * @return string
	 */
	protected function run(string $button, array $content) : string
	{
		switch($button)
		{
			case 'config':
				return $this->report(lang('Configuration'), $this->checkConfig());

			case 'endpoint':
				return $this->report(lang('Endpoint and models'), $this->checkEndpoint());

			case 'embedding':
				return $this->report(lang('Embedding'), $this->checkEmbedding());

			case 'rerank':
				return $this->report(lang('Reranking'), $this->checkRerank());

			case 'index_status':
				return $this->report(lang('Index status'), $this->checkIndex());

			case 'search':
				return $this->report(lang('Search'), $this->checkSearch($content));

			case 'index_now':
				return $this->report(lang('Index now'), $this->indexNow($content['index_scope'] ?? 'both'));

			case 'all':
				return implode("\n", [
					$this->report(lang('Configuration'), $this->checkConfig()),
					$this->report(lang('Endpoint and models'), $this->checkEndpoint()),
					$this->report(lang('Embedding'), $this->checkEmbedding()),
					$this->report(lang('Reranking'), $this->checkRerank()),
					$this->report(lang('Index status'), $this->checkIndex()),
				]);
		}
		return $this->report(lang('Error'), ['unknown check "'.$button.'"']);
	}

	/**
	 * The configuration as the code actually reads it, plus the database requirements
	 *
	 * @return string[]
	 */
	protected function checkConfig() : array
	{
		$c = $this->config;
		$lines = [
			self::line(lang('URL of OpenAI compatible API'), $c['url'] ?? '', empty($c['url']) ? 'warn' : 'ok',
				empty($c['url']) ? lang('RAG is off, only the fulltext index is used') : null),
			self::line(lang('API Key'), empty($c['api_key']) ? lang('none') : str_repeat('*', 8)),
			self::line(lang('Model to use'), $c['embedding_model'] ?? 'bge-m3 ('.lang('default').')'),
			self::line(lang('Chunk size'), ($c['chunk_size'] ?? 1500).' / '.lang('Chunk overlap').' '.($c['chunk_overlap'] ?? 100)),
			self::line(lang('Max. distance of a semantic match'), $c['max_distance'] ?? '0.4 ('.lang('default').')'),
			self::line(lang('Number of results for the search in the applications\' lists'), $c['search_depth'] ?? '200 ('.lang('default').')'),
			self::line(lang('Model to rerank the best matches'), empty($c['rerank_model']) ? lang('off') : $c['rerank_model'],
				empty($c['rerank_model']) ? 'warn' : 'ok'),
		];
		if (!empty($c['rerank_model']))
		{
			$lines[] = self::line(lang('URL of the rerank endpoint'), $this->rerankUrl(),
				'ok', empty($c['rerank_url']) ? lang('Leave empty to use the URL above') : null);
			$lines[] = self::line(lang('API of the rerank endpoint'),
				($c['rerank_api'] ?? '') === 'tei' ? 'HuggingFace TEI (/rerank)' : 'llama.cpp / vLLM / Jina (/v1/rerank)');
			$lines[] = self::line(lang('How many matches to rerank'), $c['rerank_depth'] ?? '50 ('.lang('default').')');
			$lines[] = self::line(lang('Minimum score of the reranker'), $c['rerank_min_score'] ?? '0 ('.lang('default').')');
			$lines[] = self::line(lang('Timeout of the rerank endpoint'), ($c['rerank_timeout'] ?? 3).'s');
		}
		$lines[] = self::line(lang('Applications for RAG / to calculate embeddings for'),
			empty($c['rag_apps']) ? lang('All supported') : implode(', ', (array)$c['rag_apps']));
		$lines[] = self::line(lang('Applications to add to fulltext-index'),
			empty($c['fulltext_apps']) ? lang('All supported') : implode(', ', (array)$c['fulltext_apps']));
		$lines[] = '';

		$db = $GLOBALS['egw']->db;
		$version = (float)$db->ServerInfo['version'];
		$lines[] = self::line(lang('Database'), $db->ServerInfo['description'] ?? $db->ServerInfo['version'],
			$version >= 11.7 ? 'ok' : 'fail', $version >= 11.7 ? null : lang('MariaDB 11.7+ is required for the vector type'));
		try {
			// MariaDB quotes the index options, so drop the backticks before looking for the distance
			$create = str_replace('`', '', (string)$db->query('SHOW CREATE TABLE '.Embedding::TABLE)->fetchColumn(1));
			$lines[] = self::line(Embedding::TABLE, lang('installed'), 'ok');
			$cosine = (bool)preg_match('/VECTOR KEY.*DISTANCE\s*=\s*cosine/is', $create);
			$lines[] = self::line(lang('Vector index'), $cosine ? 'DISTANCE=cosine' :
				lang('not cosine - the k-NN query can not use the index'), $cosine ? 'ok' : 'fail');
			$lines = array_merge($lines, $this->checkIndexMemory($db));
		}
		catch (\Throwable $e) {
			$lines[] = self::line(Embedding::TABLE, $e->getMessage(), 'fail');
		}
		// the async job that keeps both indexes up to date
		$async = new Api\Asyncservice();
		if (($job = $async->read(Embedding::ASYNC_JOB)))
		{
			$job = current($job);
			$lines[] = self::line(lang('Async Job'), lang('next run').': '.Api\DateTime::to($job['next']), 'ok');
		}
		else
		{
			$lines[] = self::line(lang('Async Job'), lang('not installed'), 'fail',
				lang('Save the configuration to install it - without it no index is ever updated'));
		}
		return $lines;
	}

	/**
	 * Does the vector index fit into the memory the search reads it from?
	 *
	 * The k-NN search walks the index graph through MariaDB's own cache (mhnsw_max_cache_size, 16 MB
	 * by default), missing nodes come from the InnoDB buffer pool, and what is not there from disk.
	 * With an index much bigger than both, a search reads most of it from disk and runs into the
	 * search timeout - seen with 800k chunks (about 6 GB of index) on a 16 MB cache.
	 *
	 * The index size is index_length of the table, which includes the hidden vector index table and
	 * needs no privilege beyond reading information_schema.
	 *
	 * @param Api\Db $db
	 * @return string[]
	 */
	protected function checkIndexMemory(Api\Db $db) : array
	{
		$row = $db->query('SELECT data_length, index_length, table_rows FROM information_schema.tables'.
			' WHERE table_schema=DATABASE() AND table_name='.$db->quote(Embedding::TABLE))->fetch();
		if (!$row) return [];
		$index = (int)$row['index_length'];
		$table = $index + (int)$row['data_length'];
		$mb = static fn(int $bytes) => number_format($bytes / 1048576, 0, '', '.').' MB';

		$lines = [self::line(lang('Size of the vector index'), $mb($index).', '.
			lang('%1 chunks', number_format((int)$db->query('SELECT COUNT(*) FROM '.Embedding::TABLE)->fetchColumn(), 0, '', '.')))];

		$cache = (int)$db->query('SELECT @@GLOBAL.mhnsw_max_cache_size')->fetchColumn();
		$lines[] = self::line(lang('Vector index cache').' (mhnsw_max_cache_size)', $mb($cache),
			$cache >= $index ? 'ok' : 'warn', $cache >= $index ? null :
				lang('Smaller than the index: searches read it from the buffer pool or disk and can run into the search timeout.'));
		if ($cache < $index)
		{
			// the index plus a quarter for its growth, in whole GB
			$gb = max(1, (int)ceil($index * 1.25 / 1073741824));
			$indent = str_repeat(' ', 7);
			array_push($lines,
				$indent.lang('What to do').':',
				$indent.'1. '.lang('Check the server has %1 GB of memory to spare, on top of the InnoDB buffer pool.', $gb),
				$indent.'2. '.lang('Raise the cache now, as a database administrator').': SET GLOBAL mhnsw_max_cache_size = '.($gb * 1073741824).';',
				$indent.'   '.lang('and permanently in the MariaDB server configuration, e.g. %1, then restart MariaDB',
					'/etc/mysql/mariadb.conf.d/50-server.cnf').': [mariadb] mhnsw_max_cache_size = '.$gb.'G',
				$indent.'   '.lang('If SET GLOBAL is refused, use only the configuration and the restart.'),
				$indent.'3. '.lang('Run the same RAG search twice: the first one loads the index into the cache and can still be slow, the second should answer within a second.'),
				$indent.lang('Not enough memory? A partly filled cache still helps. Or embed less: fewer applications for RAG, or only newer entries.'));
		}

		$pool = (int)$db->query('SELECT @@GLOBAL.innodb_buffer_pool_size')->fetchColumn();
		$lines[] = self::line(lang('InnoDB buffer pool').' (innodb_buffer_pool_size)', $mb($pool),
			$pool >= $table ? 'ok' : 'warn', $pool >= $table ? null :
				lang('Smaller than %1 with its indexes (%2): what is not in the vector index cache is read from disk.',
					Embedding::TABLE, $mb($table)));
		return $lines;
	}

	/**
	 * Ask the endpoint for its models and check the configured ones are among them
	 *
	 * @return string[]
	 */
	protected function checkEndpoint() : array
	{
		if (empty($this->config['url']))
		{
			return [self::line(lang('URL of OpenAI compatible API'), lang('not configured'), 'warn')];
		}
		$lines = [];
		$start = microtime(true);
		try {
			$response = $this->request('GET', rtrim($this->config['url'], '/').'/models');
		}
		catch (\Throwable $e) {
			return [self::line(rtrim($this->config['url'], '/').'/models', $e->getMessage(), 'fail')];
		}
		$models = array_column($response['data'] ?? [], 'id');
		$lines[] = self::line(rtrim($this->config['url'], '/').'/models',
			count($models).' '.lang('models').' ('.self::ms($start).')', $models ? 'ok' : 'fail');

		$embedding_model = $this->config['embedding_model'] ?? 'bge-m3';
		$lines[] = self::line(lang('Model to use'), $embedding_model,
			self::modelAvailable($embedding_model, $models) ? 'ok' : 'fail',
			self::modelAvailable($embedding_model, $models) ? null : lang('not offered by the endpoint'));

		if (!empty($this->config['rerank_model']) && $this->rerankUrl(true) === rtrim($this->config['url'], '/'))
		{
			$lines[] = self::line(lang('Model to rerank the best matches'), $this->config['rerank_model'],
				self::modelAvailable($this->config['rerank_model'], $models) ? 'ok' : 'warn',
				self::modelAvailable($this->config['rerank_model'], $models) ? null :
					lang('not offered by the endpoint - a rerank endpoint does not have to list it though'));
		}
		$lines[] = '';
		$lines[] = lang('Models of the endpoint').':';
		foreach($models as $model)
		{
			$lines[] = '  '.$model;
		}
		return $lines;
	}

	/**
	 * Embed two texts and check the vectors come back and are meaningful
	 *
	 * The texts carry a timestamp, so the sha256 cache of create() can not answer instead of the endpoint.
	 *
	 * @return string[]
	 */
	protected function checkEmbedding() : array
	{
		if (empty($this->config['url']))
		{
			return [self::line(lang('URL of OpenAI compatible API'), lang('not configured'), 'warn')];
		}
		$now = date('c');
		$texts = [
			'invoice' => 'The invoice is due 30 days after the delivery date. ('.$now.')',
			'payment' => 'Payment has to reach us within a month of the goods arriving. ('.$now.')',
			'coffee'  => 'The new coffee machine is in the kitchen on the second floor. ('.$now.')',
		];
		$start = microtime(true);
		try {
			$embedding = new Embedding();
			$responses = $embedding->create(array_values($texts));
		}
		catch (\Throwable $e) {
			return [self::line(lang('Embedding'), $e->getMessage(), 'fail')];
		}
		$vectors = [];
		foreach(array_keys($texts) as $n => $key)
		{
			foreach($responses as $response)
			{
				if ($response->n === $n) $vectors[$key] = $response->embedding;
			}
		}
		if (count($vectors) < count($texts))
		{
			return [self::line(lang('Embedding'), lang('%1 of %2 embeddings returned', count($vectors), count($texts)), 'fail')];
		}
		$dimensions = count(current($vectors));
		$lines = [
			self::line(lang('Embedding'), count($vectors).' x '.$dimensions.' '.lang('dimensions').' ('.self::ms($start).')', 'ok'),
			self::line(lang('Dimensions'), $dimensions, $dimensions === 1024 ? 'ok' : 'fail',
				$dimensions === 1024 ? null : lang('the schema stores vector(1024), other models need a schema change')),
			'',
			// a usable embedding model puts the two texts about the same thing closer together than the unrelated one
			self::line(lang('Distance').' invoice <-> payment', sprintf('%.4f', $related=self::distance($vectors['invoice'], $vectors['payment']))),
			self::line(lang('Distance').' invoice <-> coffee', sprintf('%.4f', $unrelated=self::distance($vectors['invoice'], $vectors['coffee']))),
			self::line(lang('Semantics'), $related < $unrelated ? lang('the related texts are closer - the model works') :
				lang('the unrelated text is closer - check the model'), $related < $unrelated ? 'ok' : 'fail'),
			self::line(lang('Max. distance of a semantic match'), $this->config['max_distance'] ?? 0.4,
				$related <= (float)($this->config['max_distance'] ?? 0.4) ? 'ok' : 'warn',
				$related <= (float)($this->config['max_distance'] ?? 0.4) ? null :
					lang('even the related texts are further apart than that - matches will be dropped')),
		];
		return $lines;
	}

	/**
	 * Send a real rerank request and check the endpoint answers with usable scores
	 *
	 * @return string[]
	 */
	protected function checkRerank() : array
	{
		if (empty($this->config['rerank_model']))
		{
			return [self::line(lang('Model to rerank the best matches'), lang('off'), 'warn',
				lang('Leave empty to switch the reranking off.'))];
		}
		$url = $this->rerankUrl();
		$documents = [];
		foreach(self::RERANK_DOCUMENTS as $n => $document)
		{
			$documents['doc'.$n] = $document;
		}
		$start = microtime(true);
		try {
			$embedding = new Embedding();
			$scores = $embedding->rerank(self::RERANK_QUERY, $documents);
		}
		catch (\Throwable $e) {
			return [
				self::line($url, $e->getMessage(), 'fail'),
				'',
				lang('A reranker needs a cross-encoder endpoint: llama.cpp --reranking, vLLM, Jina or HuggingFace TEI.').' '.
				lang('Ollama has no rerank endpoint, having the model pulled is not enough.'),
				lang('Every search still works, it silently keeps the order of the hybrid fusion and logs this error.'),
			];
		}
		$lines = [
			self::line($url, count($scores).' '.lang('scores').' ('.self::ms($start).')', 'ok'),
			'',
			self::line(lang('Query'), self::RERANK_QUERY),
			'',
		];
		$first = null;
		foreach($scores as $id => $score)
		{
			$first ??= $id;
			$lines[] = '  '.sprintf('%8.4f', $score).'  '.$documents[$id];
		}
		$lines[] = '';
		$lines[] = self::line(lang('Order'), $first === 'doc0' ? lang('the answering document is first - the reranker works') :
			lang('the answering document is NOT first - check the model'), $first === 'doc0' ? 'ok' : 'fail');

		// scoring three short documents proves nothing about the length a real search sends
		$start = microtime(true);
		try {
			$embedding->rerank(self::RERANK_QUERY, ['probe' => self::rerankProbeDocument()]);
			$lines[] = self::line(lang('Document length'),
				lang('%1 characters accepted', Embedding::RERANK_MAX_CHARS).' ('.self::ms($start).')', 'ok');
		}
		catch (\Throwable $e) {
			$lines[] = self::line(lang('Document length'), $e->getMessage(), 'fail',
				lang('a real search sends documents of %1 characters', Embedding::RERANK_MAX_CHARS));
			$lines[] = '';
			$lines[] = lang('llama.cpp refuses any single input longer than its physical batch, and one such document fails the whole request.').' '.
				lang('Start it with e.g. -ub 2048 -b 2048 -c 8192.');
		}
		if (!empty($this->config['rerank_min_score']))
		{
			$lines[] = self::line(lang('Minimum score of the reranker'), $this->config['rerank_min_score'],
				max($scores) >= (float)$this->config['rerank_min_score'] ? 'ok' : 'warn',
				max($scores) >= (float)$this->config['rerank_min_score'] ? null :
					lang('even the best match scores below it - every match would be dropped'));
		}
		return $lines;
	}

	/**
	 * State of both indexes, per application
	 *
	 * @return string[]
	 */
	protected function checkIndex() : array
	{
		$db = $GLOBALS['egw']->db;
		$lines = [self::row([lang('Application'), lang('Total'), lang('Entries'), lang('Embedded').' %',
			lang('Chunks'), lang('Fulltext'), lang('Fulltext').' %', lang('last').' '.lang('update')])];
		$lines[] = str_repeat('-', 103);   // the sum of the column widths in row(), plus their separators

		$embeddings = $fulltext = [];
		foreach($db->select(Embedding::TABLE, [Embedding::EMBEDDING_APP, 'COUNT(*) AS chunks',
			'COUNT(DISTINCT '.Embedding::EMBEDDING_APP_ID.') AS entries', 'MAX('.Embedding::EMBEDDING_UPDATED.') AS updated'],
			[], __LINE__, __FILE__, false, 'GROUP BY '.Embedding::EMBEDDING_APP, self::APP) as $row)
		{
			$embeddings[$row[Embedding::EMBEDDING_APP]] = $row;
		}
		// "entries" counts the rows of the entry itself (part ''), not its separately indexed parts,
		// so it is the number of entries the embeddings are measured against
		foreach($db->select(Embedding::FULLTEXT_TABLE, [Embedding::FULLTEXT_APP, 'COUNT(*) AS rows_',
			'SUM('.Embedding::FULLTEXT_PART."='') AS entries", 'MAX('.Embedding::FULLTEXT_UPDATED.') AS updated'],
			[], __LINE__, __FILE__, false, 'GROUP BY '.Embedding::FULLTEXT_APP, self::APP) as $row)
		{
			$fulltext[$row[Embedding::FULLTEXT_APP]] = $row;
		}
		// an app switched off for one of the indexes explains its numbers better than they do themselves
		$rag_apps = $this->config['rag_apps'] ?? null;
		$fulltext_apps = $this->config['fulltext_apps'] ?? null;
		$unmaintained = false;
		foreach(array_unique(array_merge(array_keys(Embedding::plugins()), array_keys($embeddings), array_keys($fulltext))) as $app)
		{
			$updated = max($embeddings[$app]['updated'] ?? '', $fulltext[$app]['updated'] ?? '');
			$embedded = (int)($embeddings[$app]['entries'] ?? 0);
			// the application's own entries, which is what "how much of it is embedded" is about - the
			// fulltext column counts ROWS, and an entry can have several (replies, files) or none
			$total = $this->appTotal($app);
			$of = $total ?? (int)($fulltext[$app]['entries'] ?? 0);
			if (empty($this->config['url']) || !empty($rag_apps) && !in_array($app, $rag_apps))
			{
				$percent = lang('off');     // not embedded on purpose, 0% would look like a failure
			}
			else
			{
				// without a fulltext row per entry there is nothing to measure against
				$percent = $of ? round(100 * $embedded / $of, 1).'%' : ($embedded ? '?' : '-');
			}
			// ENTRIES, not rows: an entry can have several fulltext rows (a tracker item and its
			// replies), so the raw row count reads higher than the number of entries and is not
			// comparable with the total next to it. The parts are appended as "+N" when there are any.
			$ft_entries = (int)($fulltext[$app]['entries'] ?? 0);
			$ft_parts = (int)($fulltext[$app]['rows_'] ?? 0) - $ft_entries;
			$ft = $ft_entries.($ft_parts > 0 ? '+'.$ft_parts : '');
			// a number here says what IS indexed, not that it stays that way: an app not in the
			// fulltext-apps is not updated any more, and silently drifts from the moment an entry changes
			if ($ft_entries && !empty($fulltext_apps) && !in_array($app, $fulltext_apps))
			{
				$ft .= '*';
				$unmaintained = true;
			}
			// measured against the same total as the embeddings, so the two percentages are comparable.
			// The count keeps its "*": a frozen index can read 100% and still be wrong tomorrow.
			if (!$ft_entries && !empty($fulltext_apps) && !in_array($app, $fulltext_apps))
			{
				$ft_percent = lang('off');
			}
			else
			{
				$ft_percent = $total ? round(100 * $ft_entries / $total, 1).'%' : ($ft_entries ? '?' : '-');
			}
			$lines[] = self::row([$app, $total ?? '?', $embedded, $percent, (int)($embeddings[$app]['chunks'] ?? 0),
				$ft, $ft_percent, $updated ? Api\DateTime::to($updated) : '-']);
		}
		if ($unmaintained)
		{
			$lines[] = '';
			$lines[] = '* '.lang('not in %1, so this index is no longer updated and drifts as soon as an entry changes',
				'"'.lang('Applications to add to fulltext-index').'"');
		}
		$lines[] = '';
		$lines[] = self::line(lang('Cached search patterns'),
			(int)$db->select(Embedding::CACHE_TABLE, 'COUNT(*)', [], __LINE__, __FILE__, false, '', self::APP)->fetchColumn());

		$errors = $this->config[Embedding::RAG_LAST_ERRORS] ?? [];
		$lines[] = self::line(lang('Last errors of RAG\'s async job'), count($errors), count($errors) ? 'warn' : 'ok');
		foreach(array_slice($errors, 0, 5) as $error)
		{
			$lines[] = '  '.Api\DateTime::to($error['date']).' ['.($error['app'] ?? '').'] '.
				substr(str_replace("\n", ' ', (string)$error['message']), 0, 120);
		}
		return $lines;
	}

	/**
	 * Run a real search and show what the ranking does
	 *
	 * @param array $content
	 * @return string[]
	 */
	protected function checkSearch(array $content) : array
	{
		if (empty($content['search_pattern']))
		{
			return [self::line(lang('Query'), lang('Enter your search query here...'), 'warn')];
		}
		$type = $content['search_type'] ?: 'hybrid';
		$app = $content['search_app'] ?: null;
		$max_distance = is_numeric($content['search_max_distance'] ?? null) ? (float)$content['search_max_distance'] : null;
		$method = ['hybrid' => 'search', 'rag' => 'searchEmbeddings', 'fulltext' => 'searchFulltext'][$type];
		$start = microtime(true);
		try {
			$embedding = new Embedding();
			$rows = $type === 'fulltext' ?
				$embedding->searchFulltext($content['search_pattern'], $app ? [$app] : null, 0, 10, true) :
				($type === 'rag' ?
					$embedding->searchEmbeddings($content['search_pattern'], $app ? [$app] : null, 0, 10, true, 'default', $max_distance) :
					$embedding->search($content['search_pattern'], $app ? [$app] : null, 0, 10, true, 'default', $max_distance));
		}
		catch (\Throwable $e) {
			return [self::line(lang('Search'), $e->getMessage(), 'fail')];
		}
		$lines = [
			self::line(lang('Query'), $content['search_pattern']),
			self::line(lang('Type of search'), $type.($app ? ' / '.$app : '').
				($type !== 'fulltext' ? ', '.lang('Max. distance of a semantic match').' '.
					($max_distance ?? $this->config['max_distance'] ?? 0.4) : '')),
			self::line(lang('Result'), ($embedding->total ?? count($rows)).' '.lang('total').', '.
				count($rows).' '.lang('shown').' ('.self::ms($start).')', $rows ? 'ok' : 'warn'),
			'',
			sprintf('%-28s %9s %9s %9s %9s  %s', 'ID', lang('Distance'), lang('Relevance'), lang('Score'), 'Rerank', lang('Title')),
			str_repeat('-', 110),
		];
		foreach($rows as $id => $row)
		{
			$lines[] = sprintf('%-28s %9s %9s %9s %9s  %s', $id,
				isset($row['distance']) ? sprintf('%.4f', $row['distance']) : '-',
				isset($row['relevance']) ? sprintf('%.4f', $row['relevance']) : '-',
				isset($row['score']) ? sprintf('%.4f', $row['score']) : '-',
				isset($row['rerank_score']) ? sprintf('%.4f', $row['rerank_score']) : '-',
				substr(str_replace("\n", ' ', (string)($row['title'] ?? '')), 0, 50));
		}
		if ($type === 'hybrid' && !empty($this->config['rerank_model']) &&
			!array_filter($rows, static fn($row) => isset($row['rerank_score'])))
		{
			$lines[] = '';
			$lines[] = self::line(lang('Reranking'), lang('no row carries a rerank score'), 'warn',
				lang('Run the rerank check to see why'));
		}
		// the cut-off is the usual reason for an empty semantic result, so always say how far the nearest chunks are
		if ($type !== 'fulltext')
		{
			$cut_off = $max_distance ?? (float)($this->config['max_distance'] ?? 0.4);
			try {
				$nearest = $embedding->searchEmbeddings($content['search_pattern'], $app ? [$app] : null, 0, 5, false, 'default', 2.0);
			}
			catch (\Throwable $e) {
				return array_merge($lines, ['', self::line(lang('Nearest chunks'), $e->getMessage(), 'fail')]);
			}
			$lines[] = '';
			$lines[] = self::line(lang('Nearest chunks'), $nearest ?
				implode(', ', array_map(static fn($d) => sprintf('%.4f', $d), $nearest)) :
				lang('none - nothing is embedded for this application yet'), $nearest ? null : 'warn');
			if ($nearest && min($nearest) >= $cut_off)
			{
				$lines[] = self::line(lang('Max. distance of a semantic match'), $cut_off, 'warn',
					lang('the nearest chunk is further away, so the semantic side returns nothing'));
			}
		}
		return $lines;
	}

	/**
	 * Index for at most INDEX_NOW_RUNTIME seconds, so an installation can be tested without waiting for the async job
	 *
	 * embed() always does the fulltext index first, which on a big installation eats the whole budget before a
	 * single embedding is calculated - hence the scope, which switches one of the two passes off by giving it
	 * an app-list no plugin can match.
	 *
	 * @param string $scope "both", "rag" (embeddings only) or "fulltext"
	 * @return string[]
	 */
	protected function indexNow(string $scope='both') : array
	{
		$before = $this->indexCounts();
		$start = microtime(true);
		try {
			// the config's async_maxruntime is minutes, way too long for a request
			$config = array_merge($this->config, ['async_maxruntime' => self::INDEX_NOW_RUNTIME]);
			if ($scope === 'rag')
			{
				$config['fulltext_apps'] = [self::NO_APP];
			}
			elseif ($scope === 'fulltext')
			{
				$config['rag_apps'] = [self::NO_APP];
			}
			$embedding = new Embedding(0, $config);
			$embedding->embed();
		}
		catch (\Throwable $e) {
			return [self::line(lang('Index now'), $e->getMessage(), 'fail')];
		}
		$after = $this->indexCounts();
		return [
			self::line(lang('Index now'), lang('ran %1 seconds', round(microtime(true) - $start)), 'ok'),
			self::line(lang('Chunks'), $before['chunks'].' -> '.$after['chunks'].
				' (+'.($after['chunks'] - $before['chunks']).')'),
			self::line(lang('Fulltext'), $before['fulltext'].' -> '.$after['fulltext'].
				' (+'.($after['fulltext'] - $before['fulltext']).')'),
			'',
			lang('Run it again to index the next batch, or save the configuration to let the async job do it.'),
		];
	}

	/**
	 * @return int[] with keys "chunks" and "fulltext"
	 */
	protected function indexCounts() : array
	{
		$db = $GLOBALS['egw']->db;
		return [
			'chunks' => (int)$db->select(Embedding::TABLE, 'COUNT(*)', [], __LINE__, __FILE__, false, '', self::APP)->fetchColumn(),
			'fulltext' => (int)$db->select(Embedding::FULLTEXT_TABLE, 'COUNT(*)', [], __LINE__, __FILE__, false, '', self::APP)->fetchColumn(),
		];
	}

	/**
	 * The url Embedding::rerank() posts to, built exactly as it builds it
	 *
	 * @param bool $base true: without the /rerank path, to compare it with the embedding url
	 * @return string
	 */
	protected function rerankUrl(bool $base=false) : string
	{
		$url = rtrim(!empty($this->config['rerank_url']) ? $this->config['rerank_url'] : ($this->config['url'] ?? ''), '/');
		if (!$base && $url && !str_ends_with($url, '/rerank'))
		{
			$url .= '/rerank';
		}
		return $url;
	}

	/**
	 * A GET/POST returning the decoded json, with the api-key as bearer token
	 *
	 * @param string $method
	 * @param string $url
	 * @param ?array $payload
	 * @return array
	 */
	protected function request(string $method, string $url, ?array $payload=null) : array
	{
		$context = stream_context_create(['http' => [
			'method'        => $method,
			'header'        => array_filter([
				'Content-Type: application/json',
				!empty($this->config['api_key']) ? 'Authorization: Bearer '.$this->config['api_key'] : null,
			]),
			'content'       => isset($payload) ? json_encode($payload) : null,
			'timeout'       => 10,
			'ignore_errors' => true,    // so a 404 gives us its body instead of a warning
		]]);
		$response = file_get_contents($url, false, $context);
		$status = isset($http_response_header[0]) ? $http_response_header[0] : '';
		if ($response === false)
		{
			throw new \Exception(lang('not reachable'));
		}
		if (!preg_match('# (2\d\d) #', ' '.$status.' '))
		{
			throw new \Exception(trim($status).': '.substr(trim($response), 0, 200));
		}
		return json_decode($response, true) ?? [];
	}

	/**
	 * Is $model offered by the endpoint, with the same tolerance testConfig() has
	 *
	 * @param string $model
	 * @param string[] $models
	 * @return bool
	 */
	protected static function modelAvailable(string $model, array $models) : bool
	{
		return (bool)array_filter($models, static fn($id) => $id === $model ||
			str_starts_with($id, $model.':') || str_ends_with($id, '/'.$model));
	}

	/**
	 * Cosine distance of two vectors, the same measure the k-NN query uses
	 *
	 * @param float[] $a
	 * @param float[] $b
	 * @return float 0 = identical, 1 = unrelated, 2 = opposite
	 */
	protected static function distance(array $a, array $b) : float
	{
		$dot = $len_a = $len_b = 0.0;
		foreach($a as $n => $value)
		{
			$dot += $value * $b[$n];
			$len_a += $value * $value;
			$len_b += $b[$n] * $b[$n];
		}
		return $len_a && $len_b ? 1 - $dot / (sqrt($len_a) * sqrt($len_b)) : 1.0;
	}

	/**
	 * @param float $start from microtime(true)
	 * @return string
	 */
	protected static function ms(float $start) : string
	{
		return round((microtime(true) - $start) * 1000).'ms';
	}

	/**
	 * One "label: value" line, optionally marked and commented
	 *
	 * @param string $label
	 * @param string|int|float $value
	 * @param ?string $status "ok", "warn" or "fail"
	 * @param ?string $comment
	 * @return string
	 */
	protected static function line(string $label, $value, ?string $status=null, ?string $comment=null) : string
	{
		$marker = ['ok' => '[OK]  ', 'warn' => '[WARN]', 'fail' => '[FAIL]'][$status] ?? '      ';
		return $marker.' '.str_pad($label.':', 48).' '.$value.($comment ? ' - '.$comment : '');
	}

	/**
	 * How many entries the application itself has, the denominator of "how much of it is embedded"
	 *
	 * Every plugin declares its table and what counts as deleted, so this works for any of them
	 * without the RAG knowing the application. Null when it cannot be counted - a plugin without a
	 * table constant, or a table that is not installed.
	 *
	 * @param string $app
	 * @return int|null
	 */
	protected function appTotal(string $app) : ?int
	{
		$class = Embedding::plugins()[$app] ?? null;
		if (!$class || !defined($class.'::TABLE') || !constant($class.'::TABLE'))
		{
			return null;
		}
		try {
			return (int)$GLOBALS['egw']->db->select(constant($class.'::TABLE'), 'COUNT(*)',
				constant($class.'::NOT_DELETED') ?: [], __LINE__, __FILE__, false, '', $app)->fetchColumn();
		}
		catch (\Throwable $e) {
			return null;    // table of an app that is not installed (any more)
		}
	}

	/**
	 * One row of the index-status table
	 *
	 * sprintf('%-20s') pads to a number of BYTES, and the column headers are translated - "Εφαρμογή"
	 * is 8 characters but 16 bytes, which shifts the whole header against its rows in every language
	 * that is not latin-1. So the padding is done on characters.
	 *
	 * @param array $cells first one left-aligned, the rest right-aligned
	 * @return string
	 */
	protected static function row(array $cells) : string
	{
		static $widths = [20, 10, 10, 8, 10, 10, 8, 20];
		$out = [];
		foreach(array_values($cells) as $n => $cell)
		{
			$cell = (string)$cell;
			$pad = max(0, ($widths[$n] ?? 10) - mb_strlen($cell));
			$out[] = $n ? str_repeat(' ', $pad).$cell : $cell.str_repeat(' ', $pad);
		}
		return implode(' ', $out);
	}

	/**
	 * @param string $title
	 * @param string[] $lines
	 * @return string
	 */
	protected function report(string $title, array $lines) : string
	{
		return '=== '.$title.' === '.Api\DateTime::to('now')."\n\n".implode("\n", $lines)."\n";
	}
}
