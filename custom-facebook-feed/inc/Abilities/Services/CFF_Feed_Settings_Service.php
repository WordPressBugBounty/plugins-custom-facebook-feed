<?php

namespace CustomFacebookFeed\Abilities\Services;

use CustomFacebookFeed\Abilities\CFF_Ability_Response;
use CustomFacebookFeed\Builder\CFF_Db;
use CustomFacebookFeed\Builder\CFF_Feed_Saver;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Reads/writes Feed Builder feeds for the Abilities API (FREE edition).
 *
 * Wraps CFF_Db / CFF_Feed_Saver and REDACTS everything an agent must not see:
 * CFF_Feed_Saver::get_feed_settings() hydrates connected sources with the
 * DECRYPTED access token, so this service never returns raw source rows.
 *
 * Ported from custom-facebook-feed-pro's Abilities module (SBR-195/SBR-510).
 * No tier concept exists in free, so `tier_check`/`is_feature_available` and
 * the FEED_TEMPLATES/template-expansion machinery are dropped entirely
 * rather than stubbed (a stub would invite a later Pro-only key to be added
 * behind it) — see projects/custom-facebook-feed/abilities-free-plan.md §2.
 *
 * @since 4.14
 */
class CFF_Feed_Settings_Service
{
	const MAX_PER_PAGE = 50;

	/**
	 * Per-feed keys whose change alters which posts are fetched from Facebook.
	 *
	 * @var string[]
	 */
	const RETRIEVAL_KEYS = array('num', 'nummobile', 'feedtype');

	/** Keys never allowed to leak from a hydrated source row. */
	const SOURCE_REDACT_KEYS = array(
		'access_token', 'accesstoken', 'ownaccesstoken', 'pagetoken', 'info', 'error',
	);

	/**
	 * @param int $page 1-based.
	 * @param int $per_page
	 * @param string $status publish|draft|trash|all (default publish).
	 * @return array{feeds: array, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function list_feeds($page = 1, $per_page = 20, $status = 'publish')
	{
		global $wpdb;

		$page     = max(1, (int) $page);
		$per_page = min(self::MAX_PER_PAGE, max(1, (int) $per_page));
		$offset   = ($page - 1) * $per_page;
		$table    = $wpdb->prefix . 'cff_feeds';

		$status = in_array($status, array('publish', 'draft', 'trash', 'all'), true) ? $status : 'publish';
		if ($status === 'all') {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant.
			$sql = $wpdb->prepare(
				"SELECT id, feed_name, feed_title, settings, status, last_modified
				FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant.
			$sql = $wpdb->prepare(
				"SELECT id, feed_name, feed_title, settings, status, last_modified
				FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$status,
				$per_page,
				$offset
			);
		}
		$rows = $wpdb->get_results($sql, ARRAY_A);

		$feeds = array();
		foreach ((array) $rows as $row) {
			$settings = json_decode($row['settings'], true);
			$settings = is_array($settings) ? $settings : array();
			$feeds[]  = array(
				'id'            => (int) $row['id'],
				'feed_name'     => (string) $row['feed_name'],
				'feed_title'    => (string) $row['feed_title'],
				'feedtype'      => isset($settings['feedtype']) ? (string) $settings['feedtype'] : 'timeline',
				'feedlayout'    => isset($settings['feedlayout']) ? (string) $settings['feedlayout'] : 'list',
				'status'        => (string) $row['status'],
				'last_modified' => (string) $row['last_modified'],
				'shortcode'     => '[custom-facebook-feed feed=' . (int) $row['id'] . ']',
			);
		}

		if ($status === 'all') {
			$total = CFF_Db::feeds_count();
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant.
			$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $status));
		}

		return array(
			'feeds'       => $feeds,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil($total / $per_page),
		);
	}

	/**
	 * @param int $feed_id
	 * @return array|null Null when the feed does not exist.
	 */
	public function get_feed($feed_id)
	{
		$feed_id = (int) $feed_id;
		if ($feed_id <= 0) {
			return null;
		}

		$saver    = new CFF_Feed_Saver($feed_id);
		$settings = $saver->get_feed_settings();
		if (!is_array($settings)) {
			return null;
		}
		$db = $saver->get_feed_db_data();

		$defaults      = CFF_Feed_Saver::settings_defaults();
		$schema        = CFF_Settings_Schema::feed_settings();
		$out           = array();
		$value_notes   = array();
		foreach ($schema as $key => $def) {
			$note      = null;
			$out[$key] = CFF_Settings_Schema::cast_out(
				$def,
				isset($settings[$key]) ? $settings[$key] : null,
				isset($defaults[$key]) ? $defaults[$key] : null,
				$note,
				$key
			);
			if ($note !== null) {
				$value_notes[] = $note;
			}
		}
		// Empty-stored resolution BEFORE cast_out sees it (AgDR-9004 requirement
		// 1, the storiestime pattern): nummobile stores '' natively, textlength/
		// desclength can be stored empty from a legacy row. A legal-in-range
		// coercion must never masquerade as a deliberate value.
		if (array_key_exists('nummobile', $settings) && $settings['nummobile'] === '') {
			$out['nummobile'] = 0;
		}
		if (array_key_exists('textlength', $settings) && $settings['textlength'] === '') {
			$out['textlength'] = 400;
		}
		if (array_key_exists('desclength', $settings) && $settings['desclength'] === '') {
			$out['desclength'] = 200;
		}
		$out['feed_name'] = isset($db['feed_name']) ? (string) $db['feed_name'] : $out['feed_name'];

		list($sources, $source_warnings) = $this->resolve_raw_sources($feed_id);
		$warnings = array_merge($source_warnings, $value_notes);

		return array(
			'id'            => $feed_id,
			'feed_title'    => isset($db['feed_title']) ? (string) $db['feed_title'] : '',
			'status'        => isset($db['status']) ? (string) $db['status'] : '',
			'last_modified' => isset($db['last_modified']) ? (string) $db['last_modified'] : '',
			'shortcode'     => '[custom-facebook-feed feed=' . $feed_id . ']',
			'sources'       => $sources,
			'warnings'      => $warnings,
			'settings'      => $out,
		);
	}

