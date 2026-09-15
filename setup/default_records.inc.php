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

use EGroupware\Rag;

// give only Admins group rights for RAG app
$adminsgroup = $GLOBALS['egw_setup']->add_account('Admins', 'Admins', 'Group', false, false);
$GLOBALS['egw_setup']->add_acl('rag', 'run', $adminsgroup);

// the schema can only create a bare vector index, which is built for the euclidean distance
Rag\Embedding::createVectorIndex($GLOBALS['egw_setup']->db);

// install the async-job to build the fulltext index
Rag\Embedding::installAsyncJob();