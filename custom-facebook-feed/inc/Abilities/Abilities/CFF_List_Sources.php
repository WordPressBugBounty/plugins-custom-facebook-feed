<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\Services\CFF_Source_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/list-sources
 *
 * @since 4.13
 */
class CFF_List_Sources extends CFF_Ability
{
	public function slug()
	{
		return 'list-sources';
	}

	public function args()
	{
		return array(
			'label'               => __('List connected Facebook accounts', 'custom-facebook-feed'),
			'description'         => __('Lists the Facebook pages/groups connected to this site (username, type, privilege, token expiry date, error flag, how many feeds use each). Never returns access tokens or account credentials.', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			// No input_schema: a bare GET passes null input, which an object schema rejects.
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'sources' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'username'      => array('type' => 'string'),
								'account_type'  => array('type' => 'string'),
								'privilege'     => array('type' => 'string'),
								'expires'       => array('type' => 'string'),
								'has_error'     => array('type' => 'boolean'),
								'used_in_feeds' => array('type' => 'integer'),
							),
						),
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	public function execute()
	{
		return array('sources' => (new CFF_Source_Service())->list_sources());
	}
}
