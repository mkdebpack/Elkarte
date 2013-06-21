<?php

/**
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * This software is a derived product, based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:  	BSD, See included LICENSE.TXT for terms and conditions.
 *
 * @version 1.0 Alpha
 *
 * Moderation helper functions.
 *
 */

if (!defined('ELKARTE'))
	die('No access...');

/**
 * How many open reports do we have?
 *  - if flush is true will clear the moderator menu count
 *  - returns the number of open reports
 *  - sets $context['open_mod_reports'] for template use
 *
 * @param boolean $flush = true if moderator menu count will be cleared
 */
function recountOpenReports($flush = true)
{
	global $user_info, $context;

	$db = database();

	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}log_reported
		WHERE ' . $user_info['mod_cache']['bq'] . '
			AND closed = {int:not_closed}
			AND ignore_all = {int:not_ignored}',
		array(
			'not_closed' => 0,
			'not_ignored' => 0,
		)
	);
	list ($open_reports) = $db->fetch_row($request);
	$db->free_result($request);

	$_SESSION['rc'] = array(
		'id' => $user_info['id'],
		'time' => time(),
		'reports' => $open_reports,
	);

	$context['open_mod_reports'] = $open_reports;
	if ($flush)
		cache_put_data('num_menu_errors', null, 900);
	return $open_reports;
}

/**
 * How many unapproved posts and topics do we have?
 * 	- Sets $context['total_unapproved_topics']
 *  - Sets $context['total_unapproved_posts']
 *  - approve_query is set to list of boards they can see
 *
 * @param string $approve_query
 * @return array of values
 */
function recountUnapprovedPosts($approve_query = null)
{
	global $context;

	$db = database();

	if ($approve_query === null)
		return array('posts' => 0, 'topics' => 0);

	// Any unapproved posts?
	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}topics AS t ON (t.id_topic = m.id_topic AND t.id_first_msg != m.id_msg)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = t.id_board)
		WHERE m.approved = {int:not_approved}
			AND {query_see_board}
			' . $approve_query,
		array(
			'not_approved' => 0,
		)
	);
	list ($unapproved_posts) = $db->fetch_row($request);
	$db->free_result($request);

	// What about topics?
	$request = $db->query('', '
		SELECT COUNT(m.id_topic)
		FROM {db_prefix}topics AS m
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE m.approved = {int:not_approved}
			AND {query_see_board}
			' . $approve_query,
		array(
			'not_approved' => 0,
		)
	);
	list ($unapproved_topics) = $db->fetch_row($request);
	$db->free_result($request);

	$context['total_unapproved_topics'] = $unapproved_topics;
	$context['total_unapproved_posts'] = $unapproved_posts;
	return array('posts' => $unapproved_posts, 'topics' => $unapproved_topics);
}

/**
 * How many falied emails (that they can see) do we have?
 *
 * @param string $approve_query
 */
function recountFailedEmails($approve_query = null)
{
	global $context;

	$db = database();

	if ($approve_query === null)
		return 0;

	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}postby_emails_error as m
			LEFT JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE {query_see_board}
			' . $approve_query . '
			OR m.id_board = -1',
		array(
		)
	);
	list ($failed_emails) = $db->fetch_row($request);
	$db->free_result($request);

	$context['failed_emails'] = $failed_emails;
	return $failed_emails;
}

/**
 * Loads the number of items awaiting moderation attention
 *  - Only loads the value a given permission level can see
 *  - If supplied a board number will load the values only for that board
 *  - Unapproved posts
 *  - Unapproved topics
 *  - Unapproved attachments
 *  - Failed emails
 *  - Reported posts
 *
 * @param int $brd
 */
