<?php
/**
 * EGroupware RAG: Test Embedding::searchEmbeddings()'s real vector-distance SQL against a real
 * MariaDB VECTOR column, with a fake embeddings HTTP client (no live network call)
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-5 entry (the still-open DB-integration half):
 * searchEmbeddings()'s own SQL (real VEC_DISTANCE_COSINE ranking, max_distance filtering,
 * SQL_CALC_FOUND_ROWS total) had no test at all.
 *
 * `Embedding::$client` is typed `?OpenAI\Client` (the library's `final` concrete class), so the
 * library's own official `OpenAI\Testing\ClientFake` (which implements `ClientContract`, not
 * `Client`) can't be assigned to it. Instead this injects a fake PSR-18 HTTP client via the same
 * `OpenAI::factory()->withHttpClient(...)->make()` path Embedding's own constructor uses, which
 * still returns a genuine `OpenAI\Client` - no live network call, no real API cost/flakiness.
 *
 * Fixture uses two orthogonal-ish unit-style vectors seeded directly into egw_rag under fake
 * app_ids (no FK constraint on that table), so VEC_DISTANCE_COSINE's ranking is deterministic
 * without needing real addressbook/fulltext content.
 */
class EmbeddingSearchEmbeddingsTest extends Api\LoggedInTest
{
	const DIM = 1024;
	// a synthetic app-name (egw_rag has no FK constraint on rag_app) guarantees zero pre-existing
	// rows in this shared dev DB - unlike a real app name (e.g. 'addressbook'), which already has
	// real, live-embedded content that would otherwise interfere with ranking/ordering assertions
	const FAKE_APP = 'ragtest_fixture';
	const NEAR_ID = 999001;
	const FAR_ID = 999002;

	private array $hashesToClean = [];

	protected function tearDown() : void
	{
		$db = $GLOBALS['egw']->db;
		$db->delete(Embedding::TABLE, [
			'rag_app' => self::FAKE_APP,
			'rag_app_id' => [self::NEAR_ID, self::FAR_ID],
		], __LINE__, __FILE__, Embedding::APP);
		if ($this->hashesToClean)
		{
			$db->delete(Embedding::CACHE_TABLE, [
				Embedding::CACHE_HASH => $this->hashesToClean,
			], __LINE__, __FILE__, Embedding::APP);
			$this->hashesToClean = [];
		}
		parent::tearDown();
	}

	private static function unitVector(int $hotIndex) : array
	{
		$v = array_fill(0, self::DIM, 0.0);
		$v[$hotIndex] = 1.0;
		return $v;
	}

	private function seedEmbedding(int $appId, array $vector) : void
	{
		$GLOBALS['egw']->db->insert(Embedding::TABLE, [
			'rag_app' => self::FAKE_APP,
			'rag_app_id' => $appId,
			'rag_chunk' => 0,
			'rag_hash' => hash('sha256', self::FAKE_APP.$appId, true),
			'rag_embedding' => $vector,
			'rag_updated' => new Api\DateTime(),
		], false, __LINE__, __FILE__, Embedding::APP);
	}

	/**
	 * @param array $queryEmbedding embedding the fake API returns for every call
	 * @param-out int $callCount incremented on every fake HTTP call, for asserting cache hits
	 */
	private function embeddingWithFakeClient(array $queryEmbedding, ?int &$callCount) : Embedding
	{
		// construct Embedding first: its file's require_once '../vendor/autoload.php' is what
		// registers the \OpenAI autoloader - referencing \OpenAI::factory() first, if this were
		// the process's very first touch of anything rag-related, fails with "Class OpenAI not
		// found" (only worked before by accident, since seedEmbedding() ran first and happened
		// to reference Embedding::TABLE)
		$embedding = new Embedding();

		$callCount = 0;
		$httpClient = new class($queryEmbedding, $callCount) implements ClientInterface {
			public function __construct(private array $embedding, private int &$callCount) {}

			public function sendRequest(RequestInterface $request) : ResponseInterface
			{
				$this->callCount++;
				$body = json_decode((string)$request->getBody(), true);
				$data = [];
				foreach ((array)($body['input'] ?? ['']) as $i => $text)
				{
					$data[] = ['object' => 'embedding', 'embedding' => $this->embedding, 'index' => $i];
				}
				$response = (new Psr17Factory())->createResponse(200)
					->withHeader('Content-Type', 'application/json');
				$response->getBody()->write(json_encode([
					'object' => 'list', 'model' => 'bge-m3', 'data' => $data,
					'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
				]));
				return $response;
			}
		};
		$client = \OpenAI::factory()
			->withHttpClient($httpClient)
			->withBaseUri('http://fake-embeddings.invalid/v1')
			->withApiKey('unused')
			->make();

		$property = new ReflectionProperty(Embedding::class, 'client');
		$property->setAccessible(true);
		$property->setValue($embedding, $client);

		return $embedding;
	}

