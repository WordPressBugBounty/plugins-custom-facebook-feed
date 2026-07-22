<?php

/**
 * Custom Facebook Feed block with live preview.
 *
 * @since 2.3
 */

namespace CustomFacebookFeed;

use CustomFacebookFeed\Helpers\Util;
use CustomFacebookFeed\Builder\CFF_Db;
use CustomFacebookFeed\CFF_Utils;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class CFF_Blocks
{
	/**
	 * Indicates if current integration is allowed to load.
	 *
	 * @since 1.8
	 *
	 * @return bool
	 */
	public function allow_load()
	{
		return function_exists('register_block_type');
	}

	/**
	 * Loads an integration.
	 *
	 * @since 2.3
	 */
	public function load()
	{
		$this->hooks();

		require_once trailingslashit( CFF_PLUGIN_DIR ) . 'inc/Admin/Blocks/CFF_Modern_Feed_Block.php';
		$modern_block = new \CustomFacebookFeed\Admin\Blocks\CFF_Modern_Feed_Block();
		$modern_block->register_hooks();
	}

	/**
	 * Integration hooks.
	 *
	 * @since 2.3
	 */
	protected function hooks()
	{
		add_action('init', array( $this, 'register_block' ));
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ), 25 );
		// Styles enqueued on enqueue_block_assets are copied into the iframed editor
		// canvas by core (wp_get_iframed_editor_assets), so this reaches the iframe on
		// WP 7.0+ and the plain editor on older versions. Do NOT push raw CSS into the
		// block_editor_settings_all `styles` array instead: entries there are treated
		// as theme editor styles on every WP version, which suppresses core's default
		// editor typography and drops the post title/content to the browser's serif
		// fallback on themes that ship no editor styles.
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_content_assets' ) );

		/*
		* Add smashballoon category and Facebook Feed Block
		* @since 4.1.9
		*/
		add_filter('block_categories_all', array( $this, 'register_block_category' ), 10, 2);
	}

	/**
	 * Register Custom Facebook Feed Gutenberg block on the backend.
	 *
	 * @since 2.3
	 */
	public function register_block()
	{

		wp_register_style(
			'cff-blocks-styles',
			trailingslashit(CFF_PLUGIN_URL) . 'assets/css/cff-blocks.css',
			array( 'wp-edit-blocks' ),
			CFFVER
		);

		$attributes = array(
			'shortcodeSettings' => array(
				'type' => 'string',
			),
			'noNewChanges' => array(
				'type' => 'boolean',
			),
			'executed' => array(
				'type' => 'boolean',
			)
		);

		register_block_type(
			'cff/cff-feed-block',
			array(
				'api_version'     => 3,
				'attributes'      => $attributes,
				'render_callback' => array( $this, 'get_feed_html' ),
				'supports'        => array( 'inserter' => false ),
			)
		);
	}

	/**
	 * Enqueue feed frontend assets so the legacy block preview renders inside
	 * the WP 6.7+ iframe block editor. Mirrors SB_Feed_Block::enqueue_block_content_assets().
	 *
	 * @since 4.5.0
	 */
	public function enqueue_block_content_assets() {
		if ( ! is_admin() ) {
			return;
		}
		\cff_main()->enqueue_styles_assets();
		\cff_main()->enqueue_scripts_assets();
		// Force enqueue inside the iframe editor even when the "load assets only
		// with shortcode" option is on — the enqueue_*_assets() methods only
		// register the handles in that case.
		wp_enqueue_style( 'cff' );
		wp_enqueue_script( 'cffscripts' );
		// Block UI rules (placeholder/settings form rendered inside the block) need
		// to reach the iframed canvas too. Use a dedicated handle instead of the
		// 'cff-blocks-styles' one registered for the outer editor page — that handle
		// depends on wp-edit-blocks, which core already provides in the canvas.
		wp_enqueue_style(
			'cff-blocks-content',
			trailingslashit( CFF_PLUGIN_URL ) . 'assets/css/cff-blocks.css',
			array(),
			CFFVER
		);
	}

	/**
	 * Load Custom Facebook Feed Gutenberg block scripts.
	 *
	 * @since 2.3
	 */
	public function enqueue_block_editor_assets()
	{
		$access_token = get_option('cff_access_token');

		wp_enqueue_style('cff-blocks-styles');
		wp_enqueue_script(
			'cff-feed-block',
			trailingslashit(CFF_PLUGIN_URL) . 'assets/js/cff-blocks.js',
			array( 'wp-blocks', 'wp-i18n', 'wp-element', 'wp-block-editor' ),
			CFFVER,
			true
		);

		$shortcodeSettings = '';

		$i18n = array(
			'addSettings'         => esc_html__('Add Settings', 'custom-facebook-feed'),
			'shortcodeSettings'   => esc_html__('Shortcode Settings', 'custom-facebook-feed'),
			'example'             => esc_html__('Example', 'custom-facebook-feed'),
			'preview'             => esc_html__('Apply Changes', 'custom-facebook-feed'),

		);

		if (! empty($_GET['cff_wizard'])) {
			$shortcodeSettings = 'feed="' . (int)$_GET['cff_wizard'] . '"';
		}

		// CFF's Helpers\Util does not expose isDebugging()/is_script_debug() helpers
		// like Instagram Feed does, so fall back to the SCRIPT_DEBUG constant.
		$is_script_debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;

		$cff_js_file = $is_script_debug
			? 'assets/js/cff-scripts.js'
			: 'assets/js/cff-scripts.min.js';

		$jquery_file = 'js/jquery/jquery' . ( $is_script_debug ? '' : '.min' ) . '.js';

		wp_localize_script(
			'cff-feed-block',
			'cff_block_editor',
			array(
				'wpnonce'  => wp_create_nonce('facebook-blocks'),
				'canShowFeed' => ! empty($access_token),
				'configureLink' => get_admin_url() . '?page=cff-settings',
				'shortcodeSettings'    => $shortcodeSettings,
				'i18n'     => $i18n,
				'iframeScriptUrl'   => trailingslashit( CFF_PLUGIN_URL ) . $cff_js_file,
				'jqueryUrl'         => includes_url( $jquery_file ),
			)
		);
	}

	/**
	 * Get form HTML to display in a Custom Facebook Feed Gutenberg block.
	 *
	 * @param array $attr Attributes passed by Custom Facebook Feed Gutenberg block.
	 *
	 * @since 2.3
	 *
	 * @return string
	 */
	public function get_feed_html($attr)
	{
		$cff_statuses = get_option('cff_statuses', array());

		$return = '';

		$shortcode_settings = isset($attr['shortcodeSettings']) ? $attr['shortcodeSettings'] : '';

		if (empty($cff_statuses['support_legacy_shortcode'])) {
			if (empty($shortcode_settings) || strpos($shortcode_settings, 'feed=') === false) {
				$feeds = \CustomFacebookFeed\Builder\CFF_Feed_Builder::get_feed_list();
				$feed_id = $feeds[0]['id'];
				$shortcode_settings .= ' feed="' . (int)$feed_id . '"';
			}
		}

		$shortcode_settings = str_replace(array( '[custom-facebook-feed', ']' ), '', $shortcode_settings);

		$return .= do_shortcode('[custom-facebook-feed ' . $shortcode_settings . ']');

		return $return;
	}

	/**
	 * Checking if is Gutenberg REST API call.
	 *
	 * @since 2.3
	 *
	 * @return bool True if is Gutenberg REST API call.
	 */
	public static function is_gb_editor()
	{
		return defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $_REQUEST['context'] ) && 'edit' === $_REQUEST['context']; // phpcs:ignore
	}

	/**
	 * Register Block Category
	 *
	 * @since 4.1.9
	 */
	public function register_block_category($categories, $context)
	{
		$exists = array_search('smashballoon', array_column($categories, 'slug'));

		if ($exists !== false) {
			return $categories;
		}

		return array_merge(
			$categories,
			array(
				array(
					'slug' => 'smashballoon',
					'title' => __('Smash Balloon', 'custom-facebook-feed'),
				),
			)
		);
	}

	/**
	 * Register Block
	 *
	 * @since 4.1.9
	 */
	public function register_facebook_feed_block()
	{
		register_block_type(
			trailingslashit(CFF_PLUGIN_DIR) . 'assets/dist/sbf-feed',
			array(
				'render_callback' => array( $this, 'render_facebook_feed_block' ),
			)
		);
	}

	/**
	 * Render Block
	 *
	 * @since 4.1.9
	 */
	public function render_facebook_feed_block($attributes)
	{
		$content = '';

		if (isset($attributes['feedId'])) {
			$content = do_shortcode('[custom-facebook-feed feed=' . (int) $attributes['feedId'] . ']');
		}

		return $content;
	}

	/**
	 * Enqueue Block Assets
	 *
	 * @since 4.1.9
	 */
	public function enqueue_facebook_feed_block_editor_assets()
	{
		$asset_file = include_once trailingslashit(CFF_PLUGIN_DIR) . 'assets/dist/blocks.asset.php';

		wp_enqueue_script(
			'cff-feed-block-editor',
			trailingslashit(CFF_PLUGIN_URL) . 'assets/dist/blocks.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_enqueue_style(
			'cff-feed-block-editor',
			trailingslashit(CFF_PLUGIN_URL) . 'assets/dist/blocks.css',
			array(),
			$asset_file['version']
		);

		wp_localize_script(
			'cff-feed-block-editor',
			'cff_feed_block_editor',
			array(
				'feeds' => CFF_Db::feeds_query(),
				'feed_url' => admin_url('admin.php?page=cff-feed-builder'),
				'plugins_info' => Util::get_smash_plugins_status_info(),
				'has_facebook_feed_block' => $this->has_facebook_feed_block(),
				'is_pro_active' => CFF_Utils::cff_is_pro_version(),
				'nonce'         => wp_create_nonce('cff-admin'),
			)
		);
	}

	/**
	 * Set Script Translations
	 *
	 * @since 4.1.9
	 */
	public function set_script_translations()
	{
		wp_set_script_translations('cff-feed-block-editor', 'custom-facebook-feed', CFF_PLUGIN_DIR . 'languages');
	}

	/**
	 * Check if the post has a Facebook Feed block
	 *
	 * @since 4.1.9
	 */
	public function has_facebook_feed_block()
	{
		return has_block('cff/cff-feed-block');
	}
}
