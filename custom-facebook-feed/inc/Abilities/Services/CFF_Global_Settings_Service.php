<?php

namespace CustomFacebookFeed\Abilities\Services;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Reads/writes plugin-wide settings for the Abilities API (FREE edition),
 * restricted to the CFF_Settings_Schema::global_settings() allowlist.
 *
 * Free has no background cron (caching is always page-load, `cff_caching_type`
 * is hard-coded to 'page') and no image-resize toggle, so Pro's cron-reschedule
 * and GDPR/resize-interlock side effects are dropped entirely. Free instead
 * has its own native page-cache keys (`cff_cache_time` / `cff_cache_time_unit`),
 * which this service now exposes (AgDR-9005 Q1).
 *
 * @since 4.14
 */
class CFF_Global_Settings_Service
{
	/**
	 * @return array<string, mixed>
	 */
	public function get()
	{
		$style_settings = get_option('cff_style_settings', array());
		$style_settings = is_array($style_settings) ? $style_settings : array();
		$style_defaults = $this->style_defaults();

		$raw = array(
			'cff_cache_time'        => get_option('cff_cache_time', 1),
			'cff_cache_time_unit'   => get_option('cff_cache_time_unit', 'hours'),
			'gdpr'                  => isset($style_settings['gdpr']) ? $style_settings['gdpr'] : null,
			'cff_locale'            => get_option('cff_locale', 'en_US'),
			'cff_preserve_settings' => get_option('cff_preserve_settings', null),
			'cff_show_credit'       => isset($style_settings['cff_show_credit']) ? $style_settings['cff_show_credit'] : null,
			'cff_format_issue'      => isset($style_settings['cff_format_issue']) ? $style_settings['cff_format_issue'] : null,
			'cff_ajax'              => get_option('cff_ajax', null),
			'disable_admin_notice'  => isset($style_settings['disable_admin_notice']) ? $style_settings['disable_admin_notice'] : null,
		);

		$schema   = CFF_Settings_Schema::global_settings();
		$out      = array();
		$warnings = array();
		foreach ($schema as $key => $def) {
			$note       = null;
			$default    = isset($style_defaults[$key]) ? $style_defaults[$key] : null;
			$raw_value  = isset($raw[$key]) ? $raw[$key] : null;
			$out[$key]  = CFF_Settings_Schema::cast_out($def, $raw_value, $default, $note, $key);
			if ($note !== null) {
				$warnings[] = $note;
			}
		}
		$out['warnings'] = $warnings;
		return $out;
	}

	/**
	 * Apply a partial update to the plugin-wide settings.
	 *
	 * Writes each option the same way CFF_Global_Settings::cff_save_settings()
	 * does for the same keys, then clears the page caches the way the
	 * Settings page save does (never by calling the AJAX handler directly).
	 *
	 * @param array $partial key => value, already schema-validated by core.
	 * @return array{updated: string[], ignored: object, warnings: string[]}|\WP_Error
	 */
	public function update(array $partial)
	{
		$schema   = CFF_Settings_Schema::global_settings();
		$writable = CFF_Settings_Schema::writable($schema);
		$updated  = array();
		$ignored  = array();
		$clean    = array();

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
			$clean[$key] = $v;
		}

		if (empty($clean)) {
			return array('updated' => array(), 'ignored' => (object) $ignored, 'warnings' => array());
		}

		$style_settings = get_option('cff_style_settings', array());
		$style_settings = is_array($style_settings) ? $style_settings : array();
		$style_touched  = false;

		$style_keys = array('cff_show_credit', 'cff_format_issue', 'disable_admin_notice');

