<?php
/**
 * EGroupware - Setup
 * https://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package rag
 * @subpackage setup
 */

use EGroupware\Api;
use EGroupware\Rag;

/**
 * Create fulltext index table
 *
 * @return string
 */
function rag_upgrade0_1_001()
{
	$GLOBALS['egw_setup']->oProc->CreateTable('egw_rag_fulltext',array(
		'fd' => array(
			'ft_id' => array('type' => 'auto','nullable' => False),
			'ft_app' => array('type' => 'ascii','precision' => '16','nullable' => False),
			'ft_app_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'ft_updated' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp'),
			'ft_title' => array('type' => 'varchar','precision' => '255'),
			'ft_description' => array('type' => 'longtext'),
			'ft_extra' => array('type' => 'longtext','meta' => 'json','comment' => 'all other textfields of the app as one JSON array')
		),
		'pk' => array('ft_id'),
		'fk' => array(),
		'ix' => array('ft_app_id',array('ft_title','ft_description','ft_extra', 'options' => array('mysql' => 'FULLTEXT'))),
		'uc' => array(array('ft_app','ft_app_id'))
	));

	return $GLOBALS['setup_info']['rag']['currentver'] = '0.1.002';
}

/**
 * Set (rag|ft)_updated to the exact modification timestamp of the entry, not the creation time of the embedding or fulltext index
 *
 * @return string
 * @throws Api\Db\Exception
 * @throws Api\Db\Exception\InvalidSql
 */
function rag_upgrade0_1_002()
{
	/** @var Api\Db $db */
	$db = $GLOBALS['egw_setup']->db;
	foreach(Rag\Embedding::plugins() as $app => $class)
	{
		/** @var Rag\Embedding\Base $plugin */
		$plugin = new $class;
		$db->query('UPDATE egw_rag_fulltext' .
			' JOIN ' . $plugin->table(false) . ' ON ft_app_id=' . $plugin->id() .
			' SET ft_updated=' . $plugin->modified() .
			' WHERE ft_app=' . $db->quote($app), __LINE__, __FILE__);
		$db->query('UPDATE egw_rag' .
			' JOIN ' . $plugin->table(false) . ' ON rag_app_id=' . $plugin->id() .
			' SET rag_updated=' . $plugin->modified() .
			' WHERE rag_app=' . $db->quote($app), __LINE__, __FILE__);
	}
	return $GLOBALS['setup_info']['rag']['currentver'] = '0.1.003';
}

function rag_upgrade0_1_003()
{
	$GLOBALS['egw_setup']->oProc->AddColumn('egw_rag','rag_hash',array(
		'type' => 'binary',
		'precision' => '32',
		'comment' => 'binary sha256 hash of chunk'
	));
	$GLOBALS['egw_setup']->oProc->CreateIndex('egw_rag', 'rag_hash');

	return $GLOBALS['setup_info']['rag']['currentver'] = '26.1.001';
}


/**
 * Search quality, part 1:
 * - vector index for the cosine distance all queries use (was built for the euclidean default and never used)
 * - cached search pattern embeddings in their own table, out of the vector index of egw_rag
 * - rag_part / ft_part column, so an entry can have separately indexed parts, e.g. replies or files
 *
 * @return string
 */
function rag_upgrade26_1_001()
{
	/** @var Api\Db $db */
	$db = $GLOBALS['egw_setup']->db;

	$GLOBALS['egw_setup']->oProc->CreateTable('egw_rag_cache', array(
		'fd' => array(
			'rc_hash' => array('type' => 'binary','precision' => '32','nullable' => False,'comment' => 'binary sha256 hash of the search pattern'),
			'rc_embedding' => array('type' => 'vector','precision' => '1024','nullable' => False),
			'rc_updated' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp')
		),
		'pk' => array('rc_hash'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	));
	$db->query("INSERT IGNORE INTO egw_rag_cache (rc_hash, rc_embedding, rc_updated)
		SELECT rag_hash, rag_embedding, rag_updated FROM egw_rag WHERE rag_app='*cache*' AND rag_hash IS NOT NULL", __LINE__, __FILE__);
	$db->query("DELETE FROM egw_rag WHERE rag_app='*cache*'", __LINE__, __FILE__);

	// raw SQL: RefreshTable() would copy the whole table incl. rebuilding the vector index twice
	$db->query("ALTER TABLE egw_rag
		ADD rag_part VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '' COMMENT '\'\' = entry itself, or e.g. reply:<id>, file:<fs_id>',
		DROP INDEX egw_rag_app_app_id_chunk,
		ADD UNIQUE INDEX egw_rag_app_app_id_part_chunk (rag_app, rag_app_id, rag_part, rag_chunk)", __LINE__, __FILE__);
	$db->query("ALTER TABLE egw_rag_fulltext
		ADD ft_part VARCHAR(64) CHARACTER SET ascii NOT NULL DEFAULT '' COMMENT '\'\' = entry itself, or e.g. reply:<id>, file:<fs_id>',
		DROP INDEX egw_rag_fulltext_ft_app_ft_app_id,
		ADD UNIQUE INDEX egw_rag_fulltext_ft_app_ft_app_id_ft_part (ft_app, ft_app_id, ft_part)", __LINE__, __FILE__);

	Rag\Embedding::createVectorIndex($db);

	return $GLOBALS['setup_info']['rag']['currentver'] = '26.1.002';
}


/**
 * Search quality, part 3: bigger, boundary-aware chunks, each prefixed with the context of its entry
 *
 * Every chunk changes, so all embeddings have to be calculated again. Tracker replies move from the
 * ft_extra column of their ticket into own parts, therefore its fulltext rows are dropped too.
 *
 * @return string
 */
function rag_upgrade26_1_002()
{
	/** @var Api\Db $db */
	$db = $GLOBALS['egw_setup']->db;

	$db->query('DELETE FROM egw_rag', __LINE__, __FILE__);
	$db->query("DELETE FROM egw_rag_fulltext WHERE ft_app='tracker'", __LINE__, __FILE__);

	// the async job calculates the new embeddings, it removes itself once it is done
	Rag\Embedding::installAsyncJob();

	return $GLOBALS['setup_info']['rag']['currentver'] = '26.1.003';
}
