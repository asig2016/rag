<?php
/**
 * A search scoped by an application's filters must search INSIDE them, not intersect afterwards.
 *
 * BEHAVIOUR UNDER TEST
 * The RAG picks the k nearest chunks and the application's filter used to be applied to that result.
 * Once the filter is selective, the k nearest chunks globally contain none of its entries and the
 * search returns NOTHING - no error, just an empty list. Measured before the fix on a real archive:
 * a client with 3 embedded mails, searching "Zahlung an das Finanzamt", found none of them.
 *
 * With the filter handed over as a sub-query (`$app_filter`) it is joined into the innermost block,
 * so the k nearest are chosen among the filtered entries.
 *
 * SETUP STRATEGY
 * Data-independent: the test picks whatever entry happens to be embedded, scopes the search to that
 * single id, and searches with `max_distance` = 2 - the maximum a cosine distance can be, so no
 * match is ever excluded for being too far. Under those conditions a correctly scoped search MUST
 * return that entry and nothing else, whatever the corpus contains. Nothing is asserted about
 * relevance or about which entries exist.
 *
 * PASS CRITERIA
 * The scoped search returns exactly the entry the filter allows. The same search unscoped returns
 * other entries too - which is what makes the scoping non-trivial.
 *
 * ENVIRONMENT
 * Needs the RAG tables with at least two embedded entries and a reachable embedding endpoint;
 * skipped otherwise, so it is safe on an installation that does not use the RAG. It connects to the
 * database itself rather than extending LoggedInTest: none of this needs a logged-in user, and a
 * search does not care who is asking - the ACL check happens in the UI, on the ids it returns.
 *
 * @package rag
 * @subpackage tests
 */

namespace EGroupware\Rag;

use EGroupware\Api;

use PHPUnit\Framework\TestCase;

class ScopedSearchTest extends TestCase
{
	/**
	 * @var string app of the entry we scope to
	 */
	protected static string $app;

	/**
	 * @var int id of the entry we scope to
	 */
	protected static int $id;

	/**
	 * @var string table and id column of that app, for the sub-query
	 */
	protected static string $table, $id_column;

	public function setUp() : void
	{
		parent::setUp();

		if (!class_exists(Embedding::class))
		{
			$this->markTestSkipped('RAG is not installed');
		}
		$db = self::db();
		// the config, straight from the table: Api\Config needs the whole Egw/Cache stack and a session
		$config = [];
		try {
			foreach($db->select('egw_config', 'config_name,config_value', ['config_app' => 'rag'],
				__LINE__, __FILE__) as $row)
			{
				$config[$row['config_name']] = json_decode($row['config_value'], true) ?? $row['config_value'];
			}
		}
		catch (\Throwable $e) {
			$this->markTestSkipped('no database: '.$e->getMessage());
		}
		if (empty($config['url']))
		{
			$this->markTestSkipped('no embedding endpoint configured');
		}
		unset($config[Embedding::RAG_LAST_ERRORS]);
		Embedding::initStatic($config);

		// whatever is embedded - the test must not depend on which app or entry that is
		try {
			$row = $db->select(Embedding::TABLE, Embedding::EMBEDDING_APP.','.Embedding::EMBEDDING_APP_ID,
				[], __LINE__, __FILE__, 0, 'ORDER BY '.Embedding::EMBEDDING_APP_ID, 'rag', 1)->fetch();
			$embedded = (int)$db->select(Embedding::TABLE, 'COUNT(DISTINCT '.Embedding::EMBEDDING_APP_ID.')',
				[], __LINE__, __FILE__, false, '', 'rag')->fetchColumn();
		}
		catch (\Throwable $e) {
			$this->markTestSkipped('RAG tables not available: '.$e->getMessage());
		}
		if (empty($row) || $embedded < 2)
		{
			$this->markTestSkipped('needs at least two embedded entries, found '.($embedded ?? 0));
		}
		self::$app = $row[Embedding::EMBEDDING_APP];
		self::$id  = (int)$row[Embedding::EMBEDDING_APP_ID];

		$plugins = Embedding::plugins();
		if (empty($plugins[self::$app]))
		{
			$this->markTestSkipped('no plugin for the embedded app '.self::$app);
		}
		$plugin = new $plugins[self::$app]();
		self::$table = $plugin->table();
		self::$id_column = $plugin->id();
	}

	/**
	 * Our own db connection, and the minimal globals Embedding needs
	 *
	 * @return Api\Db
	 */
	protected static function db() : Api\Db
	{
		if (isset($GLOBALS['egw']->db))
		{
			return $GLOBALS['egw']->db;
		}
		if (empty($GLOBALS['egw_domain']))
		{
			// "noapi" loads the credentials and the autoloader without building an Egw object or a
			// session - and without the flags header.inc.php aborts the whole process, not just this test
			$GLOBALS['egw_info']['flags'] = ($GLOBALS['egw_info']['flags'] ?? []) +
				['currentapp' => 'rag', 'noapi' => true];
			require_once realpath(__DIR__.'/../../header.inc.php');
		}
		$domain = $GLOBALS['egw_domain'][key($GLOBALS['egw_domain'])];
		$db = new Api\Db($domain);
		$db->connect();
		$GLOBALS['egw'] = new \stdClass();
		$GLOBALS['egw']->db = $db;
		$GLOBALS['egw_info']['server'] = $domain;
		$GLOBALS['egw_info']['apps']['rag'] = ['name' => 'rag'];

		return $db;
	}

	/**
	 * @return string sub-query allowing exactly the one entry
	 */
	protected function filterSubquery() : string
	{
		return 'SELECT '.self::$table.'.'.self::$id_column.' AS '.Embedding::APP_FILTER_ID.
			' FROM '.self::$table.' WHERE '.self::$table.'.'.self::$id_column.'='.self::$id;
	}

	/**
	 * max_distance 2 is the maximum a cosine distance can be, so nothing is dropped for being far away
	 */
	public function testScopedSearchReturnsOnlyWhatTheFilterAllows()
	{
		$rag = new Embedding();
		$scoped = $rag->searchEmbeddings('the', self::$app, 0, 50, false, 'default', 2.0, null,
			$this->filterSubquery());

		$this->assertNotEmpty($scoped,
			'the scoped search has to find the one entry its filter allows - finding nothing is the bug');
		$this->assertSame([self::$id], array_map('intval', array_keys($scoped)),
			'a scoped search must not return an entry the filter excludes');
	}

	/**
	 * Without the scope the same search returns other entries - otherwise the test above proves nothing
	 */
	public function testTheSameSearchUnscopedIsNotAlreadyLimited()
	{
		$rag = new Embedding();
		$unscoped = $rag->searchEmbeddings('the', self::$app, 0, 50, false, 'default', 2.0);

		$this->assertGreaterThan(1, count($unscoped),
			'unscoped this search returns many entries, which is what the filter has to narrow down');
	}

	/**
	 * An unusable sub-query must not silently scope by something else - it falls back to unscoped
	 */
	public function testAnUnusableSubqueryIsIgnored()
	{
		$rag = new Embedding();
		$ignored = $rag->searchEmbeddings('the', self::$app, 0, 50, false, 'default', 2.0, null,
			$this->filterSubquery().' GROUP BY '.self::$id_column);

		$this->assertGreaterThan(1, count($ignored),
			'a sub-query we refuse to splice has to leave the search unscoped, not scope it wrongly');
	}
}
