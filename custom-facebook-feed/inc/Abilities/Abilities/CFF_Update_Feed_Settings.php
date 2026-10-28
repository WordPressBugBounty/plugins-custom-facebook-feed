<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\CFF_Ability_Response;
use CustomFacebookFeed\Abilities\Services\CFF_Feed_Settings_Service;
use CustomFacebookFeed\Abilities\Services\CFF_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/update-feed-settings
 *
 * Gated by CFF_Write_Gate, then capability. No license-tier concept exists
 * in the free edition, so `tier_check`/`is_feature_available` (Pro's
 * per-key licence gate) are deleted rather than stubbed — a stub that always
 * returns true would silently re-expose nothing but dead code and invite a
 * Pro-only key to be added behind it later.
 *
 * @since 4.14
 */
class CFF_Update_Feed_Settings extends CFF_Ability
{
	public function slug()
	{
		return 'update-feed-settings';
	}

	public function args()
	{
		$writable = CFF_Settings_Schema::writable(CFF_Settings_Schema::feed_settings());
		$props    = array(
			'feed_id'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Feed id from list-feeds.',
			),
			'settings' => array(
				'type'                 => 'object',
				'properties'           => CFF_Settings_Schema::to_json_properties($writable),
				'minProperties'        => 1,
				'additionalProperties' => false,
				'description'          => 'Settings to change. Only the keys you pass are changed; '
					. 'other stored values are re-saved as-is.',
			),
		);

		return array(
			'label'               => __('Update a Custom Facebook Feed', 'custom-facebook-feed'),
			'description'         => __('Changes one or more settings of a feed (layout, columns, post count, header, likebox, colours). Only the keys you pass are changed. Requires AI write access to be enabled in Facebook Feed → Settings → General. Returns updated and ignored keys. A setting that is not in this schema may be a Pro-only feature: call describe-settings-schema (pro_only) or get-pro-features.', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $props,
				'required'             => array('feed_id', 'settings'),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(array('feed_id' => array('type' => 'integer'))),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, true),
		);
	}

	public function execute($input = null)
	{
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return CFF_Ability_Response::invalid_feed_id();
		}
		$settings = isset($input['settings']) ? $this->normalize_input($input['settings']) : array();

		$service = new CFF_Feed_Settings_Service();
		$result  = $service->update_feed($feed_id, $settings);

		return $result === null ? CFF_Ability_Response::feed_not_found($feed_id) : $result;
	}
}