	/**
	 * Keep only what identifies the account. Never tokens, `info`, or errors.
	 *
	 * @param array $sources
	 * @return array<int, array{username: string, account_type: string}>
	 */
	private function redact_sources($sources)
	{
		$out = array();
		foreach ((array) $sources as $source) {
			if (!is_array($source)) {
				continue;
			}
			foreach (self::SOURCE_REDACT_KEYS as $redacted) {
				if (array_key_exists($redacted, $source)) {
					unset($source[$redacted]);
				}
			}
			$out[] = array(
				'username'     => isset($source['username']) ? (string) $source['username'] : '',
				'account_type' => isset($source['account_type']) ? (string) $source['account_type'] : '',
			);
		}
		return $out;
	}

	/**
	 * Resolve `get-feed`'s `sources` from the RAW `cff_feeds.settings` row,
	 * never from `CFF_Feed_Saver::get_feed_settings()`'s hydrated array — see
	 * the "phantom source" note in the plan (§4): when a feed's saved source
	 * id no longer resolves, get_feed_settings() falls back to
	 * CFF_Db::source_query() with NO arguments, i.e. the site's first
	 * connected account, which would misreport someone else's page as this
	 * feed's source. Anything that doesn't resolve is surfaced as an explicit
	 * warning instead of silently substituted (AgDR-9004 finding L2).
	 *
	 * @param int $feed_id
	 * @return array{0: array<int, array{username: string, account_type: string}>, 1: string[]}
	 */
	private function resolve_raw_sources($feed_id)
	{
		$rows = CFF_Db::feeds_query(array('id' => $feed_id));
		$raw  = (!empty($rows) && isset($rows[0]['settings'])) ? json_decode($rows[0]['settings'], true) : array();
		$raw  = is_array($raw) ? $raw : array();

		$raw_sources = isset($raw['sources']) ? $raw['sources'] : array();
		$ids         = array();
		if (is_array($raw_sources)) {
			foreach ($raw_sources as $source) {
				$id = is_array($source) ? (isset($source['account_id']) ? $source['account_id'] : null) : $source;
				if ($id !== null && $id !== '') {
					$ids[] = $id;
				}
			}
		} elseif (is_scalar($raw_sources) && $raw_sources !== '') {
			$ids[] = $raw_sources;
		}

		if (empty($ids)) {
			return array(array(), array());
		}

		$source_args    = array('id' => $ids);
		$is_events_feed = (
			isset($raw['type'])
			&& $raw['type'] === 'events'
			&& isset($raw['eventsource'])
			&& $raw['eventsource'] === 'eventspage'
		) || (isset($raw['feedtype']) && $raw['feedtype'] === 'events');
		if ($is_events_feed) {
			$source_args['privilege'] = 'events';
		}

		$found_rows = CFF_Db::source_query($source_args);
		$found_rows = is_array($found_rows) ? $found_rows : array();

		if (empty($found_rows)) {
			$retry_args = $source_args;
			if (isset($retry_args['privilege'])) {
				unset($retry_args['privilege']);
			} else {
				$retry_args['privilege'] = 'events';
			}
			$found_rows = CFF_Db::source_query($retry_args);
			$found_rows = is_array($found_rows) ? $found_rows : array();
		}

		$found_ids = array();
		foreach ($found_rows as $row) {
			$found_ids[] = isset($row['account_id']) ? stripslashes($row['account_id']) : null;
		}

		$warnings = array();
		foreach ($ids as $id) {
			if (!in_array((string) $id, $found_ids, true)) {
				$warnings[] = sprintf(
					/* translators: %s: the feed's stored source id */
					__('Source %s is no longer connected and was left out of "sources".', 'custom-facebook-feed'),
					(string) $id
				);
			}
		}

		$unslashed_rows = array_map(
			function ($row) {
				return array(
					'username'     => isset($row['username']) ? stripslashes($row['username']) : '',
					'account_type' => isset($row['account_type']) ? stripslashes($row['account_type']) : '',
				);
			},
			$found_rows
		);

		return array($this->redact_sources($unslashed_rows), $warnings);
	}

