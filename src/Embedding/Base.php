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

namespace EGroupware\Rag\Embedding;

use EGroupware\Api;
use EGroupware\Rag\Embedding;

/**
 * Base class for app-specific RAG plugins
 *
 * In almost all cases, you only want to set the constants at the top, and maybe reimplement processRow().
 */
abstract class Base
{
	/**
	 * v-- need to be overwritten in plugin class
	 */
	/**
	 * App-name
	 */
	const APP = '';
	/**
	 * Main table
	 */
	const TABLE = '';
	/**
	 * Auto-ID column of main-table
	 */
	const ID = '';
	/**
	 * Modification time column
	 */
	const MODIFIED = '';
	/**
	 * Creation time column, only needed if modification time is initially NULL
	 */
	const CREATED = '';
	/**
	 * Type of modified column: 'int' or 'timestamp'
	 */
	const MODIFIED_TYPE = 'int';
	/**
	 * Title column
	 */
	const TITLE = '';
	/**
	 * Description column
	 */
	const DESCRIPTION = '';
	/**
	 * @var string[] additional cols to index
	 */
	protected static $additional_cols = [];
	/**
	 * SQL fragment to exclude deleted entries e.g. 'deleted IS NOT NULL'
	 */
	const NOT_DELETED = '';
	/**
	 * SQL fragment to exclude entries from being indexed by the AG, e.g. based on their length
	 */
	const RAG_EXTRA_CONDITION = '';
	/**
	 * Custom fields table
	 */
	const EXTRA_TABLE = '';
	/**
	 * Should be identical to ID
	 */
	const EXTRA_ID = '';
	/**
	 * Name of CF
	 */
	const EXTRA_NAME = '';
	/**
	 * Value of CF
	 */
	const EXTRA_VALUE = '';
	/**
	 * ^-- need to be overwritten in plugin class
	 */

	/**
	 * Number of entries queried from the DB
	 */
	const CHUNK_SIZE = 10;

