<?php

namespace CustomFacebookFeed\Abilities;

use CustomFacebookFeed\Builder\CFF_Feed_Builder;
use CustomFacebookFeed\CFF_Utils;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Pro upsell surfaces for the FREE edition's Abilities API (AgDR-9006).
 *
 * One place that knows three things, so every surface stays consistent and the
 * copy never drifts from the rest of the plugin:
 *   1. the upgrade URL + UTM convention  -> upgrade_url()
 *   2. which Pro features exist, and which feed settings they unlock
 *                                         -> features() / pro_only_settings()
 *   3. the one-line "this is Pro" hint attached to Pro-caused refusals
 *                                         -> hint() / hint_for_setting()
 *
 * Feature names/descriptions are NOT restated here: they are read from the
 * builder's own upsell popup (CFF_Feed_Builder::get_extensions_popup()), so a
 * copy change there flows through automatically. Only the map of "feature ->
 * Pro-only setting keys" lives here, and tests pin it against the free schema
 * (every listed key must be absent from the free schema, and every listed
 * `key=value` must be a value the free enum does not allow).
 *
 * UTM convention (one rule, so every port matches):
 *   - utm_source = `abilities` for API responses, `settings` for the admin card;
 *   - utm_medium = the feature id, i.e. the popup/upsell key in kebab-case
 *     (`loadMore` -> `load-more`, `date_range` -> `date-range`, see
 *     feature_medium()), for any link tied to one feature;
 *   - utm_medium = `ai-agents-card` for the banner, `get-pro-features` for that
 *     ability's top-level upgrade_url, and `describe-settings-schema` for the
 *     `pro_only.upgrade_url` of that ability.
 *
 * Free edition only. Every public method is inert in a Pro build.
 *
 * @since 4.13
 */
class CFF_Pro_Upsell
{
	/** The plugin's existing lite-upgrade landing page (same one the Settings and builder CTAs use). */
	const BASE_URL = 'https://smashballoon.com/custom-facebook-feed/facebook-lite-upgrade/';

	/** Matches the `utm_campaign` every other free-edition upgrade link already carries. */
	const UTM_CAMPAIGN = 'facebook-free';

	/**
	 * Pro-only feed settings, grouped by the builder-popup feature that unlocks them.
	 *
	 * Keys are the CFF_Feed_Builder::get_extensions_popup() array keys. Values are
	 * setting keys that exist in Pro and are deliberately ABSENT from the free
	 * schema (AgDR-9005), or `key=value` for a single Pro-only value of a key that
	 * free does expose (feedlayout=carousel). Features with no per-feed setting
	 * (feed types, Social Wall, filters, templates, ...) map to an empty list:
	 * never invent a key for them.
	 *
	 * Verified against the Pro edition's CFF_Settings_Schema (SBR-195): every key
	 * below is a real Pro feed setting.
	 *
	 * @var array<string, string[]>
	 */
	const PRO_SETTINGS_BY_FEATURE = array(
		'reviews'        => array(),
		'featuredpost'   => array(),
		'singlealbum'    => array(),
		'carousel'       => array(
			'carouselautoplay', 'carouseldesktop_cols', 'carouselheight', 'carouselinterval',
			'carouselmobile_cols', 'carouselpagination', 'feedlayout=carousel',
		),
		'socialwall'     => array(),
		'date_range'     => array(),
		'lightbox'       => array('disablelightbox'),
		'advancedFilter' => array(),
		'postSettings'   => array('layout'),
		'mediaComment'   => array(),
		'loadMore'       => array('loadmore', 'buttontext', 'buttoncolor', 'buttonhovercolor', 'buttontextcolor'),
		'photos'         => array('feedlayout=grid'),
		'videos'         => array('feedlayout=grid'),
		'albums'         => array(),
		'events'         => array(),
		'feedTemplates'  => array(),
	);

	/**
	 * Popup keys of the 3 features the AI Agents card banner names. Picked by key
	 * (not array position) so reordering the builder popup cannot change the banner.
	 *
	 * @var string[]
	 */
	const BANNER_FEATURES = array('carousel', 'lightbox', 'advancedFilter');

	/**
	 * Only the free edition promotes Pro. Guarded here so a future shared build
	 * can never surface any of this inside Pro. Uses the plugin's own edition
	 * check: Pro's main file defines CFFWELCOME_VER, the free one never does.
	 *
	 * @return bool
	 */
	public static function is_free()
	{
		return !CFF_Utils::cff_is_pro_version();
	}

	/**
	 * Upgrade link with the plugin's standard UTM set.
	 *
	 * @param string $medium What was shown (e.g. `get-pro-features`, `load-more`, `ai-agents-card`).
	 * @param string $source Surface: `abilities` for API responses, `settings` for the admin card.
	 * @return string
	 */
	public static function upgrade_url($medium, $source = 'abilities')
	{
		return self::BASE_URL
			. '?utm_campaign=' . self::UTM_CAMPAIGN
			. '&utm_source=' . rawurlencode((string) $source)
			. '&utm_medium=' . rawurlencode((string) $medium)
			. '&utm_content=UpgradeToPro';
	}

	/**
	 * Pro features as `[name, description, settings[]]`.
	 *
	 * Name + description come from the builder's upsell popup (plain text, see
	 * feature_name()); `settings` from PRO_SETTINGS_BY_FEATURE. Empty outside the
	 * free edition.
	 *
	 * @return array<int, array{name: string, description: string, settings: string[]}>
	 */
	public static function features()
	{
		if (!self::is_free() || !class_exists(CFF_Feed_Builder::class)) {
			return array();
		}
		$out = array();
		foreach (CFF_Feed_Builder::get_extensions_popup() as $id => $item) {
			$out[] = array(
				'name'        => self::feature_name($item),
				'description' => self::plain_text(isset($item['description']) ? $item['description'] : ''),
				'settings'    => isset(self::PRO_SETTINGS_BY_FEATURE[$id])
					? self::PRO_SETTINGS_BY_FEATURE[$id]
					: array(),
			);
		}
		return $out;
	}