	/**
	 * Apply a partial update to one feed's settings.
	 *
	 * CFF_Feed_Saver::set_data()/update_or_insert() REPLACES the stored
	 * settings JSON with whatever was passed, so we merge into the RAW stored
	 * row (never the token-hydrated get_feed_settings() array).
	 *
	 * @param int   $feed_id
	 * @param array $partial key => value, already schema-validated by core.
	 * @return array{feed_id: int, updated: string[], ignored: object, warnings: string[]}|\WP_Error|null
	 *         Null when the feed does not exist. WP_Error also covers an
	 *         incomplete coupled-key write (e.g. colorpalette: custom with no
	 *         custom colours) — see CFF_Settings_Schema::COUPLED_GROUPS.
	 */
	public function update_feed($feed_id, array $partial)
	{
		$feed_id = (int) $feed_id;
		if ($feed_id <= 0) {
			return null;
		}
		$rows = CFF_Db::feeds_query(array('id' => $feed_id));
		if (empty($rows)) {
			return null;
		}
		$row    = $rows[0];
		$stored = json_decode($row['settings'], true);
		$stored = is_array($stored) ? $stored : array();

		$schema   = CFF_Settings_Schema::feed_settings();
		$writable = CFF_Settings_Schema::writable($schema);
		$updated  = array();
		$ignored  = array();
		$clean    = array();
		$new_name = null;

		foreach ($partial as $key => $value) {
			if (!isset($schema[$key])) {
				$ignored[$key] = 'unknown_setting';
				continue;
			}
			if (!isset($writable[$key])) {
				$ignored[$key] = 'read_only';
				continue;
			}
			$v = CFF_Settings_Schema::validate_in($schema[$key], $value, $key);
			if (is_wp_error($v)) {
				return $v;
			}
			if ($key === 'feed_name') {
				$new_name  = $v;
				$updated[] = $key;
				continue;
			}
			$clean[$key] = $this->to_native($schema[$key], $v);
		}

		if (empty($clean) && $new_name === null) {
			return array(
				'feed_id'  => $feed_id,
				'updated'  => array(),
				'ignored'  => (object) $ignored,
				'warnings' => array(),
			);
		}

		$merged = array_merge($stored, $clean);

		$coupling_error = $this->check_coupled_groups($clean, $merged);
		if (is_wp_error($coupling_error)) {
			return $coupling_error;
		}

		$warnings = $this->inert_write_warnings($clean, $merged);

		$saver = new CFF_Feed_Saver($feed_id);
		$saver->set_feed_name($new_name !== null ? $new_name : (string) $row['feed_name']);
		$saver->set_data($merged);
		// CFF_Db::feeds_update() returns $wpdb->update(): rows changed, or false on
		// error. An idempotent replay can legitimately change 0 rows.
		if ($saver->update_or_insert() === false) {
			return new \WP_Error(
				'cff_feed_save_failed',
				__('The feed could not be saved.', 'custom-facebook-feed'),
				array('status' => 500)
			);
		}
		$updated = array_merge($updated, array_keys($clean));

		if (class_exists('\CustomFacebookFeed\CFF_Cache')) {
			$cache = new \CustomFacebookFeed\CFF_Cache($feed_id);
			// 'all' only empties the rendered/header cache rows (local, cheap) so the
			// change shows immediately. 'posts' forces a Facebook API refetch, so it is
			// limited to keys that change WHAT is fetched — otherwise an agent looping
			// over cosmetic keys would burn Graph API quota.
			$cache->clear('all');
			if (array_intersect(self::RETRIEVAL_KEYS, array_keys($clean))) {
				$cache->clear('posts');
			}
		}

		/**
		 * Fires after an AI agent updated a feed through the Abilities API.
		 *
		 * @param int      $feed_id
		 * @param string[] $updated Keys that were written.
		 */
		do_action('cff_abilities_feed_settings_updated', $feed_id, $updated);

		return array(
			'feed_id'  => $feed_id,
			'updated'  => $updated,
			'ignored'  => (object) $ignored,
			'warnings' => $warnings,
		);
	}

