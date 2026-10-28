<?php

namespace CustomFacebookFeed\Abilities\Services;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Typed allowlist of the settings exposed through the Abilities API.
 *
 * This is the single source of truth for what an AI agent may read or write
 * on the FREE edition. Anything not listed here is never exposed — connected
 * accounts, access tokens, license data, custom CSS/JS and usage tracking
 * are deliberately absent, and so are every Pro-only key and enum value
 * (AgDR-9005: free is a strict, test-pinned subset of Pro, never a
 * runtime-derived one).
 *
 * Ported from custom-facebook-feed-pro's Abilities module (SBR-195, merged
 * as #1597) and cut down per projects/custom-facebook-feed/abilities-free-plan.md
 * §3. Every enum/bound literal below is a CURATED literal pinned by
 * tests/abilities/check-schema-drift.php against this plugin's own control
 * definitions — never a runtime control-tree walk (AgDR-9005 §2).
 *
 * Per-feed keys map 1:1 onto CFF_Feed_Saver::settings_defaults(); global keys
 * map onto cff_style_settings plus the standalone cff_locale, cff_ajax,
 * cff_preserve_settings, cff_cache_time and cff_cache_time_unit options.
 *
 * BOOLEAN STORAGE: see CFF_Settings_Schema::encode_bool() — per-feed settings
 * use 'on'/'off' strings for nearly every toggle; the cff_style_settings
 * Advanced-tab booleans use real PHP booleans. Every key below is still
 * typed `boolean` in the JSON Schema; the SERVICE layer re-encodes to
 * whichever native shape that option area already uses.
 *
 * @since 4.14
 */
class CFF_Settings_Schema
{
	const MAX_STRING_LENGTH = 500;

	/**
	 * Hex colour: #rgb, #rgba, #rrggbb, #rrggbbaa, or empty. Does NOT match a
	 * bare '#' — CFF stores an "unset colour" as a literal '#', so
	 * normalize_color() converts '#' to '' before either validate_in() or
	 * cast_out() apply this pattern, keeping both directions of the round
	 * trip consistent.
	 */
	const COLOR_PATTERN = '^(#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8}))?$';

	/**
	 * Forbids the characters that let a free-text value break out of an HTML
	 * attribute or tag: `"` `'` `<` `>` `&`. Applied to `datecustom`: the
	 * value reaches a `data-cff-comment-date="…"` attribute via
	 * CFF_Shortcode_Display::get_date(), PHP's date() prints an
	 * escaped-letter literally (dropping the backslash), and the front-end JS
	 * reads the attribute with jQuery's `.attr()`, which HTML-decodes
	 * entities before handing the string back. `&` is included so an
	 * already-entity-encoded payload can't round-trip back into a live tag at
	 * the JS concatenation sink. No real PHP date() format token uses any of
	 * these five characters, so no legitimate format is lost.
	 */
	const NO_MARKUP_PATTERN = '^[^"\'<>&]*$';

	/**
	 * Keys that only work as a set: writing a "trigger" key to a particular
	 * value requires its companion keys to already resolve to a real value in
	 * the SAME write or the already-stored row — otherwise the write leaves a
	 * feed in a state the plugin's own render path can't act on
	 * (AgDR-9004 requirement 2).
	 *
	 * @var array<string, array{trigger: string, requires: string[]}>
	 */
	public const COUPLED_GROUPS = array(
		'colorpalette' => array(
			'trigger'  => 'custom',
			'requires' => array('custombgcolor1', 'custombgcolor2', 'textcolor1', 'textcolor2', 'customlinkcolor'),
		),
		'dateformat' => array(
			'trigger'  => 'custom',
			'requires' => array('datecustom'),
		),
	);

