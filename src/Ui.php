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

namespace EGroupware\Rag;

use EGroupware\Api;


/**
 * diverse hooks as static methods
 *
 */
class Ui
{
	const APP = 'rag';

	/**
	 * @var int log-level: 0: errors only, 1: result of search/get_rows() methods
	 */
	protected int $log_level = 0;

	/**
	 * Methods callable via menuaction GET parameter
	 *
	 * @var array
	 */
	public $public_functions = [
		'index' => true,
		'edit'  => true,
	];

	/**
	 * Instance of our business object
	 *
	 * @var Embedding
	 */
	protected $embedding;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->embedding = new Embedding($this->log_level);
	}

	/**
	 * Fetch rows to display
	 *
	 * ACL is taken into account by calling Api\Link::title(s) on each entry and NOT returning it, if no title was returned!
	 *
	 * @param array $query
	 * @param ?array& $rows =null
	 * @param ?array& $readonlys =null
	 * @return int total number of entries found
	 */
	public function get_rows($query, ?array &$rows=null, ?array &$readonlys=null)
	{
		// store state in session
		Api\Cache::setSession(__CLASS__, 'state', [
			'search' => $query['search'],
			'col_filter' => $query['col_filter'],
		]);
		// close no longer needed session, so other requests can run in parallel, e.g. when embedding takes some time ...
		$GLOBALS['egw']->session->commit_session();

		$rows = [];
		if (empty($query['search']))
		{
			return 0;
		}
		$apps_ids = [];
		switch($query['col_filter']['type'] ?? 'hybrid')
		{
			default:
			case 'hybrid':
				$search = 'search';
				break;
			case 'fulltext':
				$search = 'searchFulltext';
				break;
			case 'rag':
				$search = 'searchEmbeddings';
				break;
		}
		try
		{
			foreach ($this->embedding->$search($query['search'], $query['col_filter']['apps'] ?? '',
				// simple approach without storing a state:
				0,  // we always start at 0, as we don't know how many rows the acl-filter will throw out
				// we query twice as many entries requested, as user might not have access to all of them
				2 * (($query['start'] ?? 0) + ($query['num_rows'] ?? 50)), true, $query['order'].' '.($query['sort']??'ASC')) as $id => $row)
			{
				if (!$id) continue; // $id===0 is used to signal nothing found, to not generate an SQL error

				if (is_numeric($id))
				{
					$app = current($query['col_filter']['apps']);
					$app_id = $id;
				}
				else
				{
					[$app, $app_id] = explode(':', $id, 2);
				}
				$rows[$app . ':' . $app_id] = $row;
				$apps_ids[$app][] = $app_id;
			}
		}
		catch (InvalidFulltextSyntax $e) {
			Api\Json\Response::get()->message($e->getMessage(), 'error');
			return 0;
		}
		catch (\Exception $e) {
			// our own time limit: say so instead of a statement and stack trace, and answer the list,
			// which also ends its "Searching ..." spinner
			if (Embedding::isTimeout($e))
			{
				Api\Json\Response::get()->message(lang('The search took longer than %1 seconds and was stopped. Admin > RAG > Diagnostics shows if the vector index is used.',
					Api\Config::read('rag')['search_timeout'] ?? 10), 'error');
				return 0;
			}
			// everything else as before, with all its details - the exception handler answers with the
			// same response, so the spinner is stopped by this call queued ahead of it
			Api\Json\Response::get()->call('app.rag.searchFailed');
			_egw_log_exception($e);
			throw $e;
		}
		$total = $this->embedding->total ?? 0;
		foreach($apps_ids as $app => $ids)
		{
			foreach(Api\Link::titles($app, $ids) as $id => $title)
			{
				// $title can be null for not existing entries or false for not available ones to the current user
				if (isset($rows[$row_id=$app.':'.$id]) && $title)
				{
					// direct assignment, NOT array union: $rows[$row_id] already carries a
					// (possibly null/stale) 'title' key whenever return_all is used, and
					// union would keep that existing key over this freshly resolved one
					$rows[$row_id]['id'] = $row_id;
					$rows[$row_id]['app'] = $app;
					$rows[$row_id]['app_id'] = $id;
					$rows[$row_id]['title'] = $title;
					unset($ids[array_search($id, $ids)]);
				}
			}
			// remove the entries not returned / user has no access to
			foreach($ids as $id)
			{
				if (isset($rows[$row_id=$id]) || isset($rows[$row_id=$app.':'.$id]))
				{
					unset($rows[$row_id]);
				}
			}
			// subtract number of entries user has no access to
			$total -= count($ids);
		}
		// return only wanted number of rows
		$rows = empty($query['num_rows']) ? $rows : array_slice($rows, $query['start']??0, $query['num_rows']);
		if ($this->log_level) error_log(__METHOD__."(".json_encode(array_intersect_key($query, array_flip(['search', 'col_filter', 'start', 'num_rows']))).",...) rows=".json_encode(array_keys($rows)).' returning '.$total);
		$rows = array_values($rows);
		return $total;
	}

	/**
	 * Index
	 *
	 * @param ?array $content =null
	 */
	public function index(?array $content=null)
	{
		if (!is_array($content) || empty($content['nm']))
		{
			$content = [
				'nm' => (Api\Cache::getSession(__CLASS__, 'state')?:[]) + [
					'get_rows'       =>	self::APP.'.'.self::class.'.get_rows',
					'no_filter'      => true,	// disable the diverse filters we not (yet) use
					'no_filter2'     => true,
					'no_cat'         => true,
					'order'          =>	'default',// IO name of the column to sort after (optional for the sortheaders)
					'sort'           =>	'ASC',// IO direction of the sort: 'ASC' or 'DESC'
					'row_id'         => 'id',
					'actions'        => $this->get_actions(),
					'default_cols'   => '!id,app_id',
					'col_filter'     => [
						'type' => $GLOBALS['egw_info']['user']['preferences']['rag']['searchType'] ?? 'fulltext',
						'apps' => [],
					],
				]+self::fulltextOperatorHelp(),
			];
		}
		$sel_options['apps'] = array_combine($apps = array_keys(Embedding::plugins()),
			array_map(static fn($app) => lang($app), $apps));

		$tmpl = new Api\Etemplate(self::APP.'.index');
		$tmpl->exec(self::APP.'.'.self::class.'.index', $content, $sel_options, [], ['nm' => $content['nm']]);
	}

	/**
	 * Return fulltext operators and a help-text for them
	 *
	 * @param string|null $app app-name to use app-specific configuration for available search_type
	 * @return array with values for keys "operators", "operator_help", "search_type" and "options-search_type"
	 */
	public static function fulltextOperatorHelp(?string $app=null) : array
	{
		static $config = null;
		if (!isset($config)) $config = Api\Config::read(self::APP);
		$search_types = [
			"fulltext" => lang('Fulltext search'),
            "rag"      => lang('RAG / Semantic search'),
            "hybrid"   => lang('Hybrid search (RAG+Fulltext)'),
            "legacy"   => lang('Legacy search'),
		];
		// RAG / embedding URL not configured or app NOT enabled
		if (empty($config['url']) || isset($app) && !empty($config['rag_apps']) && !in_array($app, $config['rag_apps']))
		{
			unset($search_types['rag'], $search_types['hybrid']);
		}
		// fulltext search NOT enabled for given app
		if (isset($app) && !empty($config['fulltext_apps']) && !in_array($app, $config['fulltext_apps']))
		{
			unset($search_types['fulltext'], $search_types['hybrid']);
		}
		return [
			'operator_help' => implode("\n", array_map(static function($label, $operator)
			{
				return " $operator\t$label";
			}, $operators=[
				'+' => lang('The word is mandatory in all rows returned.'),
				'-' => lang('The word cannot appear in any row returned.'),
				'<' => lang('The word that follows has a lower relevance than other words, although rows containing it will still match.'),
				'>' => lang('The word that follows has a higher relevance than other words.'),
				'()' => lang('Used to group words into subexpressions.'),
				'~' => lang('The word following contributes negatively to the relevance of the row.'),
				'*' => lang('The wildcard, indicating zero or more characters. It can only appear at the end of a word.'),
				'"' => lang('Anything enclosed in the double quotes is taken as a whole (so you can match phrases, for example).'),
			], array_keys($operators))),

			'operators' => implode(' ', str_split(implode('', array_keys($operators)))),

			'search_type' => $GLOBALS['egw_info']['user']['preferences']['rag'][$app.'_search'] ??
				$GLOBALS['egw_info']['user']['preferences']['rag']['default_search'] ?? 'fulltext',
			'options-search_type' => $search_types,
		];
	}

	/**
	 * Return actions for search
	 *
	 * @return array
	 */
	protected function get_actions()
	{
		return [
			'view' => [
				'caption' => 'View',
				'default' => true,
				'allowOnMultiple' => false,
				'onExecute' => 'javaScript:app.rag.view',
				'group' => $group=0,
			],
		];
	}
}