function loadModeratorMenuCounts($brd = null)
{
	global $modSettings, $user_info;

	$menu_errors = array();

	// Work out what boards they can work in!
	$approve_boards = !empty($user_info['mod_cache']['ap']) ? $user_info['mod_cache']['ap'] : boardsAllowedTo('approve_posts');

	// Supplied a specific board to check?
	if (!empty($brd))
	{
		$filter_board = array((int) $brd);
		$approve_boards = $approve_boards == array(0) ? $filter_board : array_intersect($approve_boards, $filter_board);
	}

	// Work out the query
	if ($approve_boards == array(0))
		$approve_query = '';
	elseif (!empty($approve_boards))
		$approve_query = ' AND m.id_board IN (' . implode(',', $approve_boards) . ')';
	else
		$approve_query = ' AND 1=0';

	// Set up the cache key for this one
	$cache_key = md5($user_info['query_see_board'] . $approve_query);

	// If its been cached, guess what, thats right use it!
	$temp = cache_get_data('num_menu_errors', 900);
	if ($temp === null || !isset($temp[$cache_key]))
	{
		// Starting out with nothing is a good start
		$menu_errors[$cache_key]['attachments'] = 0;
		$menu_errors[$cache_key]['reports'] = 0;
		$menu_errors[$cache_key]['emailmod'] = 0;
		$menu_errors[$cache_key]['postmod'] = 0;
		$menu_errors[$cache_key]['topics'] = 0;
		$menu_errors[$cache_key]['posts'] = 0;

		if ($modSettings['postmod_active'] && !empty($approve_boards))
		{
			$totals = recountUnapprovedPosts($approve_query);
			$menu_errors[$cache_key]['posts'] = $totals['posts'];
			$menu_errors[$cache_key]['topics'] = $totals['topics'];

			// Totals for the menu item unapproved posts and topics
			$menu_errors[$cache_key]['postmod'] = $menu_errors[$cache_key]['topics'] + $menu_errors[$cache_key]['posts'];
		}

		// Attachments
		if ($modSettings['postmod_active'] && !empty($approve_boards))
		{
			require_once(SUBSDIR . '/Attachments.subs.php');
			$menu_errors[$cache_key]['attachments'] = list_getNumUnapprovedAttachments($approve_query);
		}

		// Reported posts
		if (!empty($user_info['mod_cache']) && $user_info['mod_cache']['bq'] != '0=1')
			$menu_errors[$cache_key]['reports'] = recountOpenReports(false);

		// Email failures that require attention
		if (!empty($modSettings['maillist_enabled']) && allowedTo('approve_emails'))
			$menu_errors[$cache_key]['emailmod'] = recountFailedEmails($approve_query);

		// Grand Totals for the top most menu
		$menu_errors[$cache_key]['total'] = $menu_errors[$cache_key]['emailmod'] + $menu_errors[$cache_key]['postmod'] + $menu_errors[$cache_key]['reports'] + $menu_errors[$cache_key]['attachments'];

		// Add this key in to the array, technically this resets the cache time for all keys
		// done this way as the entire thing needs to go null once *any* moderation action is taken
		$menu_errors = is_array($temp) ? array_merge($temp, $menu_errors) : $menu_errors;

		// Store it away for a while, not like this should change that often
		cache_put_data('num_menu_errors', $menu_errors, 900);
	}
	else
		$menu_errors = $temp === null ? array() : $temp;

	return $menu_errors[$cache_key];
}

/**
 * Log a warning notice.
 *
 * @param string $subject
 * @param string $body
 */
function logWarningNotice($subject, $body)
{
	$db = database();

	// Log warning notice.
	$db->insert('',
		'{db_prefix}log_member_notices',
		array(
			'subject' => 'string-255', 'body' => 'string-65534',
		),
		array(
			Util::htmlspecialchars($subject), Util::htmlspecialchars($body),
		),
		array('id_notice')
	);
	$id_notice = $db->insert_id('{db_prefix}log_member_notices', 'id_notice');

	return $id_notice;
}

/**
 * Logs the warning being sent to the user so other moderators can see
 *
 * @param int $memberID
 * @param string $real_name
 * @param int $id_notice
 * @param int $level_change
 * @param string $warn_reason
 */
function logWarning($memberID, $real_name, $id_notice, $level_change, $warn_reason)
{
	global $user_info;

	$db = database();

	$db->insert('',
		'{db_prefix}log_comments',
		array(
			'id_member' => 'int', 'member_name' => 'string', 'comment_type' => 'string', 'id_recipient' => 'int', 'recipient_name' => 'string-255',
			'log_time' => 'int', 'id_notice' => 'int', 'counter' => 'int', 'body' => 'string-65534',
		),
		array(
			$user_info['id'], $user_info['name'], 'warning', $memberID, $real_name,
			time(), $id_notice, $level_change, $warn_reason,
		),
		array('id_comment')
	);
}

/**
 * Removes a custom moderation center template from log_comments
 *  - Logs the template removal action for each warning affected
 *  - Removes the details for all warnings that used the template being removed
 */
