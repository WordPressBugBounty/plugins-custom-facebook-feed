<?php

namespace CustomFacebookFeed\Integrations\Elementor;

use FacebookFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Elementor_Feed_Widget;
use CustomFacebookFeed\Builder\CFF_Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

class CFF_Modern_Elementor_Widget extends SB_Elementor_Feed_Widget {

	protected function get_widget_name() {
		return 'sb-facebook-feed';
	}

	protected function get_widget_title() {
		return __( 'Facebook Feed', 'custom-facebook-feed' );
	}

	protected function get_widget_icon() {
		return 'sb-elem-icon sb-elem-facebook';
	}

	protected function get_shortcode_tag() {
		return 'custom-facebook-feed';
	}

	protected function get_feeds_options() {
		$feeds = CFF_Db::elementor_feeds_query();
		unset( $feeds[0] );
		return $feeds;
	}

	protected function get_text_domain() {
		return 'custom-facebook-feed';
	}

	protected function get_script_deps() {
		return array( 'cffscripts', 'sb-elementor-editor' );
	}

	protected function get_style_deps() {
		return array( 'cff', 'sb-elementor-editor' );
	}

	protected function get_output_filter() {
		return 'cff_output';
	}
}
