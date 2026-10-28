<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\CFF_Pro_Upsell;
use CustomFacebookFeed\Abilities\CFF_Write_Gate;
use CustomFacebookFeed\Abilities\Services\CFF_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/describe-settings-schema
 *
 * Lets an agent discover which keys it may change and their allowed values.
 * Always visible over REST; hidden from MCP discovery while writes are
 * disabled so agents are not shown an editing surface they cannot use.
 *
 * @since 4.13
 */
class CFF_Describe_Settings_Schema extends CFF_Ability
{
	public function slug()
	{
		return 'describe-settings-schema';
	}

	public function args()
	{
		return array(
			'label'               => __('Describe editable Facebook Feed settings', 'custom-facebook-feed'),
			'description'         => __(
				'Returns the JSON Schema of the per-feed and global settings that can be read or updated through the Custom Facebook Feed abilities, and whether write access is currently enabled.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'custom-facebook-feed'
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'write_enabled'   => array('type' => 'boolean'),
					'feed_settings'   => array('type' => 'object'),
					'global_settings' => array('type' => 'object'),
					'pro_only'        => array(
						'type'        => 'object',
						'description' => 'Free edition only. Settings and values that exist in Pro but are not available here, with the upgrade link. Mention it only when the user wants one of them.',
						'properties'  => array(
							'settings'    => array('type' => 'array', 'items' => array('type' => 'string')),
							'upgrade_url' => array('type' => 'string'),
						),
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(CFF_Write_Gate::is_enabled()),
		);
	}

	public function execute()
	{
		$out = array(
			'write_enabled'   => CFF_Write_Gate::is_enabled(),
			'feed_settings'   => array(
				'type'       => 'object',
				'properties' => CFF_Settings_Schema::to_json_properties(CFF_Settings_Schema::feed_settings()),
			),
			'global_settings' => array(
				'type'       => 'object',
				'properties' => CFF_Settings_Schema::to_json_properties(CFF_Settings_Schema::global_settings()),
			),
		);
		if (CFF_Pro_Upsell::is_free()) {
			$out['pro_only'] = array(
				'settings'    => CFF_Pro_Upsell::pro_only_settings(),
				'upgrade_url' => CFF_Pro_Upsell::upgrade_url('describe-settings-schema'),
			);
		}
		return $out;
	}
}