function removeWarningTemplate($id_tpl, $template_type = 'warntpl')
{
	global $user_info;

	$db = database();

	// Log the actions.
	$request = $db->query('', '
		SELECT recipient_name
		FROM {db_prefix}log_comments
		WHERE id_comment IN ({array_int:delete_ids})
			AND comment_type = {string:tpltype}
			AND (id_recipient = {int:generic} OR id_recipient = {int:current_member})',
		array(
			'delete_ids' => $id_tpl,
			'tpltype' => $template_type,
			'generic' => 0,
			'current_member' => $user_info['id'],
		)
	);
	while ($row = $db->fetch_assoc($request))
		logAction('delete_warn_template', array('template' => $row['recipient_name']));
	$db->free_result($request);

	// Do the deletes.
	$db->query('', '
		DELETE FROM {db_prefix}log_comments
		WHERE id_comment IN ({array_int:delete_ids})
			AND comment_type = {string:tpltype}
			AND (id_recipient = {int:generic} OR id_recipient = {int:current_member})',
		array(
			'delete_ids' => $id_tpl,
			'tpltype' => $template_type,
			'generic' => 0,
			'current_member' => $user_info['id'],
		)
	);
}

/**
 * Returns all the templates of a type from the system.
 * (used by createList() callbacks)
 *
 * @param $start
 * @param $items_per_page
 * @param $sort
 * @param $template_type type of template to load
 */
function warningTemplates($start, $items_per_page, $sort, $template_type = 'warntpl')
{
	global $scripturl, $user_info;

	$db = database();

	$request = $db->query('', '
		SELECT lc.id_comment, IFNULL(mem.id_member, 0) AS id_member,
			IFNULL(mem.real_name, lc.member_name) AS creator_name, recipient_name AS template_title,
			lc.log_time, lc.body
		FROM {db_prefix}log_comments AS lc
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lc.id_member)
		WHERE lc.comment_type = {string:tpltype}
			AND (id_recipient = {string:generic} OR id_recipient = {int:current_member})
		ORDER BY ' . $sort . '
		LIMIT ' . $start . ', ' . $items_per_page,
		array(
			'tpltype' => $template_type,
			'generic' => 0,
			'current_member' => $user_info['id'],
		)
	);
	$templates = array();
	while ($row = $db->fetch_assoc($request))
	{
		$templates[] = array(
			'id_comment' => $row['id_comment'],
			'creator' => $row['id_member'] ? ('<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['creator_name'] . '</a>') : $row['creator_name'],
			'time' => standardTime($row['log_time']),
			'title' => $row['template_title'],
			'body' => Util::htmlspecialchars($row['body']),
		);
	}
	$db->free_result($request);

	return $templates;
}

/**
 * Get the number of templates of a type in the system
 *  - Loads the public and users private templates
 *  - Loads warning templates by default
 *  (used by createList() callbacks)
 *
 * @param type $template_type
 */
function warningTemplateCount($template_type = 'warntpl')
{
	global $user_info;

	$db = database();

	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}log_comments
		WHERE comment_type = {string:tpltype}
			AND (id_recipient = {string:generic} OR id_recipient = {int:current_member})',
		array(
			'tpltype' => $template_type,
			'generic' => 0,
			'current_member' => $user_info['id'],
		)
	);
	list ($totalWarns) = $db->fetch_row($request);
	$db->free_result($request);

	return $totalWarns;
}

/**
 * Get all issued warnings in the system.
 *
 * @param $start
 * @param $items_per_page
 * @param $sort
 */
function warnings($start, $items_per_page, $sort)
{
	global $scripturl;

	$db = database();

	$request = $db->query('', '
		SELECT IFNULL(mem.id_member, 0) AS id_member, IFNULL(mem.real_name, lc.member_name) AS member_name_col,
			IFNULL(mem2.id_member, 0) AS id_recipient, IFNULL(mem2.real_name, lc.recipient_name) AS recipient_name,
			lc.log_time, lc.body, lc.id_notice, lc.counter
		FROM {db_prefix}log_comments AS lc
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lc.id_member)
			LEFT JOIN {db_prefix}members AS mem2 ON (mem2.id_member = lc.id_recipient)
		WHERE lc.comment_type = {string:warning}
		ORDER BY ' . $sort . '
		LIMIT ' . $start . ', ' . $items_per_page,
		array(
			'warning' => 'warning',
		)
	);
	$warnings = array();
	while ($row = $db->fetch_assoc($request))
	{
		$warnings[] = array(
			'issuer_link' => $row['id_member'] ? ('<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['member_name_col'] . '</a>') : $row['member_name_col'],
			'recipient_link' => $row['id_recipient'] ? ('<a href="' . $scripturl . '?action=profile;u=' . $row['id_recipient'] . '">' . $row['recipient_name'] . '</a>') : $row['recipient_name'],
			'time' => standardTime($row['log_time']),
			'reason' => $row['body'],
			'counter' => $row['counter'] > 0 ? '+' . $row['counter'] : $row['counter'],
			'id_notice' => $row['id_notice'],
		);
	}
	$db->free_result($request);

	return $warnings;
}

