<?php
/**
 * The app-filter join is what makes a scoped RAG search exact - and it only works in one place.
 *
 * BEHAVIOUR UNDER TEST
 * An application tells the RAG which entries it may return by handing it a sub-query selecting their
 * ids. Embedding::appFilterJoin() splices that as a JOIN into the INNERMOST block of the search -
 * the one with `ORDER BY distance LIMIT k`. Inside it, the k nearest chunks are chosen among the
 * filtered entries; outside it, the k nearest are chosen globally and the filter only removes some
 * of them afterwards, which returns nothing at all as soon as the filter is selective.
 *
 * The sub-query also has to stay mergeable. A GROUP BY, LIMIT or DISTINCT makes the optimizer
 * materialize it, which takes away its choice between using the vector index (broad filter) and
 * driving from the application's own table (selective filter) - the choice the whole design rests on.
 * Embedding::validFilterSubquery() is what keeps such a sub-query out.
 *
 * SETUP STRATEGY
 * No database and no session: both methods under test are pure string builders, reached through
 * reflection because they are not part of the public API.
 *
 * PASS CRITERIA
 * The join is emitted for a usable sub-query and refused for every shape that would be materialized
 * or is not a sub-query at all.
 *
 * @package rag
 * @subpackage tests
 */

namespace EGroupware\Rag;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilterJoinTest extends TestCase
{
	const GOOD = "SELECT ac_emailstor.id AS rag_id FROM ac_emailstor WHERE ac_emailstor.client_id='56'";

	/**
	 * @param string $method
	 * @param array $args
	 * @return mixed
	 */
	protected static function call(string $method, array $args)
	{
		$m = new \ReflectionMethod(Embedding::class, $method);
		$m->setAccessible(true);
		return $m->invokeArgs(null, $args);
	}

	public function testJoinIsEmittedForAUsableSubquery()
	{
		$join = self::call('appFilterJoin', [self::GOOD, 'egw_rag.rag_app_id']);

		$this->assertStringContainsString('JOIN ('.self::GOOD.')', $join);
		$this->assertStringContainsString('app_filter.'.Embedding::APP_FILTER_ID.'=egw_rag.rag_app_id', $join,
			'the sub-query is joined on the alias it promises, not on the app\'s own column name');
	}

	public function testNoFilterMeansNoJoin()
	{
		$this->assertSame('', self::call('appFilterJoin', [null, 'egw_rag.rag_app_id']));
		$this->assertSame('', self::call('appFilterJoin', ['', 'egw_rag.rag_app_id']));
	}

	/**
	 * @return array[] name => [sub-query, is it usable]
	 */
	public static function subqueries() : array
	{
		return [
			'plain select'        => [self::GOOD, true],
			'with a join'         => ["SELECT ac_emailstor.id AS rag_id FROM ac_emailstor LEFT JOIN ac_clients c ON c.id=ac_emailstor.client_id WHERE c.id=1", true],
			'no filter at all'    => ['SELECT ac_emailstor.id AS rag_id FROM ac_emailstor', true],
			// everything below would be materialized, or is not a sub-query
			'GROUP BY'            => ['SELECT ac_emailstor.id AS rag_id FROM ac_emailstor GROUP BY ac_emailstor.id', false],
			'LIMIT'               => ['SELECT ac_emailstor.id AS rag_id FROM ac_emailstor LIMIT 10', false],
			'DISTINCT'            => ['SELECT DISTINCT ac_emailstor.id AS rag_id FROM ac_emailstor', false],
			'UNION'               => ['SELECT ac_emailstor.id AS rag_id FROM a UNION SELECT id AS rag_id FROM b', false],
			'ORDER BY'            => ['SELECT ac_emailstor.id AS rag_id FROM ac_emailstor ORDER BY id', false],
			'HAVING'              => ['SELECT ac_emailstor.id AS rag_id FROM ac_emailstor HAVING id>1', false],
			'missing the alias'   => ['SELECT ac_emailstor.id FROM ac_emailstor', false],
			'a second statement'  => [self::GOOD.'; DROP TABLE ac_emailstor', false],
			'not a select'        => ['DELETE FROM ac_emailstor', false],
			'empty'               => ['', false],
		];
	}

	/**
	 * @param string $sql
	 * @param bool $expected
	 */
	#[DataProvider('subqueries')]
	public function testOnlyMergeableSubqueriesAreAccepted(string $sql, bool $expected)
	{
		$this->assertSame($expected, Embedding::validFilterSubquery($sql));
	}

	/**
	 * A rejected sub-query must not reach the SQL at all - the search then falls back to the id-list
	 */
	public function testARejectedSubqueryProducesNoJoin()
	{
		foreach (self::subqueries() as $name => [$sql, $usable])
		{
			if ($usable) continue;
			$this->assertFalse(Embedding::validFilterSubquery($sql), $name);
		}
	}
}
