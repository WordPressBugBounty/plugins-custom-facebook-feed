<?php

namespace CustomFacebookFeed\Abilities;

use CustomFacebookFeed\Abilities\CFF_Write_Gate;
use CustomFacebookFeed\Builder\CFF_Db;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Browser-side confirmation for ability-requested feed deletion.
 *
 * The `request-feed-deletion` ability never deletes anything: it returns a
 * link to this screen. Deletion happens only after the logged-in admin sees
 * the feed's name here and clicks Confirm — capability check, admin nonce and
 * single-use state token all verified on the POST.
 *
 * @since 4.13
 */
class CFF_Delete_Confirmation
{
	public function __construct()
	{
		add_action('admin_init', array($this, 'maybe_render'), 2);
		add_action('admin_post_cff_abilities_delete_feed', array($this, 'handle_delete'));
	}

	public function maybe_render()
	{
		if (
			empty($_GET['page']) || $_GET['page'] !== 'cff-feed-builder'
			|| empty($_GET['cff_abilities_confirm_delete']) || empty($_GET['cff_abilities_state'])
			|| !is_string($_GET['cff_abilities_confirm_delete']) || !is_string($_GET['cff_abilities_state'])
		) {
			return;
		}
		if (!cff_current_user_can('manage_custom_facebook_feed_options')) {
			return;
		}
		$feed_id = absint($_GET['cff_abilities_confirm_delete']);
		$token   = sanitize_key($_GET['cff_abilities_state']);
		// Re-check the write gate here too, matching CFF_Connect_Bootstrap's own
		// revocation check — if AI write access was turned off after this link
		// was minted, the confirmation screen itself should say so rather than
		// let the admin reach the POST form only to have handle_delete() reject
		// it there. Capability was checked above, so only an authorised user
		// reaches this; tokens are user-bound, so this can only burn the
		// caller's own link.
		if (!CFF_Write_Gate::is_enabled()) {
			self::revoke_pending($token, $feed_id);
			wp_die(
				esc_html__('AI write access has been disabled since this link was created.', 'custom-facebook-feed'),
				esc_html__('Facebook Feed', 'custom-facebook-feed'),
				array('response' => 403, 'back_link' => true)
			);
		}
		if (!$feed_id || !CFF_State_Token::peek($token, 'delete_feed_' . $feed_id)) {
			wp_die(
				esc_html__(
					'This deletion link is invalid or has expired. Ask your AI assistant for a new one.',
					'custom-facebook-feed'
				),
				esc_html__('Facebook Feed', 'custom-facebook-feed'),
				array('response' => 403, 'back_link' => true)
			);
		}
		$rows = CFF_Db::feeds_query(array('id' => $feed_id));
		if (empty($rows)) {
			wp_die(
				esc_html__('This feed no longer exists.', 'custom-facebook-feed'),
				esc_html__('Facebook Feed', 'custom-facebook-feed'),
				array('response' => 404, 'back_link' => true)
			);
		}
		$feed_name  = $rows[0]['feed_name'];
		$cancel     = admin_url('admin.php?page=cff-feed-builder');
		$review_url = admin_url('admin.php?page=cff-feed-builder&feed_id=' . $feed_id);

		// Minimal interstitial, rendered before the builder app boots.
		// phpcs:disable Generic.Files.LineLength.TooLong -- inline template/CSS.
		header('Content-Type: text/html; charset=' . get_option('blog_charset'));
		header('X-Frame-Options: SAMEORIGIN');
		header('Referrer-Policy: no-referrer');
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e('Confirm feed deletion', 'custom-facebook-feed'); ?></title>
			<style>
				body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
				.cff-confirm{background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);max-width:480px;padding:32px;text-align:center}
				.cff-confirm h1{font-size:18px;margin:0 0 12px}
				.cff-confirm p{color:#50575e;margin:0 0 24px}
				.cff-confirm .actions{display:flex;gap:12px;justify-content:center}
				.cff-confirm button{background:#d63638;border:none;border-radius:4px;color:#fff;cursor:pointer;font-size:14px;padding:10px 18px}
				.cff-confirm .actions a{align-items:center;border:1px solid #c3c4c7;border-radius:4px;color:#2c3338;display:inline-flex;font-size:14px;padding:10px 18px;text-decoration:none}
				.cff-confirm .meta{background:#f6f7f7;border-radius:4px;color:#50575e;font-size:13px;margin:0 0 24px;padding:12px}
				.cff-confirm .meta code{background:transparent}
				.cff-confirm .meta a{color:#2271b1}
			</style>
		</head>
		<body>
			<div class="cff-confirm">
				<h1><?php esc_html_e('Delete this Facebook feed?', 'custom-facebook-feed'); ?></h1>
				<p>
					<?php
					printf(
						/* translators: 1: feed name, 2: feed id */
						esc_html__('An AI assistant asked to delete the feed %1$s (ID %2$d). This removes the feed and its caches; the connected account is not affected. This cannot be undone.', 'custom-facebook-feed'),
						'<strong>' . esc_html($feed_name) . '</strong>',
						(int) $feed_id
					);
					?>
				</p>
				<p class="meta">
					<?php
					printf(
						/* translators: 1: shortcode, 2: opening anchor tag, 3: closing anchor tag */
						esc_html__('Shortcode: %1$s — %2$sreview this feed in the Feed Builder%3$s before deciding.', 'custom-facebook-feed'),
						'<code>[custom-facebook-feed feed=' . (int) $feed_id . ']</code>',
						'<a href="' . esc_url($review_url) . '" target="_blank" rel="noopener">',
						'</a>'
					);
					?>
				</p>
				<div class="actions">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="cff_abilities_delete_feed">
						<input type="hidden" name="feed_id" value="<?php echo (int) $feed_id; ?>">
						<input type="hidden" name="state" value="<?php echo esc_attr($token); ?>">
						<?php wp_nonce_field('cff_abilities_delete_feed'); ?>
						<button type="submit"><?php esc_html_e('Delete feed', 'custom-facebook-feed'); ?></button>
					</form>
					<a href="<?php echo esc_url($cancel); ?>"><?php esc_html_e('Cancel', 'custom-facebook-feed'); ?></a>
				</div>
			</div>
		</body>
		</html>
		<?php
		// phpcs:enable Generic.Files.LineLength.TooLong
		exit;
	}

	public function handle_delete()
	{
		if (!cff_current_user_can('manage_custom_facebook_feed_options')) {
			wp_die(
				esc_html__('You do not have permission to do this.', 'custom-facebook-feed'),
				'',
				array('response' => 403)
			);
		}
		check_admin_referer('cff_abilities_delete_feed');

		$feed_id = isset($_POST['feed_id']) && is_scalar($_POST['feed_id']) ? absint($_POST['feed_id']) : 0;
		$token   = isset($_POST['state']) && is_string($_POST['state']) ? sanitize_key($_POST['state']) : '';

		// If AI write access was revoked after the link was minted, honour the
		// revocation: the whole flow exists because an agent asked. The token is
		// burned (not just refused) so re-enabling the gate later cannot revive it.
		if (!CFF_Write_Gate::is_enabled()) {
			self::revoke_pending($token, $feed_id);
			wp_die(
				esc_html__('AI write access has been disabled since this link was created.', 'custom-facebook-feed'),
				'',
				array('response' => 403)
			);
		}

		if (!$feed_id || !CFF_State_Token::consume($token, 'delete_feed_' . $feed_id)) {
			wp_die(
				esc_html__('This deletion link is invalid or has expired.', 'custom-facebook-feed'),
				'',
				array('response' => 403)
			);
		}

		// Same rows CFF_Db::delete_feeds_query() removes; that helper echoes the
		// feed list and dies (it is an AJAX endpoint), so the two deletes are
		// replicated here for a redirect-based flow.
		global $wpdb;
		$feeds_table  = $wpdb->prefix . 'cff_feeds';
		$caches_table = $wpdb->prefix . 'cff_feed_caches';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix + constant.
		$wpdb->query($wpdb->prepare("DELETE FROM {$feeds_table} WHERE id = %d", $feed_id));
		$wpdb->query($wpdb->prepare("DELETE FROM {$caches_table} WHERE feed_id = %d", $feed_id));
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/**
		 * Fires after a feed was deleted through the ability-initiated,
		 * browser-confirmed flow.
		 *
		 * @param int $feed_id
		 */
		do_action('cff_abilities_feed_deleted', $feed_id);

		wp_safe_redirect(admin_url('admin.php?page=cff-feed-builder&cff_abilities_feed_deleted=' . $feed_id));
		exit;
	}

	/**
	 * Permanently kill a deletion link because the write gate refused it.
	 * Callers MUST have passed the capability check first. consume() only
	 * succeeds for a token minted by the current user for this exact action, and
	 * revoke_group() only touches the current user's slot, so an unauthorised or
	 * different user cannot burn anyone else's link.
	 *
	 * @param string $token
	 * @param int    $feed_id
	 * @return void
	 */
	private static function revoke_pending($token, $feed_id)
	{
		if ($feed_id && $token !== '') {
			CFF_State_Token::consume($token, 'delete_feed_' . $feed_id);
		}
		// Also clear the single-pending-deletion slot (and the token it points at).
		CFF_State_Token::revoke_group('delete');
	}
}