	/**
	 * Every Pro-only setting key / `key=value`, flattened. Drives the `pro_only`
	 * block of describe-settings-schema.
	 *
	 * @return string[]
	 */
	public static function pro_only_settings()
	{
		if (!self::is_free()) {
			return array();
		}
		$all = array();
		foreach (self::PRO_SETTINGS_BY_FEATURE as $settings) {
			$all = array_merge($all, $settings);
		}
		return array_values(array_unique($all));
	}

	/**
	 * The utm_medium for a feature: its popup key in kebab-case
	 * (`loadMore` -> `load-more`, `date_range` -> `date-range`).
	 *
	 * @param string $feature_id Popup/upsell key.
	 * @return string
	 */
	public static function feature_medium($feature_id)
	{
		$kebab = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', (string) $feature_id);
		return strtolower(str_replace('_', '-', $kebab));
	}

	/**
	 * Names of the BANNER_FEATURES, in banner order, from the builder's popup copy.
	 * Keys missing from the popup are skipped.
	 *
	 * @return string[]
	 */
	public static function banner_feature_names()
	{
		if (!self::is_free() || !class_exists(CFF_Feed_Builder::class)) {
			return array();
		}
		$popup = CFF_Feed_Builder::get_extensions_popup();
		$names = array();
		foreach (self::BANNER_FEATURES as $id) {
			if (isset($popup[$id])) {
				$names[] = self::feature_name($popup[$id]);
			}
		}
		return $names;
	}

	/**
	 * One-line hint for a refusal caused by a Pro-only feature.
	 *
	 * Wired once: CFF_Ability_Response::multifeed_required() (more than one
	 * source without the Multifeed extension), the one place plugin code itself
	 * refuses a Pro-caused request. Core JSON-schema validation
	 * (additionalProperties:false, narrower enums) rejects Pro-only keys/values
	 * with a 400 before plugin code runs, so a hint there is unreachable, and
	 * free's other PHP-side refusals (feed save failures, unknown or missing
	 * accounts, coupled-setting checks) are not Pro-caused. Use hint() /
	 * hint_for_setting() only where plugin code itself refuses a Pro-caused
	 * request (tier checks, a dropped template) in editions that have those.
	 *
	 * @param string $feature_label Human name of the Pro feature.
	 * @param string $medium        UTM medium: the feature id via feature_medium().
	 * @return string Empty outside the free edition.
	 */
	public static function hint($feature_label, $medium)
	{
		if (!self::is_free()) {
			return '';
		}
		return sprintf(
			/* translators: 1: Pro feature name, 2: upgrade URL */
			__('%1$s is a Pro feature. Upgrade: %2$s', 'custom-facebook-feed'),
			$feature_label,
			self::upgrade_url($medium)
		);
	}

	/**
	 * Hint for a refused setting key (and optionally value), or '' when the
	 * refusal is not Pro-caused (a typo'd key gets no upsell).
	 *
	 * @internal See hint(): for plugin-side refusals only; unreachable through the
	 *           free schema-validated abilities.
	 *
	 * @param string $key   Setting key the caller sent.
	 * @param mixed  $value Value the caller sent (only used for `key=value` Pro values).
	 * @return string
	 */
	public static function hint_for_setting($key, $value = null)
	{
		$pro = self::pro_only_settings();
		$hit = in_array($key, $pro, true)
			|| (is_scalar($value) && in_array($key . '=' . $value, $pro, true));
		if (!$hit || !class_exists(CFF_Feed_Builder::class)) {
			return '';
		}
		$token = in_array($key, $pro, true) ? $key : $key . '=' . $value;
		$popup = CFF_Feed_Builder::get_extensions_popup();
		foreach (self::PRO_SETTINGS_BY_FEATURE as $id => $settings) {
			if (in_array($token, $settings, true) && isset($popup[$id])) {
				return self::hint(self::feature_name($popup[$id]), self::feature_medium($id));
			}
		}
		return '';
	}

	/**
	 * Plain-text feature name from a popup entry. The builder popup only has a
	 * marketing heading ("Upgrade to Pro to get Photo Feeds"), possibly with
	 * markup, so strip tags and, best effort, the English "Upgrade to ..." lead-in.
	 * A heading that does not match (another locale, new copy) is kept whole.
	 *
	 * @param array $item Popup entry.
	 * @return string
	 */
	private static function feature_name(array $item)
	{
		$heading = self::plain_text(isset($item['heading']) ? $item['heading'] : '');
		$lead_in = '/^Upgrade to (?:Pro to )?(?:get |display |embed |enable |add )?(?:the )?/i';
		$name    = preg_replace($lead_in, '', $heading);
		$name    = trim((string) $name, " \t\n\r\0\x0B!.");
		return $name === '' ? $heading : ucfirst($name);
	}

	/**
	 * Strip markup (a <br/> becomes a space) and collapse whitespace.
	 *
	 * @param string $html Markup, possibly empty.
	 * @return string
	 */
	private static function plain_text($html)
	{
		$text = preg_replace('/<br\s*\/?>/i', ' ', (string) $html);
		$text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8');
		return trim((string) preg_replace('/\s+/', ' ', $text));
	}
}