/**
 * Get the total count of all current warnings.
 *
 * @return int
 */
function warningCount()
{
	$db = database();

	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}log_comments
		WHERE comment_type = {string:warning}',
		array(
			'warning' => 'warning',
		)
	);
	list ($totalWarns) = $db->fetch_row($request);
	$db->free_result($request);

	return $totalWarns;
}

/**
 * Loads a moderation template in to context for use in editing a template
 *
 * @param type $id_template
 */
function modLoadTemplate($id_template, $template_type = 'warntpl')
{
	global $user_info, $context;

	$db = database();

	$request = $db->query('', '
		SELECT id_member, id_recipient, recipient_name AS template_title, body
		FROM {db_prefix}log_comments
		WHERE id_comment = {int:id}
			AND comment_type = {string:tpltype}
			AND (id_recipient = {int:generic} OR id_recipient = {int:current_member})',
		array(
			'id' => $id_template,
			'tpltype' => $template_type,
			'generic' => 0,
			'current_member' => $user_info['id'],
		)
	);
	while ($row = $db->fetch_assoc($request))
	{
		$context['template_data'] = array(
			'title' => $row['template_title'],
			'body' => Util::htmlspecialchars($row['body']),
			'personal' => $row['id_recipient'],
			'can_edit_personal' => $row['id_member'] == $user_info['id'],
		);
	}
	$db->free_result($request);
}

/**
 * Updates an existing template or adds in a new one to the log comments table
 *
 * @param int $recipient_id
 * @param string $template_title
 * @param string $template_body
 * @param int $id_template
 * @param bool $edit true to update, false to insert a new row
 */
function modAddUpdateTemplate($recipient_id, $template_title, $template_body, $id_template, $edit = true, $type = 'warntpl')
{
	global $user_info;

	$db = database();

	if ($edit)
	{
		// Simple update...
		$db->query('', '
			UPDATE {db_prefix}log_comments
			SET id_recipient = {int:personal}, recipient_name = {string:title}, body = {string:body}
			WHERE id_comment = {int:id}
				AND comment_type = {string:comment_type}
				AND (id_recipient = {int:generic} OR id_recipient = {int:current_member})'.
				($recipient_id ? ' AND id_member = {int:current_member}' : ''),
			array(
				'personal' => $recipient_id,
				'title' => $template_title,
				'body' => $template_body,
				'id' => $id_template,
				'comment_type' => $type,
				'generic' => 0,
				'current_member' => $user_info['id'],
			)
		);
	}
	// Or inserting a new row
	else
	{
		$db->insert('',
			'{db_prefix}log_comments',
			array(
				'id_member' => 'int', 'member_name' => 'string', 'comment_type' => 'string', 'id_recipient' => 'int',
				'recipient_name' => 'string-255', 'body' => 'string-65535', 'log_time' => 'int',
			),
			array(
				$user_info['id'], $user_info['name'], $type, $recipient_id,
				$template_title, $template_body, time(),
			),
			array('id_comment')
		);
	}
}

/**
 * Get the report details, need this so we can limit access to a particular board
 * 	 - returns false if they are requesting a report they can not see or does not exist
 */
