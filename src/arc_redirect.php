<?php
$plugin['name'] = 'arc_redirect';

$plugin['version'] = '1.3';
$plugin['author'] = 'Andy Carter';
$plugin['author_uri'] = 'http://andy-carter.com/';
$plugin['description'] = 'Love redirects, hate 404s';
$plugin['order'] = '5';
$plugin['type'] = '1';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '2';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
$plugin['textpack'] = <<< EOT
#@arc_redirect
#@language en
arc_redirect => Redirects
tab_arc_redirect => Redirects
arc_original_url => Original URL
arc_redirect_url => Redirect URL
redirect_type => Redirect type
redirect_permanent => Permanent
redirect_temporary => Temporary
search_redirects => Search redirects
no_redirects_founded => No redirects found
redirect_added => Redirect added
redirect_updated => Redirect updated
add_new_redirect => Add new redirect
edit_redirect => Edit redirect
redirect_deleted => Redirect deleted
redirects_deleted => redirects deleted
error_details_incomplete_no_id => No ID# specified – unable to save redirect
error_details_incomplete => Some details are missing – unable to save redirect
error_no_such_redirect_id => A redirect with that ID# does not exist. Please try again.
test_redirect => Test
#@language de-de
arc_redirect => Weiterleitungen
tab_arc_redirect => Weiterleitungen
arc_original_url => Von URL
arc_redirect_url => Ziel URL
redirect_type => Art der Weiterleitung
redirect_permanent => Dauerhaft
redirect_temporary => Vorübergehend
search_redirects => Weiterleitungen suchen
no_redirects_founded => Keine Weiterleitungen gefunden
redirect_added => Weiterleitung hinzugefügt
redirect_updated => Weiterleitung aktualisiert
add_new_redirect => Neue Weiterleitung
edit_redirect => Weiterleitung bearbeiten
redirect_deleted => Weiterleitung gelöscht
redirects_deleted => Weiterleitungen gelöscht
unable_to_add_new_redirect => Neue Weiterleitung konnte nicht hinzugefügt werden
error_details_incomplete_no_id => ID# fehlt – die Weiterleitung konnte nicht gespeichert werden
error_details_incomplete => Angaben unvollständing – die Weiterleitung konnte nicht gespeichert werden
error_no_such_redirect_id => Eine Weiterleitung mit der ID# existiert nicht. Bitte erneuert versuchen!
test_redirect => Prüfen

EOT;

if (!defined('txpinterface'))
    @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
register_callback('arc_redirect_install','plugin_lifecycle.arc_redirect', 'installed');
register_callback('arc_redirect_uninstall','plugin_lifecycle.arc_redirect', 'deleted');
register_callback('arc_redirect', 'pretext_end');
add_privs('arc_redirect', '1,2,3,4');
register_tab('extensions', 'arc_redirect', 'arc_redirect');
register_callback('arc_redirect_tab', 'arc_redirect');

use Textpattern\Search\Filter;

/*
 * Check for redirected URLs and forward with a 301 or 302.
 */
 
function arc_redirect($event, $step)
{
	global $pretext;

	$url = parse_url($pretext['request_uri']);
	$url = doSlash(rtrim($url['path'], '/'));

	// full URL including the protocol and domain
	$fullUrl = hu . ltrim($url, '/');

	$redirect = safe_row(
		'redirectUrl, statusCode',
		'arc_redirect',
		"originalUrl = '$url' OR originalUrl = '$fullUrl' ORDER BY CHAR_LENGTH(originalUrl) DESC, arc_redirectID DESC"
	);

	if (isset($redirect['redirectUrl']))
	{
		txp_die('', $redirect['statusCode'], $redirect['redirectUrl']);
	}

	return;

}


/**
 * Redirects panel.
 *
 * @package Extensions\Redirect
 */
 
