<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\CFF_Ability_Response;
use CustomFacebookFeed\Abilities\Services\CFF_Feed_Settings_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/set-feed-sources
 *
 * Destructive by annotation: changing sources changes what a feed shows, so
 * MCP clients that honour annotations (WPVibe) show their approval panel.
 * Destructive + idempotent maps to the DELETE HTTP method under core's
 * ability-to-REST-method rule (input travels via the query string).
 *
 * @since 4.13
 */
class CFF_Set_Feed_Sources extends CFF_Ability
{
	public function slug()
	{
		return 'set-feed-sources';
	}

	public function args()
	{
		return array(
			'label'               => __('Set the accounts a Facebook Feed shows', 'custom-facebook-feed'),
			'description'         => __('Replaces a feed\'s source with ONE already-connected account (see list-sources; use get-connect-url to connect a new one first). A feed holds a single source unless the paid Multifeed extension is active. Changes what the feed displays. Requires AI write access.', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array('type' => 'integer', 'minimum' => 1),
					'sources' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string', 'maxLength' => 100),
						'minItems'    => 1,
						'maxItems'    => 10,
						'description' => 'Username or account id of ONE connected account (several only with the Multifeed extension).',
					),
				),
				'required'             => array('feed_id', 'sources'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feed_id' => array('type' => 'integer'),
					'sources' => array('type' => 'array', 'items' => array('type' => 'string')),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(true, true),
		);
	}

	public function execute($input = null)
	{
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return CFF_Ability_Response::invalid_feed_id();
		}
		$handles = isset($input['sources']) && is_array($input['sources'])
			? array_map('strval', $input['sources'])
			: array();
		$result  = (new CFF_Feed_Settings_Service())->set_feed_sources($feed_id, $handles);

		return $result === null ? CFF_Ability_Response::feed_not_found($feed_id) : $result;
	}
}
