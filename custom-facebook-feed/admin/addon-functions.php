<?php

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

/**
 * Deactivate addon.
 *
 * @since 1.0.0
 */
function cff_deactivate_addon()
{

	// Run a security check.
	check_ajax_referer('cff-admin', 'nonce');

	// Check for permissions.
	if (! current_user_can('deactivate_plugins')) {
		wp_send_json_error();
	}

	$type = 'addon';
	if (! empty($_POST['type'])) {
		$type = sanitize_key(wp_unslash($_POST['type']));
	}

	if (isset($_POST['plugin'])) {
		deactivate_plugins(wp_unslash($_POST['plugin']));

		if ('plugin' === $type) {
			wp_send_json_success(esc_html__('Plugin deactivated.', 'custom-facebook-feed'));
		} else {
			wp_send_json_success(esc_html__('Addon deactivated.', 'custom-facebook-feed'));
		}
	}

	wp_send_json_error(esc_html__('Could not deactivate the addon. Please deactivate from the Plugins page.', 'custom-facebook-feed'));
}
add_action('wp_ajax_cff_deactivate_addon', 'cff_deactivate_addon');

/**
 * Activate addon.
 *
 * @since 1.0.0
 */
function cff_activate_addon()
{

	// Run a security check.
	check_ajax_referer('cff-admin', 'nonce');

	// Check for permissions.
	if (! current_user_can('activate_plugins')) {
		wp_send_json_error();
	}

	if (isset($_POST['plugin'])) {
		$type = 'addon';
		if (! empty($_POST['type'])) {
			$type = sanitize_key(wp_unslash($_POST['type']));
		}

		$activate = activate_plugins($_POST['plugin']);

		if (! is_wp_error($activate)) {
			if ('plugin' === $type) {
				wp_send_json_success(esc_html__('Plugin activated.', 'custom-facebook-feed'));
			} else {
				wp_send_json_success(esc_html__('Addon activated.', 'custom-facebook-feed'));
			}
		}
	}

	wp_send_json_error(esc_html__('Could not activate addon. Please activate from the Plugins page.', 'custom-facebook-feed'));
}
add_action('wp_ajax_cff_activate_addon', 'cff_activate_addon');

/**
 * Whether a plugin package URL may be handed to the installer.
 *
 * The install handler runs with the install_plugins capability and downloads,
 * unpacks and activates whatever package URL it is given, so the URL is a
 * remote-code-execution primitive for anything that can reach the handler
 * (including script running in an administrator's browser session). Only
 * HTTPS packages on the exact hosts the plugin itself links to -- the
 * wordpress.org download host and smashballoon.com -- are accepted; everything
 * else is rejected before any download. Hosts are pinned exactly (no wildcard
 * subdomains) so a dangling DNS record on an unrelated subdomain can never
 * become an install path, and because the downloader follows redirects the
 * allowed hosts are ones we control or that never redirect off-site.
 *
 * @param mixed $url Raw URL submitted to the handler.
 *
 * @return bool
 *
 * @since 4.13.1
 */
function cff_is_allowed_plugin_download_url( $url )
{
	if (! is_string($url) || '' === $url) {
		return false;
	}

	$parts = wp_parse_url($url);
	if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
		return false;
	}

	if ('https' !== strtolower($parts['scheme'])) {
		return false;
	}

	// Credentials or a non-default port in the URL are never part of a legitimate package link.
	if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
		return false;
	}

	$host = strtolower($parts['host']);

	// A host must be well-formed DNS labels: no empty label (leading/trailing/double dot)
	// so ".wordpress.org" cannot satisfy the suffix comparison below.
	if (! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $host)) {
		return false;
	}

	$allowed_hosts = array( 'downloads.wordpress.org', 'wordpress.org', 'smashballoon.com', 'www.smashballoon.com' );

	return in_array($host, $allowed_hosts, true);
}