function arc_redirect_tab($event, $step) {

	switch ($step) {
		case 'add': arc_redirect_add(); break;
		case 'save': arc_redirect_save(); break;
		case 'edit': arc_redirect_edit(); break;
		case 'arc_redirect_multi_edit': arc_redirect_multi_edit(); break;
		case 'arc_redirect_change_pageby': arc_redirect_change_pageby(); break;
		default: arc_redirect_list();
	}
}


/**
 * The main panel listing all redirects.
 *
 * @param string|array $message The activity message
 */

function arc_redirect_list($message = '')
{
	global $event;

	pagetop('tab_arc_redirect',$message);

	extract(gpsa(array(
		'page',
		'sort',
		'dir',
		'crit',
		'search_method',
	)));

	if ($sort === '') {
		$sort = get_pref('arc_redirect_sort_column', 'arc_redirectID');
	} else {
		if (!in_array($sort, array('arc_redirectID', 'originalUrl', 'redirectUrl', 'statusCode'))) {
			$sort = 'arc_redirectID';
		}

		set_pref('arc_redirect_sort_column', $sort, 'arc_redirect', PREF_HIDDEN, '', 0, PREF_PRIVATE);
	}

	if ($dir === '') {
		$dir = get_pref('arc_redirect_sort_dir', 'asc');
	} else {
		$dir = ($dir == 'desc') ? "desc" : "asc";
		set_pref('arc_redirect_sort_dir', $dir, 'arc_redirect', PREF_HIDDEN, '', 0, PREF_PRIVATE);
	}

	$sort_sql = "$sort $dir";
	$switch_dir = ($dir == 'desc') ? 'asc' : 'desc';

	$search = new Filter($event,
		array(
			'arc_redirectID' => array(
				'column' => 'arc_redirect.arc_redirectID',
				'label'  => gTxt('id'),
			),
			'originalUrl' => array(
				'column' => 'arc_redirect.originalUrl',
				'label'  => gTxt('arc_original_url'),
			),
			'redirectUrl' => array(
				'column' => 'arc_redirect.redirectUrl',
				'label'  => gTxt('arc_redirect_url'),
			),
			'statusCode' => array(
				'column' => 'arc_redirect.statusCode',
				'label'  => gTxt('type'),
			),
		)
	);

	$alias_permanent = '301, Permanent';
	$alias_temporary = '302, Temporary';
	$search->setAliases('statusCode', array($alias_permanent, $alias_temporary));

	list($criteria, $crit, $search_method) = $search->getFilter();

	$search_render_options = array('placeholder' => gTxt('search_redirects'));
	$total = safe_count('arc_redirect', $criteria);

	$searchBlock =
		n.tag(
			$search->renderForm('arc_redirect', $search_render_options),
			'div', array(
				'class' => 'txp-layout-4col-3span',
				'id'	=> $event.'_control',
			)
		);

	$createBlock  = tag(arc_redirect_form(''), 'div', array('class' => 'txp-control-panel', 'style' => 'width: 100%'));
	$contentBlock = '';
    
	$paginator = new \Textpattern\Admin\Paginator($event, 'arc_redirect');
	$limit = $paginator->getLimit();

	list($page, $offset, $numPages) = pager($total, $limit, $page);

	if ($total < 1) {
        if ($criteria != 1) {
    		$contentBlock .= graf(
    			span(null, array('class' => 'ui-icon ui-icon-info')).' '.
    			gTxt('no_results_found'),
    			array('class' => 'alert-block information')
    		);
        }
	} else {
		$rs = safe_rows_start(
			"arc_redirectID, originalUrl, redirectUrl, statusCode", 
			'arc_redirect', 
			"$criteria ORDER BY $sort_sql LIMIT $offset, $limit"
		);

		$contentBlock .= 
			n.tag_start('form', array(
				'class'  => 'multi_edit_form',
				'id'	 => 'arc_redirect_form',
				'name'   => 'longform',
				'method' => 'post',
				'action' => 'index.php',
			)).
			n.tag_start('div', array('class' => 'txp-listtables')).
			n.tag_start('table', array('class' => 'txp-list')).
			n.tag_start('thead').
			tr(
				hCell(
					fInput('checkbox', 'select_all', 0, '', '', '', '', '', 'select_all'),
						'', ' class="txp-list-col-multi-edit" scope="col" title="'.gTxt('toggle_all_selected').'"'
				).
				column_head(
					gTxt('id'), 'arc_redirectID', 'arc_redirect', true, $switch_dir, '', '',
						(('arc_redirectID' == $sort) ? "$dir " : '').'txp-list-col-id'
				).
				column_head(
					gTxt('arc_original_url'), 'originalUrl', 'arc_redirect', true, $switch_dir, '', '',
						(('originalUrl' == $sort) ? "$dir " : '').'txp-list-col-originalurl'
				).
				column_head(
					gTxt('arc_redirect_url'), 'redirectUrl', 'arc_redirect', true, $switch_dir, '', '',
						(('redirectUrl' == $sort) ? "$dir " : '').'txp-list-col-redirecturl'
				).
				column_head(
					gTxt('type'), 'statusCode', 'arc_redirect', true, $switch_dir, '', '',
						(('statusCode' == $sort) ? "$dir " : '').'txp-list-col-type'
				).
				hCell(
					gTxt('manage'), '', ' class="txp-list-col-manage" scope="col"'
				)
			).
			n.tag_end('thead').
			n.tag_start('tbody');

		while ($a = nextRow($rs)) {
			extract($a, EXTR_PREFIX_ALL, 'redirect');

			$edit_url = eLink('arc_redirect', 'edit', 'id', $redirect_arc_redirectID, gTxt('edit'));

			$test_url = $redirect_originalUrl;
			if (strpos($test_url, '/') === 0) {
				$test_url = hu . ltrim($test_url, '/');
			}
			$redirect_url = href(gTxt('test_redirect'), $test_url, ' rel="external" target="_blank"');

			$contentBlock .= tr(
				td(
					fInput('checkbox', 'selected[]', $redirect_arc_redirectID), '', 'txp-list-col-multi-edit'
				).
				hCell(
					$redirect_arc_redirectID, '', ' class="txp-list-col-id" scope="row"'
				).
				td(
					txpspecialchars($redirect_originalUrl), '', 'txp-list-col-originalurl'
				).
				td(
					txpspecialchars($redirect_redirectUrl), '', 'txp-list-col-redirecturl'
				).
				td(
					$redirect_statusCode == 301 ? gTxt('redirect_permanent') : gTxt('redirect_temporary'), '', 'txp-list-col-type'
				).
				td(
					"$edit_url <span> | </span> $redirect_url", '', 'txp-list-col-manage'
				)
			);
            unset($redirect_arc_redirectID);
		}

		$methods = array(
			'delete' => gTxt('delete')
		);

		$contentBlock .= n.tag_end('tbody').
			n.tag_end('table').
			n.tag_end('div').
			multi_edit($methods, 'arc_redirect', 'arc_redirect_multi_edit').
			tInput().
			n.tag_end('form');

		$pageBlock = $paginator->render().
		nav_form('arc_redirect', $page, $numPages, $sort, $dir, $crit, $search_method, $total, $limit);

		$table = new \Textpattern\Admin\Table($event);
		echo $table->render(compact('total', 'criteria') + array('heading' => 'tab_arc_redirect'), $searchBlock, $createBlock, $contentBlock, $pageBlock);

	}

}