	/**
	 * Create a feed the way the builder's new_insert path does, minus the
	 * template step: free has no feed-templates trait (templates are an
	 * upsell popup only), so create-feed always seeds a bare `feedtype:
	 * timeline` row plus whatever quick settings were explicitly passed.
	 *
	 * @param array $input {feed_name?, sources?, settings?}
	 * @return array|\WP_Error
	 */
	public function create_feed(array $input)
	{
		$settings_data = array(
			'feedtype' => 'timeline',
		);

		$ignored = array();
		$updated = array();
		if (!empty($input['settings']) && is_array($input['settings'])) {
			$schema   = CFF_Settings_Schema::feed_settings();
			$writable = CFF_Settings_Schema::writable($schema);
			foreach ($input['settings'] as $key => $value) {
				if ($key === 'feed_name' || !isset($writable[$key])) {
					$ignored[$key] = isset($schema[$key]) ? 'read_only' : 'unknown_setting';
					continue;
				}
				$v = CFF_Settings_Schema::validate_in($schema[$key], $value, $key);
				if (is_wp_error($v)) {
					return $v;
				}
				$settings_data[$key] = $this->to_native($schema[$key], $v);
				$updated[]           = $key;
			}

			$coupling_error = $this->check_coupled_groups($settings_data, $settings_data);
			if (is_wp_error($coupling_error)) {
				return $coupling_error;
			}
		}
		$warnings = $this->inert_write_warnings($settings_data, $settings_data);

		$source_usernames = array();
		if (!empty($input['sources']) && is_array($input['sources'])) {
			$source_service = new CFF_Source_Service();
			$resolved       = $source_service->resolve_sources($input['sources']);
			if (!empty($resolved['missing'])) {
				return new \WP_Error(
					'cff_source_not_found',
					sprintf(
						/* translators: %s: comma-separated account names */
						__(
							'These accounts are not connected to this site: %s. '
							. 'Use get-connect-url to connect one first.',
							'custom-facebook-feed'
						),
						CFF_Ability_Response::safe_list($resolved['missing'])
					),
					array('status' => 404)
				);
			}
			if (count($resolved['ids']) > 1 && !$source_service->multifeed_active()) {
				return CFF_Ability_Response::multifeed_required();
			}
			// The authoritative persisted source list is `sources` — a flat list of
			// account_id strings. `id` is a derived legacy scalar that
			// CFF_Feed_Saver::get_feed_settings() overwrites on read.
			$settings_data['sources'] = $resolved['ids'];
			foreach ($resolved['details'] as $d) {
				$source_usernames[] = $d['username'];
			}
		}
		// A sourceless feed (no connected page yet) is intentionally allowed —
		// the same way the builder lets you create a feed then connect later.
		// Free's own Feed Builder can open and render a sourceless timeline feed
		// (it shows the empty/connect-prompt state), so no extra guard is needed
		// here to keep create-feed's output openable by the plugin's own UI.

		$feed_name = !empty($input['feed_name'])
			? sanitize_text_field((string) $input['feed_name'])
			: CFF_Db::feeds_query_name(
				!empty($source_usernames) ? $source_usernames[0] : __('Facebook Feed', 'custom-facebook-feed')
			);

		// Free never writes `cff_pro_first_feed_created` — that option, and the
		// insert_id-clobbering bug it works around, is Pro-only.
		$saver = new CFF_Feed_Saver(false);
		$saver->set_feed_name($feed_name);
		$saver->set_data($settings_data);
		if (!$saver->update_or_insert()) {
			return new \WP_Error(
				'cff_feed_save_failed',
				__('The feed could not be created.', 'custom-facebook-feed'),
				array('status' => 500)
			);
		}
		$feed_id = (int) $saver->get_feed_id();

		/** This action is documented in CFF_Feed_Settings_Service::update_feed(). */
		do_action('cff_abilities_feed_settings_updated', $feed_id, $updated);

		return array(
			'feed_id'   => $feed_id,
			'feed_name' => $feed_name,
			'shortcode' => '[custom-facebook-feed feed=' . $feed_id . ']',
			'updated'   => $updated,
			'ignored'   => (object) $ignored,
			'warnings'  => $warnings,
		);
	}

