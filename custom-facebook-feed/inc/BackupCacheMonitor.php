<?php

namespace CustomFacebookFeed;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Tracks how long feeds have been served from the backup cache and escalates
 * an admin notice when the cached content goes stale (SMASH-1808).
 *
 * The backup cache keeps a broken feed looking normal to visitors
 * indefinitely; support data attributes roughly 8.5% of connection-failure
 * tickets to feeds that were dead for weeks before anyone noticed — almost
 * always reported by someone other than the site owner. This class closes
 * that detection gap.
 *
 * State is recorded only at the moment a feed is actually served from
 * backup, so healthy sites never accumulate entries and never see a notice.
 * A successful fresh fetch clears the feed's entry immediately.
 *
 * @since SMASH-1808
 */
class BackupCacheMonitor
{
	public const OPTION_NAME = 'cff_backup_cache_status';

	/**
	 * The cff_feed_caches row whose last_updated is the age of the content
	 * visitors are seeing: it is rewritten only when a fetch succeeds.
	 */
	public const BACKUP_CACHE_KEY = 'posts_backup';

	/**
	 * Notice ids. The escalated id rotates weekly so a dismissal cannot
	 * outlive a still-worsening problem (dismissals are stored per notice id).
	 */
	public const NOTICE_ID = 'backup_cache_stale';
	public const NOTICE_ID_URGENT_PREFIX = 'backup_cache_stale_urgent_';

	/**
	 * Seconds between recorded serves per feed — one write per hour is
	 * plenty for a signal measured in days.
	 */
	public const SERVE_RECORD_THROTTLE = 3600;

	/**
	 * Entries not served from backup for this long are pruned; the feed was
	 * deleted or recovered without a recorded fresh fetch.
	 */
	public const ENTRY_RETENTION = 30 * DAY_IN_SECONDS;

	/**
	 * How recently a feed must have served backup content for its entry to
	 * count toward a notice.
	 *
	 * ENTRY_RETENTION is the *forgetting* horizon; this is the *evidence*
	 * horizon, and they are deliberately different. Conflating them was a real
	 * defect: retention (30d) sits above both escalation thresholds (7d / 21d),
	 * so an entry whose feed stopped being served -- shortcode removed, page
	 * deleted, feed deleted -- kept ageing on `content_last_updated` alone and
	 * escalated through BOTH tiers before it was ever pruned. A site with no
	 * broken feed at all could show a red notice for weeks.
	 *
	 * Requiring recent serve evidence is also the semantically right test: if
	 * nothing has been served from backup lately then no visitor is currently
	 * seeing stale content, which is the only thing this notice warns about.
	 *
	 * Must stay at least a full DAY below (urgent_threshold_days -
	 * stale_threshold_days) so a stopped entry cannot climb a whole tier on
	 * elapsed time alone; serve_evidence_window() enforces that even if the
	 * filter is abused. A day rather than a second because escalation is
	 * decided on floor(age / DAY_IN_SECONDS) -- see that method for the exact
	 * guarantee the margin buys.
	 */
	public const SERVE_EVIDENCE_WINDOW = 3 * DAY_IN_SECONDS;

	/**
	 * Record that a feed was just served from the backup cache.
	 *
	 * Called from the backup-serve path, so it must stay cheap: one option
	 * read, and at most one cache-row query + option write per feed per hour.
	 * That hourly throttle is what makes the query affordable, not an index:
	 * content_last_updated()'s CAST(feed_id AS CHAR) comparison is
	 * non-sargable and will scan. The CAST stays deliberately — see that
	 * method — so the throttle is the budget.
	 *
	 * Only a feed being shown to a visitor counts. What this guard covers is
	 * the clearest non-render case: a plain non-AJAX admin request, where
	 * nothing is being rendered for a visitor at all.
	 *
	 * It is not the catch-all an earlier version of this note claimed. Neither
	 * admin-side probe that shares cff_get_set_cache() is actually dropped by
	 * it: the Public Content Access check reaches the helper from
	 * templates/error-message.php on the front end, where is_admin() is false,
	 * and the builder's Events-source probe sits in
	 * CFF_Db::create_sources_database(), reached from the one-time DB-upgrade
	 * routine on wp_loaded -- which fires on front-end requests too.
	 *
	 * The customizer/builder exclusion is a separate mechanism and lives at
	 * the CFF_Graph_Data call sites as !$this->is_customizer.
	 *
	 * @param string $feed_id Feed id as stored in the caches table.
	 */
	public static function record_backup_serve($feed_id)
	{
		if (is_admin() && !wp_doing_ajax()) {
			return;
		}

		$feed_id = (string)$feed_id;
		if ('' === $feed_id) {
			return;
		}

		$status = self::get_status();
		$now = time();

		$known = isset($status['feeds'][ $feed_id ]) && is_array($status['feeds'][ $feed_id ]);
		$entry = $known ? $status['feeds'][ $feed_id ] : array();

		if (!empty($entry['last_serve']) && ($now - (int)$entry['last_serve']) < self::SERVE_RECORD_THROTTLE) {
			return;
		}

		$content_ts = self::content_last_updated($feed_id);

		$status['feeds'][ $feed_id ] = array(
			'first_serve' => isset($entry['first_serve']) ? (int)$entry['first_serve'] : $now,
			'last_serve' => $now,
			// Fall back to the first recorded serve when the row date is
			// unavailable — a lower bound on the real content age.
			'content_last_updated' => $content_ts > 0 ? $content_ts : (int)($entry['first_serve'] ?? $now),
		);

		update_option(self::OPTION_NAME, $status, false);
	}