/**
 * Edit redirect.
 *
 * @param string|array $message The activity message
 */

function arc_redirect_edit($message='')
{
	pagetop('arc_redirect',$message);

	$originalUrl = gps('originalUrl');
	$redirectUrl = gps('redirectUrl');
	$statusCode = gps('statusCode');

	if ($id = gps('id')) {
		$id = doSlash($id);
		$rs = safe_row('originalUrl, redirectUrl, statusCode', 'arc_redirect', "arc_redirectID = $id");
		extract($rs);
	}

	echo hed(gTxt('tab_arc_redirect'), 1, array('class' => 'txp-heading')).
         arc_redirect_form($id, $originalUrl, $redirectUrl, $statusCode);
}


/**
 * Add redirect.
 */

function arc_redirect_add()
{
	$originalUrl = ps('originalUrl');
	$redirectUrl = ps('redirectUrl');

	if ($originalUrl === '' || $redirectUrl === '')
	{
		arc_redirect_edit(gTxt('error_details_incomplete'));
		return;
	}

	$statusCode = ps('statusCode') == 301 ? 301 : 302;

	// Strip final slash from original url
	$originalUrl = rtrim($originalUrl, '/');

	$q = safe_insert(
		"arc_redirect",
		"originalUrl = '" . trim(doSlash($originalUrl)) . "', redirectUrl = '" . trim(doSlash($redirectUrl)) . "', statusCode = " . $statusCode
	);

	if ($q)
	{
		$message = gTxt('redirect_added');
		arc_redirect_list($message);
	}

	return;
}


