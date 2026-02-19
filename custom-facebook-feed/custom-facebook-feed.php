<?php
/*
Plugin Name: Smash Balloon Custom Facebook Feed
Plugin URI: https://smashballoon.com/custom-facebook-feed
Description: Add completely customizable Facebook feeds to your WordPress site
Version: 4.7.5
Author: Smash Balloon
Author URI: http://smashballoon.com/
License: GPLv2 or later
Text Domain: custom-facebook-feed
Domain Path: /languages
*/
/*
Copyright 2025 Smash Balloon LLC (email : hey@smashballoon.com)
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}
define('CFFVER', '4.7.5');
define('WPW_SL_STORE_URL', 'https://smashballoon.com/');
define('WPW_SL_ITEM_NAME', 'Custom Facebook Feed WordPress Plugin Personal'); // *!*Update Plugin Name at top of file*!*

// Db version.
if (! defined('CFF_DBVERSION')) {
	define('CFF_DBVERSION', '2.5');
}


// Plugin Folder Path.
if (! defined('CFF_PLUGIN_DIR')) {
	define('CFF_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (! defined('CFF_PLUGIN_DIR_FILE_BASE')) {
	define('CFF_PLUGIN_DIR_FILE_BASE', dirname(plugin_basename(__FILE__)));
}

// Plugin Folder URL.
if (! defined('CFF_PLUGIN_URL')) {
	define('CFF_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (! defined('CFF_UPLOADS_NAME')) {
	define('CFF_UPLOADS_NAME', 'sb-facebook-feed-images');
}

// Name of the database table that contains instagram posts
if (! defined('CFF_POSTS_TABLE')) {
	define('CFF_POSTS_TABLE', 'cff_posts');
}

// Name of the database table that contains feed ids and the ids of posts
if (! defined('CFF_FEEDS_POSTS_TABLE')) {
	define('CFF_FEEDS_POSTS_TABLE', 'cff_feeds_posts');
}

// Plugin File.
if (! defined('CFF_FILE')) {
	define('CFF_FILE', __FILE__);
}

if (! defined('CFF_PLUGIN_BASE')) {
	define('CFF_PLUGIN_BASE', plugin_basename(CFF_FILE));
}
if (! defined('CFF_FEED_LOCATOR')) {
	define('CFF_FEED_LOCATOR', 'cff_facebook_feed_locator');
}

if (! defined('CFF_BUILDER_DIR')) {
	define('CFF_BUILDER_DIR', CFF_PLUGIN_DIR . 'admin/builder/');
}

if (! defined('CFF_BUILDER_URL')) {
	define('CFF_BUILDER_URL', CFF_PLUGIN_URL . 'admin/builder/');
}

if (! defined('CFF_CONNECT_URL')) {
	define('CFF_CONNECT_URL', 'https://connect.smashballoon.com/auth/fb/');
}

if (!defined('CFF_OEMBED_CONNECT_URL')) {
	define('CFF_OEMBED_CONNECT_URL', 'https://connect-tools.smashballoon.com/');
}


/**
 * Check PHP version
 *
 * Check for minimum PHP 7.4 version
 *
 * @since 2.19
*/
if (version_compare(phpversion(), '7.4', '<')) {
	if (!function_exists('cff_check_php_notice')) {
		include_once CFF_PLUGIN_DIR . 'admin/enqueu-script.php';
		function cff_check_php_notice()
		{
			?>
				<div class="notice notice-error">
					<div>
						<p><strong><?php echo esc_html__('Important:', 'custom-facebook-feed') ?> </strong><?php echo esc_html__('Your website is using an outdated version of PHP. The Custom Facebook Feed plugin requires PHP version 7.4 or higher and so has been temporarily deactivated.', 'custom-facebook-feed') ?></p>

						<p>
							<?php
							echo sprintf(
								/* translators: %s: link to download previous version */
								__('To continue using the plugin, you can either manually reinstall the previous version of the plugin (%s) or contact your host to request that they upgrade your PHP version to 7.4 or higher.', 'custom-facebook-feed'),
								'<a href="https://downloads.wordpress.org/plugin/custom-facebook-feed.4.3.4.zip">' . __('download', 'custom-facebook-feed') . '</a>'
							);
							?>
						</p>
					</div>
				</div>
			<?php
		}
	}
	add_action('admin_notices', 'cff_check_php_notice');
	return; // Stop until PHP version is fixed
}

include_once CFF_PLUGIN_DIR . 'admin/admin-functions.php';
include_once CFF_PLUGIN_DIR . 'inc/Custom_Facebook_Feed.php';

if (function_exists('cff_main_pro')) {
	wp_die("Please deactivate the Pro version of the Custom Facebook Feed plugin before activating this version.<br /><br />Back to the WordPress <a href='" . get_admin_url(null, 'plugins.php') . "'>Plugins page</a>.");
}

function cff_main()
{
	return CustomFacebookFeed\Custom_Facebook_Feed::instance();
}
cff_main();
