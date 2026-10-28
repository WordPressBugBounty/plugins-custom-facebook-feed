<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\Services\CFF_Feed_Settings_Service;
use CustomFacebookFeed\Abilities\Services\CFF_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/create-feed
 *
 * No `feedtemplate` input: free has no CFF_Feed_Templates_Settings trait
 * (templates are an upsell popup in the Feed Builder, not a real feature),
 * so every created feed seeds a bare `feedtype: timeline` row plus whatever
 * quick settings were explicitly passed.
 *
 * @since 4.14
 */
class CFF_Create_Feed extends CFF_Ability
{
	public function slug()
	{
		return 'create-feed';
	}

	public function args()
	{
		$writable = CFF_Settings_Schema::writable(CFF_Settings_Schema::feed_settings());
		// feed_name has a dedicated top-level property; a nested copy would be
		// silently written into the settings blob instead of the feeds row.
		unset($writable['feed_name']);

		return array(
			'label'               => __('Create a Custom Facebook Feed', 'custom-facebook-feed'),
			'description'         => __('Creates a new feed like the Feed Builder does: optional quick settings and an optional already-connected account as the source (one source per feed unless the paid Multifeed extension is active). A feed can be created with NO sources and connected later. Returns the new feed id and its shortcode. Requires AI write access. A setting that is not in this schema may be a Pro-only feature: call describe-settings-schema (pro_only) or get-pro-features.', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_name' => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => 'Optional name; defaults to the first source username.',
					),
					'sources'   => array(
						'type'        => 'array',
						'items'       => array('type' => 'string', 'maxLength' => 100),
						'maxItems'    => 10,
						'description' => 'Username or account id of ONE ALREADY-CONNECTED account (see list-sources); several only with the Multifeed extension. Omit to create a sourceless feed.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					),
					'settings'  => array(
						'type'                 => 'object',
						'properties'           => CFF_Settings_Schema::to_json_properties($writable),
						'additionalProperties' => false,
						'description'          => 'Quick settings applied to the new feed.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feed_id'   => array('type' => 'integer'),
					'feed_name' => array('type' => 'string'),
					'shortcode' => array('type' => 'string'),
					'updated'   => array('type' => 'array', 'items' => array('type' => 'string')),
					'ignored'   => array('type' => 'object'),
					'warnings'  => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Non-fatal notes about the write: a requested change was accepted '
							. 'but is currently inert per a plugin rule.',
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, false),
		);
	}

	public function execute($input = null)
	{
		return (new CFF_Feed_Settings_Service())->create_feed($this->normalize_input($input));
	}
}