	/**
	 * Per-feed settings. Free's allowlist (38 keys) drops every Pro-only key
	 * (layout, loadmore + its 4 button colours, disablelightbox, and the 6
	 * carousel keys) per the plan's §3a table. `feedlayout` and
	 * `colorpalette` enums are narrowed to the values the free control tree
	 * can actually reach; `num` and `postcorners` carry NO invented maximum
	 * because the free control declares none (AgDR-9005: no invented bounds).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function feed_settings()
	{
		return array(
			'feed_name' => array(
				'type' => 'string',
				'description' => 'Internal feed name shown in the Feed Builder list.',
			),
			'feedtype' => array(
				'type' => 'string',
				'description' => 'Feed content type. The free plugin only ever creates "timeline" feeds — a '
					. 'downgraded Pro feed may report another value, which free renders as a timeline. '
					. 'Read-only: it is the feed\'s identity, set when the feed is created.',
				'writable' => false,
				// AgDR-9005 downgrade rule: report the stored value verbatim, never
				// coerce to "timeline" — cast_out() special-cases this flag.
				'stored_truth' => true,
			),
			'feedlayout' => array(
				'type' => 'string',
				// Curated literal (AgDR-9005), NOT a runtime derivation. Matches
				// CFF_Customize_Tab::get_customize_feedlayout_controls()'s 'feedlayout'
				// toggleset AFTER the edition filter: 'grid' is unreachable (its option
				// carries `condition: feedtype in [photos,videos,albums,singlealbum]`,
				// and free only ever creates `timeline` feeds) and 'carousel' is
				// unreachable (its option carries `checkExtension: carousel`, which
				// CFF_FB_Settings::check_active_extension() always reports inactive on
				// free). tests/abilities/check-schema-drift.php asserts this literal
				// against the live control tree with that same filter applied.
				'enum' => array('list', 'masonry'),
				'description' => 'Feed layout.',
			),
			'cols' => array(
				'type' => 'integer',
				'minimum' => 1,
				// Curated literal (AgDR-9005). Matches
				// get_customize_feedlayout_controls()'s 'cols' select (options '1'..'6').
				'maximum' => 6,
				'description' => 'Columns on desktop. Only takes effect when feedlayout is "masonry" — writing it '
					. 'while feedlayout is "list" is accepted but inert (the render path forces 1 column).',
			),
			'colstablet' => array(
				'type' => 'integer',
				'minimum' => 1,
				// Curated literal (AgDR-9005). Matches 'colstablet' select (options '1'..'6').
				'maximum' => 6,
				'description' => 'Columns on tablet. Only takes effect when feedlayout is "masonry".',
			),
			'colsmobile' => array(
				'type' => 'integer',
				'minimum' => 1,
				// Curated literal (AgDR-9005). Matches 'colsmobile' select (options '1'..'3').
				'maximum' => 3,
				'description' => 'Columns on mobile. Only takes effect when feedlayout is "masonry".',
			),
			'num' => array(
				'type' => 'integer',
				'minimum' => 1,
				// No maximum: the 'num' control declares none (AgDR-9005: no invented
				// bounds). A real Graph-API page limit belongs in the plugin control first.
				'description' => 'Number of posts on desktop.',
			),
			'nummobile' => array(
				'type' => 'integer',
				// 0 is a real, legitimate stored value meaning "same as desktop"
				// (CFF_Shortcode.php / CFF_Shortcode_Display.php treat a non-positive
				// nummobile as "use num") — AgDR-9004 requirement 1: reads must never
				// clamp or corrupt a legally stored value.
				'minimum' => 0,
				'description' => 'Number of posts on mobile. 0 = same as desktop.',
			),
			'showheader' => array(
				'type' => 'boolean',
				'on_off' => true,
				'description' => 'Show the feed header.',
			),
			'headertype' => array(
				'type' => 'string',
				'enum' => array('visual', 'text'),
				'description' => 'Header style.',
			),
			'headertext' => array(
				'type' => 'string',
				'description' => 'Text-style header content.',
			),
			'headercover' => array(
				'type' => 'boolean',
				'on_off' => true,
				'description' => 'Show the cover photo in the header.',
			),
			'headerbio' => array(
				'type' => 'boolean',
				'on_off' => true,
				'description' => 'Show the account bio in the header.',
			),
			'headeroutside' => array(
				'type' => 'boolean',
				'on_off' => true,
				'description' => 'Place the header outside the feed container.',
			),
			'headerbg' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Header background colour (hex).',
			),
			'headertextcolor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Header text colour (hex).',
			),
			'showlikebox' => array(
				'type' => 'boolean',
				'on_off' => true,
				'description' => 'Show the Facebook like box.',
			),
			'likeboxpos' => array(
				'type' => 'string',
				'enum' => array('top', 'bottom'),
				'description' => 'Like box position.',
			),
			'likeboxcover' => array(
				'type' => 'boolean',
				'on_off' => true,
				'description' => 'Show the cover photo in the like box.',
			),
			'likeboxsmallheader' => array(
				'type' => 'boolean',
				'on_off' => true,
				'description' => 'Use a small header in the like box.',
			),
			'poststyle' => array(
				'type' => 'string',
				// Curated literal (AgDR-9005), order matching
				// CFF_Customize_Tab::get_nested_post_style_controls()'s 'poststyle'
				// toggleset (checked by tests/abilities/check-schema-drift.php).
				'enum' => array('boxed', 'regular'),
				'description' => 'Post style.',
			),
			'postbgcolor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Boxed post background colour (hex). Only takes effect when poststyle is "boxed".',
			),
			'postcorners' => array(
				'type' => 'integer',
				'minimum' => 0,
				// No maximum: the 'postcorners' control declares none (AgDR-9005).
				'description' => 'Boxed post corner radius (px). Only takes effect when poststyle is "boxed".',
			),
			'boxshadow' => array(
				'type' => 'boolean',
				'on_off' => true,
				'description' => 'Boxed post shadow. Forced off at render when poststyle is "regular".',
			),
			'textlength' => array(
				'type' => 'integer',
				'minimum' => 0,
				// No maximum: the 'textlength' control declares none (AgDR-9005: no
				// invented bounds).
				'description' => 'Max post text characters. 0 = no limit (the render path skips truncation '
					. 'entirely when this is 0 — not "plugin default").',
			),
			'desclength' => array(
				'type' => 'integer',
				'minimum' => 0,
				// No maximum: the 'desclength' control declares none (AgDR-9005: no
				// invented bounds).
				'description' => 'Max description characters for link posts. 0 = no limit (the render path '
					. 'skips truncation entirely when this is 0 — not "plugin default").',
			),
			'textcolor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Post text colour (hex).',
			),
			'linkcolor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Link text colour (hex).',
			),
			'datecolor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Post date colour (hex).',
			),
			'dateformat' => array(
				'type' => 'string',
				'enum' => self::dateformat_enum(),
				'description' => 'Date format code (see the plugin\'s date-format picker for what each code renders '
					. 'as). "custom" requires datecustom to be set in the same write or already stored.',
			),
			'datecustom' => array(
				'type' => 'string',
				'pattern' => self::NO_MARKUP_PATTERN,
				'description' => 'Custom PHP date() format string (e.g. "F j, Y"). Applies only when '
					. 'dateformat is "custom". May not contain " \' < or > or &.',
			),
			'colorpalette' => array(
				'type' => 'string',
				// Curated literal (AgDR-9005). Matches
				// CFF_Customize_Tab::get_customize_colorscheme_controls()'s 'colorpalette'
				// toggleset — every value ('inherit','light','dark','custom') is reachable
				// on free, so this table is identical to Pro's. tests/abilities/check-schema-drift.php
				// asserts this literal against the live control.
				'enum' => array('inherit', 'light', 'dark', 'custom'),
				'description' => 'Colour palette. "custom" requires custombgcolor1, custombgcolor2, textcolor1, '
					. 'textcolor2 and customlinkcolor to be set in the same write or already stored.',
			),
			'custombgcolor1' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Custom palette primary background colour (hex). Applies only when '
					. 'colorpalette is "custom".',
			),
			'custombgcolor2' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Custom palette secondary background colour (hex). Applies only when '
					. 'colorpalette is "custom".',
			),
			'textcolor1' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Custom palette primary text colour (hex). Applies only when '
					. 'colorpalette is "custom".',
			),
			'textcolor2' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Custom palette secondary text colour (hex). Applies only when '
					. 'colorpalette is "custom".',
			),
			'customlinkcolor' => array(
				'type' => 'string',
				'format' => 'color',
				'description' => 'Custom palette link colour (hex). Applies only when colorpalette is "custom".',
			),
			'gdpr' => array(
				'type' => 'string',
				'enum' => array('auto', 'yes', 'no'),
				'description' => 'Per-feed GDPR mode. Read-only: the render path always uses the GLOBAL GDPR '
					. 'mode (cff_style_settings[\'gdpr\']), so a per-feed write here would persist to the row but '
					. 'never be read back at render time. To change GDPR mode, use update-global-settings '
					. '(the global gdpr key); it has legal consequences, so confirm with the site owner first.',
				'writable' => false,
			),
		);
	}

	/**
	 * Global (plugin-wide) settings, spread across CFF's several options.
	 * Free's allowlist keeps 7 of Pro's 13 keys (drops cff_caching_type, the
	 * 3 cron keys, enable_js_image_loading, and cff_disable_resize — free has
	 * no background cron and no image-resize toggle) and adds the two
	 * Free-native page-cache keys (Q1, resolved yes by AgDR-9005).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function global_settings()
	{
		return array(
			'cff_cache_time' => array(
				'type' => 'integer',
				// The Settings UI's <input type=number min=0 max=9999>; 0 is inert
				// (clamped to 1 at render by CFF_Graph_Data::get_cache_seconds()), so
				// the practical floor is 1 — but the control itself allows 0, and
				// AgDR-9005 forbids inventing a bound narrower than the control's own.
				'minimum' => 0,
				'maximum' => 9999,
				'description' => 'How long (in cff_cache_time_unit units) a feed\'s cached posts are kept before '
					. 'refetching from Facebook. Free-only: replaces Pro\'s background-cron cache keys.',
			),
			'cff_cache_time_unit' => array(
				'type' => 'string',
				// Curated literal (AgDR-9005). Matches CFF_Global_Settings's
				// timeUnits map. tests/abilities/check-schema-drift.php asserts this
				// literal against the live control.
				'enum' => array('minutes', 'hours', 'days'),
				'description' => 'Unit for cff_cache_time.',
			),
			'gdpr' => array(
				'type' => 'string',
				'enum' => array('auto', 'yes', 'no'),
				'description' => 'GDPR mode, as set in Settings → Feeds. "auto" turns it on only when '
					. 'a supported consent plugin is active, "yes" always on, "no" always off. '
					. 'While on, feeds skip third-party requests (Facebook images, scripts) until the '
					. 'visitor consents. It affects legal compliance, so confirm the change with the '
					. 'site owner first.',
			),
			'cff_locale' => array(
				'type' => 'string',
				// Constrained to the plugin's own locale allowlist
				// (CFF_Settings::locales(), inc/Admin/Traits/CFF_Settings.php) — this
				// value flows unencoded into outbound Graph API URLs, so a free-text
				// write here is a query-parameter-injection surface for a
				// prompt-injected agent.
				'enum' => array_keys(\CustomFacebookFeed\Admin\CFF_Global_Settings::locales()),
				'description' => 'Locale used to render dates/timestamps.',
			),
			'cff_preserve_settings' => array(
				'type' => 'boolean',
				'on_off' => false,
				'description' => 'Keep plugin settings in the database when the plugin is uninstalled.',
			),
			'cff_show_credit' => array(
				'type' => 'boolean',
				'on_off' => false,
				'description' => 'Show a "powered by Smash Balloon" credit link under feeds.',
			),
			'cff_format_issue' => array(
				'type' => 'boolean',
				'on_off' => false,
				'description' => 'Fix a text-shortening issue caused by some themes.',
			),
			'cff_ajax' => array(
				'type' => 'boolean',
				'on_off' => false,
				'description' => 'AJAX theme-loading fix — works around themes/JS errors that break the initial load.',
			),
			'disable_admin_notice' => array(
				'type' => 'boolean',
				'on_off' => false,
				'description' => 'Hide feed-issue admin notices. Read-only for AI assistants '
					. '(an agent must not silence error notices).',
				'writable' => false,
			),
		);
	}

	/**
	 * Build a JSON Schema `properties` map from a table.
	 *
	 * `$mode = 'write'` (default) is the full curated schema — enum, minimum
	 * and maximum enforced, for input schemas and describe-settings-schema.
	 *
	 * `$mode = 'read'` is the READ-SUPERSET output schema (AgDR-9005 downgrade
	 * rule): drops `enum`/`minimum`/`maximum` entirely. A legally stored value
	 * can sit outside this key's free bounds (e.g. a feed downgraded from
	 * Pro) — cast_out() reports it verbatim plus a warnings[] note rather
	 * than clamping it, so the output schema must not re-impose the very
	 * constraint cast_out() is deliberately not enforcing, or core's
	 * output-schema validation 500s on the read. Genuinely invalid values
	 * (wrong type, a pattern violation) still fall back to the plugin default
	 * inside cast_out(), so type/pattern/color constraints stay enforced in
	 * both modes.
	 *
	 * @param array  $table
	 * @param string $mode 'write' (default) or 'read'.
	 * @return array
	 */
	public static function to_json_properties(array $table, $mode = 'write')
	{
		$out = array();
		foreach ($table as $key => $def) {
			$prop = array('type' => $def['type']);
			$copy_keys = $mode === 'read' ? array('description') : array('enum', 'minimum', 'maximum', 'description');
			foreach ($copy_keys as $k) {
				if (isset($def[$k])) {
					$prop[$k] = $def[$k];
				}
			}
			if ($def['type'] === 'string' && ($mode === 'read' || !isset($def['enum']))) {
				$prop['maxLength'] = self::MAX_STRING_LENGTH;
			}
			if (isset($def['format']) && $def['format'] === 'color') {
				$prop['pattern']     = self::COLOR_PATTERN;
				$prop['description'] = (isset($prop['description']) ? $prop['description'] . ' ' : '')
					. 'Hex colour (#rgb, #rrggbb or #rrggbbaa), or empty for the default '
					. '(a bare "#" is also accepted and treated the same as empty).';
			} elseif (isset($def['pattern'])) {
				$prop['pattern'] = $def['pattern'];
			}
			if (isset($def['writable']) && $def['writable'] === false) {
				$prop['readOnly'] = true;
			}
			$out[$key] = $prop;
		}
		return $out;
	}

