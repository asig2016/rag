<?php
/**
 * EGroupware RAG: Test Ui::get_rows()'s search-type dispatch, id-parsing, ACL filtering, and
 * pagination
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use ReflectionProperty;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-6 entry (the last open item).
 *
 * Pattern used: same as every other Nextmatch get_rows() test in this codebase (e.g.
 * timesheet/tests/TimesheetUiGetRowsTest.php) - direct instantiation, a hand-built $query
 * array, and assertions on the mutated $rows/$readonlys and the returned total. Nobody in this
 * codebase mocks the Nextmatch widget or Etemplate to test get_rows() - it's a plain method
 * call.
 *
 * Ui::$embedding is built internally in the constructor with no injection point, so a stub
 * Embedding subclass is swapped in via ReflectionProperty - the exact same technique
 * EmbeddingHybridSearchTest.php already established for isolating Embedding::search() from its
 * own real DB/HTTP dependencies. Doing the same one layer up isolates Ui::get_rows()'s own
 * logic (type dispatch, the 2*(start+num_rows) over-fetch math, "$id" parsing, the
 * Api\Link::titles() ACL-drop/total-adjustment, and the final slice+array_values()) from a live
 * RAG/fulltext backend.
 *
 * Found+fixed one bug while adding this coverage (2026-09-18): the ACL-title merge used array
 * union (`$rows[$row_id] = $rows[$row_id]+['title'=>$title,...]`), which keeps a key's
 * existing value when the key already exists - and $rows[$row_id] always already carries a
 * 'title' key (return_all is always used here), so the freshly resolved, ACL-checked title
 * from Api\Link::titles() was silently discarded in favor of whatever the search result
 * already had. Not an access-control issue (a row only survives at all when $title is truthy),
 * just a display-staleness one - fixed via direct key assignment instead of union.
 */
class UiGetRowsTest extends Api\LoggedInTest
{
	private array $contactIds = [];

	protected function tearDown() : void
	{
		if ($this->contactIds)
		{
			$bo = new \addressbook_bo();
			foreach ($this->contactIds as $id)
			{
				$bo->delete($id);
			}
			$this->contactIds = [];
		}
		parent::tearDown();
	}

	private function createRealContact() : int
	{
		$bo = new \addressbook_bo();
		$contact = [
			'n_family' => 'RagGetRowsTest', 'n_given' => 'X'.uniqid(),
			'owner' => $GLOBALS['egw_info']['user']['account_id'],
		];
		$id = $bo->save($contact);
		$this->assertIsInt($id, 'failed to create the addressbook test fixture');
		$this->contactIds[] = $id;
		return $id;
	}

	/**
	 * A stub Embedding whose search()/searchFulltext()/searchEmbeddings() return a canned
	 * result and record how they were called, instead of touching a real DB/HTTP client
	 */
	private function makeStub(array $result, int $total) : Embedding
	{
		return new class($result, $total) extends Embedding {
			public array $calls = [];

			public function __construct(private array $result, private int $totalToSet)
			{
			}

			public function search(string $pattern, $app=null, int $start=0, int $num_rows=50,
				bool $return_all=false, string $order='default', ?float $max_distance=null, float $min_relevance=0.05,
				?array $app_ids=null, ?string $app_filter=null) : array
			{
				$this->calls[] = ['search', $pattern, $app, $start, $num_rows, $return_all, $order];
				$this->total = $this->totalToSet;
				return $this->result;
			}

			public function searchFulltext(string $pattern, $app=null, int $start=0, int $num_rows=50,
				bool $return_all=false, string $order='default', float $min_relevance=0.05, ?string $mode=null,
				?array $app_ids=null, bool $require_all=true, ?string $app_filter=null) : array
			{
				$this->calls[] = ['searchFulltext', $pattern, $app, $start, $num_rows, $return_all, $order];
				$this->total = $this->totalToSet;
				return $this->result;
			}

			public function searchEmbeddings(string $pattern, $app=null, int $start=0, int $num_rows=50,
				bool $return_all=false, string $order='default', ?float $max_distance=null,
				?array $app_ids=null, ?string $app_filter=null) : array
			{
				$this->calls[] = ['searchEmbeddings', $pattern, $app, $start, $num_rows, $return_all, $order];
				$this->total = $this->totalToSet;
				return $this->result;
			}
		};
	}

	private function uiWithStub(Embedding $stub) : Ui
	{
		$ui = new Ui();
		$property = new ReflectionProperty(Ui::class, 'embedding');
		$property->setAccessible(true);
		$property->setValue($ui, $stub);
		return $ui;
	}

	public function testEmptySearchReturnsZeroWithoutTouchingEmbedding()
	{
		// no stub needed: get_rows() must return before ever calling $this->embedding
		$ui = new Ui();
		$rows = null;
		$readonlys = null;

		$total = $ui->get_rows(['search' => '', 'col_filter' => []], $rows, $readonlys);

		$this->assertSame(0, $total);
		$this->assertSame([], $rows);
	}

	public function testDispatchesToSearchMethodByColFilterType()
	{
		$cases = [
			[null, 'search'],       // col_filter['type'] absent -> defaults to 'hybrid'
			['hybrid', 'search'],
			['fulltext', 'searchFulltext'],
			['rag', 'searchEmbeddings'],
		];
		foreach ($cases as [$type, $expectedMethod])
		{
			$stub = $this->makeStub([], 0);
			$ui = $this->uiWithStub($stub);
			$rows = null;
			$readonlys = null;
			$colFilter = ['apps' => []];
			if ($type !== null)
			{
				$colFilter['type'] = $type;
			}

			$ui->get_rows(['search' => 'pattern', 'col_filter' => $colFilter, 'order' => 'default'], $rows, $readonlys);

			$this->assertSame($expectedMethod, $stub->calls[0][0] ?? null,
				'col_filter.type = '.var_export($type, true));
		}
	}

