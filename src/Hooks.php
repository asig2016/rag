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
use EGroupware\Rag\Embedding\Base;


/**
 * diverse hooks as static methods
 *
 */
class Hooks
{
	const APP = 'rag';

	/**
	 * Hooks to build RAGs sidebox-menu plus the admin and Api\Preferences sections
	 *
	 * @param string|array $args hook args
	 */
	static function allHooks($args)
	{
		$appname = self::APP;
		$location = is_array($args) ? $args['location'] : $args;

		if ($location == 'sidebox_menu')
		{

		}

		if ($GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$file = Array(
				'Site Configuration' => Api\Egw::link('/index.php','menuaction=admin.admin_config.index&appname=' . $appname.'&ajax=true'),
				'Diagnostics' => Api\Egw::link('/index.php', 'menuaction=rag.EGroupware\\Rag\\Diagnostics.index&ajax=true'),
			);
			if ($location == 'admin')
			{
				display_section($appname,$file);
			}
			else
			{
				//$GLOBALS['egw']->framework->sidebox($appname, lang('Configuration'), $file);
			}
		}
	}

	/**
	 * Hook to overwrite config and/or set "sel_options"
	 *
	 * @param array $data
	 */
	public static function config($data)
	{
		// show configuration errors, also when opening app configuration
		self::configValidate($data);

		$apps = array_keys(Embedding::plugins());
		$apps = array_combine($apps, array_map('lang', $apps));
		asort($apps);
		$ret = [
			'sel_options' => [
				'fulltext_apps' => $apps,
				'rag_apps' => $apps,
		]];

		if (($errors = Api\Config::read(self::APP)[Embedding::RAG_LAST_ERRORS] ?? []))
		{
			$last_error = current($errors);
			// shorten long error-messages, specially SQL errors contain
			if (strlen($last_error['message']) > 100)
			{
				[$message, $message2] = explode("\n", $last_error['message'], 2)+[null,null];
				$last_error['message'] = substr($message, 0, 100).(strlen($message) > 100 ? '...' : '');
				if (!empty($message2))
				{
					$last_error['message'] .= "\n".substr($message2, 0, 100).(strlen($message2) > 100 ? '...' : '');
				}
			}
			$ret += [
				'rag_last_error_time' => Api\DateTime::to($last_error['date']).': '.$last_error['message'],
				'rag_last_errors' => json_encode($errors, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
			];
		}
		return $ret;
	}

	/**
	 * Hook to validate app configuration
	 *
	 * @param array $data
	 * @todo check url & api_key by e.g. querying the available models
	 */
	public static function configValidate($data)
	{
		$error = null;
		if (!empty($data['url']))
		{
			try {
				$embed = new Embedding(0, array_filter($data)); // array-filter removes empty fields
				$embed->testConfig();
				Embedding::removeAsyncJob();
				Embedding::installAsyncJob();
			}
			catch (\Exception $e) {
				$error = $e->getMessage();
				switch($e->getCode())
				{
					case 1002:
					case 1003:
						Api\Etemplate::set_validation_error('url', $error, 'newsettings');
						break;
					case 1004:
						Api\Etemplate::set_validation_error('embedding_model', $error, 'newsettings');
						break;
					default:
						Api\Json\Response::get()->message($error, empty($data['url']) ? 'info' : 'error');
				}
			}
			// testConfig() only checks the embedding model: the reranker is a second endpoint and can be dead
			// without anything ever saying so, a search just silently keeps the order of the hybrid fusion
			if (!empty($data['rerank_model']))
			{
				$embed = new Embedding(0, array_filter($data));
				$rerank_url = !empty($data['rerank_url']) ? $data['rerank_url'] : $data['url'];
				try {
					// one document of the length a real search sends: an endpoint can score short test
					// documents perfectly and still refuse a real one, e.g. llama.cpp's physical batch size
					$embed->rerank(Diagnostics::RERANK_QUERY, array_merge(Diagnostics::RERANK_DOCUMENTS,
						[Diagnostics::rerankProbeDocument()]));
				}
				catch (\Throwable $e) {
					$error = $e->getMessage();
					try {
						// the short documents alone tell us whether it is the endpoint or our document length
						$embed->rerank(Diagnostics::RERANK_QUERY, Diagnostics::RERANK_DOCUMENTS);
						Api\Json\Response::get()->message(lang('Rerank endpoint %1 refuses a document of %2 characters, as every search sends: %3',
							$rerank_url, Embedding::RERANK_MAX_CHARS, $error), 'error');
					}
					catch (\Throwable $e2) {
						Api\Json\Response::get()->message(lang('Rerank endpoint %1 is not usable: %2',
							$rerank_url, $e2->getMessage()), 'error');
					}
				}
			}
		}
	}

	public static function settings(array $data) : array
	{
		return [
			'addressbook_search' => [
				'type'    => 'select',
				'label'   => lang('What type of search to use for search in %1', lang('Addressbook')),
				'name'    => 'addressbook_search',
				'values'  => [
					'legacy' => lang('Legacy search, as used before'),
					'fulltext' => lang('Fulltext search'),
					'hybrid'  => lang('Hybrid search: RAG+Fulltext'),
					'rag' => lang('RAG search only'),
				],
				'help'    => '',
				'xmlrpc'  => false,
				'admin'   => false,
				'default' => 'legacy',
			],
			'default_search' => [
				'type'    => 'select',
				'label'   => 'What type of search to use for search in the apps',
				'name'    => 'default_search',
				'values'  => [
					'fulltext' => lang('Fulltext search'),
					'legacy' => lang('Legacy search, as used before'),
					'hybrid'  => lang('Hybrid search: RAG+Fulltext'),
					'rag' => lang('RAG search only'),
				],
				'help'    => '',
				'xmlrpc'  => false,
				'admin'   => false,
				'default' => 'fulltext',
			],
			'default_search_order' => [
				'type'    => 'select',
				'label'   => 'Should search in apps be ordered by relevance',
				'name'    => 'default_search_order',
				'values'  => [
					'app'  => lang('No, use search-order chosen in app'),
					'relevance' => lang('Always use relevance'),
				],
				'help'    => '',
				'xmlrpc'  => false,
				'admin'   => false,
				'default' => 'app',
			],
			'fulltext_match_wordstart' => [
				'type'    => 'select',
				'label'   => 'Change fulltext search pattern to match words starting with pattern',
				'name'    => 'fulltext_match_wordstart',
				'values'  => [
					'yes'  => lang('Yes').', '.lang('Default'),
					'no' => lang('No'),
				],
				'help'    => '',
				'xmlrpc'  => false,
				'admin'   => false,
				'default' => 'yes',
			],
		];
	}

	/**
	 * Add search to topmenu
	 *
	 * @param string|array $data hook-data
	 */
	public static function topMenuInfo($data)
	{
		/** @var \kdots_framework $framework */
		$framework = $GLOBALS['egw']->framework;
		if (!empty($GLOBALS['egw_info']['user']['apps']['rag']))
		{
			$framework->add_topmenu_item(
				'rag',
				Api\Egw::link('/index.php', ['menuaction' => 'rag.EGroupware\\Rag\\Ui.index', 'ajax' => 'true']),
				lang('Search'),
				'rag',
				'search',
			);
		}
	}

	/**
	 * Add options for RAG search to app's index templates
	 *
	 * @param array $data content of request plus special keys "location_name" and "location_object" (=Etemplate object)
	 * @return array Modifications to make to the response.  These changes are
	 * made before any processing is done on the template exec().
	 *  String[] 'data':		Changes to the content
	 *  String[] 'readonlys':	Changes to the readonlys
	 *	String[] 'preserve':	Changes to preserve
	 */
	public static function etemplate2_before_exec(array $data)
	{
		if (!preg_match('/^([^.]+)\.(index|list)$/', $data['location_name'], $matches) ||
			!isset(Embedding::plugins()[$app=$matches[1]]) ||
			$app === 'calendar')    // plugin, but no search-integration yet
		{
			return;
		}
		Api\Translation::add_app(self::APP);
		return [
			'data' => [
				'nm' => Ui::fulltextOperatorHelp($app),
			],
		];
	}
}