/**
 * Saves redirect.
 */

function arc_redirect_save()
{
	if (!$id=ps('id'))
	{
		arc_redirect_list(gTxt('error_details_incomplete_no_id'));
		return;
	}

	$originalUrl = ps('originalUrl');
	$redirectUrl = ps('redirectUrl');
	$statusCode = ps('statusCode');

	if ($originalUrl == '' || $redirectUrl == '' || empty($statusCode))
	{
		arc_redirect_edit(gTxt('error_details_incomplete'));
		return;
	}

	// Strip final slash from original url
	$originalUrl = rtrim($originalUrl, '/');

	$id = doSlash($id);

	$rs = safe_update(
		"arc_redirect",
		"originalUrl	= '" . trim(doSlash($originalUrl)) . "',  redirectUrl = '" . trim(doSlash($redirectUrl)) . "',  statusCode = " . trim(doSlash($statusCode)) . "",
		"arc_redirectID = $id"
	);

	if ($rs)
	{
		$message = gTxt('redirect_updated');
		arc_redirect_list($message);
	}
	return;
}


/**
 * Processes multi-edit actions.
 */

function arc_redirect_multi_edit() {
	$selected = ps('selected');

	if (!$selected || !is_array($selected)) {
		arc_redirect_list();
		return;
	}

	$method = ps('edit_method');
	$changed = array();

	$message = '';

	switch ($method) {
		case 'delete':

			foreach ($selected as $id) {
				$id = doSlash($id);
				if (safe_delete('arc_redirect', 'arc_redirectID = '.$id)) {
					$changed[] = $id;
				}
			}
			$num = count($changed);
			if ($num > 1) {
				$message = $num . ' ' . gTxt('redirects_deleted');
			} else {
				$message = gTxt('redirect_deleted');
			}
			
			break;
	}

	arc_redirect_list($message);
}


/**
 * Updates pageby value.
 */

function arc_redirect_change_pageby()
{
	global $event;

	Txp::get('\Textpattern\Admin\Paginator', $event, 'arc_redirect')->change();
	arc_redirect_list();
}