	/**
	 * Cast a raw stored value to the declared type for output.
	 *
	 * Stored data is not guaranteed to satisfy the schema (legacy installs,
	 * a downgrade from Pro, import/export, a bad save). AgDR-9005's downgrade
	 * rule draws a hard line between two different failure classes:
	 *
	 *  - A value that is the RIGHT TYPE but sits outside this key's curated
	 *    free enum/bounds (e.g. "grid" on feedlayout, cols: 8, textlength:
	 *    5000 after a downgrade from Pro) is LEGALLY STORED. It is reported
	 *    VERBATIM, with `$note` set to a warning the caller collects into
	 *    `warnings[]` — never clamped, never coerced. Writes stay free-only;
	 *    only reads are permissive.
	 *  - A value that is GENUINELY INVALID (wrong type, fails a security
	 *    pattern such as NO_MARKUP_PATTERN, not a real hex colour) falls back
	 *    to $default (the plugin's own default for that key).
	 *
	 * The one exception is `stored_truth` keys, which report the value
	 * verbatim with no enum/bound check at all (there is nothing to be
	 * "outside" of), and so never set `$note`.
	 *
	 * @param array  $def     Schema entry for the key.
	 * @param mixed  $value   Raw stored value (null when missing).
	 * @param mixed  $default Plugin default for the key.
	 * @param string $note    Out param: set to a human-readable warning when
	 *                        the returned value is legally stored but
	 *                        outside the free set; left null otherwise.
	 * @param string $key     Setting key, for the warning message only.
	 * @return mixed
	 */
	public static function cast_out(array $def, $value, $default = null, &$note = null, $key = null)
	{
		$note = null;

		// feedtype is the one stored-truth exception: a downgraded Pro feed may
		// carry a value (e.g. "photos") outside this key's own (empty) enum, and
		// AgDR-9005's downgrade rule requires reporting it verbatim rather than
		// silently coercing it to "timeline".
		if ($def['type'] === 'string' && !empty($def['stored_truth'])) {
			$truth = is_scalar($value) ? (string) $value : (is_scalar($default) ? (string) $default : '');
			// Every string returned verbatim from storage must still respect
			// the schema's maxLength, even a stored_truth key with no enum to
			// be "outside" of -- otherwise an over-length legacy value 500s
			// the whole read (the same failure class N1 covers below).
			if (mb_strlen($truth) > self::MAX_STRING_LENGTH) {
				$truth = mb_substr($truth, 0, self::MAX_STRING_LENGTH);
			}
			return $truth;
		}

		switch ($def['type']) {
			case 'boolean':
				if ($value === null) {
					$value = $default;
				}
				return $value === true || $value === 'true' || $value === 1 || $value === '1' || $value === 'on';

			case 'integer':
				$is_whole_number = is_numeric($value) && (float) $value === (float) (int) $value;
				if ($value === null || $value === '' || !$is_whole_number) {
					// Genuinely invalid/missing -- fall back to the plugin default.
					return is_numeric($default) ? (int) $default : 0;
				}
				$int = (int) $value;
				if (isset($def['minimum']) && $int < $def['minimum']) {
					$note = sprintf(
						/* translators: 1: setting key, 2: stored value, 3: free minimum */
						__('%1$s is stored as %2$d, below the free minimum of %3$d.', 'custom-facebook-feed'),
						$key ?: 'value',
						$int,
						$def['minimum']
					);
				} elseif (isset($def['maximum']) && $int > $def['maximum']) {
					$note = sprintf(
						/* translators: 1: setting key, 2: stored value, 3: free maximum */
						__('%1$s is stored as %2$d, above the free maximum of %3$d.', 'custom-facebook-feed'),
						$key ?: 'value',
						$int,
						$def['maximum']
					);
				}
				// Legally stored value, reported verbatim -- never clamped.
				return $int;

			default:
				$str = is_scalar($value) ? (string) $value : '';
				// Run every content/security check (colour, pattern, enum)
				// against the FULL stored string before any truncation --
				// truncating first (the N2 regression) let markup that sits
				// past char 500 dodge NO_MARKUP_PATTERN entirely, because the
				// check only ever saw the already-cut prefix. maxLength is
				// enforced only once a branch is about to return the string
				// verbatim (the enum-outside-set branch and the final
				// catch-all below) -- see N1's reasoning for why it must be
				// enforced at all.
				$is_color = isset($def['format']) && $def['format'] === 'color';
				if ($is_color) {
					$str = self::normalize_color($str);
				}

				$color_regex = self::compiled_pattern(self::COLOR_PATTERN);
				if ($is_color && !preg_match($color_regex, $str)) {
					// Genuinely invalid (not a hex colour at all) -- fall back.
					$fallback_str = is_scalar($default) ? self::normalize_color((string) $default) : '';
					$str          = preg_match($color_regex, $fallback_str) ? $fallback_str : '';
					return $str;
				}

				if (!$is_color && isset($def['pattern'])) {
					$field_regex = self::compiled_pattern($def['pattern']);
					if (!preg_match($field_regex, $str)) {
						// Genuinely invalid -- these patterns are security bounds
						// (e.g. NO_MARKUP_PATTERN), not a free/Pro distinction. Fall back.
						$fallback_str = is_scalar($default) ? (string) $default : '';
						$str          = preg_match($field_regex, $fallback_str) ? $fallback_str : '';
						return $str;
					}
				}

				if (isset($def['enum']) && !in_array($str, $def['enum'], true)) {
					// Right type, just outside the free enum (e.g. a downgrade from
					// Pro) -- legally stored, reported verbatim, never coerced.
					// Truncate only now, after the enum check itself has seen
					// the full string -- see the N1/N2 note above the switch.
					if (mb_strlen($str) > self::MAX_STRING_LENGTH) {
						$str = mb_substr($str, 0, self::MAX_STRING_LENGTH);
					}
					$note = sprintf(
						/* translators: 1: setting key, 2: stored value (clipped), 3: free enum */
						__('%1$s is stored as "%2$s", outside the free set (%3$s).', 'custom-facebook-feed'),
						$key ?: 'value',
						self::clip_for_note($str),
						implode(', ', $def['enum'])
					);
					return $str;
				}

				// Enforce maxLength BEFORE returning the raw stored string
				// verbatim. Every string key's read schema carries
				// `maxLength: 500` (to_json_properties(), 'read' mode); an
				// over-length value returned as-is fails core's
				// output-schema validation and 500s the whole ability read
				// -- that is the N1 regression. Enforced here, after every
				// colour/pattern/enum check above has already run against
				// the full string, so markup past char 500 can't dodge
				// NO_MARKUP_PATTERN (the N2 regression).
				if (mb_strlen($str) > self::MAX_STRING_LENGTH) {
					$str = mb_substr($str, 0, self::MAX_STRING_LENGTH);
				}

				return $str;
		}
	}