	/**
	 * @var Api\Db;
	 */
	protected $db;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->db = $GLOBALS['egw']->db;
	}

	/**
	 * Allows row-specific modifications without overwriting getUpdated()
	 *
	 * @param array|null $row
	 * @param bool $fulltext
	 * @return void
	 */
	protected function processRow(array &$row=null, bool $fulltext=false)
	{

	}

	/**
	 * Context prefixed to every chunk of an entry
	 *
	 * Without it only the first chunk of a long text tells which entry it belongs to, and the following ones
	 * can not be matched by e.g. the subject of the entry.
	 * Reimplement it to add app-specific context like a sender or a category, but keep it short and stable:
	 * changing it re-embeds every entry of the app!
	 *
	 * @param array $row
	 * @return string e.g. "[tracker] Can not print invoices\n"
	 */
	public function chunkHeader(array $row) : string
	{
		$title = trim((string)($row[static::TITLE] ?? ''));

		return '['.static::APP.']'.($title !== '' ? ' '.$title : '')."\n";
	}

	/**
	 * Parts of an entry to index on their own, e.g. replies or files
	 *
	 * The entry's own text is always indexed as part '', these are additional ones. Returning fewer parts than
	 * the last run removes the ones no longer returned from both indexes.
	 *
	 * @param array $row as returned by getUpdated()
	 * @param bool $fulltext false: for the RAG/embeddings, true: for the fulltext index
	 * @return iterable of arrays with keys part (e.g. "reply:123" or "file:<fs_id>"), text and optional
	 *  title, modified and header (defaulting to the ones of the entry)
	 */
	public function getParts(array $row, bool $fulltext=false) : iterable
	{
		return [];
	}

	/**
	 * Get updated / not yet indexed app-entries
	 *
	 * Should only be overwritten in plugin classes if processRow is not sufficient!.
	 *
	 * @param bool $fulltext false: check the rag, true: check fulltext index
	 * @param ?array $hook_data null or data from notify-all hook, to just emit this entry
	 * @return \Generator<array>
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function getUpdated(bool $fulltext=false, ?array $hook_data=null)
	{
		$where = [];
		$cols = array_merge([static::ID, static::MODIFIED, static::TITLE, static::DESCRIPTION], static::$additional_cols);
		// check / process hook-data to not query entry again, if already contained
		if ($hook_data && $hook_data['app'] === static::APP && !empty($hook_data['id']))
		{
			$where[static::ID] = $hook_data['id'];
			$entries = self::getRowFromNotifyHookData($hook_data, $cols);
		}
		if (!$fulltext && static::RAG_EXTRA_CONDITION)
		{
			$where[] = static::RAG_EXTRA_CONDITION;
		}
		$join = $this->getJoin($where, $fulltext);
		// Page by id and never from offset 0 again: an entry indexed successfully drops out of the join anyway,
		// but one failing every time (or never matching the modified condition) would otherwise be selected again
		// and again, and CHUNK_SIZE of them would keep this loop running until Embedding::$max_runtime.
		$last_id = 0;
		do
		{
			$r = 0;
			$page_where = $where;
			$page_where[] = static::TABLE.'.'.static::ID.' > '.(int)$last_id;
			foreach ($entries ?? $this->db->select(static::TABLE, $cols,
				$page_where, __LINE__, __FILE__, 0,
				'ORDER BY ' . static::TABLE.'.'.static::ID . ' ASC', '', static::CHUNK_SIZE, $join) as $row)
			{
				$last_id = max($last_id, (int)$row[static::ID]);
				if (!empty($row[static::MODIFIED]) && !is_object($row[static::MODIFIED]))
				{
					// hook-data is in user-timezone, while queried data is in server-timezone
					$row[static::MODIFIED] = new Api\DateTime(static::MODIFIED_TYPE === 'int' && is_numeric($row[static::MODIFIED]) ?
						(int)$row[static::MODIFIED] : $row[static::ID], isset($entries) ? Api\DateTime::$user_timezone : Api\DateTime::$server_timezone);
				}
				$this->processRow($row, $fulltext);
				$row = $this->getExtraTexts($row[static::ID], $row, $hook_data['data']??null);
				++$r;
				yield $row;
			}
		} while ($r === static::CHUNK_SIZE);
	}

	/**
	 * Get join for egw_rag(_fulltext) table to check of not yet updated/created embeddings/fulltext index
	 *
	 * @param array &$where
	 * @param bool $fulltext false: check the rag, true: check fulltext index
	 * @return string
	 */
	protected function getJoin(array &$where=[], bool $fulltext=false)
	{
		if (static::NOT_DELETED)
		{
			$where[] = static::NOT_DELETED; // no need to embed deleted entries
		}
		if (!$fulltext)
		{
			$where[] = '('.Embedding::EMBEDDING_UPDATED.' IS NULL OR '.Embedding::EMBEDDING_UPDATED.'<'.$this->modified().')';

			return 'LEFT JOIN '.Embedding::TABLE.' ON '.
				Embedding::EMBEDDING_APP.'='.$this->db->quote(static::APP).' AND '.
				Embedding::EMBEDDING_APP_ID.'='.static::ID.' AND '.Embedding::EMBEDDING_PART."='' AND ".
				Embedding::EMBEDDING_CHUNK.'=0';
		}
		$where[] = '('.Embedding::FULLTEXT_UPDATED.' IS NULL OR '.Embedding::FULLTEXT_UPDATED.'<'.$this->modified().')';

		return 'LEFT JOIN '.Embedding::FULLTEXT_TABLE.' ON '.
			Embedding::FULLTEXT_APP.'='.$this->db->quote(static::APP).' AND '.
			Embedding::FULLTEXT_APP_ID.'='.static::ID.' AND '.Embedding::FULLTEXT_PART."=''";
	}

	/**
	 * Get values from textual custom-fields
	 *
	 * @param int $id
	 * @param array $texts
	 * @param array|null $hook_data
	 * @return array
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	protected function getExtraTexts(int $id, array $texts=[], ?array $hook_data=null) : array
	{
		static $cfs=null;
		if (!isset($cfs))
		{
			$cfs = array_filter(Api\Storage\Customfields::get(static::APP), static function($cf)
			{
				return in_array($cf['type'], ['text', 'htmlarea']);
			});
		}
		if ($cfs)
		{
			// check if we hook-data already supplies ALL values, then and only then use it instead querying the DB
			if ($hook_data)
			{
				$rows = [];
				foreach(array_keys($cfs) as $cf)
				{
					if (!array_key_exists('#'.$cf, $hook_data))
					{
						$rows = null;
						break;
					}
					if (!empty($hook_data['#'.$cf]) && !trim($hook_data['#'.$cf]))
					{
						$rows[] = [
							static::EXTRA_ID    => $id,
							static::EXTRA_NAME  => $cf,
							static::EXTRA_VALUE => $hook_data['#'.$cf],
						];
					}
				}
			}
			foreach($rows ?? $this->db->select(static::EXTRA_TABLE, [static::EXTRA_ID, static::EXTRA_NAME, static::EXTRA_VALUE], [
				static::EXTRA_ID => $id,
				static::EXTRA_NAME => array_keys($cfs),
			], __LINE__, __FILE__, false, 'ORDER BY '.static::EXTRA_NAME, static::APP) as $row)
			{
				if ($cfs[$row[static::EXTRA_NAME]]['type'] == 'htmlarea')
				{
					$row[static::EXTRA_VALUE] = trim(strip_tags($row[static::EXTRA_VALUE]));
				}
				$texts[$row[STATIC::EXTRA_NAME]] = $row[static::EXTRA_VALUE];
			}
		}
		return $texts;
	}

	/**
	 * Check if hook-data contains all required columns and returns them in order (!)
	 *
	 * @param array $data hook-data incl. optional $data['data'] containing (partial) entry
	 * @param array $cols columns to query
	 * @return array[]|null array with one row containing values from $data['data'] for all $cols
	 */
	protected function getRowFromNotifyHookData(array $data, array $cols) : ?array
	{
		$row = [];
		foreach($cols as $col)
		{
			if (!array_key_exists($col, $data['data']??[]))
			{
				return null;
			}
			$row[$col] = $data['data'][$col];
			// if modified time is not set, used created time
			if ($col === static::MODIFIED && empty($row[$col]) && static::CREATED)
			{
				$row[$col] = $row[static::CREATED] ?? null;
			}
		}
		return [$row];
	}

	/**
	 * @return string ID column without table-prefix
	 */
	public function id() : string
	{
		return static::ID;
	}

	/**
	 * @param bool $alias true: return alias used, false: return alias used in search
	 * @return string table-name or -alias
	 */
	public function table(bool $alias=true) : string
	{
		return static::TABLE;
	}

	/**
	 * @return string SQL fragment to get modified timestamp
	 */
	public function modified() : string
	{
		$col = static::MODIFIED;
		if (static::CREATED)
		{
			$col = 'COALESCE('.$col.','.static::CREATED.')';
		}
		return static::MODIFIED_TYPE === 'int' ? $this->db->from_unixtime($col) : $col;
	}

	/**
	 * Purge deleted / no longer existing entries from the RAG's indexes (max once daily)
	 *
	 * @return void
	 */
	public function purgeDeleted()
	{
		Api\Cache::getInstance(__CLASS__, 'purge-'.self::APP, function()
		{
			try {
				// clean fulltext index
				$this->db->query('DELETE ' . Embedding::FULLTEXT_TABLE . ' FROM ' . Embedding::FULLTEXT_TABLE .
					' LEFT JOIN ' . static::TABLE . ' ON ' .
					Embedding::FULLTEXT_TABLE . '.' . Embedding::FULLTEXT_APP_ID . '=' . static::TABLE . '.' . static::ID .
					' WHERE ' . Embedding::FULLTEXT_APP . '=' . $this->db->quote(static::APP) .
					' AND (' . static::TABLE . '.' . static::ID . ' IS NULL' . (static::NOT_DELETED ? ' OR NOT ' . static::NOT_DELETED : '') . ')',
					__LINE__, __FILE__);
				// clean RAG
				$this->db->query('DELETE ' . Embedding::TABLE . ' FROM ' . Embedding::TABLE .
					' LEFT JOIN ' . static::TABLE . ' ON ' .
					Embedding::TABLE . '.' . Embedding::EMBEDDING_APP_ID . '=' . static::TABLE . '.' . static::ID .
					' WHERE ' . Embedding::EMBEDDING_APP . '=' . $this->db->quote(static::APP) .
					' AND (' . static::TABLE . '.' . static::ID . ' IS NULL' . (static::NOT_DELETED ? ' OR NOT ' . static::NOT_DELETED : '') . ')',
					__LINE__, __FILE__);
			}
			catch (Api\Db\Exception\InvalidSql $e)
			{
				// ignore exception, as VECTOR / RAG table might be missing
				_egw_log_exception($e);
			}
			return true;
		}, [], 86400);
	}
}