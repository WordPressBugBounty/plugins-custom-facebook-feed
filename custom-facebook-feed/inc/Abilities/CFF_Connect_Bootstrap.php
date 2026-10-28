<?php

namespace CustomFacebookFeed\Abilities;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Browser-side start of an ability-initiated account connect.
 *
 * The Feed Builder never *links* to the OAuth relay: add-source.js's
 * processFBConnect() auto-submits a POST form carrying a browser-session
 * `cff_con` nonce and a raw state field. This page reproduces that form
 * exactly, for both the plain "page" connect and the "group" connect variant.
 * `get-connect-url` hands the admin a wp-admin URL carrying only our
 * single-use state token; landing here (capability-checked, token consumed)
 * mints a FRESH session nonce and auto-submits the same POST the Connect
 * button would — so the relay round-trip and the account-picker popup on
 * return run through the plugin's native path, completely unchanged.
 *
 * The state token never leaves this site: the relay only ever sees the same
 * fields the builder sends it.
 *
 * @since 4.13
 */
class CFF_Connect_Bootstrap
{
	public function __construct()
	{
		add_action('admin_init', array($this, 'maybe_render'), 1);
	}

	public function maybe_render()
	{
		if (
			empty($_GET['page']) || $_GET['page'] !== 'cff-feed-builder'
			|| empty($_GET['cff_abilities_connect']) || !is_string($_GET['cff_abilities_connect'])
		) {
			return;
		}
		if (!cff_current_user_can('manage_custom_facebook_feed_options')) {
			return;
		}
		// Revocation parity with CFF_Delete_Confirmation::handle_delete(): if AI
		// write access was turned off after this link was minted, honour the
		// revocation rather than completing a connect an agent is no longer
		// allowed to have requested.
		$token = sanitize_key($_GET['cff_abilities_connect']);
		// The token is consumed (burned) when the gate refuses, so turning write
		// access back on later cannot revive a link the admin was told is dead.
		// Capability was checked above and tokens are user-bound, so this can
		// only burn the current user's own link.
		if (!CFF_Write_Gate::is_enabled()) {
			CFF_State_Token::consume($token, 'connect');
			wp_die(
				esc_html__('AI write access has been disabled since this link was created.', 'custom-facebook-feed'),
				esc_html__('Facebook Feed', 'custom-facebook-feed'),
				array('response' => 403, 'back_link' => true)
			);
		}
		if (!CFF_State_Token::consume($token, 'connect')) {
			wp_die(
				esc_html__(
					'This connect link is invalid or has expired. Ask your AI assistant for a new one.',
					'custom-facebook-feed'
				),
				esc_html__('Facebook Feed', 'custom-facebook-feed'),
				array('response' => 403, 'back_link' => true)
			);
		}

		// `has_group` does NOT mean "connect a group" — it means "this site
		// ALREADY HAS group sources", per CFF_Source::get_connection_urls()
		// (inc/Builder/CFF_Source.php:471: CFF_Db::check_group_source() > 0),
		// which assigns the SAME value to both its 'page' and 'group' url
		// entries. add-source.js's processFBConnect() appends the
		// `?has_group=true` action suffix off that same flag, never off the
		// selected account type (admin/builder/assets/js/add-source.js:387).
		// There is no native "connect as a group" input to mirror, so this
		// reproduces the one real value instead of inventing a distinction
		// that doesn't exist upstream.
		$has_group = \CustomFacebookFeed\Builder\CFF_Db::check_group_source() > 0;

		// Same fields, same values, same method as processFBConnect() in
		// admin/builder/assets/js/add-source.js — with the nonce minted HERE,
		// in the browser session, so the native return path verifies it.
		$fields = array(
			'wordpress_user' => get_option('admin_email'),
			'v'              => 'free',
			'vn'             => CFFVER,
			'cff_con'        => wp_create_nonce('cff_con'),
			// JS boolean→string coercion on input.value: the native false value
			// is the literal string 'false', not ''.
			'has_group'      => $has_group ? 'true' : 'false',
			// Return to the SETTINGS page (its own maybe_source_connection_data()
			// pass + sources list), so the admin watches the account get added
			// where accounts are managed — not in the feed list. Same behaviour
			// as the Settings page's own Connect button (get_connection_urls(true)).
			'state'          => "{'{url=" . admin_url('admin.php?page=cff-settings') . "}'}",
		);

		$form_action = CFF_CONNECT_URL . ($has_group ? '?has_group=true' : '');

		header('Content-Type: text/html; charset=' . get_option('blog_charset'));
		header('X-Frame-Options: SAMEORIGIN');
		header('Referrer-Policy: no-referrer');
		// phpcs:disable Generic.Files.LineLength.TooLong -- inline template/CSS.
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo('charset'); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e('Connecting to Facebook…', 'custom-facebook-feed'); ?></title>
			<style>
				body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
				.cff-connect{background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);max-width:440px;padding:32px;text-align:center}
				.cff-connect h1{font-size:18px;margin:0 0 12px}
				.cff-connect p{color:#50575e;margin:0 0 24px}
				.cff-connect button{background:#1877F2;border:none;border-radius:4px;color:#fff;cursor:pointer;font-size:14px;padding:10px 18px}
			</style>
		</head>
		<body>
			<div class="cff-connect">
				<h1><?php esc_html_e('Connecting to Facebook…', 'custom-facebook-feed'); ?></h1>
				<p><?php esc_html_e('Redirecting you to log in with Facebook. Afterwards you will return here to pick the page(s) or group(s) to connect. Your AI assistant never sees your credentials.', 'custom-facebook-feed'); ?></p>
				<form id="cff-abilities-connect" method="post" action="<?php echo esc_url($form_action); ?>">
					<?php foreach ($fields as $name => $value) : ?>
						<input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
					<?php endforeach; ?>
					<noscript><button type="submit"><?php esc_html_e('Continue to Facebook login', 'custom-facebook-feed'); ?></button></noscript>
				</form>
				<script>document.getElementById('cff-abilities-connect').submit();</script>
			</div>
		</body>
		</html>
		<?php
		// phpcs:enable Generic.Files.LineLength.TooLong
		exit;
	}
}
