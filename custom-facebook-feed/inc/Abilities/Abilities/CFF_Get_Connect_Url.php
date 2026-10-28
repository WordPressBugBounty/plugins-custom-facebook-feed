<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\Services\CFF_Source_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/get-connect-url
 *
 * The OAuth handoff: the agent hands the returned wp-admin link to the human;
 * that page (CFF_Connect_Bootstrap) consumes the single-use state token and
 * auto-submits the Feed Builder's own connect POST with a fresh session
 * nonce, so the relay round-trip and the page/group picker on return run
 * through the plugin's native path. The agent never sees Meta credentials.
 *
 * @since 4.13
 */
class CFF_Get_Connect_Url extends CFF_Ability
{
	public function slug()
	{
		return 'get-connect-url';
	}

	public function args()
	{
		return array(
			'label'               => __('Get a Facebook account-connect link', 'custom-facebook-feed'),
			'description'         => __('Returns a single-use link (valid 15 minutes) the site admin opens in their browser to connect a Facebook page or group via OAuth. The assistant never touches Meta credentials. After connecting, use list-sources to see the new account.', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			// No `group` input: the native flow has no "connect as a group"
			// selection to mirror — `has_group` on the plugin's own connect URLs
			// means "this site already has group sources"
			// (inc/Builder/CFF_Source.php:471), the SAME value on both its 'page'
			// and 'group' entries, so there is nothing here for an agent to choose.
			// Null-tolerant: POST abilities must define an input schema when any
			// input arrives; a bodyless POST passes null.
			'input_schema'        => array(
				'type'                 => array('object', 'null'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'connect_url'  => array('type' => 'string'),
					'expires_in'   => array('type' => 'integer'),
					'instructions' => array('type' => 'string'),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, false),
		);
	}

	public function execute()
	{
		return (new CFF_Source_Service())->get_connect_url();
	}
}
