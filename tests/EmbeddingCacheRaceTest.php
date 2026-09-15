<?php
/**
 * EGroupware RAG: Test Embedding::cacheQueryEmbedding() writing the search pattern cache
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use EGroupware\Api\Db\Exception\InvalidSql;
use ReflectionMethod;
use ReflectionProperty;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-1 entry.
 *
 * Embedding::searchEmbeddings()'s query-embedding cache used to live under the *cache* pseudo-app
 * in egw_rag, numbered by a non-atomic MAX(rag_chunk)+1 read: two concurrent searches caching two
 * different, not yet seen patterns could compute the same next rag_chunk and collide on the
 * (rag_app,rag_app_id,rag_chunk) unique key, throwing an uncaught duplicate-key InvalidSql
 * straight to the user - a real, live-reported bug. First fixed by a retry in
 * Embedding::cacheQueryEmbedding(), the collision is now impossible by design: the cache has its
 * own egw_rag_cache table with the pattern's sha256 hash as primary key, written with a REPLACE.
 *
 * These tests exercise cacheQueryEmbedding() directly (via reflection, it's protected): caching
 * the same pattern twice must not fail, a missing cache table (update not yet run) must not fail
 * the search, and every other DB error still has to propagate.
 */
class EmbeddingCacheRaceTest extends Api\LoggedInTest
{
	/**
	 * @var string[] binary sha256 hashes inserted by this test, cleaned up in tearDown()
	 */
	private array $hashes = [];

	protected function tearDown() : void
	{
		if ($this->hashes)
		{
			$GLOBALS['egw']->db->delete(Embedding::CACHE_TABLE, [
				Embedding::CACHE_HASH => $this->hashes,
			], __LINE__, __FILE__, Embedding::APP);
			$this->hashes = [];
		}
		parent::tearDown();
	}

	/**
	 * Build a fake create()-response object for a chunk we never actually send to an embeddings API
	 */
	private function fakeResponse(string $seed) : object
	{
		$hash = hash('sha256', self::class.'::'.$seed.'::'.microtime(true), true);
		$this->hashes[] = $hash;
		return (object)[
			'sha256'    => $hash,
			'embedding' => array_fill(0, 1024, 0.0),  // egw_rag_cache.rc_embedding is VECTOR(1024)
		];
	}

	private function callCacheQueryEmbedding(Embedding $embedding, object $response) : void
	{
		$method = new ReflectionMethod(Embedding::class, 'cacheQueryEmbedding');
		$method->setAccessible(true);
		$method->invoke($embedding, $response);
	}

	private function setDb(Embedding $embedding, $db) : void
	{
		$property = new ReflectionProperty(Embedding::class, 'db');
		$property->setAccessible(true);
		$property->setValue($embedding, $db);
	}

	/**
	 * A $db stand-in whose insert() always throws a simulated InvalidSql with the given MariaDB
	 * error code, everything else forwards to the real $db.
	 */
	private function failingDb(int $code)
	{
		return new class($GLOBALS['egw']->db, $code)
		{
			public int $insertCalls = 0;

			public function __construct(private $real, private int $code)
			{
			}

			public function __call($name, $args)
			{
				if ($name === 'insert')
				{
					$this->insertCalls++;
					throw new InvalidSql('simulated error', $this->code);
				}
				return $this->real->$name(...$args);
			}
		};
	}

	private function cachedRows(string $hash) : int
	{
		return (int)$GLOBALS['egw']->db->select(Embedding::CACHE_TABLE, 'COUNT(*)', [
			Embedding::CACHE_HASH => $hash,
		], __LINE__, __FILE__, false, '', Embedding::APP)->fetchColumn();
	}

	public function testStoresEmbeddingInCacheTable()
	{
		$embedding = new Embedding();
		$response = $this->fakeResponse('store');
		$this->callCacheQueryEmbedding($embedding, $response);

		$this->assertSame(1, $this->cachedRows($response->sha256),
			'the embedding should have been persisted to the cache table');
	}

	public function testCachingTheSamePatternTwiceDoesNotCollide()
	{
		$embedding = new Embedding();
		$response = $this->fakeResponse('twice');
		$this->callCacheQueryEmbedding($embedding, $response);
		// what a concurrent search for the same, not yet cached pattern does
		$this->callCacheQueryEmbedding($embedding, $response);

		$this->assertSame(1, $this->cachedRows($response->sha256),
			'the second write has to replace the row, not fail with a duplicate key or add another one');
	}

	public function testMissingCacheTableDoesNotFailTheSearch()
	{
		$embedding = new Embedding();
		$failing = $this->failingDb(1146);   // "Table 'egw_rag_cache' doesn't exist", update not yet run
		$this->setDb($embedding, $failing);

		$this->callCacheQueryEmbedding($embedding, $this->fakeResponse('no-table'));
		$this->assertSame(1, $failing->insertCalls);
	}

	public function testOtherErrorsPropagate()
	{
		$embedding = new Embedding();
		$failing = $this->failingDb(1064);   // "syntax error"
		$this->setDb($embedding, $failing);

		try
		{
			$this->callCacheQueryEmbedding($embedding, $this->fakeResponse('other-error'));
			$this->fail('expected an InvalidSql exception');
		}
		catch (InvalidSql $e)
		{
			$this->assertSame(1064, $e->getCode());
		}
		$this->assertSame(1, $failing->insertCalls);
	}
}
