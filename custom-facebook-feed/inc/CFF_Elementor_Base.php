<?php

namespace CustomFacebookFeed;

use FacebookFeed\Vendor\Smashballoon\Framework\Packages\Blocks\RecommendedElementorWidgets;
use FacebookFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Feed_Blocks_Registry;
use FacebookFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Block_Utils;
use FacebookFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Elementor_Editor_Assets;
use CustomFacebookFeed\Builder\CFF_Db;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}


class CFF_Elementor_Base
{
	private static $instance = null;

	public static function register() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	public static function instance() {
		return self::register();
	}

	private function init() {
		if ( doing_action( 'init' ) || did_action( 'init' ) ) {
			$this->init_elementor_integration();
		} else {
			add_action( 'init', array( $this, 'init_elementor_integration' ), 4 );
		}
	}

	public function init_elementor_integration() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			return;
		}

		$recommended = new RecommendedElementorWidgets( 'facebook' );
		$recommended->setup();

		$registry = SB_Feed_Blocks_Registry::instance();
		$registry->register_elementor_widget(
			array(
				'blockId'    => 'facebook',
				'widgetName' => 'sb-facebook-feed',
				'globalVar'  => 'cffElementorData',
				'feedInitFn' => 'cff_init',
			)
		);

		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );
		add_action( 'elementor/controls/register', array( $this, 'register_controls' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_frontend_scripts' ) );
		add_action( 'elementor/elements/categories_registered', array( $this, 'add_smashballoon_categories' ) );
		add_action( 'elementor/editor/after_enqueue_scripts', array( $this, 'enqueue_editor_scripts' ) );
	}

	public function register_widgets( $widgets_manager ) {
		// Register modern widget
		require_once trailingslashit( CFF_PLUGIN_DIR ) . 'inc/Integrations/Elementor/CFF_Modern_Elementor_Widget.php';
		$widgets_manager->register( new \CustomFacebookFeed\Integrations\Elementor\CFF_Modern_Elementor_Widget() );

		// Register legacy widget for backward compatibility (existing pages use 'cff-widget' type)
		require_once trailingslashit( CFF_PLUGIN_DIR ) . 'inc/CFF_Elementor_Widget.php';
		$widgets_manager->register( new \CustomFacebookFeed\CFF_Elementor_Widget() );
	}

	public function register_controls( $controls_manager ) {
		require_once trailingslashit( CFF_PLUGIN_DIR ) . 'inc/CFF_Feed_Elementor_Control.php';
		$controls_manager->register( new \CustomFacebookFeed\CFF_Feed_Elementor_Control() );
	}

	public function register_frontend_scripts() {
		\cff_main()->enqueue_styles_assets();
		\cff_main()->enqueue_scripts_assets();

		$feeds = CFF_Db::elementor_feeds_list();

		$data = array(
			'feeds'         => ! empty( $feeds ) ? $feeds : array(),
			'feed_url'      => admin_url( 'admin.php?page=cff-feed-builder' ),
			'is_pro_active' => CFF_Utils::cff_is_pro_version(),
		);

		wp_localize_script( 'cffscripts', 'cffElementorData', $data );

		wp_register_script(
			'elementor-preview',
			CFF_PLUGIN_URL . 'assets/js/elementor-preview.js',
			array( 'jquery' ),
			CFFVER,
			true
		);

		SB_Feed_Blocks_Registry::instance()->enqueue_elementor_assets();
	}

	public function add_smashballoon_categories( $elements_manager ) {
		$elements_manager->add_category(
			SB_Block_Utils::CATEGORY_SLUG,
			array(
				'title' => esc_html__( 'Smash Balloon', 'custom-facebook-feed' ),
				'icon'  => 'fa fa-plug',
			)
		);
	}

	public function enqueue_editor_scripts() {
		SB_Elementor_Editor_Assets::enqueue_shared_elementor_styles( CFFVER );
	}
}
