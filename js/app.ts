/**
 * EGroupware RAG system
 *
 * @package rag
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @copyright 2025 by Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

import { EgwApp } from '../../api/js/jsapi/egw_app';
// app is an ambient global (declare global {} in egw_global.d.ts, unconditionally included
// via tsconfig's "**/*.d.ts") - no import needed or possible.

class RagApp extends EgwApp
{
	/**
	 * app js initialization stage
	 */
	constructor()
	{
		super('rag');
	}

	/**
	 * et2 object is ready to use
	 *
	 * @param {object} et2 object
	 * @param {string} name template name et2_ready is called for eg. "example.edit"
	 */
	et2_ready(et2,name)
	{
		// call parent
		super.et2_ready.apply(this, arguments);
	}

	/**
	 * View an entry
	 *
	 * @param {object} _action action object, attribute id contains the name of the action
	 * @param {array} _selected array with selected rows, attribute id containers the row-id
	 */
	view(_action, _selected)
	{
		this.egw.open(_selected[0].id.split('::')[1]);
	}

	/**
	 * Search button pressed
	 */
	search(_ev, _widget)
	{
		const header = this.et2.getWidgetById('rag.index.header');
		const filters = {
			search: header?.getWidgetById('search').value,
			col_filter: {
				type: header?.getWidgetById('col_filter[type]').parentNode.querySelector('input[type="radio"]:checked').value,
				apps: header?.getWidgetById('col_filter[apps]').value,
			}
		};
		this.showSearching(filters);
		this.nm.applyFilters(filters);

		// store state as implizit preference
		if (_widget.id !== 'search')
		{
			this.egw.set_preference(this.appname,
				_widget.id === 'col_filter[type]' ? 'searchType' : 'searchApps',
				_widget.get_value());	// can't use .value because of old radio-buttons :(
		}
	}

	/**
	 * Show a "Searching ..." spinner over the list until the results are in
	 *
	 * A RAG or hybrid search can take seconds (embedding the query, reranking). The list fires
	 * et2-search-result once the rows arrived. Unchanged filters do not reload the list, so no
	 * spinner then, and a fallback removes it should a request fail and the event never come.
	 *
	 * @param filters as passed to applyFilters()
	 */
	protected showSearching(filters : {search? : string, col_filter? : {type? : string, apps? : any}})
	{
		const active = this.nm.activeFilters || {};
		if ((filters.search || '') === (active.search || '') &&
			filters.col_filter?.type === active.col_filter?.type &&
			JSON.stringify(filters.col_filter?.apps || []) === JSON.stringify(active.col_filter?.apps || []))
		{
			return;
		}
		// one spinner at a time: a search started while another runs takes over its handler and fallback
		this.searchDone?.();

		const node = this.nm.getDOMNode();
		this.egw.loading_prompt('rag-search', true, this.egw.lang('Searching ...'), node);

		const fallback = setTimeout(() => this.searchDone?.(), 300000);
		const done = () =>
		{
			clearTimeout(fallback);
			node.removeEventListener('et2-search-result', done);
			this.egw.loading_prompt('rag-search', false);
			if (this.searchDone === done) this.searchDone = null;
		};
		this.searchDone = done;
		node.addEventListener('et2-search-result', done);
	}

	/**
	 * Removes the spinner of the running search, see showSearching()
	 */
	protected searchDone : () => void = null;

	/**
	 * The search failed server-side: the list never gets its rows, so end the spinner here
	 *
	 * Called from Ui::get_rows() ahead of the error it passes on.
	 */
	searchFailed()
	{
		this.searchDone?.();
	}
}

app.classes.rag = RagApp;