	/**
	 * Attach already-connected accounts to a feed (replaces the source list).
	 *
	 * @param int      $feed_id
	 * @param string[] $handles usernames or account ids.
	 * @return array|\WP_Error|null Null when the feed does not exist.
	 */
	public function set_feed_sources($feed_id, array $handles)
	{
		$feed_id = (int) $feed_id;
		$rows    = CFF_Db::feeds_query(array('id' => $feed_id));
		if ($feed_id <= 0 || empty($rows)) {
			return null;
		}
		$source_service = new CFF_Source_Service();
		$resolved       = $source_service->resolve_sources($handles);
		if (!empty($resolved['missing'])) {
			return new \WP_Error(
				'cff_source_not_found',
				sprintf(
					/* translators: %s: comma-separated account names */
					__(
						'These accounts are not connected to this site: %s. '
						. 'Use get-connect-url to connect one first.',
						'custom-facebook-feed'
					),
					CFF_Ability_Response::safe_list($resolved['missing'])
				),
				array('status' => 404)
			);
		}
		if (count($resolved['ids']) > 1 && !$source_service->multifeed_active()) {
			return CFF_Ability_Response::multifeed_required();
		}
		if (empty($resolved['ids'])) {
			return new \WP_Error(
				'cff_invalid_value',
				__('At least one connected account is required.', 'custom-facebook-feed'),
				array('status' => 400)
			);
		}

		$stored = json_decode($rows[0]['settings'], true);
		$stored = is_array($stored) ? $stored : array();
		// `sources` is the authoritative persisted source list; `id` is a derived
		// legacy scalar that get_feed_settings() overwrites on read.
		// CFF_Feed_Saver::format_settings() collapses a single-element `sources`
		// list to its scalar element on save; passing the resolved array through
		// unchanged is safe — the saver performs the collapse itself.
		$stored['sources'] = $resolved['ids'];

		$saver = new CFF_Feed_Saver($feed_id);
		$saver->set_feed_name((string) $rows[0]['feed_name']);
		$saver->set_data($stored);
		if ($saver->update_or_insert() === false) {
			return new \WP_Error(
				'cff_feed_save_failed',
				__('The feed could not be saved.', 'custom-facebook-feed'),
				array('status' => 500)
			);
		}

		if (class_exists('\CustomFacebookFeed\CFF_Cache')) {
			$cache = new \CustomFacebookFeed\CFF_Cache($feed_id);
			$cache->clear('all');
			$cache->clear('posts'); // source change always alters what is fetched.
		}

		/** This action is documented in CFF_Feed_Settings_Service::update_feed(). */
		do_action('cff_abilities_feed_settings_updated', $feed_id, array('sources'));

		return array(
			'feed_id' => $feed_id,
			'sources' => array_map(
				function ($d) {
					return $d['username'];
				},
				$resolved['details']
			),
		);
	}

