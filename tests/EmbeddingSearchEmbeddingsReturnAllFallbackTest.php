<?php
/**
 * EGroupware RAG: Test searchEmbeddings()'s $return_all fallback for entries not (yet)
 * fulltext-indexed
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
 * doc/ai/projects/rag-test-coverage.md's priority-4 follow-up entry.
 *
 * searchEmbeddings()'s $return_all path falls back to asking the app's plugin directly for
 * title/description when a matched row isn't (yet) fulltext-indexed (ft_title etc. come back
 * NULL from the LEFT JOIN). Two bugs made this fallback a complete no-op:
 *
 * 1. It called getUpdated(true, ['data' => ['app'=>..., 'id'=>...]]) - app/id nested under
 *    'data', but getUpdated()'s hook-data guard checks a TOP-LEVEL 'app'/'id' key that shape
 *    doesn't have. The guard never fired, so the query was never scoped to this one entry at
 *    all - it read up to CHUNK_SIZE arbitrary stale rows for the app instead.
 *
 * 2. Even with correct scoping, `$row += $entry` would never have worked: array `+=` keeps a
 *    key's existing value when the key already exists, regardless of whether that value is
 *    null - and $row['title']/['description'] both already exist (as null, from the LEFT
 *    JOIN's unmatched columns) before this fallback runs. Confirmed directly:
 *    `$row = ['title'=>null]; $row += ['title'=>'x'];` leaves title null. On top of that,
 *    getUpdated() yields the app's own raw column names (e.g. n_fileas), never literally
 *    'title'/'description', so even a value-preserving merge would have landed under the
 *    wrong keys.
 *
 * Fixed by scoping getUpdated() the same way Embedding::read() does (top-level app/id,
 * $ignoreStaleness=true - this entry's current data regardless of index freshness) and
 * explicitly assigning $row['title']/['description']/['extra'] from the plugin's real column
 * names instead of relying on array union.
 */
class EmbeddingSearchEmbeddingsReturnAllFallbackTest extends Api\LoggedInTest
{
	const SCHEMA_APP = 'api';   // egw_addressbook's schema is owned by 'api', not 'addressbook'

	private ?int $contactId = null;
	private array $hashesToClean = [];

	protected function tearDown() : void
	{
		$db = $GLOBALS['egw']->db;
		if ($this->contactId)
		{
			$db->delete('egw_addressbook', ['contact_id' => $this->contactId], __LINE__, __FILE__, self::SCHEMA_APP);
			$db->delete(Embedding::TABLE, ['rag_app' => 'addressbook', 'rag_app_id' => $this->contactId],
				__LINE__, __FILE__, Embedding::APP);
			$this->contactId = null;
		}
		if ($this->hashesToClean)
		{
			$db->delete(Embedding::CACHE_TABLE, [
				Embedding::CACHE_HASH => $this->hashesToClean,
			], __LINE__, __FILE__, Embedding::APP);
			$this->hashesToClean = [];
		}
		parent::tearDown();
	}

	/**
	 * A real addressbook contact inserted directly into egw_addressbook (bypassing
	 * addressbook_bo::save()'s notify-hook, which would otherwise fulltext-index it
	 * automatically) so it exists for getUpdated() to find, but is NOT fulltext-indexed -
	 * exactly the condition that triggers searchEmbeddings()'s plugin fallback.
	 */
	private function insertUnindexedContact(string $fileas, string $note) : int
	{
		$db = $GLOBALS['egw']->db;
		$db->insert('egw_addressbook', [
			'contact_owner' => $GLOBALS['egw_info']['user']['account_id'],
			'contact_creator' => $GLOBALS['egw_info']['user']['account_id'],
			'contact_modified' => time(),
			'n_family' => 'ReturnAllFallbackTest',
			'n_fileas' => $fileas,
			'contact_note' => $note,
		], false, __LINE__, __FILE__, self::SCHEMA_APP);
		return (int)$db->get_last_insert_id('egw_addressbook', 'contact_id');
	}

	private static function unitVector(int $hotIndex) : array
	{
		$v = array_fill(0, 1024, 0.0);
		$v[$hotIndex] = 1.0;
		return $v;
	}

	private function seedEmbeddingRow(int $contactId, array $vector) : void
	{
		$GLOBALS['egw']->db->insert(Embedding::TABLE, [
			'rag_app' => 'addressbook',
			'rag_app_id' => $contactId,
			'rag_chunk' => 0,
			'rag_hash' => hash('sha256', 'addressbook'.$contactId, true),
			'rag_embedding' => $vector,
			'rag_updated' => new Api\DateTime(),
		], false, __LINE__, __FILE__, Embedding::APP);
	}

	private function embeddingWithFakeClient(array $queryEmbedding) : Embedding
	{
		// construct Embedding first: its file's require_once '../vendor/autoload.php' is what
		// registers the \OpenAI autoloader (see EmbeddingSearchEmbeddingsTest.php's docblock)
		$embedding = new Embedding();

		$httpClient = new class($queryEmbedding) implements ClientInterface {
			public function __construct(private array $embedding)
			{
			}

			public function sendRequest(RequestInterface $request) : ResponseInterface
			{
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

	public function testFallbackFillsRealTitleAndDescriptionForUnindexedEntry()
	{
		$this->contactId = $this->insertUnindexedContact('Fallback, Test', str_repeat('real note content. ', 5));

		$vector = self::unitVector(0);
		$this->seedEmbeddingRow($this->contactId, $vector);

		$pattern = 'unique pattern '.uniqid();
		$this->addToHashCleanup($pattern);

		$embedding = $this->embeddingWithFakeClient($vector);
		$result = $embedding->searchEmbeddings($pattern, 'addressbook', 0, 50, true, 'default', 0.5);

		$this->assertArrayHasKey($this->contactId, $result);
		$this->assertSame('Fallback, Test', $result[$this->contactId]['title'],
			'title must come from the real plugin column (n_fileas), not stay null or land under the wrong key');
		$this->assertStringContainsString('real note content', $result[$this->contactId]['description']);
	}

	private function addToHashCleanup(string $pattern) : void
	{
		$this->hashesToClean[] = hash('sha256', $pattern, true);
	}
}
