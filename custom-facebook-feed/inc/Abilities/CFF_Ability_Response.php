<?php

namespace CustomFacebookFeed\Abilities;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WP_Error factories shared by every Custom Facebook Feed ability.
 *
 * Every error carries an HTTP status in `data.status` so the core Abilities
 * REST controller and the MCP adapter surface the right code.
 *
 * @since 4.13
 */
class CFF_Ability_Response
{
	public static function forbidden()
	{
		return new WP_Error(
			'cff_forbidden',
			__('You do not have permission to manage Custom Facebook Feed settings.', 'custom-facebook-feed'),
			array('status' => 403)
		);
	}

	public static function writes_disabled()
	{
		return new WP_Error(
			'cff_writes_disabled',
			__(
				'AI write access is disabled. Enable "MCP Write Access" under Facebook Feed → Settings → General.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'custom-facebook-feed'
			),
			array('status' => 403)
		);
	}

	public static function invalid_feed_id()
	{
		return new WP_Error(
			'cff_invalid_feed_id',
			__('A valid feed_id is required.', 'custom-facebook-feed'),
			array('status' => 400)
		);
	}

	public static function feed_not_found($feed_id)
	{
		return new WP_Error(
			'cff_feed_not_found',
			sprintf(
				/* translators: %d: feed id */
				__('Feed %d was not found.', 'custom-facebook-feed'),
				(int) $feed_id
			),
			array('status' => 404)
		);
	}

	/**
	 * Caller-supplied text made safe to echo inside an error message: tags and
	 * control characters stripped, length-limited, then HTML-escaped (MCP/REST
	 * clients may render messages as HTML).
	 *
	 * @param mixed $text
	 * @param int   $max  Max characters kept before escaping.
	 * @return string
	 */
	public static function safe_text($text, $max = 60)
	{
		$text = sanitize_text_field((string) $text);
		if (mb_strlen($text) > $max) {
			$text = mb_substr($text, 0, $max) . '...';
		}
		return esc_html($text);
	}

	/**
	 * Comma-separated safe_text() of each item (see safe_text()).
	 *
	 * @param array $items
	 * @return string
	 */
	public static function safe_list(array $items)
	{
		return implode(', ', array_map(array(__CLASS__, 'safe_text'), array_slice($items, 0, 10)))
			. (count($items) > 10 ? ', ...' : '');
	}

	/**
	 * More than one source was sent without the Multifeed extension.
	 *
	 * @return WP_Error
	 */
	public static function multifeed_required()
	{
		return new WP_Error(
			'cff_multifeed_required',
			sprintf(
				/* translators: %s: upgrade hint naming the Multifeed extension and its link */
				__('A feed can have only one source (a Facebook Page or Group) without the Multifeed extension. Send a single account. %s', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				CFF_Pro_Upsell::hint(
					__('Multiple sources per feed (Multifeed extension)', 'custom-facebook-feed'),
					'multifeed'
				)
			),
			array('status' => 403)
		);
	}
}
