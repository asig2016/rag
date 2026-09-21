<?php
/**
 * EGroupware RAG: Test ApiHandler::propfind_generator()'s per-entry ACL filtering
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
 * doc/ai/projects/rag-test-coverage.md's priority-6 entry: propfind_generator() is the one
 * part of ApiHandler's REST surface ApiHandlerRestSurfaceTest.php explicitly left uncovered,
 * pending a way to construct a working Api\CalDAV/Handler pair without a full HTTP round trip.
 *
 * Constructs a real (not reflection-bypassed) Api\CalDAV instance - cheap and safe to build
 * directly under LoggedInTest, the same pattern
 * addressbook/tests/GroupDavMemberDeserializationTest.php already uses - and a real ApiHandler
 * built from it, with a stub Embedding swapped into the typed `bo` property via
 * ReflectionProperty (same technique as EmbeddingHybridSearchTest.php/UiGetRowsTest.php) so
 * the search results themselves are canned, while everything downstream (Api\Link::titles()
 * ACL resolution, get_etag(), add_resource()) runs for real.
 */
class ApiHandlerPropfindAclTest extends Api\LoggedInTest
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
		// Api\CalDAV::__construct() (used by handlerWithStubBo()) unconditionally calls
		// set_exception_handler() and never restores it - harmless in a real request (the
		// process ends), but leaves global state changed for whatever runs after it in the
		// same PHPUnit process. See addressbook/tests/GroupDavMemberDeserializationTest.php's
		// tearDown() for the same fix - one restore_exception_handler() call undoes exactly
		// the one push (PHP keeps a LIFO stack).
		restore_exception_handler();
		parent::tearDown();
	}

	private function createRealContact() : int
	{
		$bo = new \addressbook_bo();
		$contact = [
			'n_family' => 'ApiHandlerPropfindTest', 'n_given' => 'X'.uniqid(),
			'owner' => $GLOBALS['egw_info']['user']['account_id'],
		];
		$id = $bo->save($contact);
		$this->assertIsInt($id, 'failed to create the addressbook test fixture');
		$this->contactIds[] = $id;
		return $id;
	}

	private function handlerWithStubBo(array $searchResult) : ApiHandler
	{
		$stub = new class($searchResult) extends Embedding {
			public function __construct(private array $result)
			{
			}

			public function search(string $pattern, $app=null, int $start=0, int $num_rows=50,
				bool $return_all=false, string $order='default', ?float $max_distance=null, float $min_relevance=0.05,
				?array $app_ids=null, ?string $app_filter=null) : array
			{
				$this->total = count($this->result);
				// propfind_generator() loops chunk-by-chunk while search() keeps returning a
				// non-empty result - only return our canned rows for the first chunk, or the
				// loop never terminates (a real search() naturally runs out of rows)
				return $start === 0 ? $this->result : [];
			}
		};

		$handler = new ApiHandler('rag', new Api\CalDAV());
		$property = new ReflectionProperty(ApiHandler::class, 'bo');
		$property->setAccessible(true);
		$property->setValue($handler, $stub);
		return $handler;
	}

	public function testInaccessibleEntryIsDroppedNotIncluded()
	{
		$realId = $this->createRealContact();
		$fakeId = 999999999;   // Api\Link::titles() returns no truthy title for a nonexistent id

		$handler = $this->handlerWithStubBo([
			"addressbook:$realId" => ['modified' => new Api\DateTime(), 'distance' => 0.1, 'description' => 'a', 'extra' => []],
			"addressbook:$fakeId" => ['modified' => new Api\DateTime(), 'distance' => 0.2, 'description' => 'b', 'extra' => []],
		]);

		$filter = ['search' => 'pattern', 'apps' => null];
		$entries = iterator_to_array($handler->propfind_generator('/rag/', $filter, []));
		$paths = array_column($entries, 'path');

		$this->assertNotEmpty(array_filter($paths, static fn($p) => str_contains($p, "addressbook:$realId")),
			'the accessible entry must be present');
		$this->assertEmpty(array_filter($paths, static fn($p) => str_contains($p, "addressbook:$fakeId")),
			'the inaccessible entry must not be present');
	}

	public function testAllAccessibleEntriesArePresent()
	{
		$firstId = $this->createRealContact();
		$secondId = $this->createRealContact();

		$handler = $this->handlerWithStubBo([
			"addressbook:$firstId" => ['modified' => new Api\DateTime(), 'distance' => 0.1, 'description' => 'a', 'extra' => []],
			"addressbook:$secondId" => ['modified' => new Api\DateTime(), 'distance' => 0.2, 'description' => 'b', 'extra' => []],
		]);

		$filter = ['search' => 'pattern', 'apps' => null];
		$entries = iterator_to_array($handler->propfind_generator('/rag/', $filter, []));

		$this->assertCount(2, $entries);
	}
}