function modReportDetails($id_report)
{
	global $user_info;

	$db = database();

	$request = $db->query('', '
		SELECT lr.id_report, lr.id_msg, lr.id_topic, lr.id_board, lr.id_member, lr.subject, lr.body,
			lr.time_started, lr.time_updated, lr.num_reports, lr.closed, lr.ignore_all,
			IFNULL(mem.real_name, lr.membername) AS author_name, IFNULL(mem.id_member, 0) AS id_author
		FROM {db_prefix}log_reported AS lr
			LEFT JOIN {db_prefix}members AS mem ON (mem.id_member = lr.id_member)
		WHERE lr.id_report = {int:id_report}
			AND ' . ($user_info['mod_cache']['bq'] == '1=1' || $user_info['mod_cache']['bq'] == '0=1' ? $user_info['mod_cache']['bq'] : 'lr.' . $user_info['mod_cache']['bq']) . '
		LIMIT 1',
		array(
			'id_report' => $id_report,
		)
	);

	// So did we find anything?
	if (!$db->num_rows($request))
		$row = false;
	else
		$row = $db->fetch_assoc($request);

	$db->free_result($request);

	return $row;
}

/**
 * This is a helper function: approve everything unapproved.
 * Used from moderation panel.
 */
function approveAllUnapproved()
{
	$db = database();

	// Start with messages and topics.
	$request = $db->query('', '
		SELECT id_msg
		FROM {db_prefix}messages
		WHERE approved = {int:not_approved}',
		array(
			'not_approved' => 0,
		)
	);
	$msgs = array();
	while ($row = $db->fetch_row($request))
		$msgs[] = $row[0];
	$db->free_result($request);

	if (!empty($msgs))
	{
		require_once(SUBSDIR . '/Post.subs.php');
		approvePosts($msgs);
		cache_put_data('num_menu_errors', null, 900);
	}

	// Now do attachments
	$request = $db->query('', '
		SELECT id_attach
		FROM {db_prefix}attachments
		WHERE approved = {int:not_approved}',
		array(
			'not_approved' => 0,
		)
	);
	$attaches = array();
	while ($row = $db->fetch_row($request))
		$attaches[] = $row[0];
	$db->free_result($request);

	if (!empty($attaches))
	{
		require_once(SUBSDIR . '/Attachments.subs.php');
		approveAttachments($attaches);
		cache_put_data('num_menu_errors', null, 900);
	}
}

/**
 * Returns the number of watched users in the system.
 * (used by createList() callbacks).
 *
 * @param string $approve_query
 * @param int $warning_watch
 * @return int
 */
function watchedUserCount($approve_query, $warning_watch = 0)
{
	$db = database();

	// @todo $approve_query is not used

	$request = $db->query('', '
		SELECT COUNT(*)
		FROM {db_prefix}members
		WHERE warning >= {int:warning_watch}',
		array(
			'warning_watch' => $warning_watch,
		)
	);
	list ($totalMembers) = $db->fetch_row($request);
	$db->free_result($request);

	return $totalMembers;
}

/**
 * Retrieved the watched users in the system.
 * (used by createList() callbacks).
 *
 * @param int $start
 * @param int $items_per_page
 * @param string $sort
 * @param string $approve_query
 * @param string $dummy
 */