	/**
	 * Re-encode a validated value into the shape settings_defaults() expects
	 * for that key (booleans only — see CFF_Settings_Schema::encode_bool()).
	 *
	 * @param array $def
	 * @param mixed $v
	 * @return mixed
	 */
	private function to_native(array $def, $v)
	{
		if ($def['type'] === 'boolean') {
			return CFF_Settings_Schema::encode_bool($v, $def);
		}
		return is_scalar($v) ? (string) $v : $v;
	}

	/**
	 * Warn (never refuse) when a value being written is currently inert per
	 * the render path — a Free-specific class of coupling AgDR-9004
	 * requirement 3 asks for: an accepted-but-inert write must surface a
	 * signal, not a silent no-op success. Two couplings, per the plan §3a:
	 *   - cols/colstablet/colsmobile written while feedlayout is "list"
	 *     (render forces a single column).
	 *   - postbgcolor/postcorners/boxshadow written while poststyle is
	 *     "regular" (render never applies boxed-only styling).
	 *
	 * @param array $clean  Keys being written THIS call (already validated).
	 * @param array $merged Resulting settings row (stored merged with $clean).
	 * @return string[]
	 */
	private function inert_write_warnings(array $clean, array $merged)
	{
		$warnings = array();

		$cols_keys = array('cols', 'colstablet', 'colsmobile');
		if (array_intersect($cols_keys, array_keys($clean)) && ($merged['feedlayout'] ?? 'list') === 'list') {
			$warnings[] = __(
				'cols/colstablet/colsmobile were written but are currently inert: feedlayout is "list", '
				. 'which the render path always shows as a single column.',
				'custom-facebook-feed'
			);
		}

		$boxed_keys = array('postbgcolor', 'postcorners', 'boxshadow');
		if (array_intersect($boxed_keys, array_keys($clean)) && ($merged['poststyle'] ?? 'regular') === 'regular') {
			$warnings[] = __(
				'postbgcolor/postcorners/boxshadow were written but are currently inert: poststyle is '
				. '"regular", which never applies boxed-post styling.',
				'custom-facebook-feed'
			);
		}

		return $warnings;
	}

	/**
	 * Refuse a write that sets a coupled trigger key (colorpalette, dateformat)
	 * to its "requires companions" value without those companions resolving to
	 * a real value — either in this same write or already stored. Without
	 * this, an agent can set `colorpalette: custom` or `dateformat: custom`
	 * and land the feed in a state it can never see is incomplete
	 * (AgDR-9004 requirement 2).
	 *
	 * @param array $clean  Keys being written THIS call (already validated).
	 * @param array $merged Resulting settings row (stored merged with $clean).
	 * @return \WP_Error|null
	 */
	private function check_coupled_groups(array $clean, array $merged)
	{
		foreach (CFF_Settings_Schema::COUPLED_GROUPS as $trigger_key => $group) {
			if (!isset($clean[$trigger_key]) || $clean[$trigger_key] !== $group['trigger']) {
				continue; // Trigger not being set to its coupled value THIS call.
			}
			$missing = array();
			foreach ($group['requires'] as $companion) {
				$value = isset($merged[$companion]) ? $merged[$companion] : '';
				$value = is_scalar($value) ? CFF_Settings_Schema::normalize_color((string) $value) : '';
				if ($value === '') {
					$missing[] = $companion;
				}
			}
			if (!empty($missing)) {
				return new \WP_Error(
					'cff_incomplete_setting',
					sprintf(
						/* translators: 1: trigger key, 2: trigger value, 3: comma-separated companion keys */
						__(
							'Setting %1$s to "%2$s" also requires: %3$s. Include them in the same write.',
							'custom-facebook-feed'
						),
						$trigger_key,
						$group['trigger'],
						implode(', ', $missing)
					),
					array('status' => 400)
				);
			}
		}
		return null;
	}
}
