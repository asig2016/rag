<?php
/**
 * EGroupware RAG: Test Embedding::search()'s hybrid (embeddings+fulltext) merge/sort/pagination
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use ReflectionMethod;
use ReflectionProperty;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-5 entry: search()'s merge/dedupe/total
 * -adjustment/sort/pagination arithmetic (777-828, add_rows() 840-854) had no test at all.
 *
 * Uses a stub subclass overriding searchEmbeddings()/searchFulltext() with canned results
 * instead of a real DB + a live/mocked embeddings HTTP client - this exercises the exact real
 * merge/sort/paginate code in search() itself, just with controlled inputs, matching how both
 * real methods set $this->total (searchFulltext() unconditionally overwrites it; search() then
 * adjusts it using the embeddings side's total captured right after searchEmbeddings() runs).
 */
class EmbeddingHybridSearchTest extends Api\LoggedInTest
{
	private function stub(array $embeddingResult, int $embeddingTotal, array $fulltextResult, int $fulltextTotal) : Embedding
	{
		$stub = new class extends Embedding {
			public array $embeddingResult = [];
			public int $embeddingTotal = 0;
			public array $fulltextResult = [];
			public int $fulltextTotal = 0;

			public function searchEmbeddings(string $pattern, $app=null, int $start=0, int $num_rows=50,
				bool $return_all=false, string $order='default', ?float $max_distance=null,
				?array $app_ids=null, ?string $app_filter=null) : array
			{
				$this->total = $this->embeddingTotal;
				return $this->embeddingResult;
			}

			public function searchFulltext(string $pattern, $app=null, int $start=0, int $num_rows=50,
				bool $return_all=false, string $order='default', float $min_relevance=0.05, ?string $mode=null,
				?array $app_ids=null, bool $require_all=true, ?string $app_filter=null) : array
			{
				$this->total = $this->fulltextTotal;
				return $this->fulltextResult;
			}
		};
		$stub->embeddingResult = $embeddingResult;
		$stub->embeddingTotal = $embeddingTotal;
		$stub->fulltextResult = $fulltextResult;
		$stub->fulltextTotal = $fulltextTotal;

		// force search() past its "if (!$this->client) return searchFulltext(...)" shortcut -
		// a real (but never actually called) OpenAI\Client satisfies the typed property without
		// any network I/O, Client's constructor just stores its transporter (see vendor source)
		$client = \OpenAI::factory()->withBaseUri('http://127.0.0.1:1')->withApiKey('unused')->make();
		$property = new ReflectionProperty(Embedding::class, 'client');
		$property->setAccessible(true);
		$property->setValue($stub, $client);

		return $stub;
	}

	public function testNoClientDelegatesEntirelyToFulltext()
	{
		$stub = new class extends Embedding {
			public function searchFulltext(string $pattern, $app=null, int $start=0, int $num_rows=50,
				bool $return_all=false, string $order='default', float $min_relevance=0.05, ?string $mode=null,
				?array $app_ids=null, bool $require_all=true, ?string $app_filter=null) : array
			{
				return ['sentinel' => [$pattern, $app, $start, $num_rows, $return_all, $order, $min_relevance]];
			}
		};
		// this instance actually has a live RAG endpoint configured (self::$url is not empty),
		// so $client is never null by default here - force it to null to simulate an
		// install where RAG isn't configured, which is the actual scenario under test
		$clientProp = new ReflectionProperty(Embedding::class, 'client');
		$clientProp->setAccessible(true);
		$clientProp->setValue($stub, null);

		// a non-default min_relevance, to prove it is forwarded
		$result = $stub->search('pattern', 'addressbook', 5, 10, true, 'modified', .4, 0.9);

		// search()'s no-client fallback used to drop a caller-supplied $min_relevance, falling back to
		// searchFulltext()'s own default (0.05) - it is forwarded now, like $app_ids and $app_filter
		$this->assertSame(
			['pattern', 'addressbook', 5, 10, true, 'modified', 0.9],
			$result['sentinel'],
			'search() must delegate directly to searchFulltext() with all args when no client is configured');
	}

	public function testDedupesOverlapAndMergesFieldsEmbeddingWins()
	{
		$modifiedA = new Api\DateTime('2026-01-01');
		$modifiedB = new Api\DateTime('2026-02-02');
		$stub = $this->stub(
			[1 => ['distance' => 0.1, 'modified' => $modifiedA, 'title' => 'from-embedding',
				'description' => 'embedding-desc', 'extra' => []]],
			1,
			[1 => ['relevance' => 0.9, 'modified' => $modifiedB, 'title' => 'from-fulltext',
				'description' => 'fulltext-desc', 'extra' => ['x']]],
			1,
		);

		$result = $stub->search('pattern', 'addressbook', 0, 50, true);

		$this->assertCount(1, $result, 'the same id from both sides must be reported only once');
		$this->assertArrayHasKey(1, $result);
		// add_rows() treats embeddings as the base ($rows) and fulltext as $additional: shared
		// keys (modified/title/description/extra) keep the embedding side's value, only keys
		// unique to fulltext (relevance) get merged in
		$this->assertSame(0.1, $result[1]['distance']);
		$this->assertSame('from-embedding', $result[1]['title']);
		$this->assertSame('embedding-desc', $result[1]['description']);
		$this->assertSame($modifiedA, $result[1]['modified']);
		$this->assertSame(0.9, $result[1]['relevance'],
			'relevance is unique to the fulltext side and must still be merged in');
	}