	/**
	 * Writable subset of a table (keys not flagged `writable => false`).
	 *
	 * @param array $table
	 * @return array
	 */
	public static function writable(array $table)
	{
		return array_filter(
			$table,
			function ($def) {
				return !isset($def['writable']) || $def['writable'] !== false;
			}
		);
	}

	/**
	 * Validate + normalise ONE incoming value for storage.
	 *
	 * Core has already validated the request against the JSON Schema, so this
	 * is the second, PHP-side line: it never trusts the transport. Returns the
	 * value to store, or a WP_Error (code cff_invalid_value, status 400).
	 *
	 * @param array  $def
	 * @param mixed  $value
	 * @param string $key
	 * @return mixed|\WP_Error
	 */
	public static function validate_in(array $def, $value, $key)
	{
		$bad = function ($reason) use ($key) {
			return new \WP_Error(
				'cff_invalid_value',
				sprintf(
					/* translators: 1: setting key, 2: reason */
					__('Invalid value for %1$s: %2$s', 'custom-facebook-feed'),
					$key,
					$reason
				),
				array('status' => 400)
			);
		};

		switch ($def['type']) {
			case 'boolean':
				if (is_bool($value)) {
					return $value;
				}
				if (in_array($value, array('true', '1', 1, 'on'), true)) {
					return true;
				}
				if (in_array($value, array('false', '0', 0, 'off', ''), true)) {
					return false;
				}
				return $bad(__('expected a boolean', 'custom-facebook-feed'));

			case 'integer':
				if (!is_numeric($value) || (float) $value !== (float) (int) $value) {
					return $bad(__('expected an integer', 'custom-facebook-feed'));
				}
				$int = (int) $value;
				if (isset($def['minimum']) && $int < $def['minimum']) {
					return $bad(sprintf(__('minimum is %d', 'custom-facebook-feed'), $def['minimum']));
				}
				if (isset($def['maximum']) && $int > $def['maximum']) {
					return $bad(sprintf(__('maximum is %d', 'custom-facebook-feed'), $def['maximum']));
				}
				return $int;

			default:
				if (!is_scalar($value)) {
					return $bad(__('expected a string', 'custom-facebook-feed'));
				}
				$str = sanitize_text_field((string) $value);
				$is_color = isset($def['format']) && $def['format'] === 'color';
				if ($is_color) {
					$str = self::normalize_color($str);
				}
				if (isset($def['enum']) && !in_array($str, $def['enum'], true)) {
					return $bad(sprintf(__('allowed values: %s', 'custom-facebook-feed'), implode(', ', $def['enum'])));
				}
				if ($is_color && $str !== '' && !preg_match(self::compiled_pattern(self::COLOR_PATTERN), $str)) {
					return $bad(__('expected a hex colour such as #1877f2', 'custom-facebook-feed'));
				}
				if (!$is_color && isset($def['pattern'])
					&& !preg_match(self::compiled_pattern($def['pattern']), $str)
				) {
					return $bad(__('does not match the allowed pattern for this setting', 'custom-facebook-feed'));
				}
				if (mb_strlen($str) > self::MAX_STRING_LENGTH) {
					return $bad(
						sprintf(__('maximum length is %d characters', 'custom-facebook-feed'), self::MAX_STRING_LENGTH)
					);
				}
				return $str;
		}
	}