		foreach ($clean as $key => $v) {
			$def = $schema[$key];
			switch ($key) {
				case 'cff_locale':
					update_option('cff_locale', $v);
					break;
				case 'cff_cache_time':
					update_option('cff_cache_time', (int) $v);
					break;
				case 'cff_cache_time_unit':
					update_option('cff_cache_time_unit', $v);
					break;
				case 'cff_preserve_settings':
					update_option('cff_preserve_settings', $v);
					break;
				case 'gdpr':
					// Same option key and sanitiser as the Settings page save
					// (CFF_Global_Settings::cff_save_settings()): cff_style_settings['gdpr'].
					$style_settings['gdpr'] = sanitize_text_field($v);
					$style_touched          = true;
					break;
				case 'cff_ajax':
					// Native representation: Free's own settings save
					// (CFF_Global_Settings::cff_save_settings()) stores
					// sanitize_text_field()'d '1'/'' strings for this option, not a
					// real PHP boolean — mirror that exactly so an agent write and a
					// human save leave the option in the identical shape.
					update_option('cff_ajax', $v ? '1' : '');
					break;
				case 'disable_admin_notice':
					// Handled below via $style_keys — disable_admin_notice is
					// readOnly (`writable => false`), so this branch never actually
					// runs; kept only for symmetry with the style-keys list.
					break;
				default:
					if (in_array($key, $style_keys, true)) {
						$style_settings[$key] = CFF_Settings_Schema::encode_bool($v, $def);
						$style_touched         = true;
					}
			}
			$updated[] = $key;
		}

		if ($style_touched) {
			update_option('cff_style_settings', $style_settings);
		}

		// Same side effects as the Settings page save's free-edition branch
		// (CFF_Global_Settings::cff_clear_cache()), reproduced directly rather
		// than by calling the AJAX handler — which check_ajax_referer()'s and
		// dies on a request with no `nonce` POST field, after the options
		// above have already been written.
		if (array_key_exists('cff_cache_time', $clean) || array_key_exists('cff_cache_time_unit', $clean)) {
			$this->reschedule_cron();
		}
		$this->clear_stored_caches();

		/**
		 * Fires after an AI agent updated global settings through the Abilities API.
		 *
		 * @param string[] $updated Keys that were written.
		 */
		do_action('cff_abilities_global_settings_updated', $updated);

		$warnings = array();
		if (in_array('gdpr', $updated, true) && $clean['gdpr'] === 'no') {
			$warnings[] = 'GDPR mode is now "no": feeds load Facebook images and scripts before '
				. 'visitors consent. Tell the site owner, since this can affect legal compliance.';
		}

		return array('updated' => $updated, 'ignored' => (object) $ignored, 'warnings' => $warnings);
	}

	/**
	 * Reschedule the `cff_cron_job` cache-expiry event the same way the
	 * free-edition branch of CFF_Global_Settings::cff_clear_cache() does,
	 * using the just-written `cff_cache_time` / `cff_cache_time_unit`
	 * options. Never calls the AJAX handler itself — it check_ajax_referer()'s
	 * and dies on a request with no `nonce` POST field.
	 */
	private function reschedule_cron()
	{
		$cff_cache_time      = (int) get_option('cff_cache_time', 1);
		$cff_cache_time_unit = get_option('cff_cache_time_unit', 'hours');

		wp_clear_scheduled_hook('cff_cron_job');

		$cff_cron_schedule = 'hourly';
		if ($cff_cache_time_unit === 'hours' && $cff_cache_time > 5) {
			$cff_cron_schedule = 'twicedaily';
		}
		if ($cff_cache_time_unit === 'days') {
			$cff_cron_schedule = 'daily';
		}

		wp_schedule_event(time(), $cff_cron_schedule, 'cff_cron_job');
	}

	/**
	 * Clear the stored feed caches the same way the Settings page save does
	 * (its `$this->clear_stored_caches()` call) — the plugin's own public,
	 * side-effect-only method. This has no nonce check of its own, unlike
	 * the AJAX handler `cff_clear_cache()` that wraps it on the Settings page.
	 */
	private function clear_stored_caches()
	{
		if (
			function_exists('cff_main')
			&& method_exists(cff_main()->cff_global_settings, 'clear_stored_caches')
		) {
			cff_main()->cff_global_settings->clear_stored_caches();
		}
	}

	/**
	 * Plugin defaults for the cff_style_settings-backed keys, used as the
	 * cast_out() fallback for legacy/missing values.
	 *
	 * @return array<string, mixed>
	 */
	private function style_defaults()
	{
		return array(
			'gdpr'                  => 'auto',
			'cff_preserve_settings' => false,
			'cff_show_credit'       => false,
			'cff_format_issue'      => false,
			'disable_admin_notice'  => false,
			'cff_ajax'              => false,
			'cff_cache_time'        => 1,
			'cff_cache_time_unit'   => 'hours',
		);
	}
}