	public function testRanksBySmallestDistanceAndFiltersByMaxDistance()
	{
		$this->seedEmbedding(self::NEAR_ID, self::unitVector(0));
		$this->seedEmbedding(self::FAR_ID, self::unitVector(1));   // orthogonal -> cosine distance 1

		$pattern = 'unique test pattern '.uniqid();
		$this->hashesToClean[] = hash('sha256', $pattern, true);

		$callCount = 0;
		$embedding = $this->embeddingWithFakeClient(self::unitVector(0), $callCount);

		// max_distance=0.5 excludes the orthogonal (distance≈1) far vector, keeps the identical one
		$result = $embedding->searchEmbeddings($pattern, self::FAKE_APP, 0, 50, false, 'default', 0.5);

		$this->assertSame(1, $callCount, 'a not-yet-cached pattern must call the embeddings API once');
		$this->assertArrayHasKey(self::NEAR_ID, $result);
		$this->assertArrayNotHasKey(self::FAR_ID, $result);
		$this->assertLessThan(0.1, $result[self::NEAR_ID], 'distance to an identical vector should be ~0');
	}

	public function testIncludesBothWithPermissiveMaxDistanceOrderedByDistance()
	{
		$this->seedEmbedding(self::NEAR_ID, self::unitVector(0));
		$this->seedEmbedding(self::FAR_ID, self::unitVector(1));

		$pattern = 'unique test pattern '.uniqid();
		$this->hashesToClean[] = hash('sha256', $pattern, true);

		$callCount = 0;
		$embedding = $this->embeddingWithFakeClient(self::unitVector(0), $callCount);

		$result = $embedding->searchEmbeddings($pattern, self::FAKE_APP, 0, 50, false, 'default', 1.5);

		$this->assertSame([self::NEAR_ID, self::FAR_ID], array_keys($result), 'closest match first');
		$this->assertLessThan($result[self::FAR_ID], $result[self::NEAR_ID]);
	}

	public function testSecondSearchForSamePatternHitsTheCacheInsteadOfCallingTheApiAgain()
	{
		$this->seedEmbedding(self::NEAR_ID, self::unitVector(0));

		$pattern = 'unique test pattern '.uniqid();
		$this->hashesToClean[] = hash('sha256', $pattern, true);

		$callCount = 0;
		$embedding = $this->embeddingWithFakeClient(self::unitVector(0), $callCount);

		$embedding->searchEmbeddings($pattern, self::FAKE_APP, 0, 50, false, 'default', 1.5);
		$this->assertSame(1, $callCount);

		$embedding->searchEmbeddings($pattern, self::FAKE_APP, 0, 50, false, 'default', 1.5);
		$this->assertSame(1, $callCount,
			'the same pattern searched again must hit the cache table via the sha256 lookup in '.
			'create(), not call the embeddings API a second time');

		$cached = $GLOBALS['egw']->db->select(Embedding::CACHE_TABLE, Embedding::CACHE_HASH, [
			Embedding::CACHE_HASH => hash('sha256', $pattern, true),
		], __LINE__, __FILE__, false, '', Embedding::APP)->fetchColumn();
		$this->assertNotFalse($cached, 'the pattern embedding should have been persisted to the cache table');
	}
}