	/**
	 * Re-encode a validated PHP boolean back into a key's NATIVE storage
	 * shape. `on_off` => 'on'/'off' strings (the per-feed toggles); the
	 * default (no flag set) => a real PHP boolean (the cff_style_settings
	 * Advanced-tab convention).
	 *
	 * @param bool  $value
	 * @param array $def Schema entry for the key.
	 * @return bool|string
	 */
	public static function encode_bool($value, array $def)
	{
		if (!empty($def['on_off'])) {
			return $value ? 'on' : 'off';
		}
		return (bool) $value;
	}

	/**
	 * Normalise CFF's legacy "unset colour" storage shape (a bare '#') to the
	 * empty string. Shared by cast_out() (read) and validate_in() (write) so
	 * a stored '#' and an agent-supplied '#' are always treated identically.
	 *
	 * @param string $str Value to normalise.
	 * @return string
	 */
	public static function normalize_color($str)
	{
		return $str === '#' ? '' : $str;
	}

	/**
	 * Clip a stored value for embedding inside a human-readable warning
	 * note. The note is not schema-validated the way the returned value
	 * is (cast_out() already length-caps the value itself to MAX_STRING_LENGTH
	 * before this is ever called), but a 500-character quote inside a
	 * warning string is still unreadable, so clip to a much shorter preview.
	 *
	 * @param string $str Value to clip.
	 * @return string
	 */
	public static function clip_for_note($str)
	{
		$preview_length = 100;
		if (mb_strlen($str) <= $preview_length) {
			return $str;
		}
		return mb_substr($str, 0, $preview_length) . '…';
	}

