<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\CFF_Ability_Response;
use CustomFacebookFeed\Abilities\CFF_State_Token;
use CustomFacebookFeed\Builder\CFF_Db;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/request-feed-deletion
 *
 * Never deletes anything itself: it returns a browser confirmation link. The
 * deletion happens only when the logged-in admin clicks Confirm on the
 * wp-admin screen behind that link (see CFF_Delete_Confirmation).
 *
 * @since 4.13
 */
class CFF_Request_Feed_Deletion extends CFF_Ability
{
	public function slug()
	{
		return 'request-feed-deletion';
	}

	public function args()
	{
		return array(
			'label'               => __('Request deletion of a Custom Facebook Feed', 'custom-facebook-feed'),
			'description'         => __('Starts deletion of ONE feed. Returns a wp-admin confirmation link; the feed is deleted only after the site admin opens the link in their browser and clicks Confirm. The link is single-use, expires in 15 minutes, and requesting another deletion replaces any pending one — only one feed can ever be awaiting confirmation.', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array('type' => 'integer', 'minimum' => 1),
				),
				'required'             => array('feed_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feed_id'     => array('type' => 'integer'),
					'feed_name'   => array('type' => 'string'),
					'review_url'  => array(
						'type'        => 'string',
						'description' => 'Opens this exact feed in the Feed Builder, to inspect before confirming.',
					),
					'confirm_url' => array('type' => 'string'),
					'expires_in'  => array('type' => 'integer'),
					'note'        => array('type' => 'string'),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			// Deliberately NOT annotated destructive: this ability only mints a
			// confirmation link — the destructive act happens on the wp-admin page
			// behind it, after the human clicks Delete. Annotating it destructive
			// makes MCP clients add a second, earlier approval that duplicates the
			// browser confirmation and doubles the friction (maintainer decision,
			// carried over unchanged from the Instagram Feed port).
			'meta'                => $this->write_meta(false, false),
		);
	}

	public function execute($input = null)
	{
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return CFF_Ability_Response::invalid_feed_id();
		}
		$rows = CFF_Db::feeds_query(array('id' => $feed_id));
		if (empty($rows)) {
			return CFF_Ability_Response::feed_not_found($feed_id);
		}

		// One pending deletion per user: this invalidates any earlier, unconfirmed link.
		$token = CFF_State_Token::create_exclusive('delete_feed_' . $feed_id, 'delete');
		if (is_wp_error($token)) {
			return $token;
		}

		return array(
			'feed_id'     => $feed_id,
			'feed_name'   => (string) $rows[0]['feed_name'],
			'review_url'  => admin_url('admin.php?page=cff-feed-builder&feed_id=' . $feed_id),
			'confirm_url' => add_query_arg(
				array(
					'page'                         => 'cff-feed-builder',
					'cff_abilities_confirm_delete' => $feed_id,
					'cff_abilities_state'          => $token,
				),
				admin_url('admin.php')
			),
			'expires_in'  => CFF_State_Token::TTL,
			'note'        => __('Nothing has been deleted yet. Ask the site admin to open the confirmation link in their browser and click "Delete feed".', 'custom-facebook-feed'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
		);
	}
}