	public function testPassesOverfetchCountAndOrderSortToEmbedding()
	{
		$stub = $this->makeStub([], 0);
		$ui = $this->uiWithStub($stub);
		$rows = null;
		$readonlys = null;

		$ui->get_rows([
			'search' => 'pattern', 'col_filter' => ['apps' => []],
			'order' => 'modified', 'sort' => 'DESC', 'start' => 10, 'num_rows' => 20,
		], $rows, $readonlys);

		[$method, $pattern, $app, $start, $numRows, $returnAll, $order] = $stub->calls[0];
		$this->assertSame('search', $method);
		$this->assertSame('pattern', $pattern);
		$this->assertSame(0, $start,
			"always starts at 0 - get_rows() doesn't know in advance how many rows the ACL filter will drop");
		$this->assertSame(2 * (10 + 20), $numRows,
			'over-fetches to allow for rows the ACL filter removes afterward');
		$this->assertTrue($returnAll);
		$this->assertSame('modified DESC', $order);
	}

	public function testZeroIdSentinelIsSkipped()
	{
		// Embedding's own docblock: id 0 signals "nothing found", used to avoid an SQL error
		$stub = $this->makeStub([0 => ['distance' => 1.0]], 0);
		$ui = $this->uiWithStub($stub);
		$rows = null;
		$readonlys = null;

		$total = $ui->get_rows(['search' => 'pattern', 'col_filter' => ['apps' => []], 'order' => 'default'], $rows, $readonlys);

		$this->assertSame([], $rows);
		$this->assertSame(0, $total);
	}

	public function testAclFiltersOutInaccessibleEntriesAndAdjustsTotal()
	{
		$realId = $this->createRealContact();
		$fakeId = 999999999;   // does not exist -> Api\Link::titles() gives it no truthy title
		$stub = $this->makeStub([
			"addressbook:$realId" => ['distance' => 0.1],
			"addressbook:$fakeId" => ['distance' => 0.2],
		], 5);   // pretend total across all pages is 5
		$ui = $this->uiWithStub($stub);
		$rows = null;
		$readonlys = null;

		$total = $ui->get_rows(['search' => 'pattern', 'col_filter' => ['apps' => ['addressbook']], 'order' => 'default'], $rows, $readonlys);

		$this->assertCount(1, $rows, 'the inaccessible/nonexistent entry must be dropped');
		$this->assertSame("addressbook:$realId", $rows[0]['id']);
		$this->assertSame('addressbook', $rows[0]['app']);
		$this->assertEquals($realId, $rows[0]['app_id']);
		$this->assertNotEmpty($rows[0]['title']);
		$this->assertSame(4, $total, 'total must be reduced by 1 for the dropped entry');
	}

	public function testFreshAclTitleOverwritesStaleTitleFromSearchResult()
	{
		$realId = $this->createRealContact();
		// simulates a row that already carries a (stale/wrong, e.g. cached-at-index-time)
		// title - return_all always includes a 'title' key, so this is the normal shape
		$stub = $this->makeStub([
			"addressbook:$realId" => ['distance' => 0.1, 'title' => 'STALE-OR-WRONG-TITLE'],
		], 1);
		$ui = $this->uiWithStub($stub);
		$rows = null;
		$readonlys = null;

		$ui->get_rows(['search' => 'pattern', 'col_filter' => ['apps' => ['addressbook']], 'order' => 'default'], $rows, $readonlys);

		$this->assertCount(1, $rows);
		$this->assertNotSame('STALE-OR-WRONG-TITLE', $rows[0]['title'],
			'the freshly ACL-resolved title must win over whatever the search result already carried');
		$this->assertNotEmpty($rows[0]['title']);
	}

	public function testNumericIdUsesFirstColFilterAppAsFallback()
	{
		$realId = $this->createRealContact();
		// a plain int key (no "app:id" string) happens when Embedding::search() is scoped to
		// a single string $app - get_rows() must resolve the app from col_filter['apps']
		$stub = $this->makeStub([$realId => ['distance' => 0.05]], 1);
		$ui = $this->uiWithStub($stub);
		$rows = null;
		$readonlys = null;

		$ui->get_rows(['search' => 'pattern', 'col_filter' => ['apps' => ['addressbook']], 'order' => 'default'], $rows, $readonlys);

		$this->assertCount(1, $rows);
		$this->assertSame('addressbook', $rows[0]['app']);
		$this->assertEquals($realId, $rows[0]['app_id']);
	}

	public function testPaginationSlicesAndReindexesFromZero()
	{
		$ids = [$this->createRealContact(), $this->createRealContact(), $this->createRealContact()];
		$result = [];
		foreach ($ids as $i => $id)
		{
			$result["addressbook:$id"] = ['distance' => $i / 10];
		}
		$stub = $this->makeStub($result, 3);
		$ui = $this->uiWithStub($stub);
		$rows = null;
		$readonlys = null;

		$ui->get_rows(['search' => 'pattern', 'col_filter' => ['apps' => ['addressbook']],
			'order' => 'default', 'start' => 1, 'num_rows' => 1], $rows, $readonlys);

		$this->assertCount(1, $rows, 'num_rows=1 must return exactly one row');
		$this->assertSame("addressbook:{$ids[1]}", $rows[0]['id'], 'start=1 must skip the first entry');
		$this->assertSame(0, array_key_first($rows), 'the final $rows must be reindexed from 0, not keep the "app:id" keys');
	}
}