	/**
	 * Derive the `dateformat` enum from the plugin's own date-format picker
	 * (AgDR-9004 requirement 1: derive, don't restate) instead of hand-listing
	 * the format codes. `array_map('strval', …)` matters: PHP casts every
	 * purely-numeric-string array key to a real int, so a bare `array_keys()`
	 * would silently mix PHP integers with the one string 'custom', and every
	 * strict `in_array()` comparison in this class (plus core's own
	 * output-schema validation) would then reject a legitimately-stored
	 * dateformat like the default ('1') — see custom-facebook-feed-pro's
	 * SBR-497 for the exact failure mode this avoids.
	 *
	 * @return string[]
	 */
	private static function dateformat_enum()
	{
		if (!method_exists('\CustomFacebookFeed\Builder\Tabs\CFF_Builder_Customizer_Tab', 'get_date_format_options')) {
			return array();
		}
		return array_map(
			'strval',
			array_keys(\CustomFacebookFeed\Builder\Tabs\CFF_Builder_Customizer_Tab::get_date_format_options())
		);
	}

	/**
	 * Delimit a schema `pattern` string for `preg_match()`. Uses `~` as the
	 * delimiter (absent from every pattern this schema defines) and escapes
	 * any literal `~` in the pattern text, so a pattern containing an
	 * unescaped `/` can never prematurely terminate a `/`-delimited pattern.
	 *
	 * @param string $pattern Raw pattern text (no delimiters).
	 * @return string A complete, delimited PCRE pattern.
	 */
	private static function compiled_pattern($pattern)
	{
		return '~' . str_replace('~', '\\~', $pattern) . '~';
	}
}
