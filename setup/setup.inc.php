<?php
/**
 * EGroupware RAG system
 *
 * @package rag
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @copyright 2025-26 by Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

$setup_info['rag']['name']      = 'rag';
$setup_info['rag']['version']   = '26.1.003';
$setup_info['rag']['app_order'] = 5;
$setup_info['rag']['tables']    = ['egw_rag', 'egw_rag_fulltext', 'egw_rag_cache'];
$setup_info['rag']['only_db']   = ['mysql']; // MariaDB 11.7+ required for Vector/RAG
$setup_info['rag']['enable']    = 5;        // hidden from navbar, but framework app without index
$setup_info['rag']['autoinstall'] = true;   // install automatic on update
$setup_info['rag']['index']     = 'rag.EGroupware\\Rag\\Ui.index&ajax=true';

$setup_info['rag']['author'] =
$setup_info['rag']['maintainer'] = array(
	'name'  => 'Ralf Becker',
	'email' => 'rb@egroupware.org',
);
$setup_info['rag']['license']  = 'GPL';
$setup_info['rag']['description'] = 'A RAG system for EGroupware';
$setup_info['rag']['note'] = 'Requires MariaDB 11.7+!';

/* The hooks this app includes, needed for hooks registration */
$setup_info['rag']['hooks'] = array();
$setup_info['rag']['hooks']['admin'] = 'EGroupware\\Rag\\Hooks::allHooks';
$setup_info['rag']['hooks']['sidebox_menu'] = 'EGroupware\\Rag\\Hooks::allHooks';
$setup_info['rag']['hooks']['notify-all'] = 'EGroupware\\Rag\\Embedding::notify';
$setup_info['rag']['hooks']['config'] = 'EGroupware\Rag\Hooks::config';
$setup_info['rag']['hooks']['config_validate'] = 'EGroupware\Rag\Hooks::configValidate';
$setup_info['rag']['hooks']['settings'] = 'EGroupware\\Rag\\Hooks::settings';
$setup_info['rag']['hooks']['topmenu_info'] = 'EGroupware\\Rag\\Hooks::topMenuInfo';
$setup_info['rag']['hooks']['etemplate2_before_exec'][] = 'EGroupware\\Rag\\Hooks::etemplate2_before_exec';

/* Dependencies for this app to work */
$setup_info['rag']['depends'][] = array(
	 'appname' => 'api',
	 'versions' => Array('26.1')
);