/**
 * Install addon.
 *
 * @since 1.0.0
 */
function cff_install_addon()
{

	// Run a security check.
	check_ajax_referer('cff-admin', 'nonce');

	// Check for permissions.
	if (! current_user_can('install_plugins')) {
		wp_send_json_error();
	}

	$error = esc_html__('Could not install addon. Please download from smashballoon.com and install manually.', 'custom-facebook-feed');

	if (empty($_POST['plugin'])) {
		wp_send_json_error($error);
	}

	// Only packages from trusted hosts may be installed; see cff_is_allowed_plugin_download_url().
	$plugin_url = is_string($_POST['plugin']) ? esc_url_raw(wp_unslash($_POST['plugin'])) : '';
	if (! cff_is_allowed_plugin_download_url($plugin_url)) {
		wp_send_json_error($error);
	}

	// Set the current screen to avoid undefined notices.
	set_current_screen('cff-about');

	// Prepare variables.
	$url = esc_url_raw(
		add_query_arg(
			array(
				'page' => 'cff-about',
			),
			admin_url('admin.php')
		)
	);

	$creds = request_filesystem_credentials($url, '', false, false, null);

	// Check for file system permissions.
	if (false === $creds) {
		wp_send_json_error($error);
	}

	if (! WP_Filesystem($creds)) {
		wp_send_json_error($error);
	}

	/*
	 * We do not need any extra credentials if we have gotten this far, so let's install the plugin.
	 */

	// require_once CFF_PLUGIN_DIR . 'admin/class-install-skin.php';

	// Do not allow WordPress to search/download translations, as this will break JS output.
	remove_action('upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20);

	// Create the plugin upgrader with our custom skin.
	$installer = new CustomFacebookFeed\Helpers\PluginSilentUpgrader(new CustomFacebookFeed\Admin\CFF_Install_Skin());

	// Error check.
	if (! method_exists($installer, 'install') || empty($_POST['plugin'])) {
		wp_send_json_error($error);
	}

	$installer->install($plugin_url);

	// Flush the cache and return the newly installed plugin basename.
	wp_cache_flush();

	$plugin_basename = $installer->plugin_info();

	if ($plugin_basename) {
		$type = 'addon';
		if (! empty($_POST['type'])) {
			$type = sanitize_key($_POST['type']);
		}

		// Activate the plugin silently.
		$activated = activate_plugin($plugin_basename);

		if (! is_wp_error($activated)) {
			wp_send_json_success(
				array(
					'msg'          => 'plugin' === $type ? esc_html__('Plugin installed & activated.', 'custom-facebook-feed') : esc_html__('Addon installed & activated.', 'custom-facebook-feed'),
					'is_activated' => true,
					'basename'     => $plugin_basename,
				)
			);
		} else {
			wp_send_json_success(
				array(
					'msg'          => 'plugin' === $type ? esc_html__('Plugin installed.', 'custom-facebook-feed') : esc_html__('Addon installed.', 'custom-facebook-feed'),
					'is_activated' => false,
					'basename'     => $plugin_basename,
				)
			);
		}
	}

	wp_send_json_error($error);
}


add_action('wp_ajax_cff_install_addon', 'cff_install_addon');


/**
 * Smash Balloon Encrypt or decrypt
 *
 * @param string @action
 * @param string @string
 *
 * @return string $output
 */
function sb_encrypt_decrypt($action, $string)
{
	$output = false;

	$encrypt_method = "AES-256-CBC";
	$secret_key     = 'SMA$H.BA[[OON#23121';
	$secret_iv      = '1231394873342102221';

	// hash
	$key = hash('sha256', $secret_key);

	// iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
	$iv = substr(hash('sha256', $secret_iv), 0, 16);

	if ($action == 'encrypt') {
		$output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
		$output = base64_encode($output);
	} elseif ($action == 'decrypt') {
		$output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
	}

	return $output;
}
