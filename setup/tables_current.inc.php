<?php
/**
 * EGroupware - Setup
 * https://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * Requires MariaDB 11.7+!
 *
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package rag
 * @subpackage setup
 */

$phpgw_baseline = array(
	'egw_rag' => array(
		'fd' => array(
			'rag_id' => array('type' => 'auto','nullable' => False),
			'rag_app' => array('type' => 'ascii','precision' => '16','nullable' => False,'comment' => 'app-name'),
			'rag_app_id' => array('type' => 'int','precision' => '4','nullable' => False,'comment' => 'app-id'),
			'rag_chunk' => array('type' => 'int','precision' => '4','nullable' => False,'comment' => '0=title, 1,2... n-th chunk of description'),
			'rag_updated' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp'),
			'rag_embedding' => array('type' => 'vector','precision' => '1024','nullable' => False,'comment' => 'contains the embedding'),
			'rag_hash' => array('type' => 'binary','precision' => '32','comment' => 'binary sha256 hash of chunk'),
			'rag_part' => array('type' => 'ascii','precision' => '64','nullable' => False,'default' => '','comment' => "'' = entry itself, or e.g. reply:<id>, file:<fs_id>")
		),
		'pk' => array('rag_id'),
		'fk' => array(),
		'ix' => array('rag_app_id','rag_embedding','rag_hash'),
		'uc' => array(array('rag_app','rag_app_id','rag_part','rag_chunk'))
	),
	'egw_rag_fulltext' => array(
		'fd' => array(
			'ft_id' => array('type' => 'auto','nullable' => False),
			'ft_app' => array('type' => 'ascii','precision' => '16','nullable' => False),
			'ft_app_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'ft_updated' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp'),
			'ft_title' => array('type' => 'varchar','precision' => '255'),
			'ft_description' => array('type' => 'longtext'),
			'ft_extra' => array('type' => 'longtext','meta' => 'json','comment' => 'all other textfields of the app as one JSON array'),
			'ft_part' => array('type' => 'ascii','precision' => '64','nullable' => False,'default' => '','comment' => "'' = entry itself, or e.g. reply:<id>, file:<fs_id>")
		),
		'pk' => array('ft_id'),
		'fk' => array(),
		'ix' => array('ft_app_id',array('ft_title','ft_description','ft_extra','options' => array('mysql' => 'FULLTEXT'))),
		'uc' => array(array('ft_app','ft_app_id','ft_part'))
	),
	'egw_rag_cache' => array(
		'fd' => array(
			'rc_hash' => array('type' => 'binary','precision' => '32','nullable' => False,'comment' => 'binary sha256 hash of the search pattern'),
			'rc_embedding' => array('type' => 'vector','precision' => '1024','nullable' => False),
			'rc_updated' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp')
		),
		'pk' => array('rc_hash'),
		'fk' => array(),
		'ix' => array(),
		'uc' => array()
	)
);