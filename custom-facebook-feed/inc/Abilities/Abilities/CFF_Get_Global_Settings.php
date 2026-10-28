<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\Services\CFF_Global_Settings_Service;
use CustomFacebookFeed\Abilities\Services\CFF_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/get-global-settings
 *
 * @since 4.13
 */
class CFF_Get_Global_Settings extends CFF_Ability
{
	public function slug()
	{
		return 'get-global-settings';
	}

	public function args()
	{
		return array(
			'label'               => __('Get Facebook Feed global settings', 'custom-facebook-feed'),
			'description'         => __(
				'Returns the plugin-wide Custom Facebook Feed settings (cache duration, GDPR mode, locale, and a few display and loading options). Never returns connected accounts, tokens or license data.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'custom-facebook-feed'
			),
			// No input_schema: a bare GET passes null input, which an object schema rejects.
			'output_schema'       => array(
				'type'       => 'object',
				// Read-superset schema (AgDR-9005 downgrade rule): see
				// CFF_Settings_Schema::to_json_properties()'s docblock. 'warnings' is
				// added explicitly since it isn't one of the global_settings() keys.
				'properties' => array_merge(
					CFF_Settings_Schema::to_json_properties(CFF_Settings_Schema::global_settings(), 'read'),
					array(
						'warnings' => array(
							'type'        => 'array',
							'items'       => array('type' => 'string'),
							'description' => 'Non-fatal notices, e.g. a stored value that sits outside the '
								. 'free edition\'s own set (AgDR-9005 downgrade rule: reported verbatim, never '
								. 'clamped).',
						),
					)
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	public function execute()
	{
		return (new CFF_Global_Settings_Service())->get();
	}
}
