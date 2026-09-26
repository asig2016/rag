<?php
/**
 * A filtered semantic search never combines its filter with the vector index.
 *
 * BEHAVIOUR UNDER TEST
 * With a WHERE (app, ids or an application's filter join) the k-NN query still uses the vector index,
 * but MariaDB 12.3 then checks candidate after candidate against the table: on 813,788 chunks of one
 * app 19s with the app filter warm, 383s cold, 0.17s without it. Embedding::searchEmbeddings() therefore
 * picks one of three plans by how many chunks the filters leave:
 * - "none":  the filter would leave everything (eg. the one app everything is embedded for)
 * - "exact": few chunks, the distance is calculated for each of them, driven by the normal indexes
 * - "post":  many, the vector index search for more candidates, filtered afterwards
 *
 * PASS CRITERIA
 * The plan matches the filter, and whatever plan, no entry outside the filter is returned; the exact
 * plan also finds the entry it is scoped to (max_distance 2 = the largest cosine distance).
 *
 * ENVIRONMENT
 * As ScopedSearchTest, whose setup (an embedded entry, the config, a reachable endpoint) it reuses.
 *
 * @package rag
 * @subpackage tests
 */

namespace EGroupware\Rag;

require_once __DIR__.'/ScopedSearchTest.php';

class SearchPlanTest extends ScopedSearchTest
{
	/**
	 * Keep the exact limit of the class, some tests lower it
	 */
	protected int $exact_max_chunks;

	public function setUp() : void
	{
		parent::setUp();
		$this->exact_max_chunks = (new \ReflectionProperty(Embedding::class, 'exact_max_chunks'))->getValue();
	}

	public function tearDown() : void
	{
		if (isset($this->exact_max_chunks))
		{
			(new \ReflectionProperty(Embedding::class, 'exact_max_chunks'))->setValue(null, $this->exact_max_chunks);
		}
		parent::tearDown();
	}

	public function testAppFilterIsLeftOutWhenItLeavesEverything()
	{
		$embedding = new Embedding();
		$narrows = (new \ReflectionMethod(Embedding::class, 'appNarrows'))->invoke($embedding, self::$app);

		$embedding->searchEmbeddings('Zahlung an das Finanzamt', self::$app, 0, 10, false, 'default', 2.0);

		if ($narrows)
		{
			$this->assertNotSame('none', $embedding->plan, 'other apps are embedded too, so the app filter has to be applied');
		}
		else
		{
			$this->assertSame('none', $embedding->plan, 'everything embedded is '.self::$app.': no filter needed');
		}
	}

	public function testFewIdsAreSearchedExactly()
	{
		$embedding = new Embedding();
		$found = $embedding->searchEmbeddings('Zahlung an das Finanzamt', self::$app, 0, 10, false, 'default', 2.0, [self::$id]);

		$this->assertSame('exact', $embedding->plan);
		$this->assertSame([self::$id], array_keys($found), 'exactly the one entry the ids allow');
	}

	public function testManyChunksAreFilteredAfterTheVectorSearch()
	{
		// every filter counts as "many" now
		(new \ReflectionProperty(Embedding::class, 'exact_max_chunks'))->setValue(null, 0);
		$embedding = new Embedding();
		$found = $embedding->searchEmbeddings('Zahlung an das Finanzamt', self::$app, 0, 10, false, 'default', 2.0, [self::$id]);

		$this->assertSame('post', $embedding->plan);
		$this->assertSame([], array_diff(array_keys($found), [self::$id]), 'nothing outside the filter');
	}
}
