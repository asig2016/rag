<?php
/**
 * EGroupware RAG system
 *
 * @package rag
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @copyright 2025 by Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use EGroupware\Api\Db\Exception\InvalidSql;
use OpenAI;
use Symfony\Component\HttpClient\HttpClient;

require_once __DIR__.'/../vendor/autoload.php';

class Embedding
{
	const APP = 'rag';
	const TABLE = 'egw_rag';
	const EMBEDDING_UPDATED = 'rag_updated';
	const EMBEDDING_APP = 'rag_app';
	const EMBEDDING_CACHE = '*cache*';  // EMBEDDING_APP of cached embeddings before they moved to CACHE_TABLE, only used in the update
	const EMBEDDING_APP_ID = 'rag_app_id';
	const EMBEDDING_CHUNK = 'rag_chunk';
	const EMBEDDING = 'rag_embedding';
	const EMBEDDING_MODIFIED = 'rag_updated';
	const EMBEDDING_HASH = 'rag_hash';
	/**
	 * Part of an entry the chunks belong to: '' = the entry's own text, later e.g. "reply:<id>" or "file:<fs_id>"
	 */
	const EMBEDDING_PART = 'rag_part';
	/**
	 * Embeddings of search patterns, kept apart from egw_rag so they are not part of its vector index
	 */
	const CACHE_TABLE = 'egw_rag_cache';
	const CACHE_HASH = 'rc_hash';
	const CACHE_EMBEDDING = 'rc_embedding';
	const CACHE_UPDATED = 'rc_updated';
	const FULLTEXT_TABLE = 'egw_rag_fulltext';
	const FULLTEXT_UPDATED = 'ft_updated';
	const FULLTEXT_APP = 'ft_app';
	const FULLTEXT_APP_ID = 'ft_app_id';
	const FULLTEXT_TITLE = 'ft_title';
	const FULLTEXT_DESCRIPTION = 'ft_description';
	const FULLTEXT_EXTRA = 'ft_extra';
	const FULLTEXT_PART = 'ft_part';
	const FULLTEXT_MODIFIED = 'ft_updated';

	/**
	 * Name of config storing the last errors of RAG's async-job
	 */
	const RAG_LAST_ERRORS = 'rag-last-errors';

	/**
	 * Stop calculating embeddings after this time: 5min - 15sec
	 *
	 * So the async job stops before the next one starts.
	 */
	protected static int $max_runtime = 285;

	/**
	 *
	 * When should the async job run
	 *
	 */
	protected static array $async_times = ['min'=>'*/5'];

	/**
	 * @var ?OpenAI\Client
	 */
	protected ?OpenAI\Client $client=null;

	/**
	 * @var Api\Db;
	 */
	protected $db;

	/**
	 * @var int max size of chunk
	 */
	protected static int $chunk_size = 1500;
	/**
	 * @var int overlap of chunks
	 */
	protected static int $chunk_overlap = 100;
	/**
	 * @var string embedding model to use
	 */
	protected static string $model = 'bge-m3';
	/**
	 * @var bool minimize number of chunks e.g. by concatenating title and describtion
	 */
	public static bool $minimize_chunks = true;
	/**
	 * @var float max. cosine distance of a chunk to count as semantic match (0 = identical, 2 = opposite)
	 */
	protected static float $max_distance = .4;
	/**
	 * @var int number of ids search2criteria() fetches for the apps' own list search
	 */
	protected static int $search_depth = 200;
	/**
	 * @var float seconds a scoped search may take before it falls back to the unscoped one, 0: no limit
	 *
	 * Only a safety net for the app-filter join: when the filter matches a large, heavily embedded set
	 * the optimizer can pick a brute-force distance scan (~30us per chunk here). It costs nothing while
	 * queries are fast, which they are - tens of ms measured.
	 */
	protected static float $search_timeout = 10.0;
	/**
	 * Constant k of Reciprocal Rank Fusion: bigger values weigh the top ranks of each list less
	 */
	const RRF_K = 60;
	/**
	 * @var ?string cross-encoder model to rerank the best matches with, null/empty: no reranking
	 */
	protected static ?string $rerank_model = null;
	/**
	 * @var ?string base-url of the rerank endpoint, defaults to self::$url
	 */
	protected static ?string $rerank_url = null;
	/**
	 * @var string api of the rerank endpoint: "cohere" (llama.cpp, vLLM, Jina) or "tei" (HuggingFace TEI)
	 */
	protected static string $rerank_api = 'cohere';
	/**
	 * @var int how many of the best matches are reranked
	 */
	protected static int $rerank_depth = 50;
	/**
	 * @var float drop candidates the reranker scores below this, 0: keep all
	 */
	protected static float $rerank_min_score = 0.0;
	/**
	 * @var float seconds to wait for the rerank endpoint
	 */
	protected static float $rerank_timeout = 3.0;
	/**
	 * @var int max. characters of a document sent to the reranker
	 */
	const RERANK_MAX_CHARS = 2000;

	/**
	 * @var int log-level: 0: errors only, 1: result of search*() methods
	 */
	protected static int $log_level = 0;

	/**
	 * @var array|null limit RAG to the following apps, default all
	 */
	protected static ?array $rag_apps = null;
	/**
	 * @var array|null limit fulltext index to the following apps, default all
	 */
	protected static ?array $fulltext_apps   = null;

	/**
	 * @var string base-url of OpenAI compatible api (you can NOT use localhost!):
	 * - IONOS:  https://openai.inference.de-txl.ionos.com/v1
	 * - Ollama: http://172.17.0.1:11434/v1  requires Ollama to be bound on all interfaces / 0.0.0.0 (not just localhost!)
	 * - Ralf's Ollama: http://10.44.253.3:11434/v1
	 */
	protected static ?string $url = null;
	protected static ?string $api_key = null;

	/**
	 * Total number of rows of last search*() call
	 *
	 * @var int|null
	 */
	public ?int $total;

	/**
	 * Constructor
	 *
	 * @param int $log_level
	 * @param array|null $config
	 */
	public function __construct(int $log_level = 0, ?array $config=null)
	{
		if ($config)
		{
			self::initStatic($config);
		}
		if (self::$url)
		{
			$factory = Openai::factory();
			if (self::$url) $factory->withBaseUri(self::$url);
			if (self::$api_key) $factory->withApiKey(self::$api_key);
			$this->client = $factory->make();
		}
		$this->db = $GLOBALS['egw']->db;
		self::$log_level = $log_level;
	}

	/**
	 * Test configuration
	 *
	 * @return void
	 * @throws \Exception on error with codes between 1000 and 1004
	 */
	public function testConfig()
	{
		if ((float)$this->db->ServerInfo['version'] < 11.7)
		{
			throw new \Exception('MariaDB 11.7+ is currently the only supported vector database, you can NOT use the RAG without it!', 1000);
		}

		if (!$this->db->query('SHOW CREATE TABLE '.self::TABLE)->fetchColumn())
		{
			throw new \Exception(self::TABLE.' is NOT installed', 1001);
		}

		if (empty(self::$url))
		{
			throw new \Exception('OpenAI compatible endpoint is not configured', 1002);
		}

		try {
			$models = $this->client->models()->list()->data ?? [];
		}
		catch (\Throwable $e) {
			throw new \Exception($e->getMessage(), 1003, $e);
		}

		if (!array_filter($models, static fn(object $model) => self::$model === $model->id ||
			str_starts_with($model->id, self::$model.':') ||  str_ends_with($model->id, '/'.self::$model)))
		{
			throw new \Exception('Model '.self::$model.' is not supported by the endpoint!', 1004);
		}
	}

	/**
	 * Init our static variables from configuration
	 *
	 * @param ?array $config
	 */
	public static function initStatic(?array $config=null)
	{
		if (!$config)
		{
			$config = Api\Config::read(self::APP);
		}

		self::$rag_apps = $config['rag_apps'] ?? null;
		self::$fulltext_apps = $config['fulltext_apps'] ?? null;

		self::$url = $config['url'] ?? null;
		self::$api_key = $config['api_key'] ?? null;

		self::$chunk_size = (int)($config['chunk_size'] ?? 1500) ?: 1500;
		self::$chunk_overlap = (int)($config['chunk_overlap'] ?? 100);
		self::$model = $config['embedding_model'] ?? 'bge-m3';
		self::$minimize_chunks = ($config['minimize_chunks'] ?? 'yes') !== 'no';
		self::$max_distance = is_numeric($config['max_distance'] ?? null) && $config['max_distance'] > 0 ? (float)$config['max_distance'] : .4;
		self::$search_depth = (int)($config['search_depth'] ?? 200) ?: 200;
		self::$search_timeout = (float)($config['search_timeout'] ?? 10);

		self::$rerank_model = !empty($config['rerank_model']) ? $config['rerank_model'] : null;
		self::$rerank_url = !empty($config['rerank_url']) ? $config['rerank_url'] : null;
		self::$rerank_api = ($config['rerank_api'] ?? 'cohere') === 'tei' ? 'tei' : 'cohere';
		self::$rerank_depth = (int)($config['rerank_depth'] ?? 50) ?: 50;
		self::$rerank_min_score = (float)($config['rerank_min_score'] ?? 0);
		self::$rerank_timeout = (float)($config['rerank_timeout'] ?? 3) ?: 3.0;
		// the cast has to happen after ??, (int)null is 0 and would stop embed() after the first entry
		self::$max_runtime = (int)($config['async_maxruntime'] ?? 285) ?: 285;

		$custom_times = [];
		foreach (['year', 'month', 'day', 'dow', 'hour', 'min'] as $key) {
			if (isset($config['async_' . $key])) {
				$custom_times[$key] = $config['async_' . $key];
			}
		}

		if (!empty($custom_times)) {
			self::$async_times = $custom_times;
		}

	}

	/**
	 * Check if RAG is available for $app and what default-search is allowed by config and preferred by the user
	 *
	 * - if no plugin for $app --> RAG is not available
	 * - if default_search preference is "legacy" --> RAG is not available / swithed off
	 * - if no URL configured --> only fulltext is available, if NOT configured to be off
	 *
	 * @param string $app app-name
	 * @param string|null $type search-type, defaults to RAG preference "search_type"
	 * @return string|null null=not available, "hybrid", "rag" or "fulltext" search to use for $app
	 */
	public static function available(string $app, ?string $type=null) : ?string
	{
		// check RAG is installed, we don't check/care if the individual user has run-rights
		if (empty($GLOBALS['egw_info']['apps']['rag']))
		{
			return null;
		}
		// no plugin for $app --> not available
		if (empty(self::plugins()[$app]))
		{
			return null;
		}
		// check default search not switched to legacy --> not available
		if (!isset($type))
		{
			$type = $GLOBALS['egw_info']['user']['preferences']['rag'][$app.'_search'] ??
				$GLOBALS['egw_info']['user']['preferences']['rag']['default_search'] ??
				($app === 'addressbook' ? 'legacy' : 'hybrid');
		}
		if ($type === 'legacy')
		{
			return null;
		}
		// only fulltext possible, because RAG not configured or turned off for $app --> use fulltext
		if (empty(self::$url) || !(empty(self::$rag_apps) || in_array($app, self::$rag_apps)))
		{
			$type = 'fulltext';
		}
		// if we're to use fulltext, check if it's not turned off for $app
		if ($type === 'fulltext')
		{
			return !(empty(self::$fulltext_apps) || in_array($app, self::$fulltext_apps)) ? null : 'fulltext';
		}
		return $type;   // fulltext or rag
	}

	/**
	 * Check if RAG assisted search is available and if yes, implement preferred search by modifying the parameters
	 *
	 * @param string $app app-name
	 * @param string $criteria search
	 * @param $order_by
	 * @param $extra_cols
	 * @param array $filter
	 * @param ?int[] $app_ids optional limit the search to these ids of $app
	 * @return bool false: search not available, or configured to be off, true: search available and implemented via changed parameters
	 */
	public static function search2criteria(string $app, string &$criteria, &$order_by, &$extra_cols, ?array &$filter, ?array $app_ids=null,
		?string $app_filter=null) : bool
	{
		$criteria_in = $criteria;
		// Contacts class in API uses "api", but the app is / has to be "addressbook"
		if ($app === 'api')
		{
			$app = 'addressbook';
		}
		if (preg_match('/^(fulltext|hybrid|rag|legacy):(.*)$/', $criteria, $matches))
		{
			$criteria = $matches[2];
		}
		if (($matches[1]??null) === 'legacy' || preg_match('/^#?\d+$/', $criteria) ||
			// automatic switch to legacy search when using an asterisk at the beginning of a word
			preg_match('/(^|\s)\*[\\pL\\pN.]+/', $criteria) &&
				// remove the asterisk, when it's the only one in the pattern, to also find matches including the pattern, not just starting with
				(count(explode('*', $criteria)) > 2 ||
					($criteria = preg_replace('/(^|\s)\*([\\pL\\pN.]+)/', '$1$2', $criteria))) ||
			!($search = self::available($app, $matches[1]??null)))
		{
			if (self::$log_level) error_log(__METHOD__."(app='$app', criteria='$criteria_in', ...) returning false --> legacy search");
			return false;
		}
		/**
		 * @var Api\Db $db
		 */
		$db = $GLOBALS['egw']->db;
		$rag = new self();
		$search = $search === 'hybrid' ? 'search' : 'search'.ucfirst($search === 'rag' ? 'Embeddings' : $search);
		try {
			$ids = $rag->$search($criteria, $app, 0, self::$search_depth, app_ids: $app_ids, app_filter: $app_filter);
		}
		catch (InvalidFulltextSyntax $e) {
			Api\Json\Response::get()->message($e->getMessage(), 'error');
			$filter[] = '0=1';  // never true --> finds nothing
			if (self::$log_level) error_log(__METHOD__."(app='$app', criteria='$criteria_in', ...) returning true --> Fulltext syntax error");
			return true;
		}
		$plugin = new (self::plugins()[$app]);
		$filter[] = $db->expression($plugin->table(), $plugin->table().'.', [$plugin->id() => array_keys($ids)]);
		// should we order by relevance, or keep order chosen in the app
		if (($GLOBALS['egw_info']['user']['preferences']['rag']['default_search_order'] ?? 'app') === 'relevance')
		{
			$order_by = self::orderByIds($ids, $plugin->table() . '.' . $plugin->id());
		}
		if (!is_array($extra_cols)) $extra_cols = $extra_cols ? explode(',', $extra_cols) : [];
		// the value is the cosine distance for "rag", the fulltext relevance for "fulltext" and the fused score (higher is better) for "hybrid"
		$extra_cols[] = self::distanceById($ids, $plugin->table().'.'.$plugin->id()).' AS distance';
		$criteria = null;
		if (self::$log_level) error_log(__METHOD__."(app='$app', criteria='$criteria_in', ...) returning true --> $search found ".count($ids)." results");
		return true;
	}

	/**
	 * run an async job to update the RAG
	 *
	 * @return void
	 */
	public static function asyncJob()
	{
		$self = new self();
		$self->embed();
	}

	const ASYNC_JOB = 'rag:embed';

	public static function installAsyncJob()
	{
		$async = new Api\Asyncservice();
		if (!$async->read(self::ASYNC_JOB))
		{
			$async->set_timer(self::$async_times, self::ASYNC_JOB, self::class.'::asyncJob');
		}
	}

	public static function removeAsyncJob()
	{
		$async = new Api\Asyncservice();
		$async->cancel_timer(self::ASYNC_JOB);
	}

	/**
	 * Callback for hook "notify-all" to enable embedding (on new/updated entries) and remove embeddings of deleted entries
	 *
	 * @param array $data
	 * @return void
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public static function notify($data)
	{
		// check if we're interested in the given app
		if (empty($data['app']) || !isset(self::plugins()[$data['app']]) || empty($GLOBALS['egw_info']['apps']['rag']))
		{
			return;
		}
		// delete embeddings of deleted entries
		if ($data['type'] === 'delete' && !empty($data['id']))
		{
			/** @var Api\Db $db */
			$db = $GLOBALS['egw']->db;
			try {
				$db->delete(self::FULLTEXT_TABLE, [
					self::FULLTEXT_APP => $data['app'],
					self::FULLTEXT_APP_ID => $data['id'],
				], __LINE__, __FILE__, self::APP);
				$db->delete(self::TABLE, [
					self::EMBEDDING_APP => $data['app'],
					self::EMBEDDING_APP_ID => $data['id'],
				], __LINE__, __FILE__, self::APP);
			}
			catch (InvalidSql $e) {
				// ignore, MariaDB is probably not 11.8, or table not installed
			}
		}
		// install the async job for added or updated entries, if directly adding them failed
		elseif ($data['type'] !== 'delete')
		{
			try {
				$rag = new self;
				$rag->embed($data);
			}
			catch (\Exception $e) {
				self::installAsyncJob();
			}
		}
	}

	/**
	 * Get all embedding plugins, searching installed apps for a class named:
	 * - EGroupware\Rag\Embedding\<App-name>
	 * - EGroupware\<App-name>\Rag
	 *
	 * @param ?string $check_app check if the newest plugin is registered and if not, flush the cache once
	 * @return array app-name => class-name pairs
	 */
	public static function plugins(?string $check_app='addressbook') : array
	{
		// Api\Cache::unsetTree(self::APP, 'app_plugins');
		$plugins = Api\Cache::getTree(self::APP, 'app_plugins', static function()
		{
			$plugins = [];
			$apps = array_keys($GLOBALS['egw_info']['apps'] ??
				array_flip(array_filter(scandir(EGW_SERVER_ROOT),
					fn($file) => $file[0] !== '.' && is_dir(EGW_SERVER_ROOT.'/'.$file))));
			foreach($apps as $app)
			{
				$app_class = ucfirst($app);
				if (class_exists($class="EGroupware\\Rag\\Embedding\\$app_class") ||
					class_exists($class="EGroupware\\$app_class\\Rag"))
				{
					$plugins[$app] = $class;
				}
			}
			return $plugins;
		}, [], 86400);

		// check if our newest plugin is returned, if not remove cache and try again
		if ($check_app && !isset($plugins[$check_app]))
		{
			Api\Cache::unsetTree(self::APP, 'app_plugins');
			$plugins = self::plugins(null);
		}
		return $plugins;
	}

	/**
	 * Create embeddings for given chunk(s)
	 *
	 * Identical chunks are only returned once, so the result can have fewer elements than $chunks.
	 *
	 * @param string[] $chunks array of utf-8 strings
	 * @return object[] objects with attributes n (key in $chunks), sha256, chunk and embedding,
	 *  plus app, app_id and id, if the embedding was found in egw_rag, or cached=true, if found in egw_rag_cache
	 * @throws \Exception
	 */
	public function create(array $chunks) : array
	{
		if (!$chunks) return [];    // otherwise we start a query for rag_hash IS NULL
		$responses = [];
		foreach($chunks as $n => $chunk)
		{
			$sha256 = hash('sha256', $chunk, true);
			$responses[$sha256] ??= (object)[
				'n'      => $n,
				'sha256' => $sha256,
				'chunk'  =>	$chunk,
			];
		}
		// check if we already have an embedding for that chunk-content by comparing the sha256 hash
		foreach($this->db->select(self::TABLE, '*', [
			self::EMBEDDING_HASH => array_keys($responses),
		], __LINE__, __FILE__, false, 'GROUP BY '.self::EMBEDDING_HASH, self::APP) as $row)
		{
			if (!isset($responses[$row[self::EMBEDDING_HASH]])) continue;   // not sure how this can happen, but it does...
			$response = $responses[$row[self::EMBEDDING_HASH]];
			$response->embedding = array_values(unpack('g*', $row[self::EMBEDDING]));
			$response->app = $row[self::EMBEDDING_APP];
			$response->app_id = $row[self::EMBEDDING_APP_ID];
			$response->id = $row['rag_id'];
		}
		// then in the cache of search patterns
		if (($missing = array_keys(array_filter($responses, static fn($r) => !isset($r->embedding)))))
		{
			try {
				foreach($this->db->select(self::CACHE_TABLE, [self::CACHE_HASH, self::CACHE_EMBEDDING], [
					self::CACHE_HASH => $missing,
				], __LINE__, __FILE__, false, '', self::APP) as $row)
				{
					if (!isset($responses[$row[self::CACHE_HASH]])) continue;
					$responses[$row[self::CACHE_HASH]]->embedding = array_values(unpack('g*', $row[self::CACHE_EMBEDDING]));
					$responses[$row[self::CACHE_HASH]]->cached = true;
				}
			}
			catch (InvalidSql $e) {
				// cache table not yet created, because the update has not run
			}
		}
		// any chunks we need embeddings for, $pending maps the position in the request to the sha256 key
		$pending = array_keys(array_filter($responses, static fn($r) => !isset($r->embedding)));
		if ($pending)
		{
			$input = array_map(static fn($sha256) => $responses[$sha256]->chunk, $pending);
			try {
				$response = $this->client->embeddings()->create([
					'model' => self::$model,
					'input' => $input,
				]);
			}
			catch (\Exception $e)   // JsonException of unsure namespace, therefore catch them all
			{
				// fix invalid utf-8 characters by replacing them BEFORE calculating the embeddings
				if (str_starts_with($e->getMessage(), 'Malformed UTF-8 characters, possibly incorrectly encoded'))
				{
					$response = $this->client->embeddings()->create([
						'model' => self::$model,
						'input' => json_decode(json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true),
					]);
					unset($e);
				}
				else
				{
					throw $e;
				}
			}
			foreach ($response->embeddings as $n => $embedding)
			{
				// the response carries the position of the input, don't rely on the order alone
				if (($sha256 = $pending[$embedding->index ?? $n] ?? null) !== null)
				{
					// models like qwen3-embedding return more dimensions than our vector(1024) column takes
					$responses[$sha256]->embedding = array_slice($embedding->embedding, 0, 1024);
				}
			}
			foreach ($pending as $sha256)
			{
				if (empty($responses[$sha256]->embedding))
				{
					throw new \Exception('Endpoint returned no embedding for chunk #'.$responses[$sha256]->n.
						' of '.count($chunks).' ('.count($response->embeddings).' of '.count($input).' embeddings returned)');
				}
			}
		}
		return array_values($responses);
	}

	/**
	 * (Re-)create the vector index of egw_rag for the cosine distance all queries use
	 *
	 * The schema array can only create a bare VECTOR index, which MariaDB builds with mhnsw_default_distance
	 * (euclidean by default) and then never uses for VEC_DISTANCE_COSINE().
	 * Rebuilding the index on a big table takes a while!
	 *
	 * @param Api\Db $db
	 * @throws Api\Db\Exception
	 */
	public static function createVectorIndex(Api\Db $db)
	{
		$drop = [];
		foreach($db->query('SHOW INDEX FROM '.self::TABLE, __LINE__, __FILE__) as $index)
		{
			if (strtoupper($index['Index_type']) === 'VECTOR')
			{
				$drop[$index['Key_name']] = 'DROP INDEX '.$db->name_quote($index['Key_name']);
			}
		}
		$db->query('ALTER TABLE '.self::TABLE.' '.implode(', ', $drop).($drop ? ', ' : '').
			'ADD VECTOR INDEX egw_rag_embedding ('.self::EMBEDDING.') M=16 DISTANCE=cosine', __LINE__, __FILE__);
	}

	/**
	 * Run all embedding plugins and embed all not yet or updated entries
	 *
	 * @param ?array $hook_data null or data from notify-all hook, to just embed this entry
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 * @throws Api\Exception\WrongParameter
	 */
	public function embed(?array $hook_data=null)
	{
		$start = microtime(true);
		foreach([
		        true => self::$fulltext_apps,
	        ] + ($this->client ? [    // only add RAG/embeddings, if configured
				false => self::$rag_apps,
			] : []) as $fulltext => $apps)
		{
			foreach(self::plugins() as $app => $class)
			{
				if (!empty($apps) && !in_array($app, $apps)) continue;
				if ($hook_data && $hook_data['app'] !== $app) continue;

				try {
					/** @var Embedding\Base $plugin */
					$plugin = new $class();

					// purge deleted entries (run only once for async job and fulltext, purges both indexes)
					if ($fulltext && !$hook_data) $plugin->purgeDeleted();

					// check if only certain apps are enabled for RAG or fulltext
					if ($fulltext && !empty(self::$fulltext_apps) && !in_array($app, self::$fulltext_apps) ||
						!$fulltext && !empty(self::$rag_apps) && !in_array($app, self::$rag_apps))
					{
						continue;
					}
					$entry = null;
					foreach ($plugin->getUpdated($fulltext, $hook_data) as $entry)
					{
						if (microtime(true) - $start > self::$max_runtime)
						{
							break;
						}
						$extra = $entry;
						$id = array_shift($extra);
						$modified = array_shift($extra);
						$title = array_shift($extra);
						$description = array_shift($extra);
						// context prefixed to every chunk, so a chunk in the middle of a long text stays attributable
						$header = $plugin->chunkHeader($entry);
						$parts = ['' => [
							'title'       => $title,
							'description' => $description,
							'extra'       => $extra,
							'modified'    => $modified,
							'header'      => $header,
						]];
						// separately indexed parts of the entry, e.g. replies or files
						foreach($plugin->getParts($entry, (bool)$fulltext) as $part)
						{
							$parts[$part['part']] = [
								'title'       => $part['title'] ?? $title,
								'description' => $part['text'] ?? null,
								'extra'       => [],
								'modified'    => $part['modified'] ?? $modified,
								'header'      => $part['header'] ?? $header,
							];
						}
						try
						{
							$chunks = $responses = [];
							foreach($parts as $name => $part)
							{
								// fulltext index or RAG/embeddings
								if ($fulltext)
								{
									$extra_ft = $part['extra'] ? array_values(array_filter(array_map('trim', $part['extra']), static function ($v) {
										return $v && strlen((string)$v) > 3;
									})) : null;
									if (!$extra_ft || count($extra_ft) <= 1)
									{
										$extra_ft = $extra_ft ? $extra_ft[0] : null;
									}
									else
									{
										$extra_ft = json_encode($extra_ft,
											JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
									}
									$this->db->insert(self::FULLTEXT_TABLE, [
										self::FULLTEXT_TITLE => $part['title'] ?: null,
										self::FULLTEXT_DESCRIPTION => $part['description'] ?: null,
										self::FULLTEXT_EXTRA => $extra_ft,
										self::FULLTEXT_MODIFIED => $part['modified'],
									], [
										self::FULLTEXT_APP => $app,
										self::FULLTEXT_APP_ID => $id,
										self::FULLTEXT_PART => $name,
									], __LINE__, __FILE__, self::APP);
									continue;
								}
								if (self::$minimize_chunks)
								{
									$chunks[$name] = self::chunkSplit(implode("\n", array_filter(
										array_merge([$part['description']], array_values($part['extra'])))), $part['header']);
								}
								else
								{
									// embed the description and each reply or CF on its own
									$chunks[$name] = [];
									foreach(array_merge([$part['description']], array_values($part['extra'])) as $text)
									{
										$chunks[$name] = self::chunkSplit($text, $part['header'], $chunks[$name]);
									}
								}
							}
							// an entry without any text still has its context, e.g. the subject: index that alone,
							// otherwise it gets no embedding at all and is queried again on every run of the job
							if (!$fulltext && empty($chunks['']) && trim($parts['']['header']) !== '')
							{
								$chunks[''] = [rtrim($parts['']['header'])];
							}
							if (!$fulltext)
							{
								// one request for all chunks of all parts of the entry
								$flat = [];
								foreach($chunks as $name => $part_chunks)
								{
									foreach($part_chunks as $n => $chunk)
									{
										$flat[$name.':'.$n] = $chunk;
									}
								}
								foreach($this->create($flat) as $embedding)
								{
									$responses[$embedding->chunk] = $embedding;
								}
							}
						}
						catch (\Throwable $e)
						{
						}
						// handle all exceptions by logging them to RAG-config and PHP error-log, and then continuing with the next entry
						if (isset($e))
						{
							self::logError($e, $app, $fulltext, $entry);
							// if called in hook, don't continue
							if ($hook_data) return;
							unset($e);
							continue;
						}
						if (!$fulltext)
						{
							foreach($chunks as $name => $part_chunks)
							{
								$n = -1;
								foreach($part_chunks as $n => $chunk)
								{
									$this->db->insert(self::TABLE, [
										self::EMBEDDING => $responses[$chunk]->embedding,
										self::EMBEDDING_HASH => $responses[$chunk]->sha256,
										self::EMBEDDING_MODIFIED => $parts[$name]['modified'],
									], [
										self::EMBEDDING_APP => $app,
										self::EMBEDDING_APP_ID => $id,
										self::EMBEDDING_PART => $name,
										self::EMBEDDING_CHUNK => $n,
									], __LINE__, __FILE__, self::APP);
								}
								// delete excess old chunks, if there are any
								$this->db->delete(self::TABLE, [
									self::EMBEDDING_APP => $app,
									self::EMBEDDING_APP_ID => $id,
									self::EMBEDDING_PART => $name,
									self::EMBEDDING_CHUNK . '>' . $n,
								], __LINE__, __FILE__, self::APP);
							}
						}
						// remove parts the entry no longer has, e.g. a deleted reply
						$this->db->delete($fulltext ? self::FULLTEXT_TABLE : self::TABLE, [
							($fulltext ? self::FULLTEXT_APP : self::EMBEDDING_APP) => $app,
							($fulltext ? self::FULLTEXT_APP_ID : self::EMBEDDING_APP_ID) => $id,
							'NOT '.$this->db->expression($fulltext ? self::FULLTEXT_TABLE : self::TABLE,
								[($fulltext ? self::FULLTEXT_PART : self::EMBEDDING_PART) => array_keys($parts)]),
						], __LINE__, __FILE__, self::APP);
					}
				}
				catch (\Throwable $e) {
					// catch and log all errors, also the ones in the app-plugins
					self::logError($e, $app, $fulltext??null, $entry??null);
					continue;   // with next plugin/app
				}
			}
		}
		// if we finished, we can remove the job (gets readded for new entries via notify hook)
		if (!$hook_data && microtime(true) - $start < self::$max_runtime)
		{
			self::removeAsyncJob();
		}
	}

	/**
	 * Log an exception to RAG configuration to display
	 *
	 * @param \Throwable $e
	 * @param ?string $fulltext
	 * @param ?string $app
	 * @param ?array $entry
	 * @throws Api\Exception\WrongParameter
	 */
	public static function logError(\Throwable $e, $app=null, $fulltext=null, $entry=null)
	{
		error_log(__METHOD__ . "() app=$app, fulltext=$fulltext, entry=".json_encode($entry, JSON_INVALID_UTF8_IGNORE));
		_egw_log_exception($e);
		// store the last N errors to display in RAG config
		$errors = (array)(Api\Config::read(self::APP)[self::RAG_LAST_ERRORS] ?? []);
		array_unshift($errors, [
			'date' => new Api\DateTime(),
			'message' => $e->getMessage(),
			'app' => $app,
			'rag-or-fulltext' => $fulltext ? 'fulltext' : 'rag',
			'entry' => $entry,
			'code' => $e->getCode(),
			'class' => get_class($e),
			'trace' => $e->getTrace(),
		]);
		Api\Config::save_value(self::RAG_LAST_ERRORS, array_slice($errors, 0, 5), self::APP);
	}

	/**
	 * Validate order parameter
	 *
	 * @param string $order one of "default", "distance", "relevance" or "modified", optional with ASC or DESC suffix
	 * @param ?string $not_modified what to return if sort is not "modified": "!?(relevance|distance|default)"
	 * "!" prefix means to inverse the sort e.g. ASC should become DESC, unless given $order is identical to $not_modified (without prefix)
	 * @return string $order or "default", if $sort is invalid
	 */
	protected static function validateOrder(string $order, ?string $not_modified=null) : string
	{
		if (preg_match('/^(default|distance|relevance|modified)(\s(ASC|DESC))?$/i', $order, $matches))
		{
			$order = $matches[1];
			$sort = strtoupper($matches[3] ?? 'ASC');
		}
		else
		{
			$order = 'default';
			$sort = 'ASC';
		}
		if ($not_modified && $order !== 'modified' && !str_ends_with($not_modified, $order))
		{
			$order = $not_modified[0] === '!' ? substr($not_modified, 1) : $not_modified;
			$sort = $not_modified[0] === '!' ? ($sort === 'ASC' ? 'DESC' : 'ASC') : $sort;
		}
		return $order.' '.$sort;
	}

	/**
	 * Read a single search-result entry by its composite "app:id" identifier
	 *
	 * Enforces the same per-entry ACL as search() does for result rows, via Api\Link::title().
	 * Always asks the app's plugin directly instead of using the fulltext index cache: not every
	 * app is guaranteed to be fulltext-indexed, and the index can be stale relative to the entry's
	 * current modified time.
	 *
	 * @param string $id "app:id" e.g. "addressbook:123"
	 * @param bool $check_acl unused, kept for Api\CalDAV\Handler::read() signature compatibility
	 * @return array|null|false array with app, app_id, modified, title, description, extra;
	 *  null if not found, false if user has no access to the underlying entry
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function read($id, bool $check_acl=false)
	{
		[$app, $app_id] = array_pad(explode(':', (string)$id, 2), 2, null);
		if (!$app || !$app_id || !($plugin_class = self::plugins()[$app] ?? null))
		{
			return null;
		}
		// Api\Link::title() enforces the ACL of the underlying app-entry:
		// null = entry does not exist, false = exists but no access, string = title
		if (!($title = Api\Link::title($app, $app_id)))
		{
			return $title === false ? false : null;
		}
		/** @var Embedding\Base $plugin */
		$plugin = new $plugin_class();
		// top-level app+id (not nested under 'data') is required so getUpdated() scopes its
		// query to this one entry via $where[ID], see Base::getUpdated()/notify()'s $data shape.
		// $ignoreStaleness=true: we want this entry's current data regardless of whether the
		// fulltext/rag index already is up-to-date for it - getUpdated()'s default "what's
		// pending an update" semantics is empty by definition for an already up-to-date entry,
		// which is the common, correct-state case (a bug found while adding read()'s test
		// coverage: read() on any already-indexed entry always returned null, "not found").
		foreach ($plugin->getUpdated(true, ['app' => $app, 'id' => $app_id], true) as $entry)
		{
			// same value-only extra shape as stored in FULLTEXT_EXTRA, see embed()
			$extra = $entry;
			unset($extra[$plugin_class::ID], $extra[$plugin_class::MODIFIED], $extra[$plugin_class::TITLE], $extra[$plugin_class::DESCRIPTION]);
			return [
				'app' => $app,
				'app_id' => $app_id,
				'modified' => $entry[$plugin_class::MODIFIED] ?? null,
				'title' => $title,
				'description' => $entry[$plugin_class::DESCRIPTION] ?? null,
				'extra' => $extra ? array_values(array_filter(array_map('trim', $extra), static function ($v) {
					return $v && strlen((string)$v) > 3;
				})) : [],
			];
		}
		return null;
	}

	/**
	 * Hybrid search in RAG and fulltext index for given app and $pattern
	 *
	 * Both searches run in their own best-first order and are merged with Reciprocal Rank Fusion:
	 * every entry scores sum(1 / (RRF_K + rank)) over the lists it is found in, so an entry found by both searches
	 * ranks high, and an exact keyword match is no longer pushed behind every semantic match.
	 * @link https://mariadb.com/docs/server/reference/sql-structure/vectors/optimizing-hybrid-search-query-with-reciprocal-rank-fusion-rrf
	 *
	 * If the embedding endpoint fails, the fulltext result is returned alone.
	 *
	 * @param string $pattern
	 * @param ?string|string[] $app app-name(s) or '' or NULL for searching all apps
	 * @param int $start default 0
	 * @param int $num_rows default 50
	 * @param bool $return_all true: return array with modified time, title, description, extra data, distance, relevance and score,
	 *  false: only return the fused score
	 * @param string $order one of "default" (fused score), "distance", "relevance" or "modified", optional with ASC or DESC suffix
	 * @param ?float $max_distance default null: configured max_distance (.4)
	 * @param float $min_relevance default 0.05 = 5% of highest match
	 * @param ?int[] $app_ids optional limit the search to these ids
	 * @return float[]|array[] int id => float score (higher is better) pairs, for $app === '' we return string "$app:$id"
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function search(string $pattern, $app=null, int $start=0, int $num_rows=50, bool $return_all=false,
	                       string $order='default', ?float $max_distance=null, float $min_relevance=0.05, ?array $app_ids=null,
	                       ?string $app_filter=null) : array
	{
		if (!$this->client)
		{
			return $this->searchFulltext($pattern, $app, $start, $num_rows, $return_all, $order, $min_relevance, null, $app_ids,
				app_filter: $app_filter);
		}
		// both lists need to reach at least as deep as the requested page, and a bit further to fuse sensibly
		$depth = max($start + $num_rows, 100);
		try {
			$embedding_matches = $this->searchEmbeddings($pattern, $app, 0, $depth, $return_all, 'default', $max_distance, $app_ids, $app_filter);
			$total_embeddings = $this->total ?? 0;
		}
		catch (InvalidSql $e) {
			throw $e;
		}
		catch (\Exception $e) {
			// embedding endpoint not reachable or failing: keep search working with the fulltext index
			_egw_log_exception($e);
			$embedding_matches = [];
			$total_embeddings = 0;
		}
		$fulltext_matches = $this->searchFulltext($pattern, $app, 0, $depth, $return_all, 'default', $min_relevance, null, $app_ids,
			app_filter: $app_filter);
		$total_fulltext = $this->total ?? 0;

		$scores = self::rrf([$embedding_matches, $fulltext_matches]);

		// we only know the overlap of the fetched rows, there might be more in common
		$this->total = $total_embeddings + $total_fulltext -
			(count($embedding_matches) + count($fulltext_matches) - count($scores));

		if (!$return_all)
		{
			$both = $scores;
		}
		else
		{
			$both = [];
			foreach($scores as $id => $score)
			{
				// embedding row first, so its modified time and texts win, but add relevance from the fulltext row
				$both[$id] = ($embedding_matches[$id] ?? []) + ($fulltext_matches[$id] ?? []) + ['score' => $score];
			}
		}

		// a cross-encoder judges the query against the text of the best matches, which a bi-encoder
		// (the embeddings) can only approximate - but only if the result is used in that order
		if (self::$rerank_model && ($order === 'default' || str_starts_with($order, 'relevance') || self::$rerank_min_score > 0))
		{
			$both = $this->rerankResults($pattern, $both, $return_all);
		}
		[$order, $sort] = explode(' ', self::validateOrder($order), 2);
		if ($order !== 'default')
		{
			// we can only order it by a different criteria if we got the data / $return_all === true
			if ($return_all)
			{
				// entries without the value, e.g. no distance for a fulltext-only match, always go to the end
				uasort($both, static function(array $a, array $b) use ($order, $sort)
				{
					if (!isset($a[$order]) || !isset($b[$order]))
					{
						return isset($a[$order]) ? -1 : (isset($b[$order]) ? 1 : 0);
					}
					return $sort === 'ASC' ? $a[$order] <=> $b[$order] : $b[$order] <=> $a[$order];
				});
			}
		}
		elseif ($sort !== 'ASC')
		{
			$both = array_reverse($both, true);
		}
		$both = array_slice($both, $start, $num_rows, true);
		if (self::$log_level)
		{
			error_log(__METHOD__."('$pattern', '".json_encode($app)."', start=$start, num_rows=$num_rows, return_all=$return_all, order=$order $sort, max_distance=$max_distance) total=$this->total returning ".
				json_encode($both));
		}
		return $both;
	}

	/**
	 * Rerank the best matches of a search with a cross-encoder
	 *
	 * The embeddings encode entry and query independently, a cross-encoder scores them together and is
	 * therefore a lot better at telling a real answer from a topically similar text - but it has to run
	 * over the candidates, so only the first self::$rerank_depth of them are reranked.
	 *
	 * Failures are logged and the given order is kept: a search must not fail because of the reranker.
	 *
	 * @param string $pattern the search-pattern
	 * @param array $rows id => score or id => row, best first
	 * @param bool $return_all are the values rows (with title/description) or just scores
	 * @return array same shape, reranked
	 */
	protected function rerankResults(string $pattern, array $rows, bool $return_all) : array
	{
		$candidates = array_slice($rows, 0, self::$rerank_depth, true);
		$rest = array_slice($rows, count($candidates), null, true);
		if (count($candidates) < 2)
		{
			return $rows;   // nothing to reorder
		}
		try {
			$documents = $this->documents(array_keys($candidates), $return_all ? $candidates : []);
			if (!$documents)
			{
				return $rows;
			}
			$scores = $this->rerank($pattern, $documents);
		}
		catch (\Throwable $e) {
			self::logError($e, 'rag', false, ['pattern' => $pattern, 'candidates' => count($candidates)]);
			return $rows;
		}
		$reranked = [];
		foreach($scores as $id => $score)
		{
			if (!isset($candidates[$id]) || self::$rerank_min_score && $score < self::$rerank_min_score)
			{
				continue;
			}
			$reranked[$id] = $return_all ? $candidates[$id] + ['rerank_score' => $score] : $candidates[$id];
		}
		// candidates without a score, e.g. no text to send, keep their fused rank behind the reranked ones
		foreach($candidates as $id => $row)
		{
			if (!isset($reranked[$id]) && !(self::$rerank_min_score && isset($scores[$id])))
			{
				$reranked[$id] = $row;
			}
		}
		// the entries the reranker dropped are gone from the result
		$this->total -= count($candidates) - count($reranked);

		if (self::$log_level)
		{
			error_log(__METHOD__."('$pattern', ".count($rows)." rows) reranked ".count($candidates).
				' candidates, dropped '.(count($candidates) - count($reranked)));
		}
		return $reranked + $rest;
	}

	/**
	 * Texts to send to the reranker for the given entries
	 *
	 * @param array $ids int id (for a single app) or "app:id"
	 * @param array $rows optional rows with title/description, as returned with $return_all
	 * @return array id => text, entries without any text are not returned
	 * @throws Api\Db\Exception
	 */
	protected function documents(array $ids, array $rows=[]) : array
	{
		$documents = $missing = [];
		foreach($ids as $id)
		{
			if (isset($rows[$id]['title']) || isset($rows[$id]['description']))
			{
				$documents[$id] = self::document($rows[$id]['title'] ?? '', $rows[$id]['description'] ?? '',
					$rows[$id]['extra'] ?? []);
			}
			else
			{
				$missing[] = $id;
			}
		}
		if ($missing)
		{
			// one query for the texts of all entries missing them, no matter which app they are from
			$where = [];
			foreach($missing as $id)
			{
				[$app, $app_id] = strpos((string)$id, ':') !== false ? explode(':', $id, 2) : [null, $id];
				$where[] = '('.(isset($app) ? self::FULLTEXT_APP.'='.$this->db->quote($app).' AND ' : '').
					self::FULLTEXT_APP_ID.'='.(int)$app_id.')';
			}
			foreach($this->db->select(self::FULLTEXT_TABLE, [self::FULLTEXT_APP, self::FULLTEXT_APP_ID,
				self::FULLTEXT_TITLE, self::FULLTEXT_DESCRIPTION, self::FULLTEXT_EXTRA], [
					implode(' OR ', $where),
					self::FULLTEXT_PART => '',
				], __LINE__, __FILE__, false, '', self::APP) as $row)
			{
				foreach([(int)$row[self::FULLTEXT_APP_ID], $row[self::FULLTEXT_APP].':'.$row[self::FULLTEXT_APP_ID]] as $id)
				{
					if (in_array($id, $missing))
					{
						$documents[$id] = self::document($row[self::FULLTEXT_TITLE], $row[self::FULLTEXT_DESCRIPTION],
							!empty($row[self::FULLTEXT_EXTRA]) ? (array)json_decode($row[self::FULLTEXT_EXTRA], true) : []);
					}
				}
			}
		}
		return array_filter($documents, static fn($text) => trim($text) !== '');
	}

	/**
	 * One document for the reranker: title first, then as much of the text as we send
	 *
	 * @param ?string $title
	 * @param ?string $description
	 * @param array $extra
	 * @return string
	 */
	protected static function document(?string $title, ?string $description, array $extra=[]) : string
	{
		$text = implode("\n", array_filter(array_merge([$title, $description], array_map('strval', $extra)),
			static fn($t) => isset($t) && trim((string)$t) !== ''));

		return mb_substr(trim($text), 0, self::RERANK_MAX_CHARS);
	}

	/**
	 * Score documents against a query with the configured cross-encoder
	 *
	 * @param string $query
	 * @param array $documents id => text
	 * @return float[] id => score, best (highest) first
	 * @throws \Exception if the endpoint is not reachable or answers something unexpected
	 */
	public function rerank(string $query, array $documents) : array
	{
		if (!self::$rerank_model || !$documents)
		{
			return [];
		}
		$url = rtrim(self::$rerank_url ?? self::$url ?? '', '/');
		if (!$url)
		{
			throw new \Exception('No URL configured for the rerank endpoint');
		}
		if (!str_ends_with($url, '/rerank'))
		{
			$url .= '/rerank';
		}
		$ids = array_keys($documents);
		$texts = array_values($documents);
		$payload = self::$rerank_api === 'tei' ? [
			'query' => $query,
			'texts' => $texts,
		] : [
			'model' => self::$rerank_model,
			'query' => $query,
			'documents' => $texts,
			'top_n' => count($texts),
		];
		$response = $this->httpClient()->request('POST', $url, [
			'headers' => array_filter([
				'Content-Type' => 'application/json',
				'Authorization' => self::$api_key ? 'Bearer '.self::$api_key : null,
			]),
			'json' => $payload,
			'timeout' => self::$rerank_timeout,
		])->toArray();

		// cohere/llama.cpp/vLLM: {results: [{index, relevance_score}]}, TEI: [{index, score}]
		$results = $response['results'] ?? $response['data'] ?? $response;
		$scores = [];
		foreach((array)$results as $result)
		{
			if (!isset($result['index']) || !isset($ids[$result['index']]))
			{
				continue;
			}
			$score = $result['relevance_score'] ?? $result['score'] ?? null;
			if (isset($score))
			{
				$scores[$ids[$result['index']]] = (float)$score;
			}
		}
		if (!$scores)
		{
			throw new \Exception('Rerank endpoint '.$url.' returned no scores: '.
				substr(json_encode($response, JSON_INVALID_UTF8_IGNORE), 0, 200));
		}
		arsort($scores);
		return $scores;
	}

	/**
	 * @var ?object PSR-18 / Symfony HttpClient, only used for the rerank endpoint (openai-php has no rerank)
	 */
	protected ?object $http_client = null;

	/**
	 * @param ?object $client to inject one, e.g. for tests
	 * @return object
	 */
	public function httpClient(?object $client=null) : object
	{
		if (isset($client))
		{
			$this->http_client = $client;
		}
		return $this->http_client ??= HttpClient::create(['timeout' => self::$rerank_timeout]);
	}

	/**
	 * Reciprocal Rank Fusion of several best-first result lists
	 *
	 * @param array[] $lists arrays with ids as keys, best match first
	 * @param int $k constant of the formula, default RRF_K
	 * @return float[] id => fused score, best (highest) first
	 */
	public static function rrf(array $lists, int $k=self::RRF_K) : array
	{
		$scores = [];
		foreach($lists as $list)
		{
			$rank = 0;
			foreach(array_keys($list) as $id)
			{
				$scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($k + ++$rank);
			}
		}
		arsort($scores);    // sorting is stable since PHP 8.0: equal scores keep the order of first appearance
		return $scores;
	}

	/**
	 * Merge search results from RAG and fulltext for hybrid search
	 *
	 * Works mostly like array_merge_recursive, but for attributes in both, only the first is used.
	 * array_merge_recursive would create an array with all values, which would create invalid modified DateTime objects!
	 *
	 * @param array $rows
	 * @param array $additional
	 * @return array
	 */
	protected static function add_rows(array $rows, array $additional) : array
	{
		foreach($additional as $key => $row)
		{
			if (isset($rows[$key]))
			{
				$rows[$key] += $row;
			}
			else
			{
				$rows[$key] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Alias the app-filter sub-query gives its id column - defined by core, which builds the sub-query
	 */
	const APP_FILTER_ID = Api\Storage\Base::RAG_FILTER_ID;

	/**
	 * JOIN restricting a search to the entries an application's own filters allow
	 *
	 * The app hands us a sub-query selecting its ids (aliased self::APP_FILTER_ID), not a list of ids:
	 * a list has to be produced up-front and capped, and above that cap the search runs unscoped and
	 * is intersected afterwards - which silently drops every match outside the k best hits. As a join
	 * MariaDB decides per query: it keeps the vector index when the filter is broad, and drives from
	 * the app's own table (exact) when it is selective.
	 *
	 * Note this does NOT make a broad search exact - the inner LIMIT $k still cuts. What it fixes is
	 * the selective case, where the k nearest chunks globally contain none of the filtered entries.
	 *
	 * @param ?string $app_filter sub-query or null
	 * @param string $id_column our column to join it on, EMBEDDING_APP_ID or FULLTEXT_APP_ID
	 * @return string '' if there is no filter
	 */
	protected static function appFilterJoin(?string $app_filter, string $id_column) : string
	{
		return empty($app_filter) ? '' :
			' JOIN ('.$app_filter.') app_filter ON app_filter.'.self::APP_FILTER_ID.'='.$id_column;
	}

	/**
	 * Prefix a query with a time limit, so one pathological plan cannot hang a list
	 *
	 * @param string $sql
	 * @return string
	 */
	protected static function timeLimited(string $sql) : string
	{
		// %F, not a plain cast: a small float would render as 1.0E-6, which is not what we mean to send
		return self::$search_timeout > 0 ?
			'SET STATEMENT max_statement_time='.sprintf('%.3F', self::$search_timeout).' FOR '.$sql : $sql;
	}

	/**
	 * Did this query die on our own time limit, rather than for a real reason?
	 *
	 * @param \Throwable $e
	 * @return bool
	 */
	public static function isTimeout(\Throwable $e) : bool
	{
		// 1969 is the abort itself; a query that follows it on the same connection can surface as 188
		// ("Operation was interrupted"). We only ever ask this when WE set a limit on a scoped search,
		// so treating both as ours is safe: the fallback just repeats the search unscoped.
		return self::$search_timeout > 0 &&
			(in_array($e->getCode(), [1969, 188]) ||
				stripos($e->getMessage(), 'max_statement_time exceeded') !== false ||
				stripos($e->getMessage(), 'was interrupted') !== false);
	}

	/**
	 * Can we splice $sql into the innermost block of a search?
	 *
	 * It has to be a plain, mergeable SELECT - anything the optimizer has to materialize takes away
	 * the choice of plan that makes the join worthwhile.
	 *
	 * This is NOT a sanitizer and must not be mistaken for one. The sub-query is built by core from
	 * the same $filter the caller's own list query is built from: whatever could be injected here is
	 * already in that query verbatim, and rejecting it here would not prevent anything. The trust
	 * boundary is where integer-keyed col_filter entries are stripped from client input
	 * (Api\Etemplate\Widget\Nextmatch::ajax_get_rows()), not here.
	 *
	 * @param ?string $sql
	 * @return bool
	 */
	public static function validFilterSubquery(?string $sql) : bool
	{
		return !empty($sql) && preg_match('/^\s*SELECT\s/i', $sql) &&
			stripos($sql, ' AS '.self::APP_FILTER_ID) !== false &&
			!preg_match('/\b(GROUP\s+BY|HAVING|LIMIT|DISTINCT|UNION|ORDER\s+BY|PROCEDURE|INTO)\b/i', $sql) &&
			strpos($sql, ';') === false;
	}

	/**
	 * Semantic search in given app for $pattern
	 *
	 * Returns found IDs and their distance ordered by the smallest distance / the best match first.
	 *
	 * @param string $pattern
	 * @param ?string|string[] $app app-name(s) or '' or NULL for searching all apps
	 * @param int $start default 0
	 * @param int $num_rows default 50
	 * @param bool $return_all true: return array with modified time, title, description, extra data and distance,
	 * *  false: only return distance value
	 * @param string $order one of "default", "distance", "relevance" or "modified", optional with ASC or DESC suffix
	 * @param ?float $max_distance default null: configured max_distance (.4)
	 * @param array $app_ids:  Optionally limit the the search to these specific app ids
	 * @return float[] int id => float distance pairs for non-empty and string $app, empty $app or array we return string "$app:$id"
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function searchEmbeddings(string $pattern, $app=null, int $start=0, int $num_rows=50, bool $return_all=false,
	                                 string $order='default', ?float $max_distance=null, ?array $app_ids=null,
	                                 ?string $app_filter=null) : array
	{
		$max_distance ??= self::$max_distance;
		// a sub-query scopes ONE app's entries, it must never be joined onto a multi-app or global search
		if (!is_string($app) || $app === '' || !self::validFilterSubquery($app_filter)) $app_filter = null;
		if (isset($app_filter)) $app_ids = null;     // same intent, applying both only costs
		// we remove boolean mode fulltext operators
		if (preg_match(self::BOOLEAN_MODE_OPERATORS_PREG, $pattern))
		{
			// remove - operator incl. next word from embedding
			$pattern = preg_replace('/(^| )-([^ ]+)/', ' ', $pattern);
			// remove all other boolean mode operators
			$pattern = preg_replace(self::BOOLEAN_MODE_OPERATORS_PREG, ' ', $pattern);
		}
		try {
			$response = $this->create([$pattern]);
		}
		catch (\Exception $e) {
			_egw_log_exception($e);
			throw $e;
		}
		// is $pattern already cached, or do we need to do so now
		if (empty($response[0]->id) && empty($response[0]->cached))
		{
			$this->cacheQueryEmbedding($response[0]);
		}
		$where = [];
		if ($app) $where[] = $this->db->expression(self::TABLE, [self::EMBEDDING_APP => $app]);
		// the app's own filters as a join - see appFilterJoin(). The id-list is the fallback for the
		// apps and storages that cannot express themselves as a mergeable sub-query.
		$from = self::TABLE.self::appFilterJoin($app_filter, self::TABLE.'.'.self::EMBEDDING_APP_ID);
		if ($app_ids) $where[] = self::TABLE.'.'.self::EMBEDDING_APP_ID.' IN ('.implode(',', array_map('intval', $app_ids)).')';

		// k-nearest chunks: MariaDB only uses the vector index for exactly this shape, ORDER BY the distance function
		// (with the distance the index was built with) ASC plus a LIMIT, therefore the entry level grouping,
		// the max. distance and the requested order are applied outside
		$k = min(max(500, 5 * ($start + $num_rows)), 10000);
		$knn = 'SELECT '.self::EMBEDDING_APP.','.self::EMBEDDING_APP_ID.','.self::EMBEDDING_MODIFIED.
			',VEC_DISTANCE_COSINE('.self::EMBEDDING.', '.$this->db->quote($response[0]->embedding, 'vector').') AS distance'.
			' FROM '.$from.($where ? ' WHERE '.implode(' AND ', $where) : '').
			' ORDER BY distance LIMIT '.$k;
		// best chunk per entry, so every entry counts once for LIMIT and total
		$entries = 'SELECT '.self::EMBEDDING_APP.','.self::EMBEDDING_APP_ID.',MIN(distance) AS distance,MAX('.self::EMBEDDING_MODIFIED.') AS modified'.
			' FROM ('.$knn.') knn WHERE distance < '.(float)$max_distance.
			' GROUP BY '.self::EMBEDDING_APP.','.self::EMBEDDING_APP_ID;

		$cols = ['entries.*'];
		$join = '';
		if ($return_all)
		{
			$cols[] = self::FULLTEXT_TITLE.' AS title';
			$cols[] = self::FULLTEXT_DESCRIPTION.' AS description';
			$cols[] = self::FULLTEXT_EXTRA.' AS extra';
			$join = ' LEFT JOIN '.self::FULLTEXT_TABLE.' ON entries.'.self::EMBEDDING_APP.'='.self::FULLTEXT_APP.
				' AND entries.'.self::EMBEDDING_APP_ID.'='.self::FULLTEXT_APP_ID.' AND '.self::FULLTEXT_PART."=''";
		}
		// FOUND_ROWS() reports the last query run, and readEntry() below runs one for every row without
		// a fulltext title - so the rows have to be collected and the total read before any of those
		$rows = [];
		try {
			foreach($this->db->query(self::timeLimited('SELECT SQL_CALC_FOUND_ROWS '.implode(',', $cols).' FROM ('.$entries.') entries'.$join.
				' ORDER BY '.self::validateOrder($order, 'distance')), __LINE__, __FILE__, $start, $num_rows) as $row)
			{
				$rows[] = $row;
			}
		}
		catch (\Throwable $e) {
			// a scoped search that runs into the limit falls back to the unscoped one: that is what it
			// did before there was a join, so this can never be worse than not having tried
			if (!$app_filter || !self::isTimeout($e)) throw $e;
			// NOT the original exception: a Db exception's message carries the whole statement, which
			// here contains the list's filter values, and logError() persists it to a config an admin
			// reads. The fact that it timed out is all anyone can act on.
			self::logError(new \Exception('scoped search exceeded '.self::$search_timeout.'s, fell back to the unscoped one',
				$e->getCode()), $app, false, ['app_filter' => true]);
			// without the limit: we are already over budget, and the unscoped search is the one that
			// ran before there was a join - retrying it under the same limit would only fail again
			$timeout = self::$search_timeout;
			self::$search_timeout = 0;
			try {
				return $this->searchEmbeddings($pattern, $app, $start, $num_rows, $return_all, $order, $max_distance);
			}
			finally {
				self::$search_timeout = $timeout;
			}
		}
		$this->total = (int)$this->db->query('SELECT FOUND_ROWS()')->fetchColumn();

		$id_distance = [];
		foreach($rows as $row)
		{
			$id = $app && is_string($app) ? (int)$row[self::EMBEDDING_APP_ID] : $row[self::EMBEDDING_APP].':'.$row[self::EMBEDDING_APP_ID];
			// if the app is not fulltext indexed, we won't get texts and need to query them separate from the app
			if ($return_all && !isset($row['title']))
			{
				$row = array_merge($row, $this->readEntry($row[self::EMBEDDING_APP], (int)$row[self::EMBEDDING_APP_ID]));
			}
			$id_distance[$id] = $return_all ? [
				'distance' => (float)$row['distance'],
				'modified' => new Api\DateTime($row['modified'], Api\DateTime::$server_timezone),
				'title' => $row['title'] ?? null,
				'description' => $row['description'] ?? null,
				'extra' => !empty($row['extra']) ? (is_array($row['extra']) ? $row['extra'] : (array)json_decode($row['extra'], true)) : [],
			] : (float)$row['distance'];
		}
		if (self::$log_level)
		{
			error_log(__METHOD__."('$pattern', '$app', start=$start, num_rows=$num_rows, max_distance=$max_distance) total=$this->total returning ".
				json_encode($id_distance));
		}
		return $id_distance;
	}

	/**
	 * Cache the embedding of a search pattern in the cache table
	 *
	 * The cache table has the sha256 hash of the pattern as primary key and is written with a REPLACE, so
	 * concurrent searches caching the same or different, not yet seen patterns can not collide (as they did
	 * with the MAX(rag_chunk)+1 numbering, when the cache was stored under the *cache* pseudo-app in egw_rag).
	 *
	 * @param object $response object with ->sha256 (binary sha256 hash) and ->embedding (float[]) attributes
	 * @throws InvalidSql for everything but the cache table not yet existing
	 */
	protected function cacheQueryEmbedding(object $response) : void
	{
		try {
			$this->db->insert(self::CACHE_TABLE, [
				self::CACHE_EMBEDDING => $response->embedding,
				self::CACHE_UPDATED => new Api\DateTime(),
			], [
				self::CACHE_HASH => $response->sha256,
			], __LINE__, __FILE__, self::APP);
		}
		catch (InvalidSql $e) {
			// 1146: cache table not yet created, because the update has not run --> search without caching
			if ($e->getCode() != 1146)
			{
				throw $e;
			}
		}
	}

	/**
	 * Read title, description and extra texts of an entry directly from its app
	 *
	 * Used for entries of apps without fulltext index.
	 *
	 * @param string $app
	 * @param int $id
	 * @return array with keys title, description and extra (array), empty array if the entry is not found
	 */
	protected function readEntry(string $app, int $id) : array
	{
		static $plugins=null;
		$plugins ??= self::plugins();
		if (empty($plugins[$app]))
		{
			return [];
		}
		/** @var Embedding\Base $plugin */
		$plugin = new $plugins[$app];
		// app and id have to be top-level keys, otherwise getUpdated() iterates over all not yet indexed entries of the app,
		// $ignoreStaleness=true: we want the entry's current data, whether or not the index is up-to-date
		foreach($plugin->getUpdated(true, ['app' => $app, 'id' => $id], true) as $entry)
		{
			// same positional mapping as in embed()
			$extra = array_values($entry);
			return [
				'title' => $extra[2] ?? null,
				'description' => $extra[3] ?? null,
				'extra' => array_values(array_filter(array_slice($extra, 4), static fn($v) => is_scalar($v) && trim((string)$v) !== '')),
			];
		}
		return [];
	}

	/**
	 * Boolean mode operators from MariaDB fulltext search
	 */
	const BOOLEAN_MODE_OPERATORS_PREG = '/[+<>()~*"-]+/';

	/**
	 * Run a fulltext search for $pattern
	 *
	 * @param string $pattern
	 * @param ?string|string[] $app app-name(s) or '' or NULL for searching all apps
	 * @param int $start default 0
	 * @param int $num_rows default 50
	 * @param bool $return_all true: return array with modified time, title, description, extra data and relevance,
	 *  false: only return relevance value
	 * @param string $order one of "default", "distance", "relevance" or "modified", optional with ASC or DESC suffix
	 * @param float $min_relevance default 0.05 = 5% of highest relevance
	 * @param ?string $mode default null, check for BOOLEAN mode operators in $pattern: +-<>()~*",
	 *  or 'IN BOOLEAN MODE', 'IN NATURAL LANGUAGE MODE', 'WITH QUERY EXPANSION'
	 * @param array $app_ids:  Optionally limit the the search to these specific app ids
	 * @param bool $require_all true: every word of the pattern has to match (see requireAll()), false: any of them
	 * @return float[] int id => float relevance pairs for non-empty and string $app, empty $app or array we return string "$app:$id"
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function searchFulltext(string $pattern, $app=null, int $start=0, int $num_rows=50, bool $return_all=false,
	                               string $order='default', float $min_relevance=0.05, ?string $mode=null, ?array $app_ids=null,
	                               bool $require_all=true, ?string $app_filter=null) : array
	{
		// a sub-query scopes ONE app's entries, it must never be joined onto a multi-app or global search
		if (!is_string($app) || $app === '' || !self::validFilterSubquery($app_filter)) $app_filter = null;
		if (isset($app_filter)) $app_ids = null;
		$pattern_in = $pattern;
		$min_relevance_in = $min_relevance;
		// did the user use operators himself, then we do not touch the pattern (checked before we add asterisks)
		$user_operators = (bool)preg_match(self::BOOLEAN_MODE_OPERATORS_PREG, $pattern);
		// To find word(s) with a dash inside e.g. domain-names or ending with one (gives a FT syntax error!),
		// we must NOT use boolean mode, but natural language mode.
		// Because in boolean mode it will never match because the dash before the 2nd word will exclude all matches with that word :(
		// And we can not add an asterisk after the word, as that requires boolean mode
		if (!$mode && preg_match('/^[\\pL\\pN.]+-[\\pL\\pN.]*$/ui', $pattern) ||
			// @ in boolean mode always gives a syntax error: unexpected '@', expecting $end (1064) --> use NATURAL LANGUAGE MODE
			strpos($pattern, '@') !== false)
		{
			$mode = 'IN NATURAL LANGUAGE MODE';
		}
		// should we add an asterisk ("*") after each pattern/word NOT enclosed in quotes
		elseif (($GLOBALS['egw_info']['user']['preferences']['rag']['fulltext_match_wordstart'] ?? 'yes') === 'yes')
		{
			static $word = '[\\pL\\pN-]+';    // \pL = unicode letters, \pN = unicode numbers
			$pattern = preg_replace('/\*+/', '*',   // in case user already used an asterisk, two give a syntax error :(
				preg_replace_callback('/("([^"]+)"|'.$word.')/ui',
					fn($m) => $m[1][0] === '"' ? $m[1] : preg_replace('/('.$word.')( |$|\))/ui', '$1*$2', $m[1]),
					$pattern));
		}
		// require every word, instead of matching entries containing just one of them
		if (!$mode && !$user_operators && $require_all)
		{
			$pattern = self::requireAll($pattern);
		}
		switch(strtoupper($mode??''))
		{
			case 'IN BOOLEAN MODE':
			case 'IN NATURAL LANGUAGE MODE':
			case 'WITH QUERY EXPANSION':
				break;
			default:
				$mode = preg_match(self::BOOLEAN_MODE_OPERATORS_PREG, $pattern) ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';
				break;
		}
		$match = 'MATCH('.self::FULLTEXT_TITLE.','.self::FULLTEXT_DESCRIPTION.','.self::FULLTEXT_EXTRA.') AGAINST('.$this->db->quote($pattern).' '.$mode.')';
		$where = [];
		if ($app) $where[] = $this->db->expression(self::FULLTEXT_TABLE, [self::FULLTEXT_APP => $app]);
		// see searchEmbeddings(): the app's filters as a join, an id-list only as the fallback. Unlike
		// the k-NN the inner query below has no LIMIT, so scoping makes the fulltext side exact.
		$from = self::FULLTEXT_TABLE.self::appFilterJoin($app_filter, self::FULLTEXT_TABLE.'.'.self::FULLTEXT_APP_ID);
		if ($app_ids) $where[] = self::FULLTEXT_TABLE.'.'.self::FULLTEXT_APP_ID.' IN ('.implode(',', array_map('intval', $app_ids)).')';
		$order = self::validateOrder($order, '!relevance');
		try {
			if ($min_relevance)
			{
				// $min_relevance is a FRACTION of the best match, so the probe has to see the same scope:
				// measured against a global best match the threshold can exclude everything inside a
				// narrow filter, which is the same "finds nothing" bug one level down
				$max_relevance = $this->db->select(self::FULLTEXT_TABLE, $match.' AS relevance',
					array_merge($app ? [self::FULLTEXT_APP => $app] : [], [$match],
						$app_ids ? [self::FULLTEXT_TABLE.'.'.self::FULLTEXT_APP_ID.' IN ('.implode(',', array_map('intval', $app_ids)).')'] : []),
					__LINE__, __FILE__, 0, 'ORDER BY relevance DESC', self::APP, 1,
					self::appFilterJoin($app_filter, self::FULLTEXT_TABLE.'.'.self::FULLTEXT_APP_ID))->fetchColumn();
				$min_relevance *= $max_relevance;
			}
			// an entry can have several rows / parts, e.g. replies or files: the best matching one counts,
			// so every entry uses one row of the requested page and of the total, like in searchEmbeddings()
			$matches = 'SELECT '.self::FULLTEXT_APP.','.self::FULLTEXT_APP_ID.','.$match.' AS relevance,'.
				self::FULLTEXT_MODIFIED.' FROM '.$from.
				' WHERE '.implode(' AND ', array_merge($where, [$match.' > '.(float)$min_relevance]));
			$entries = 'SELECT '.self::FULLTEXT_APP.','.self::FULLTEXT_APP_ID.',MAX(relevance) AS relevance,MAX('.
				self::FULLTEXT_MODIFIED.') AS modified FROM ('.$matches.') matches'.
				' GROUP BY '.self::FULLTEXT_APP.','.self::FULLTEXT_APP_ID;

			$cols = ['entries.*'];
			$join = '';
			if ($return_all)
			{
				$cols[] = self::FULLTEXT_TITLE.' AS title';
				$cols[] = self::FULLTEXT_DESCRIPTION.' AS description';
				$cols[] = self::FULLTEXT_EXTRA.' AS extra';
				// texts of the entry itself, not of the part which matched
				$join = ' LEFT JOIN '.self::FULLTEXT_TABLE.' ON entries.'.self::FULLTEXT_APP.'='.self::FULLTEXT_TABLE.'.'.self::FULLTEXT_APP.
					' AND entries.'.self::FULLTEXT_APP_ID.'='.self::FULLTEXT_TABLE.'.'.self::FULLTEXT_APP_ID.
					' AND '.self::FULLTEXT_TABLE.'.'.self::FULLTEXT_PART."=''";
			}
			$id_relevance = [];
			foreach ($this->db->query(self::timeLimited('SELECT SQL_CALC_FOUND_ROWS '.implode(',', $cols).' FROM ('.$entries.') entries'.$join.
				' ORDER BY '.$order), __LINE__, __FILE__, $start, $num_rows) as $row)
			{
				$id = $app && is_string($app) ? (int)$row[self::FULLTEXT_APP_ID] : $row[self::FULLTEXT_APP] . ':' . $row[self::FULLTEXT_APP_ID];
				$id_relevance[$id] = $return_all ? [
					'relevance' => (float)$row['relevance'],
					'modified' => new Api\DateTime($row['modified'], Api\DateTime::$server_timezone),
					'title' => $row['title'] ?? null,
					'description' => $row['description'] ?? null,
					'extra' => !empty($row['extra']) ? (array)json_decode($row['extra'], true) : [],
				] : (float)$row['relevance'];
			}
			$this->total = (int)$this->db->query('SELECT FOUND_ROWS()')->fetchColumn();

			// nothing matches all the words --> fall back to the previous behaviour of matching any of them
			if (!$id_relevance && !$user_operators && $require_all && substr_count($pattern, '+') > 1)
			{
				// $min_relevance is the absolute threshold by now, the parameter is a fraction of the best match
				return $this->searchFulltext($pattern_in, $app, $start, $num_rows, $return_all, $order,
					$min_relevance_in, $mode, $app_ids, false, $app_filter);
			}
		}
		catch (InvalidSql $e) {
			_egw_log_exception($e);
			if ($e->getCode() === 1064)
			{
				throw new InvalidFulltextSyntax($e->getMessage(), $e->getCode(), $e, $pattern);
			}
			throw $e;
		}
		catch (\Throwable $e) {
			// see searchEmbeddings(): a scoped search running into our own time limit falls back to the
			// unscoped one - which is what it did before there was a join. 1969 is a plain Db exception,
			// NOT an InvalidSql, so it needs its own catch.
			if (!$app_filter || !self::isTimeout($e)) throw $e;
			// see searchEmbeddings(): the exception's message is the whole statement
			self::logError(new \Exception('scoped search exceeded '.self::$search_timeout.'s, fell back to the unscoped one',
				$e->getCode()), $app, true, ['app_filter' => true]);
			// see searchEmbeddings(): the fallback must not run into the same limit
			$timeout = self::$search_timeout;
			self::$search_timeout = 0;
			try {
				return $this->searchFulltext($pattern_in, $app, $start, $num_rows, $return_all, $order,
					$min_relevance_in, $mode, null, $require_all);
			}
			finally {
				self::$search_timeout = $timeout;
			}
		}
		if (self::$log_level)
		{
			error_log(__METHOD__."('$pattern', '$app', start=$start, num_rows=$num_rows, min_relevance=$min_relevance) total=$this->total returning ".
				json_encode($id_relevance));
		}
		return $id_relevance;
	}

	/**
	 * InnoDB's default stopword list: these words are not in the index and requiring one finds nothing
	 *
	 * @link https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/full-text-indexes/stopwords
	 */
	const INNODB_STOPWORDS = ['a','about','an','are','as','at','be','by','com','de','en','for','from','how','i','in',
		'is','it','la','of','on','or','that','the','this','to','was','what','when','where','who','will','with','und',
		'www'];

	/**
	 * Below this length a word is not in the fulltext index (innodb_ft_min_token_size)
	 */
	const INNODB_MIN_TOKEN_SIZE = 3;

	/**
	 * Require every word of a boolean mode pattern, by prefixing it with a "+"
	 *
	 * Without it MariaDB's boolean mode matches an entry containing just one of the words, which in a
	 * groupware means everything containing e.g. "invoice", while the entries containing all words are
	 * only ranked a bit higher - and are lost completely, if the list is sorted by date or the result is
	 * cut off by a limit.
	 *
	 * Left alone are words the index does not contain anyway (shorter than innodb_ft_min_token_size or a
	 * stopword), as requiring them would find nothing. A word containing a dash is quoted: in boolean
	 * mode the dash would otherwise exclude everything containing the part behind it.
	 *
	 * @param string $pattern
	 * @return string
	 */
	public static function requireAll(string $pattern) : string
	{
		return preg_replace_callback('/"[^"]*"|[\pL\pN][\pL\pN._-]*\*?/u', static function(array $matches)
		{
			$word = $matches[0];
			if ($word[0] === '"')
			{
				return strlen($word) > 2 ? '+'.$word : $word;    // a phrase is always required
			}
			$bare = rtrim($word, '*');
			// a word with a dot is tokenized into its parts, e.g. the Greek "Φ.Π.Α." into three single
			// characters, which are not in the index: such a word never matches in boolean mode, with or
			// without a "+", but requiring it would also kill the matches of the other words
			if (mb_strlen($bare) < self::INNODB_MIN_TOKEN_SIZE || strpos($bare, '.') !== false ||
				in_array(mb_strtolower($bare), self::INNODB_STOPWORDS, true))
			{
				return $word;
			}
			// a dash inside the word is the "exclude" operator in boolean mode --> quote the word
			return '+'.(strpos($bare, '-') !== false ? '"'.$bare.'"' : $word);
		}, $pattern);
	}

	/**
	 * Create SQL fragment to return distance by id-column
	 *
	 * @param array $id_distance id => distance pairs
	 * @param string $id_column
	 * @return string SQL "CASE $id_column WHEN $id1 THEN $distance1 WHEN ... END"
	 */
	public static function distanceById(array $id_distance, string $id_column) : string
	{
		// if there is no result, we need to add something giving no result and not an sql error
		if (!$id_distance)
		{
			$id_distance[0] = 1;
		}
		$sql = "CASE $id_column ";
		foreach ($id_distance as $id => $distance)
		{
			$sql .= " WHEN ".(int)$id." THEN ".(float)$distance;
		}
		$sql .= " END";
		return $sql;
	}

	/**
	 * Create SQL fragment to order by keys given in $id_distance
	 *
	 * @param array $id_distance
	 * @param string $id_column
	 * @return string
	 */
	public static function orderByIds(array $id_distance, string $id_column) : string
	{
		return self::distanceById(array_flip(array_keys($id_distance)), $id_column);
	}

	/**
	 * Split a text into chunks of max. self::$chunk_size characters, each prefixed with $header
	 *
	 * Splits on paragraph and then sentence boundaries, so a chunk is a meaningful piece of text,
	 * and overlaps consecutive chunks by self::$chunk_overlap characters.
	 *
	 * @param ?string $text
	 * @param string $header context prefixed to every chunk, e.g. "[tracker] Ticket subject\n"
	 * @param array $chunks chunks to add to
	 * @return array of chunks
	 */
	protected static function chunkSplit(?string $text, string $header='', array $chunks=[])
	{
		if (!isset($text) || !trim($text)) return $chunks;    // nothing to do

		// always leave room for some text, even if the header is long
		$size = max(200, self::$chunk_size - mb_strlen($header));
		$overlap = min(self::$chunk_overlap, (int)($size / 4));

		$buffer = '';
		foreach(preg_split('/(?<=[.!?;:])\s+|\n+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) as $sentence)
		{
			// a single sentence longer than the chunk-size has to be cut hard
			while (mb_strlen($sentence) > $size)
			{
				if ($buffer !== '')
				{
					$chunks[] = $header.$buffer;
					$buffer = '';
				}
				$chunks[] = $header.mb_substr($sentence, 0, $size);
				$sentence = mb_substr($sentence, $size - $overlap);
			}
			if ($buffer !== '' && mb_strlen($buffer) + 1 + mb_strlen($sentence) > $size)
			{
				$chunks[] = $header.$buffer;
				// start the next chunk with the tail of the previous one, to not lose context at the boundary
				$buffer = $overlap ? ltrim(mb_substr($buffer, -$overlap)) : '';
			}
			$buffer .= ($buffer === '' ? '' : ' ').$sentence;
		}
		if (trim($buffer) !== '')
		{
			$chunks[] = $header.$buffer;
		}
		return $chunks;
	}
}
Embedding::initStatic();