	public function testKeepsEntriesFoundOnOnlyOneSide()
	{
		$stub = $this->stub(
			[1 => ['distance' => 0.1, 'modified' => new Api\DateTime(), 'title' => 'e', 'description' => '', 'extra' => []]],
			1,
			[2 => ['relevance' => 0.5, 'modified' => new Api\DateTime(), 'title' => 'f', 'description' => '', 'extra' => []]],
			1,
		);

		$result = $stub->search('pattern', 'addressbook', 0, 50, true);

		$this->assertCount(2, $result);
		$this->assertArrayHasKey('distance', $result[1]);
		$this->assertArrayNotHasKey('relevance', $result[1]);
		$this->assertArrayHasKey('relevance', $result[2]);
		$this->assertArrayNotHasKey('distance', $result[2]);
	}

	public function testDefaultOrderKeepsEmbeddingsBeforeFulltext()
	{
		$stub = $this->stub(
			[10 => ['distance' => 0.3, 'modified' => new Api\DateTime(), 'title' => 'e10', 'description' => '', 'extra' => []]],
			1,
			[20 => ['relevance' => 0.9, 'modified' => new Api\DateTime(), 'title' => 'f20', 'description' => '', 'extra' => []]],
			1,
		);

		$result = $stub->search('pattern', 'addressbook', 0, 50, true);   // $order defaults to 'default'

		$this->assertSame([10, 20], array_keys($result),
			'with default order, embedding matches must come first, then fulltext-only ones');
	}

	public function testTotalIsAdjustedForOverlap()
	{
		// 1 overlapping id out of 2 embedding + 2 fulltext matches on this page -> 3 distinct
		$stub = $this->stub(
			[
				1 => ['distance' => 0.1, 'modified' => new Api\DateTime(), 'title' => 'a', 'description' => '', 'extra' => []],
				2 => ['distance' => 0.2, 'modified' => new Api\DateTime(), 'title' => 'b', 'description' => '', 'extra' => []],
			],
			10,   // total embedding matches across all pages
			[
				2 => ['relevance' => 0.5, 'modified' => new Api\DateTime(), 'title' => 'b-again', 'description' => '', 'extra' => []],
				3 => ['relevance' => 0.4, 'modified' => new Api\DateTime(), 'title' => 'c', 'description' => '', 'extra' => []],
			],
			20,   // total fulltext matches across all pages (search()'s $this->total starts here)
		);

		$stub->search('pattern', 'addressbook', 0, 50, true);

		// fulltextTotal(20) + embeddingTotal(10) - (2+2-3 distinct) = 20 + 10 - 1 = 29
		$this->assertSame(29, $stub->total);
	}

	public function testOrderByDistanceSortsAscendingAndPushesFulltextOnlyToEnd()
	{
		$stub = $this->stub(
			[
				1 => ['distance' => 0.5, 'modified' => new Api\DateTime(), 'title' => 'far', 'description' => '', 'extra' => []],
				2 => ['distance' => 0.1, 'modified' => new Api\DateTime(), 'title' => 'close', 'description' => '', 'extra' => []],
			],
			2,
			[3 => ['relevance' => 0.9, 'modified' => new Api\DateTime(), 'title' => 'fulltext-only', 'description' => '', 'extra' => []]],
			1,
		);

		$result = $stub->search('pattern', 'addressbook', 0, 50, true, 'distance');

		$this->assertSame([2, 1, 3], array_keys($result),
			'closest distance first, entries without a distance value sort to the end');
	}

	public function testPaginatesAcrossTheMergedResultNotPerSide()
	{
		$stub = $this->stub(
			[1 => ['distance' => 0.1, 'modified' => new Api\DateTime(), 'title' => 'e1', 'description' => '', 'extra' => []]],
			1,
			[2 => ['relevance' => 0.9, 'modified' => new Api\DateTime(), 'title' => 'f2', 'description' => '', 'extra' => []]],
			1,
		);

		// 2 distinct merged entries total; asking for 1 starting at offset 1 must give the 2nd
		$result = $stub->search('pattern', 'addressbook', 1, 1, true);

		$this->assertSame([2], array_keys($result));
	}

	private function addRows(array $rows, array $additional) : array
	{
		$method = new ReflectionMethod(Embedding::class, 'add_rows');
		$method->setAccessible(true);
		return $method->invoke(null, $rows, $additional);
	}

	public function testAddRowsUnionKeepsLeftSideOnOverlap()
	{
		$merged = $this->addRows(
			[1 => ['a' => 'left', 'shared' => 'left-shared']],
			[1 => ['b' => 'right', 'shared' => 'right-shared'], 2 => ['c' => 'only-right']],
		);

		$this->assertSame(['a' => 'left', 'shared' => 'left-shared', 'b' => 'right'], $merged[1],
			'shared keys keep the left/base side, keys unique to the right side get merged in');
		$this->assertSame(['c' => 'only-right'], $merged[2]);
	}
}