function watchedUsers($start, $items_per_page, $sort, $approve_query, $dummy)
{
	global $txt, $modSettings, $user_info;

	$db = database();
	$request = $db->query('', '
		SELECT id_member, real_name, last_login, posts, warning
		FROM {db_prefix}members
		WHERE warning >= {int:warning_watch}
		ORDER BY {raw:sort}
		LIMIT ' . $start . ', ' . $items_per_page,
		array(
			'warning_watch' => $modSettings['warning_watch'],
			'sort' => $sort,
		)
	);
	$watched_users = array();
	$members = array();
	while ($row = $db->fetch_assoc($request))
	{
		$watched_users[$row['id_member']] = array(
			'id' => $row['id_member'],
			'name' => $row['real_name'],
			'last_login' => $row['last_login'] ? standardTime($row['last_login']) : $txt['never'],
			'last_post' => $txt['not_applicable'],
			'last_post_id' => 0,
			'warning' => $row['warning'],
			'posts' => $row['posts'],
		);
		$members[] = $row['id_member'];
	}
	$db->free_result($request);

	if (!empty($members))
	{
		// First get the latest messages from these users.
		$request = $db->query('', '
			SELECT m.id_member, MAX(m.id_msg) AS last_post_id
			FROM {db_prefix}messages AS m' . ($user_info['query_see_board'] == '1=1' ? '' : '
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board AND {query_see_board})') . '
			WHERE m.id_member IN ({array_int:member_list})' . (!$modSettings['postmod_active'] || allowedTo('approve_posts') ? '' : '
				AND m.approved = {int:is_approved}') . '
			GROUP BY m.id_member',
			array(
				'member_list' => $members,
				'is_approved' => 1,
			)
		);
		$latest_posts = array();
		while ($row = $db->fetch_assoc($request))
			$latest_posts[$row['id_member']] = $row['last_post_id'];

		if (!empty($latest_posts))
		{
			// Now get the time those messages were posted.
			$request = $db->query('', '
				SELECT id_member, poster_time
				FROM {db_prefix}messages
				WHERE id_msg IN ({array_int:message_list})',
				array(
					'message_list' => $latest_posts,
				)
			);
			while ($row = $db->fetch_assoc($request))
			{
				$watched_users[$row['id_member']]['last_post'] = standardTime($row['poster_time']);
				$watched_users[$row['id_member']]['last_post_id'] = $latest_posts[$row['id_member']];
			}

			$db->free_result($request);
		}

		$request = $db->query('', '
			SELECT MAX(m.poster_time) AS last_post, MAX(m.id_msg) AS last_post_id, m.id_member
			FROM {db_prefix}messages AS m' . ($user_info['query_see_board'] == '1=1' ? '' : '
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board AND {query_see_board})') . '
			WHERE m.id_member IN ({array_int:member_list})' . (!$modSettings['postmod_active'] || allowedTo('approve_posts') ? '' : '
				AND m.approved = {int:is_approved}') . '
			GROUP BY m.id_member',
			array(
				'member_list' => $members,
				'is_approved' => 1,
			)
		);
		while ($row = $db->fetch_assoc($request))
		{
			$watched_users[$row['id_member']]['last_post'] = standardTime($row['last_post']);
			$watched_users[$row['id_member']]['last_post_id'] = $row['last_post_id'];
		}
		$db->free_result($request);
	}

	return $watched_users;
}

/**
 * Count of posts of watched users.
 * (used by createList() callbacks)
 *
 * @param string $approve_query
 * @param int $warning_watch
 * @return int
 */
function watchedUserPostsCount($approve_query, $warning_watch)
{
	$db = database();

	// @todo $approve_query is not used in the function

	$request = $db->query('', '
		SELECT COUNT(*)
			FROM {db_prefix}messages AS m
				INNER JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
				INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
			WHERE mem.warning >= {int:warning_watch}
				AND {query_see_board}
				' . $approve_query,
		array(
			'warning_watch' => $warning_watch,
		)
	);
	list ($totalMemberPosts) = $db->fetch_row($request);
	$db->free_result($request);

	return $totalMemberPosts;
}

/**
 * Retrieve the posts of watched users.
 * (used by createList() callbacks).
 *
 * @param int $start
 * @param int $items_per_page
 * @param string $sort
 * @param string $approve_query
 * @param array $delete_boards
 */
function watchedUserPosts($start, $items_per_page, $sort, $approve_query, $delete_boards)
{
	global $scripturl, $modSettings;

	$db = database();

	$request = $db->query('', '
		SELECT m.id_msg, m.id_topic, m.id_board, m.id_member, m.subject, m.body, m.poster_time,
			m.approved, mem.real_name, m.smileys_enabled
		FROM {db_prefix}messages AS m
			INNER JOIN {db_prefix}members AS mem ON (mem.id_member = m.id_member)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE mem.warning >= {int:warning_watch}
			AND {query_see_board}
			' . $approve_query . '
		ORDER BY m.id_msg DESC
		LIMIT ' . $start . ', ' . $items_per_page,
		array(
			'warning_watch' => $modSettings['warning_watch'],
		)
	);
	$member_posts = array();
	while ($row = $db->fetch_assoc($request))
	{
		$row['subject'] = censorText($row['subject']);
		$row['body'] = censorText($row['body']);

		$member_posts[$row['id_msg']] = array(
			'id' => $row['id_msg'],
			'id_topic' => $row['id_topic'],
			'author_link' => '<a href="' . $scripturl . '?action=profile;u=' . $row['id_member'] . '">' . $row['real_name'] . '</a>',
			'subject' => $row['subject'],
			'body' => parse_bbc($row['body'], $row['smileys_enabled'], $row['id_msg']),
			'poster_time' => standardTime($row['poster_time']),
			'approved' => $row['approved'],
			'can_delete' => $delete_boards == array(0) || in_array($row['id_board'], $delete_boards),
		);
	}
	$db->free_result($request);

	return $member_posts;
}