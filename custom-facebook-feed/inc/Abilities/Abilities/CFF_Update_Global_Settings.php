<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\Services\CFF_Global_Settings_Service;
use CustomFacebookFeed\Abilities\Services\CFF_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/update-global-settings
 *
 * Gated by CFF_Write_Gate (off by default), then the same capability as the
 * Settings page. Unknown keys are rejected by core (additionalProperties:false);
 * read-only keys are reported in `ignored`.
 *
 * @since 4.13
 */
class CFF_Update_Global_Settings extends CFF_Ability
{
	public function slug()
	{
		return 'update-global-settings';
	}

	public function args()
	{
		$writable = CFF_Settings_Schema::writable(CFF_Settings_Schema::global_settings());

		return array(
			'label'               => __('Update Facebook Feed global settings', 'custom-facebook-feed'),
			'description'         => __('Updates one or more plugin-wide Custom Facebook Feed settings (cache duration, GDPR mode, locale, and a few display and loading options). Requires AI write access to be enabled in Facebook Feed → Settings → General. Returns the keys that were updated and the keys that were ignored.', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => CFF_Settings_Schema::to_json_properties($writable),
				'minProperties'        => 1,
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, true),
		);
	}

	public function execute($input = null)
	{
		return (new CFF_Global_Settings_Service())->update($this->normalize_input($input));
	}
}