	/**
	 * Record that a feed committed fresh content — it is healthy again.
	 *
	 * @param string $feed_id Feed id as stored in the caches table.
	 */
	public static function record_fresh_content($feed_id)
	{
		$feed_id = (string)$feed_id;
		$status = self::get_status();

		if (!isset($status['feeds'][ $feed_id ])) {
			return;
		}

		unset($status['feeds'][ $feed_id ]);
		update_option(self::OPTION_NAME, $status, false);
	}

	/**
	 * Days of staleness before the first notice. Filterable (AC 2).
	 *
	 * @return int
	 */
	public static function stale_threshold_days()
	{
		return max(1, (int)apply_filters('cff_backup_cache_stale_threshold_days', 7));
	}

	/**
	 * Days of staleness before the notice escalates.
	 *
	 * @return int
	 */
	public static function urgent_threshold_days()
	{
		return max(self::stale_threshold_days() + 1, (int)apply_filters('cff_backup_cache_urgent_threshold_days', 21));
	}

	/**
	 * Seconds since the last recorded backup serve within which an entry still
	 * counts toward a notice. Filterable, but clamped.
	 *
	 * Floor: SERVE_RECORD_THROTTLE, below which a genuinely broken feed could
	 * be missed between two throttled recordings.
	 *
	 * Ceiling: one full DAY under the gap between the two tier thresholds, so
	 * the day count an entry can gain while it sits unserved is at most
	 * (gap_in_days - 1) -- strictly less than the width of a tier. Stated
	 * precisely, the guarantee is: an entry that was at or below the top of
	 * its first stale day at its last serve (7.99 days on the defaults --
	 * floor 7, the bottom of tier 1) reaches at most 20 days here, and so
	 * cannot escalate to tier 2 on elapsed time alone.
	 *
	 * A day and not a second because escalation is decided on
	 * floor(age / DAY_IN_SECONDS): under the old gap-minus-one-second ceiling
	 * that same 7.99-day entry reached floor 21 and did escalate.
	 *
	 * The clamp bounds the climb rather than freezing the entry -- one already
	 * well inside tier 1 at its last serve can still cross into tier 2 as the
	 * rest of the window elapses. That is correct: it was genuinely serving
	 * stale backup content inside the window.
	 *
	 * @return int Seconds.
	 */
	public static function serve_evidence_window()
	{
		$window = (int)apply_filters(
			'cff_backup_cache_serve_evidence_window',
			self::SERVE_EVIDENCE_WINDOW
		);

		$tier_gap = (self::urgent_threshold_days() - self::stale_threshold_days()) * DAY_IN_SECONDS;
		$ceiling = max(self::SERVE_RECORD_THROTTLE, $tier_gap - DAY_IN_SECONDS);

		return max(self::SERVE_RECORD_THROTTLE, min($window, $ceiling));
	}

	/**
	 * Evaluate the current staleness state across all tracked feeds.
	 *
	 * Prunes entries that have not been served from backup recently — a feed
	 * that no longer renders should not nag forever.
	 *
	 * @return array {
	 *     @type int $tier 0 healthy, 1 stale, 2 urgent.
	 *     @type int $worst_days Age in days of the stalest feed.
	 *     @type int $feed_count Number of feeds currently stale past the threshold.
	 * }
	 */
	public static function evaluate()
	{
		$status = self::get_status();
		$now = time();
		$changed = false;

		$worst_days = 0;
		$stale_count = 0;

		$evidence_window = self::serve_evidence_window();

		foreach ($status['feeds'] as $feed_id => $entry) {
			$has_serve = is_array($entry) && !empty($entry['last_serve']);
			$since_serve = $has_serve ? ($now - (int)$entry['last_serve']) : PHP_INT_MAX;

			// Past the forgetting horizon: drop the entry entirely.
			if (!$has_serve || $since_serve > self::ENTRY_RETENTION) {
				unset($status['feeds'][ $feed_id ]);
				$changed = true;
				continue;
			}

			// Inside retention but with no recent serve evidence: keep the entry
			// (the feed may come back and we would rather not lose its history)
			// but do not let it drive a notice. This is what stops a removed or
			// deleted feed escalating on elapsed time alone.
			if ($since_serve > $evidence_window) {
				continue;
			}

			$content_ts = isset($entry['content_last_updated']) ? (int)$entry['content_last_updated'] : 0;
			if ($content_ts <= 0) {
				continue;
			}

			$age_days = (int)floor(($now - $content_ts) / DAY_IN_SECONDS);
			if ($age_days >= self::stale_threshold_days()) {
				$stale_count++;
			}
			if ($age_days > $worst_days) {
				$worst_days = $age_days;
			}
		}

		if ($changed) {
			update_option(self::OPTION_NAME, $status, false);
		}

		$tier = 0;
		if ($stale_count > 0) {
			$tier = $worst_days >= self::urgent_threshold_days() ? 2 : 1;
		}

		return array(
			'tier' => $tier,
			'worst_days' => $worst_days,
			'feed_count' => $stale_count,
		);
	}

