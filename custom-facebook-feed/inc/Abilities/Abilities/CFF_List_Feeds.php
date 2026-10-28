<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\Services\CFF_Feed_Settings_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * facebook-feed/list-feeds
 *
 * @since 4.13
 */
class CFF_List_Feeds extends CFF_Ability
{
	public function slug()
	{
		return 'list-feeds';
	}

	public function args()
	{
		$summary = array(
			'type'       => 'object',
			'properties' => array(
				'id'            => array('type' => 'integer'),
				'feed_name'     => array('type' => 'string'),
				'feed_title'    => array('type' => 'string'),
				'feedtype'      => array('type' => 'string', 'description' => 'timeline, events, videos, photos, albums, reviews, ...'),
				'feedlayout'    => array('type' => 'string'),
				'status'        => array('type' => 'string'),
				'last_modified' => array('type' => 'string'),
				'shortcode'     => array('type' => 'string', 'description' => 'Shortcode that renders this feed.'),
			),
		);

		return array(
			'label'               => __('List Custom Facebook Feeds', 'custom-facebook-feed'),
			'description'         => __(
				'Lists the feeds created in the Feed Builder with their id, name, layout and shortcode. Paginated.',
				'custom-facebook-feed'
			),
			'input_schema'        => array(
				// null is accepted because a bare GET passes null input (all params are optional).
				'type'                 => array('object', 'null'),
				'properties'           => array(
					'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => CFF_Feed_Settings_Service::MAX_PER_PAGE,
						'default' => 20,
					),
					'status'   => array(
						'type'        => 'string',
						'enum'        => array('publish', 'draft', 'trash', 'all'),
						'default'     => 'publish',
						'description' => 'Filter by feed status; "all" returns every feed.',
					),
				),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feeds'       => array('type' => 'array', 'items' => $summary),
					'total'       => array('type' => 'integer'),
					'page'        => array('type' => 'integer'),
					'per_page'    => array('type' => 'integer'),
					'total_pages' => array('type' => 'integer'),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	public function execute($input = null)
	{
		$input = $this->normalize_input($input);
		return (new CFF_Feed_Settings_Service())->list_feeds(
			isset($input['page']) ? (int) $input['page'] : 1,
			isset($input['per_page']) ? (int) $input['per_page'] : 20,
			isset($input['status']) ? (string) $input['status'] : 'publish'
		);
	}
}
