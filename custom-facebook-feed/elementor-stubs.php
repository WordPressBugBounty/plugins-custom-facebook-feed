<?php
/**
 * Static-analysis stubs.
 *
 * The Elementor plugin is not a Composer dependency of this plugin, so its
 * classes are invisible to PHPStan. This file declares a minimal stub of the
 * Elementor base class that CFF_Elementor_Widget extends, purely so static
 * analysis can resolve it and the control methods it calls.
 *
 * It is never loaded at runtime: nothing requires it, it lives outside the
 * PSR-4 autoload root (inc/), and the class_exists() guard means it can never
 * redeclare the real Elementor class when the Elementor plugin is active.
 */

namespace Elementor;

// phpcs:disable -- static-analysis stub only; not subject to coding standards.
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	abstract class Widget_Base {
		public function start_controls_section( ...$args ) {}
		public function add_control( ...$args ) {}
		public function end_controls_section( ...$args ) {}
		public function get_settings_for_display( ...$args ) {}
	}
}