/**
 * Renders an add a redirect form.
 *
 * @return string HTML
 * @access private
 * @see	form()
 */

 function arc_redirect_form($id = '', $originalUrl = '', $redirectUrl = '', $statusCode = '')
 {        
     // bump back if non-existent ID specified
     if ($id) {
         $rs = safe_row('arc_redirectID', 'arc_redirect', "arc_redirectID = $id");
         if (!$rs) {
            arc_redirect_list(gTxt('error_no_such_redirect_id'));
     		return;
         }
     }
     
     // 'edit' or 'add' operation
     $action = ($id != '') ? 'save' : 'add';

     $statusCodes = array(
 		301 => gTxt('redirect_permanent'),
 		302 => gTxt('redirect_temporary')
 	);

 	return form(
 		(($action == "add") ? hed(gTxt('add_new_redirect'), 2) : hed(gTxt('edit_redirect'), 2)).
 		inputLabel(
 			'originalUrl',
 			fInput('text', 'originalUrl', $originalUrl, '', '', '', INPUT_REGULAR, '', 'originalUrl'),
 			gTxt('arc_original_url'), '', array('class' => 'txp-form-field input-original-url')
 		).
 		inputLabel(
 			'redirectUrl',
 			fInput('text', 'redirectUrl', $redirectUrl, '', '', '', INPUT_REGULAR, '', 'redirectUrl'),
 			gTxt('arc_redirect_url'), '', array('class' => 'txp-form-field input-redirect-url')
 		).
 		inputLabel(
 			'statusCode',
 			selectInput('statusCode', $statusCodes, $statusCode),
 			gTxt('redirect_type'), '', array('class' => 'txp-form-field set-status-code')
 		).
 		graf(
 			fInput('submit', $action, gTxt($action), 'publish'),
 			array('class' => 'txp-edit-actions', 'style' => 'text-align: left')
 		).
 		eInput('arc_redirect').
 		(($id != '') ? hInput('id',$id) : '').
 		sInput($action),
 		'display:block; margin-left: 0;', '', 'post', 'txp-edit', '', 'arc_redirect_details'
 	);

 }


/**
 * Installation function – builds MySQL table.
 */

function arc_redirect_install()
{
	// Create the arc_redirect table if it does not already exist
    safe_create('arc_redirect', '
        arc_redirectID  INT          NOT NULL AUTO_INCREMENT,
        originalUrl     VARCHAR(255) NOT NULL,
        redirectUrl     VARCHAR(255) NOT NULL,
        statusCode      INT          NOT NULL DEFAULT \'301\',
        PRIMARY KEY (arc_redirectID)
    ');
	
	// Add 'statusCode' column to an existing legacy 'arc_redirect' table
    if (!getRows("SHOW COLUMNS FROM ".safe_pfx('arc_redirect')." LIKE 'statusCode'")) {
        safe_alter(
            'arc_redirect',
            "statusCode INT NOT NULL DEFAULT \'301\'"
        );
    }

	return;
}


/**
 * Uninstall function – deletes MySQL table and related preferences.
 */

function arc_redirect_uninstall()
{
	// Drop the arc_redirect table
    safe_drop("arc_redirect");
    // Remove arc_redirect prefs
    safe_delete('txp_prefs', 'event = "arc_redirect"');
}
# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---

h1. arc_redirect (love redirects, hate 404s)

If you're in the process of restructuring a Textpattern site, then this is the plugin for you!

Requirements:-

* Textpattern 4.5+

h2. Installation

To install go to 'Plugins' under 'Admin' and paste the plugin code into the 'Install plugin' box, 'upload' and then 'install'. You will then need to activate the plugin.


h2. Uninstall

To uninstall %(tag)arc_redirect% simply delete the plugin from the 'Plugins' tab.  This will remove the plugin code and drop the %(tag)arc_redirect% table from your Textpattern database.


h2. Usage

arc_redirect adds a new tab under 'Extensions' from where you can define pairs of URLs for handling redirects. Basically provide an original URL on your Textpattern site that is generating a 404, "page not found", error and a redirect URL. Then whenever someone goes to the original URL rather than get the standard 404 error page they will be redircted to the new URL (with a 301 permenantely moved, or 302 temporarily removed).

The redirect from URL must produce a 404 page in Textpattern on the site this plugin is installed.

* arc_redirect treats _http://www.example.com/missing_ the same as _http://www.example.com/missing/_
* arc_redirect does not treat _http://example.com/missing_ and _http://www.example.com/missing_ as the same URL
* You can use absolute URLs like _/missing_


h2. Author

"Andy Carter":http://andy-carter.com. For other Textpattern plugins by me visit my "Plugins page":http://andy-carter.com/txp.

Thanks to "Oliver Ker":http://oliverker.co.uk/ for giving me the idea for this plugin.

h2. License

The MIT License (MIT)

Copyright (c) 2015 Andy Carter

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

# --- END PLUGIN HELP ---
-->
<?php
}
?>
