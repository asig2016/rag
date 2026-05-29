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

require_once __DIR__.'/../vendor/autoload.php';

class Embedding
{
	const APP = 'rag';
	const TABLE = 'egw_rag';
	const EMBEDDING_UPDATED = 'rag_updated';
	const EMBEDDING_APP = 'rag_app';
	const EMBEDDING_CACHE = '*cache*';  // used as EMBEDDING_APP for cached embeddings
	const EMBEDDING_APP_ID = 'rag_app_id';
	const EMBEDDING_CHUNK = 'rag_chunk';
	const EMBEDDING = 'rag_embedding';
	const EMBEDDING_MODIFIED = 'rag_updated';
	const EMBEDDING_HASH = 'rag_hash';
	const FULLTEXT_TABLE = 'egw_rag_fulltext';
	const FULLTEXT_UPDATED = 'ft_updated';
	const FULLTEXT_APP = 'ft_app';
	const FULLTEXT_APP_ID = 'ft_app_id';
	const FULLTEXT_TITLE = 'ft_title';
	const FULLTEXT_DESCRIPTION = 'ft_description';
	const FULLTEXT_EXTRA = 'ft_extra';
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
	protected static int $chunk_size = 500;
	/**
	 * @var int overlap of chunks
	 */
	protected static int $chunk_overlap = 50;
	/**
	 * @var string embedding model to use
	 */
	protected static string $model = 'bge-m3';
	/**
	 * @var bool minimize number of chunks e.g. by concatenating title and describtion
	 */
	public static bool $minimize_chunks = true;

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
		catch (\Exception $e) {
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

		self::$chunk_size = $config['chunk_size'] ?? 500;
		self::$chunk_overlap = $config['chunk_overlap'] ?? 50;
		self::$model = $config['embedding_model'] ?? 'bge-m3';
		self::$minimize_chunks = ($config['minimize_chunks'] ?? 'yes') !== 'no';
		self::$max_runtime = (int) $config['async_maxruntime'] ?? 285;

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
	 * @return bool false: search not available, or configured to be off, true: search available and implemented via changed parameters
	 */
	public static function search2criteria(string $app, string &$criteria, &$order_by, &$extra_cols, ?array &$filter) : bool
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
			$ids = $rag->$search($criteria, $app, 0, 200);
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
	 * @param string[] $chunks array of utf-8 strings
	 * @return array[] array of objects with attributes n, sha256, chunk and embedding
	 *  (also app, app_id and id, if response is from DB), same order and keys as $chunks!
	 * @throws \Exception
	 */
	public function create(array $chunks) : array
	{
		if (!$chunks) return [];    // otherwise we start a query for rag_hash IS NULL
		$responses = [];
		foreach($chunks as $n => $chunk)
		{
			$sha256 = hash('sha256', $chunk, true);
			$responses[$sha256] = (object)[
				'n'      => $n,
				'sha256' => $sha256,
				'chunk'  =>	$chunk,
			];
		}
		// check if we already have an embedding for that chunk-content by comparing the sha256 hash
		foreach($this->db->select(self::TABLE, '*', [
			'rag_hash' => array_map(fn($v) => $v->sha256, $responses),
		], __LINE__, __FILE__, false, 'GROUP BY rag_hash', self::APP) as $row)
		{
			if (!isset($responses[$row['rag_hash']])) continue;   // not sure how this can happen, but it does...
			$responses[$row['rag_hash']]->embedding = array_values(unpack('g*', $row['rag_embedding']));
			$responses[$row['rag_hash']]->app = $row['rag_app'];
			$responses[$row['rag_hash']]->app_id = $row['rag_app_id'];
			$responses[$row['rag_hash']]->id = $row['rag_id'];
			unset($chunks[$responses[$row['rag_hash']]->n]);
		}
		// any chunks we need embeddings for
		if ($chunks)
		{
			$chunks = array_values($chunks);
			try {
				$response = $this->client->embeddings()->create([
					'model' => self::$model,
					'input' => $chunks,
				]);
			}
			catch (\Exception $e)   // JsonException of unsure namespace, therefore catch them all
			{
				// fix invalid utf-8 characters by replacing them BEFORE calculating the embeddings
				if (str_starts_with($e->getMessage(), 'Malformed UTF-8 characters, possibly incorrectly encoded'))
				{
					$response = $this->client->embeddings()->create([
						'model' => self::$model,
						'input' => json_decode(json_encode($chunks, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true),
					]);
					unset($e);
				}
				else
				{
					throw $e;
				}
			}
			foreach($response->embeddings as $n => $embedding)
			{
				foreach($responses as &$response)
				{
					if ($response->chunk === $chunks[$n])
					{
						$response->embedding = $embedding->embedding;
						break;
					}
				}
			}
		}
		return array_values($responses);
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
						try
						{
							// fulltext index or RAG/embeddings
							if ($fulltext)
							{
								$extra_ft = $extra ? array_values(array_filter(array_map('trim', $extra), static function ($v) {
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
									self::FULLTEXT_TITLE => $title ?: null,
									self::FULLTEXT_DESCRIPTION => $description ?: null,
									self::FULLTEXT_EXTRA => $extra_ft,
									self::FULLTEXT_MODIFIED => $modified,
								], [
									self::FULLTEXT_APP => $app,
									self::FULLTEXT_APP_ID => $id,
								], __LINE__, __FILE__, self::APP);
								//continue;
							}
							if (self::$minimize_chunks)
							{
								$chunks = self::chunkSplit($title . "\n" . $description.($extra ? "\n" . implode("\n", $extra) : ""));
							}
							else
							{
								$chunks = self::chunkSplit($description, [$title]);
								// embed each reply or $cfs on its own
								foreach ($extra as $field)
								{
									$chunks = self::chunkSplit($field, $chunks);
								}
							}
							$response = $this->create($chunks);
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
						$n = -1;
						foreach ($response as $n => $embedding)
						{
							$this->db->insert(self::TABLE, [
								self::EMBEDDING => $embedding->embedding,
								self::EMBEDDING_HASH => $embedding->sha256,
								self::EMBEDDING_MODIFIED => $modified,
							], [
								self::EMBEDDING_APP => $app,
								self::EMBEDDING_APP_ID => $id,
								self::EMBEDDING_CHUNK => $n,
							], __LINE__, __FILE__, self::APP);
						}
						// delete excess old chunks, if there are any
						$this->db->delete(self::TABLE, [
							self::EMBEDDING_APP => $app,
							self::EMBEDDING_APP_ID => $id,
							self::EMBEDDING_CHUNK . '>' . $n,
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
	 * Hybrid search in RAG and fulltext index for given app and $pattern
	 *
	 * Returns found IDs and their distance ordered by the smallest distance / the best match first,
	 * then the fulltext search results with the highest relevance first.
	 * Same entries are only returned once with their embedding distance!.
	 *
	 * @ToDo: better way to merge fulltext and embedding searches https://mariadb.com/docs/server/reference/sql-structure/vectors/optimizing-hybrid-search-query-with-reciprocal-rank-fusion-rrf
	 * @param string $pattern
	 * @param ?string|string[] $app app-name(s) or '' or NULL for searching all apps
	 * @param int $start default 0
	 * @param int $num_rows default 50
	 * @param bool $return_all true: return array with modified time, title, description, extra data and distance&relevance,
	 *  false: only return distance and relevance value
	 * @param string $order one of "default", "distance", "relevance" or "modified", optional with ASC or DESC suffix
	 * @param float $max_distance default .4
	 * @param float $min_relevance default 0.05 = 5% of highest match
	 * @return float[]|array[] int id => float distance pairs, for $app === '' we return string "$app:$id"
	 *  If there is no result, we return [0 => 1.0], to not generate an SQL error, but an empty result!
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function search(string $pattern, $app=null, int $start=0, int $num_rows=50, bool $return_all=false,
	                       string $order='default', float $max_distance=.4, float $min_relevance=0.05, ?array $app_ids=null) : array
	{
		if (!$this->client)
		{
			return $this->searchFulltext($pattern, $app, $start, $num_rows, $return_all, $order,0.05, null, $app_ids);
		}
		// quick/dump approach for merging: always query from start=0, $start+$num_rows rows, and then slice
		$embedding_matches = $this->searchEmbeddings($pattern, $app, 0, $start+$num_rows, $return_all, $order, $max_distance);
		$total_embeddings = $this->total ?? 0;

		$fulltext_matches = $this->searchFulltext($pattern, $app, 0, $start+$num_rows, $return_all, $order, $min_relevance);

		// + makes sure to return every entry only once, with the embeddings first
		$both = $return_all ? self::add_rows($embedding_matches, $fulltext_matches) : $embedding_matches+$fulltext_matches;

		// we can only subtract the entries found in both returned sets, but there might be more in common ...
		$this->total += $total_embeddings - (count($embedding_matches)+count($fulltext_matches)-count($both));

		if ($order !== 'default ASC')
		{
			[$order, $sort] = explode(' ', self::validateOrder($order), 2);
			// we can only order it by a different criteria if we got the data / $return_all === true
			if ($return_all && $order !== 'default')
			{
				$sort_to_end = $sort === 'ASC' ? 1 : -1;
				uasort($both, static function(array $a, array $b) use ($order, $sort_to_end)
				{
					if (!isset($a[$order]))
					{
						return !isset($b[$order]) ? 0 : $sort_to_end;
					}
					if (!isset($b[$order]))
					{
						return -$sort_to_end;
					}
					return $a[$order] <=> $b[$order];
				});
			}
			if ($sort !== 'ASC')
			{
				$both = array_reverse($both, true);
			}
		}
		$both = array_slice($both, $start, $num_rows, true);
		if (self::$log_level)
		{
			error_log(__METHOD__."('$pattern', '$app', start=$start, num_rows=$num_rows, return_modified=$return_all, order=$order, max_distance=$max_distance) total=$this->total returning ".
				json_encode($both));
		}
		return $both;
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
	 * @param float $max_distance default .4
	 * @param array $app_ids:  Optionally limit the the search to these specific app ids
	 * @return float[] int id => float distance pairs for non-empty and string $app, empty $app or array we return string "$app:$id"
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function searchEmbeddings(string $pattern, $app=null, int $start=0, int $num_rows=50, bool $return_all=false,
	                                 string $order='default', float $max_distance=.4, ?array $app_ids=null) : array
	{
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
		if (empty($response[0]->id))
		{
			$this->db->insert(self::TABLE, [
				self::EMBEDDING_APP => self::EMBEDDING_CACHE,
				self::EMBEDDING_APP_ID => 0,
				self::EMBEDDING_CHUNK => 1+(int)$this->db->select(self::TABLE, 'MAX('.self::EMBEDDING_CHUNK.')', [
					self::EMBEDDING_APP => self::EMBEDDING_CACHE,
					self::EMBEDDING_APP_ID => 0,
				], __LINE__, __FILE__, false, '', self::APP)->fetchColumn(),
				self::EMBEDDING_HASH => $response[0]->sha256,
				self::EMBEDDING => $response[0]->embedding,
				self::EMBEDDING_MODIFIED => new Api\DateTime(),
			], false, __LINE__, __FILE__, self::APP);
		}
		$cols = [
			self::EMBEDDING_APP,
			self::EMBEDDING_APP_ID,
			'VEC_DISTANCE_COSINE('.Embedding::EMBEDDING.', '.$this->db->quote($response[0]->embedding, 'vector').') as distance',
			self::EMBEDDING_MODIFIED.' AS modified',
		];
		if ($return_all)
		{
			$cols[] = self::FULLTEXT_TITLE.' AS title';
			$cols[] = self::FULLTEXT_DESCRIPTION.' AS description';
			$cols[] = self::FULLTEXT_EXTRA.' AS extra';
		}
		$order = self::validateOrder($order, 'distance');
		$id_distance = [];
		foreach($this->db->select(self::TABLE, 'SQL_CALC_FOUND_ROWS '.implode(',', $cols),
			$app ? [self::EMBEDDING_APP => $app,] : self::EMBEDDING_APP.'<>'.$this->db->quote(self::EMBEDDING_CACHE),
			__LINE__, __FILE__, $start, 'HAVING distance<'.$max_distance.($app_ids ? ' AND '.self::EMBEDDING_APP_ID .' IN ('.implode(',',$app_ids).')' : '' ).' ORDER BY '.$order, self::APP, $num_rows,
			$return_all ? ' LEFT JOIN '.self::FULLTEXT_TABLE.' ON '.self::EMBEDDING_APP.'='.self::FULLTEXT_APP.
			' AND '.self::EMBEDDING_APP_ID.'='.self::FULLTEXT_APP_ID : '') as $row)
		{
			$id = $app && is_string($app) ? (int)$row[self::EMBEDDING_APP_ID] : $row[self::EMBEDDING_APP].':'.$row[self::EMBEDDING_APP_ID];
			// only insert the first / best match, as multiple chunks could match
			if (!isset($id_distance[$id]))
			{
				// if the app is not fulltext index, we won't get texts and need to query them separate from the app
				if ($return_all && !isset($row['title']))
				{
					static $plugins=null; $plugins ??= self::plugins();
					/** @var Embedding\Base $plugin */ $plugin = $plugins[$row[self::EMBEDDING_APP]] ?? null;
					if ($plugin)
					{
						foreach((new $plugin)->getUpdated(true, ['data' => [
							'app' => $row[self::EMBEDDING_APP],
							'id' => $row[self::EMBEDDING_APP_ID],
						]]) as $entry)
						{
							$row += $entry;
						}
					}
				}
				$id_distance[$id] = $return_all ? [
					'distance' => (float)$row['distance'],
					'modified' => new Api\DateTime($row['modified'], Api\DateTime::$server_timezone),
					'title' => $row['title'],
					'description' => $row['description'],
					'extra' => $row['extra'] ? (array)json_decode($row['extra'], true) : [],
				] : (float)$row['distance'];
			}
		}
		$this->total = $this->db->query('SELECT FOUND_ROWS()')->fetchColumn();
		if (self::$log_level)
		{
			error_log(__METHOD__."('$pattern', '$app', start=$start, num_rows=$num_rows, max_distance=$max_distance) total=$this->total returning ".
				json_encode($id_distance));
		}
		return $id_distance;
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
	 * @return float[] int id => float relevance pairs for non-empty and string $app, empty $app or array we return string "$app:$id"
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function searchFulltext(string $pattern, $app=null, int $start=0, int $num_rows=50, bool $return_all=false,
	                               string $order='default', float $min_relevance=0.05, ?string $mode=null, ?array $app_ids=null) : array
	{
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
		$cols = [
			self::FULLTEXT_APP,
			self::FULLTEXT_APP_ID,
			$match.' AS relevance',
			self::FULLTEXT_MODIFIED.' AS modified',
		];
		if ($return_all)
		{
			$cols[] = self::FULLTEXT_TITLE.' AS title';
			$cols[] = self::FULLTEXT_DESCRIPTION.' AS description';
			$cols[] = self::FULLTEXT_EXTRA.' AS extra';
		}
		$order = self::validateOrder($order, '!relevance');
		try {
			if ($min_relevance)
			{
				$max_relevance = $this->db->select(self::FULLTEXT_TABLE, $match.' AS relevance',
					($app ? [self::FULLTEXT_APP => $app] : []) + [$match],
					__LINE__, __FILE__, 0, 'ORDER BY relevance DESC', self::APP, 1)->fetchColumn();
				$min_relevance *= $max_relevance;
			}
			$id_relevance = [];
			foreach ($this->db->select(self::FULLTEXT_TABLE, 'SQL_CALC_FOUND_ROWS ' . implode(',', $cols),
				($app ? [self::FULLTEXT_APP => $app] : []) + [$match . ' > '.$min_relevance] + ($app_ids ? [self::FULLTEXT_APP_ID .' IN ('.implode(',',$app_ids).')'] : [] ),
				__LINE__, __FILE__, $start, 'ORDER BY '.$order, self::APP, $num_rows) as $row)
			{
				if ($row['relevance'] < $min_relevance)
				{
					if ($order !== 'relevance DESC')
					{
						continue;
					}
					else
					{
						break;
					}
				}
				$id = $app && is_string($app) ? (int)$row[self::FULLTEXT_APP_ID] : $row[self::FULLTEXT_APP] . ':' . $row[self::FULLTEXT_APP_ID];
				$id_relevance[$id] = $return_all ? [
					'relevance' => (float)$row['relevance'],
					'modified' => new Api\DateTime($row['modified'], Api\DateTime::$server_timezone),
					'title' => $row['title'],
					'description' => $row['description'],
					'extra' => $row['extra'] ? (array)json_decode($row['extra'], true) : [],
				] : (float)$row['relevance'];
			}
			$this->total = $this->db->query('SELECT FOUND_ROWS()')->fetchColumn();
		}
		catch (InvalidSql $e) {
			_egw_log_exception($e);
			if ($e->getCode() === 1064)
			{
				throw new InvalidFulltextSyntax($e->getMessage(), $e->getCode(), $e, $pattern);
			}
			throw $e;
		}
		if (self::$log_level)
		{
			error_log(__METHOD__."('$pattern', '$app', start=$start, num_rows=$num_rows, min_relevance=$min_relevance) total=$this->total returning ".
				json_encode($id_relevance));
		}
		return $id_relevance;
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
	 * Semantic search in given app for $pattern
	 *
	 * Returns an SQL fragment for a column and a join.
	 *
	 * @param string $pattern search query
	 * @param string $app app-name
	 * @return string SQL fragment
	 */
	public function searchColumnJoin(string $pattern, string $app, ?string &$join=null) : string
	{
		$response = $this->client->embeddings()->create([
			'model' => self::$model,
			'input' => [$pattern],
		]);
		$plugin = ucfirst(__CLASS__.'\\'.ucfirst($app));
		$plugin = new $plugin();
		return $plugin->searchColumnJoin($response->embeddings[0]->embedding, $join);
	}

	/**
	 * Split description into chunks
	 *
	 * Using self::$chunk_size and an overlap of self::$chunk_overlap.
	 *
	 * @param ?string $description
	 * @param array $chunks additional chunks, e.g. title
	 * @return array of chunks
	 */
	protected static function chunkSplit(?string $description, array $chunks=[])
	{
		if (!$description || !trim($description)) return $chunks;    // nothing to do

		$n = 0;
		while(strlen($chunk = substr($description, $n*(self::$chunk_size-self::$chunk_overlap),
			self::$chunk_size)) > self::$chunk_overlap || !$n)
		{
			$chunks[] = $chunk;
			$n++;
		}
		return $chunks;
	}
}
Embedding::initStatic();