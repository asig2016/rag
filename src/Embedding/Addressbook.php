<?php
/**
 * EGroupware RAG system
 *
 * @package addressbook
 * @subpackage rag
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @copyright 2025 by Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag\Embedding;

use EGroupware\Api;
use EGroupware\Rag\Embedding\Base;

/**
 * Plugin for Addressbook
 */
class Addressbook extends Base
{
	const APP = 'addressbook';
	const TABLE = 'egw_addressbook';
	const ID = 'contact_id';
	const MODIFIED = 'contact_modified';
	const CREATED = 'contact_created';
	const TITLE = 'n_fileas';
	const DESCRIPTION = 'contact_note';
	protected static $additional_cols = [];
	const NOT_DELETED = "contact_tid<>'D'";
	const EXTRA_TABLE = 'egw_addressbook_extra';
	const EXTRA_ID = 'contact_id';
	const EXTRA_NAME = 'contact_name';
	const EXTRA_VALUE = 'contact_value';
	// RAG makes only sense for a long note
	const RAG_EXTRA_CONDITION = 'LENGTH(contact_note)>50';

	/**
	 * Get all text-fields with meaningful content to fulltext-index
	 *
	 * Ignoring telephone number, for with we already have a good/better search.
	 */
	public static function initStatic()
	{
		foreach($GLOBALS['egw']->db->get_table_definitions('api', self::TABLE)['fd'] as $col => $data)
		{
			if (($data['type'] === 'text' || $data['type'] === 'varchar' && $data['precision'] > 8) &&
				!str_starts_with($col, 'tel_') &&
				!in_array($col, [self::TITLE, self::DESCRIPTION, 'contact_uid', 'carddav_name', 'contact_pubkey',
					'contact_freebusy_uri', 'contact_calendar_uri', 'contact_tz', 'contact_geo']))
			{
				self::$additional_cols[] = $col;
			}
		}
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
		if (!$fulltext)
		{
			// only index description/note for RAG, but keep the title/n_fileas for the chunk-header
			$row = array_intersect_key($row, array_flip([self::ID, self::MODIFIED, self::TITLE, self::DESCRIPTION]));
		}
	}
}
Addressbook::initStatic();