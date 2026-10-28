<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\CFF_Ability_Response;
use CustomFacebookFeed\Abilities\Services\CFF_Feed_Settings_Service;
use CustomFacebookFeed\Abilities\Services\CFF_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/get-feed
 *
 * @since 4.13
 */
class CFF_Get_Feed extends CFF_Ability
{
	public function slug()
	{
		return 'get-feed';
	}

	public function args()
	{
		return array(
			'label'               => __('Get a Custom Facebook Feed', 'custom-facebook-feed'),
			'description'         => __(
				'Returns one feed\'s customer-configurable settings (layout, columns, post count, header, likebox, buttons, colors). Connected accounts are reduced to username and type; tokens are never returned.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'custom-facebook-feed'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Feed id from list-feeds.',
					),
				),
				'required'             => array('feed_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'            => array('type' => 'integer'),
					'feed_title'    => array('type' => 'string'),
					'status'        => array('type' => 'string'),
					'last_modified' => array('type' => 'string'),
					'shortcode'     => array('type' => 'string'),
					'sources'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'username'     => array('type' => 'string'),
								'account_type' => array('type' => 'string'),
							),
						),
					),
					'settings'      => array(
						'type'       => 'object',
						// Read-superset schema (AgDR-9005 downgrade rule): see
						// CFF_Settings_Schema::to_json_properties()'s docblock.
						'properties' => CFF_Settings_Schema::to_json_properties(
							CFF_Settings_Schema::feed_settings(),
							'read'
						),
					),
					'warnings'      => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Non-fatal notices, e.g. a stored source id that no longer resolves to a '
							. 'connected account (AgDR-9004 L2: never substitute an unrelated account instead).',
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	public function execute($input = null)
	{
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return CFF_Ability_Response::invalid_feed_id();
		}
		$feed = (new CFF_Feed_Settings_Service())->get_feed($feed_id);
		return $feed === null ? CFF_Ability_Response::feed_not_found($feed_id) : $feed;
	}
}
