<?php

namespace CustomFacebookFeed\Abilities\Abilities;

use CustomFacebookFeed\Abilities\CFF_Ability_Response;
use CustomFacebookFeed\Abilities\CFF_Write_Gate;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Base class for one Custom Facebook Feed ability.
 *
 * Subclasses return the wp_register_ability() args; the shared pieces
 * (namespace, capability check, input normalisation, read-only meta) live here
 * so every ability fences authorization the same way.
 *
 * @since 4.13
 */
abstract class CFF_Ability
{
	const NAMESPACE_PREFIX = 'facebook-feed';
	const CATEGORY         = 'facebook-feed';

	/**
	 * @return string Slug after the namespace, e.g. `get-feed`.
	 */
	abstract public function slug();

	/**
	 * @return array Args for wp_register_ability() (without `category`).
	 */
	abstract public function args();

	/**
	 * @return string Fully-qualified ability name.
	 */
	public function name()
	{
		return self::NAMESPACE_PREFIX . '/' . $this->slug();
	}

	public function register()
	{
		$args             = $this->args();
		$args['category'] = self::CATEGORY;
		wp_register_ability($this->name(), $args);
	}

	/**
	 * Same gate as the Feed Builder and Settings pages, including the
	 * `cff_settings_pages_capability` filter contract.
	 *
	 * @return true|\WP_Error
	 */
	public function can_manage()
	{
		return cff_current_user_can('manage_custom_facebook_feed_options') ? true : CFF_Ability_Response::forbidden();
	}

	/**
	 * Core hands abilities null|array|object depending on transport.
	 *
	 * @param mixed $input
	 * @return array
	 */
	protected function normalize_input($input)
	{
		if (is_object($input)) {
			$input = get_object_vars($input);
		}
		return is_array($input) ? $input : array();
	}

	/**
	 * @param bool $mcp_public
	 * @return array
	 */
	protected function read_meta($mcp_public = true)
	{
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array('public' => (bool) $mcp_public),
		);
	}

	/**
	 * Write gate first, then capability — same order as the IG Feed pattern.
	 *
	 * @return true|\WP_Error
	 */
	public function can_write()
	{
		$gate = CFF_Write_Gate::check();
		if (is_wp_error($gate)) {
			return $gate;
		}
		return $this->can_manage();
	}

	/**
	 * @param bool $destructive
	 * @param bool $idempotent
	 * @return array
	 */
	protected function write_meta($destructive, $idempotent)
	{
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
			'show_in_rest' => true,
			// Hidden from MCP discovery while writes are disabled (agents should not
			// be shown a tool they cannot use); REST stays capability-gated.
			'mcp'          => array('public' => CFF_Write_Gate::is_enabled()),
		);
	}

	/**
	 * @param array $extra Extra top-level output properties.
	 * @return array
	 */
	protected function write_output_schema(array $extra = array())
	{
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				$extra,
				array(
					'updated' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Keys that were written.',
					),
					'ignored' => array(
						'type'        => 'object',
						'description' => 'Keys not written, with the reason: read_only or unknown_setting.',
					),
					'warnings' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Non-fatal notes about this write: a requested change was accepted but '
							. 'immediately overridden by another plugin rule (e.g. an interlocked setting), so '
							. 'it appears in `updated` but did not actually take effect (AgDR-0504 requirement 3).',
					),
				)
			),
		);
	}
}
