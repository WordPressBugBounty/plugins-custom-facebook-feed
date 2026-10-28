<?php

namespace CustomFacebookFeed\Abilities\Services;

use CustomFacebookFeed\Abilities\CFF_State_Token;
use CustomFacebookFeed\Builder\CFF_Db;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Connected-account (source) operations for the Abilities API.
 *
 * Always redacted: never returns access tokens, the encrypted `info` blob or
 * raw error payloads. Connecting an account remains a HUMAN step — this
 * service only builds the connect link; the OAuth handshake happens in the
 * admin's browser through the existing Smash Balloon relay.
 *
 * @since 4.13
 */
class CFF_Source_Service
{
	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function list_sources()
	{
		$out = array();
		foreach ((array) CFF_Db::source_query() as $row) {
			if (!is_array($row) || empty($row['username'])) {
				continue;
			}
			$out[] = array(
				'username'      => (string) $row['username'],
				'account_type'  => isset($row['account_type']) ? (string) $row['account_type'] : '',
				'privilege'     => isset($row['privilege']) ? (string) $row['privilege'] : '',
				'expires'       => isset($row['expires']) ? substr((string) $row['expires'], 0, 10) : '',
				'has_error'     => !empty($row['error']),
				'used_in_feeds' => isset($row['used_in']) ? (int) $row['used_in'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Build the account-connect URL for the human to open.
	 *
	 * Points at OUR wp-admin bootstrap page (CFF_Connect_Bootstrap), which —
	 * in the admin's browser session — reproduces the exact POST form the Feed
	 * Builder's Connect button submits to the OAuth relay, with a fresh
	 * session nonce. The single-use state token in this URL never leaves the
	 * site; the relay only sees the builder's own fields.
	 *
	 * The plugin has no "connect as a group" input to mirror: `has_group` on
	 * its own connect URLs means "this site already has group sources"
	 * (CFF_Db::check_group_source() > 0), the SAME value on both the 'page'
	 * and 'group' entries — CFF_Connect_Bootstrap computes that flag itself.
	 *
	 * @return array{connect_url: string, expires_in: int, instructions: string}
	 */
	public function get_connect_url()
	{
		$token = CFF_State_Token::create('connect');
		if (is_wp_error($token)) {
			return $token;
		}

		$args = array(
			'page'                  => 'cff-feed-builder',
			'cff_abilities_connect' => $token,
		);

		return array(
			'connect_url'  => add_query_arg($args, admin_url('admin.php')),
			'expires_in'   => CFF_State_Token::TTL,
			'instructions' => __('Open this link in the browser where you are logged into wp-admin. It forwards you to the Facebook login; afterwards you return to Facebook Feed → Settings, where the account/group picker opens and the new source appears under Manage Sources. The link is single-use and expires in 15 minutes. The AI assistant never sees your Meta credentials.', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
		);
	}

	/**
	 * Whether this site may attach more than one source to a feed: only with the
	 * paid Multifeed extension. Same check the Feed Builder uses to decide
	 * between "Change" and "Add new" on the sources control
	 * (CFF_Feed_Saver_Manager), which is false in the free plugin.
	 *
	 * @return bool
	 */
	public function multifeed_active()
	{
		return (bool) \CustomFacebookFeed\CFF_FB_Settings::check_active_extension('multifeed');
	}

	/**
	 * Resolve usernames/account ids to source rows.
	 *
	 * @param string[] $handles usernames or numeric account ids.
	 * @return array{ids: string[], details: array, missing: string[]}
	 */
	public function resolve_sources(array $handles)
	{
		$rows    = (array) CFF_Db::source_query();
		$ids     = array();
		$details = array();
		$missing = array();
		foreach ($handles as $handle) {
			$handle = trim((string) $handle);
			$found  = null;
			foreach ($rows as $row) {
				if (
					(isset($row['username']) && strcasecmp($row['username'], $handle) === 0)
					|| (isset($row['account_id']) && (string) $row['account_id'] === $handle)
				) {
					$found = $row;
					break;
				}
			}
			if ($found === null) {
				$missing[] = $handle;
				continue;
			}
			$account_id = (string) $found['account_id'];
			if (in_array($account_id, $ids, true)) {
				continue; // Two handles resolved to the same account.
			}
			$ids[]     = $account_id;
			$details[] = array(
				'id'       => $account_id,
				'username' => (string) $found['username'],
			);
		}
		return array('ids' => $ids, 'details' => $details, 'missing' => $missing);
	}
}
