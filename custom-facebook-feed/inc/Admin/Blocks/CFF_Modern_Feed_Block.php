<?php

namespace CustomFacebookFeed\Admin\Blocks;

use FacebookFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Feed_Block;
use CustomFacebookFeed\Builder\CFF_Db;

class CFF_Modern_Feed_Block extends SB_Feed_Block {

	protected function get_block_name() {
		return 'smashballoon/facebook-feed';
	}

	protected function get_shortcode_tag() {
		return 'custom-facebook-feed';
	}

	protected function get_script_handle() {
		return 'sb-feed-blocks';
	}

	protected function get_text_domain() {
		return 'custom-facebook-feed';
	}

	protected function get_plugin_dir() {
		return trailingslashit( CFF_PLUGIN_DIR );
	}

	protected function get_enqueue_scripts_action() {
		return 'cff_enqueue_scripts';
	}

	protected function get_localize_var_name() {
		return 'cffFacebookFeedBlock';
	}

	protected function get_feed_block_id() {
		return 'facebook';
	}

	protected function get_init_function() {
		return 'cff_init';
	}

	protected function get_block_dir() {
		return $this->get_plugin_dir() . 'vendor/smashballoon/framework/Packages/Blocks/dist/feed-blocks/facebook';
	}

	protected function get_editor_localize_data() {
		$feeds = CFF_Db::feeds_query();

		return array(
			'feeds'    => ! empty( $feeds ) ? $feeds : array(),
			'feed_url' => admin_url( 'admin.php?page=cff-feed-builder' ),
			'nonce'    => wp_create_nonce( 'cff-blocks' ),
		);
	}

	public function register_hooks() {
		add_action(
			'cff_enqueue_scripts',
			function ( $force = false ) {
				\cff_main()->enqueue_styles_assets();
				\cff_main()->enqueue_scripts_assets();
				// When the parent block fires this action with $force = true (iframe
				// editor preview or a page that contains the block) the feed must
				// load regardless of the "load assets only with shortcode" option,
				// which otherwise prevents enqueue_*_assets() from enqueueing.
				if ( $force ) {
					wp_enqueue_style( 'cff' );
					wp_enqueue_script( 'cffscripts' );
				}
			}
		);

		parent::register_hooks();

		if ( doing_action( 'init' ) || did_action( 'init' ) ) {
			$this->register_block();
		}

		remove_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ), 25 );
	}
}