	/**
	 * The notice id for a tier. Tier 2 rotates weekly: dismissing it hides
	 * it for at most a week while the feed stays dead (AC 5), and the tier 1
	 * to tier 2 jump mints a fresh id so a tier 1 dismissal never suppresses
	 * the escalation.
	 *
	 * @param int $tier Tier 1 or 2.
	 *
	 * @return string
	 */
	public static function notice_id($tier)
	{
		if ($tier >= 2) {
			return self::NOTICE_ID_URGENT_PREFIX . gmdate('oW');
		}

		return self::NOTICE_ID;
	}

	/**
	 * The stored status option, shape-healed.
	 *
	 * @return array
	 */
	public static function get_status()
	{
		$status = get_option(self::OPTION_NAME, array());
		if (!is_array($status) || !isset($status['feeds']) || !is_array($status['feeds'])) {
			$status = array('feeds' => array());
		}

		return $status;
	}

	/**
	 * Remember what was last rendered — id, day count and feed count — so a
	 * tier change or recovery can remove the right notice, and a changed day
	 * count can refresh the copy (SBNotices ignores add_notice for an
	 * existing id, so stale copy must be removed before re-adding).
	 *
	 * @param string $notice_id Currently registered id, empty when none.
	 * @param int    $worst_days Day count rendered into the copy.
	 * @param int    $feed_count Feed count rendered into the copy.
	 */
	public static function set_registered_notice($notice_id, $worst_days = 0, $feed_count = 0)
	{
		$status = self::get_status();

		// Nothing has ever been registered and nothing is being registered now,
		// so there is nothing to remember. Writing the all-zero payload anyway
		// would create this option on every site at the first admin_init —
		// turning a cached notoptions miss into a real option row plus a real
		// query on every later admin request, forever, on sites that will never
		// see this notice.
		if ('' === (string)$notice_id && !isset($status['notice'])) {
			return;
		}

		$notice = array(
			'id' => (string)$notice_id,
			'days' => (int)$worst_days,
			'feeds' => (int)$feed_count,
		);

		if (isset($status['notice']) && $status['notice'] === $notice) {
			return;
		}

		$status['notice'] = $notice;
		update_option(self::OPTION_NAME, $status, false);
	}

	/**
	 * The last rendered notice state.
	 *
	 * @return array { @type string $id @type int $days @type int $feeds }
	 */
	public static function get_registered_notice()
	{
		$status = self::get_status();
		$notice = isset($status['notice']) && is_array($status['notice']) ? $status['notice'] : array();

		return array(
			'id' => isset($notice['id']) ? (string)$notice['id'] : '',
			'days' => isset($notice['days']) ? (int)$notice['days'] : 0,
			'feeds' => isset($notice['feeds']) ? (int)$notice['feeds'] : 0,
		);
	}

	/**
	 * When the backup content was last refreshed from live data.
	 *
	 * Not an indexed lookup: wrapping feed_id in CAST(... AS CHAR) makes the
	 * comparison non-sargable, so this scans the caches table. That is an
	 * accepted cost of the CAST, which stays because it is what keeps the
	 * comparison exact-string; the hourly serve throttle bounds how often it
	 * runs. Do not read "cheap because indexed" into the caller's docblock.
	 *
	 * @param string $feed_id Feed id as stored in the caches table.
	 *
	 * @return int Unix timestamp, 0 when unavailable.
	 */
	private static function content_last_updated($feed_id)
	{
		global $wpdb;
		$cache_table_name = $wpdb->prefix . 'cff_feed_caches';

		// CAST makes the comparison exact-string: against a bigint feed_id
		// column a non-numeric key would coerce to 0 and match a stray row.
		$last_updated = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_updated FROM $cache_table_name WHERE CAST(feed_id AS CHAR) = %s AND cache_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
				$feed_id,
				self::BACKUP_CACHE_KEY
			)
		);

		if (empty($last_updated)) {
			return 0;
		}

		$ts = strtotime($last_updated . ' UTC');

		return false !== $ts ? $ts : 0;
	}
}
