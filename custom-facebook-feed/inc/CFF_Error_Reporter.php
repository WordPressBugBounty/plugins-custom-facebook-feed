<?php

/**
 * Class CFF_Error_Reporter
 *
 * Set as a global object to record and report errors
 *
 * @since
 */

namespace CustomFacebookFeed;

use CustomFacebookFeed\CFF_Education;
use CustomFacebookFeed\Builder\CFF_Db;
use CustomFacebookFeed\Builder\CFF_Source;
use CustomFacebookFeed\Token_Health\MetaErrorMap;
use CustomFacebookFeed\UsageTracking\ErrorAccumulator;

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class CFF_Error_Reporter
{
	/**
	 * @var array
	 */
	public $errors;

	/**
	 * @var array
	 */
	public $frontend_error;

	/**
	 * @var string
	 */
	public $reporter_key;

	/**
	 * @var array
	 */
	public $display_error;


	/**
	 * CFF_Error_Reporter constructor.
	 */
	public function __construct()
	{
		$this->reporter_key = 'cff_error_reporter';
		$this->errors = get_option($this->reporter_key, []);
		if (! isset($this->errors['connection'])) {
			$this->errors = array(
				'connection' 			=> [],
				'resizing' 				=> [],
				'database_create' 		=> [],
				'upload_dir' 			=> [],
				'accounts' 				=> [],
				'error_log' 			=> [],
				'action_log' 			=> [],
				'revoked' 			=> []
			);
		}


		$this->prune_orphaned_account_errors();

		$this->display_error = [];
		$this->frontend_error = '';

		add_action('cff_feed_issue_email', [$this, 'maybe_trigger_report_email_send']);
		add_action('wp_ajax_cff_dismiss_critical_notice', [$this, 'dismiss_critical_notice']);
		add_action('wp_footer', [$this, 'critical_error_notice'], 300);
		add_action('cff_admin_notices', [$this, 'admin_error_notices']);
		add_action('cff_admin_notices', [$this, 'platform_data_deleted_notice']);
		add_action('cff_admin_notices', [$this, 'group_deprecation_notice']);
		add_action('cff_admin_notices', [$this, 'platform_unused_feed_notice']);
	}

	/**
	 * @return array
	 *
	 * @since 2.0/4.0
	 */
	public function get_errors()
	{
		return $this->errors;
	}

	/**
	 * @param $type
	 * @param $message_array
	 *
	 * @since 2.0/4.0
	 */
	public function add_error($type, $args, $connected_account_term = false)
	{
		$connected_account = false;

		$log_item = date('m-d H:i:s') . ' - ';
		if ($connected_account_term !== false) {
			if (!is_array($connected_account_term)) {
				$connected_account = CFF_Source::get_single_source_info($connected_account_term);
			} else {
				$connected_account = $connected_account_term;
			}

			$this->add_connected_account_error($connected_account, $type, $args);
		}

		// Accumulate API-error telemetry per day so weekly usage reports cover
		// the whole period instead of sampling this option at cron time. The
		// accesstoken payload carries its Graph code as `errorno`, which
		// MetaErrorMap::extract() does not read, so it is passed explicitly.
		//
		// What that buys is narrow and worth stating: only codes 10, 4 and 200
		// reach this branch ($access_token_refresh_errors), so the explicit pass
		// is what stops those three landing in `other`. It does nothing for a
		// dead token -- 190 arrives on the `api` branch below, where extract()
		// supplies both the code and the subcode. The subcode is forwarded here
		// anyway so a future caller that does route a 190 through this path can
		// still bump `expiring`.
		if ($type === 'accesstoken') {
			ErrorAccumulator::record_api_error(
				isset($args['errorno']) ? (int)$args['errorno'] : 0,
				isset($args['error_subcode']) ? (int)$args['error_subcode'] : 0
			);
		} elseif ($type === 'api' || $type === 'wp_remote_get') {
			$extracted = MetaErrorMap::extract($args);
			if ($extracted['code'] > 0) {
				ErrorAccumulator::record_api_error($extracted['code'], $extracted['subcode'], $extracted['message']);
			} else {
				// An `api` call whose response is a WP_Error is a transport failure,
				// semantically identical to the wp_remote_get type -- the same shape
				// this method already special-cases below. Record it as `network`
				// rather than dropping it into the `other` catch-all.
				$is_transport = 'wp_remote_get' === $type
					|| (isset($args['response']) && is_wp_error($args['response']));
				ErrorAccumulator::record_category($is_transport ? 'network' : 'other');
			}
		}

		// Access Token Error
		if ($type === 'accesstoken') {
			$accesstoken_error_exists = false;
			if (isset($this->errors['accounts'])) {
				foreach ($this->errors['accounts'] as $account) {
					// Per-account buckets are keyed by error type ('api' => ...), so
					// 'accesstoken' is normally absent and reading it unguarded emits
					// "Undefined array key" on PHP 8.
					if (isset($account['accesstoken']) && $args['accesstoken'] === $account['accesstoken']) {
						$accesstoken_error_exists = true;
					}
				}
			}
			if (
				!$accesstoken_error_exists
				&& isset($this->errors['accounts'])
				&& !empty($connected_account['account_id'])
			) {
				$this->errors['accounts'][$connected_account['account_id']][] = array(
					'accesstoken' => $args['accesstoken'],
					'post_id' => $args['post_id'],
					'critical' => true,
					'type' => $type,
					'errorno' => $args['errorno']
				);
			}
		}

		// Connection Error API & WP REMOTE CALL
		if ($type === 'api' || $type === 'wp_remote_get') {
			$connection_details = array(
				'error_id' => ''
			);
			$connection_details['critical'] = false;

			if (isset($args['error']['code'])) {
				$connection_details['error_id'] = $args['error']['code'];
				if ($this->is_critical_error($args)) {
					$connection_details['critical'] = true;
				}

				if ($this->is_app_permission_related($args)) {
					if (!isset($this->errors['revoked']) || (!is_array($this->errors['revoked']))) {
						$this->errors['revoked'] = array();
					}
					if (isset($connected_account['account_id']) && !in_array($connected_account['account_id'], $this->errors['revoked'], true)) {
						$this->errors['revoked'][] = $connected_account['account_id'];
					}
					/**
					 * Fires when an app permission related error is encountered
					 *
					 * @param array $connected_account The connected account that encountered the error
					 *
					 * @since
					 */
					do_action('cff_app_permission_revoked', $connected_account);
				}
			} elseif (isset($args['response']) && is_wp_error($args['response'])) {
				foreach ($args['response']->errors as $key => $item) {
					$connection_details['error_id'] = $key;
				}
				$connection_details['critical'] = true;
			}

			$connection_details['error_message'] = $this->generate_error_message($args, $connected_account);
			$log_item .= $connection_details['error_message']['admin_message'];
			$this->errors['connection'] = $connection_details;
		}

		if ($type === 'image_editor' || $type === 'storage') {
			$this->errors['resizing'] = $args;
			$log_item .= is_array($args) ? wp_json_encode($args) : $args;
		}

		if ($type === 'database_create') {
			$this->errors['database_create'] = $args;
			$log_item .= $args;
		}

		if ($type === 'upload_dir') {
			$this->errors['upload_dir'] = $args;
			$log_item .= $args;
		}

		if ($type === 'platform_data_deleted') {
			$this->errors['platform_data_deleted'] = $args[0];
			$log_item .= is_array($args) ? wp_json_encode($args) : $args;
		}


		$current_log = $this->errors['error_log'];
		if (is_array($current_log) && count($current_log) >= 10) {
			reset($current_log);
			unset($current_log[key($current_log)]);
		}
		$current_log[] = $log_item;
		$this->errors['error_log'] = $current_log;
		update_option($this->reporter_key, $this->errors, false);
	}

	/**
	 * Stores information about an encountered error related to a connected account
	 *
	 * @param $connected_account array
	 * @param $error_type string
	 * @param $details mixed/array/string
	 *
	 * @since 2.19
	 */
	public function add_connected_account_error($connected_account, $error_type, $details)
	{
		// Per-account errors are addressed by the Facebook account id everywhere
		// they are read, so that is the only key worth storing one under. A source
		// row also carries the sources table's own primary key in ['id'], and
		// keying by that stored errors nothing could ever look up again.
		if (empty($connected_account['account_id'])) {
			return;
		}

		$account_id = $connected_account['account_id'];
		$this->errors['accounts'][ $account_id ][ $error_type ] = $details;

		// clear_time is a RETRY-BACKOFF HINT, not an error expiry, and nothing
		// may read it as one. It is written for every api/accesstoken error at a
		// flat three minutes -- matching the Instagram siblings' window -- and
		// only lengthened for the routes that publish a retry_after. A dead
		// token (190/463) therefore carries a three-minute clear_time, so
		// treating it as an expiry would silently drop a genuine critical state
		// after three minutes and make every critical surface flicker. Recovery
		// is driven by clear_account_errors() on a successful fetch instead,
		// which is evidence the account is healthy rather than a guess.
		if ($error_type === 'api' || $error_type === 'accesstoken') {
			$this->errors['accounts'][ $account_id ][ $error_type ]['clear_time'] = time() + 60 * 3;
		}

		$error = MetaErrorMap::extract($details);
		$route = MetaErrorMap::route($error['code'], $error['subcode'], $error['message']);

		// Rate limits and throttled features need longer than the general
		// window, and the table is where those durations live now. Still a
		// backoff hint -- see above.
		if (!empty($route['retry_after'])) {
			$this->errors['accounts'][ $account_id ][ $error_type ]['clear_time'] = time() + (int)$route['retry_after'];
		}

		// Only mark the source unusable when it genuinely is: that error column
		// is what paints a Reconnect prompt in the builder, so a rate limit must
		// not reach it. Skipped rather than cleared, so a source already invalid
		// stays invalid until the next successful fetch clears it.
		//
		// PPCA is the one cause the table cannot route: it is not a Graph code,
		// it arrives as "(#10)" inside the message, and code 10 on its own means
		// only "a scope is missing" -- which leaves the source usable. PPCA does
		// not: the account does not manage the page and the feed will never work,
		// which is the clearest Source Invalid case there is. Detected with the
		// same single predicate the copy layer routes it with, so the two cannot
		// drift apart.
		if ($route['invalidates_source'] || self::is_ppca_error($error['message'])) {
			\CustomFacebookFeed\Builder\CFF_Source::add_error($account_id, $details);
		}
	}

	/**
	 * @return mixed
	 *
	 * @since 2.19
	 */
	public function get_error_log()
	{
		return $this->errors['error_log'];
	}



	/**
	 * Applies the shared routing table over the copy this class already
	 * carries: the table owns whether reconnecting can fix a failure, and
	 * supplies copy for the shapes that previously had none.
	 *
	 * @param array $data Copy data from get_error_message_data().
	 * @param array $route Row from the shared error map.
	 * @param int   $error_code Graph error code, so the routed copy can still
	 *                         name it for support.
	 *
	 * @return array
	 */
	private function apply_error_route($data, $route, $error_code = 0)
	{
		// Reconnecting cannot grant a missing scope, clear a rate limit or
		// restore a lost page role, so the table decides this, not a code list.
		$data['show_reconnect'] = (bool)$route['show_reconnect'];

		// Every row this table replaces leads with "Error N:", and the title is
		// the only admin-facing place the code still appears once the raw Graph
		// message is dropped — without it support cannot triage a screenshot.
		$copy = array(
			'rate_limited' => array(
				/* translators: %s: Facebook Graph API error code. */
				'title' => sprintf(__('Error %s: Rate Limit Reached', 'custom-facebook-feed'), $error_code),
				'description' => __('Facebook is temporarily limiting requests from this site.', 'custom-facebook-feed'),
				'action' => __('The feed retries automatically and keeps serving its cached posts meanwhile. Increasing the cache time in your feed settings reduces how often this happens. No reconnection is needed.', 'custom-facebook-feed'),
			),
			'temporarily_blocked' => array(
				/* translators: %s: Facebook Graph API error code. */
				'title' => sprintf(__('Error %s: Temporarily Blocked', 'custom-facebook-feed'), $error_code),
				'description' => __('Facebook has temporarily blocked requests for this account.', 'custom-facebook-feed'),
				'action' => __('This clears on its own. No reconnection is needed; if it persists for more than a day, contact support.', 'custom-facebook-feed'),
			),
			'page_role_lost_or_2fa' => array(
				/* translators: %s: Facebook Graph API error code. */
				'title' => sprintf(__('Error %s: Page Access Lost', 'custom-facebook-feed'), $error_code),
				'description' => __('This account no longer has access to the connected page.', 'custom-facebook-feed'),
				'action' => __('Either the account lost its administrator, editor or moderator role on the page, or the page now requires Two Factor Authentication which this account has not enabled. Restore the page role, or enable Two Factor Authentication, then retry the feed.', 'custom-facebook-feed'),
			),
			'permission_regrant' => array(
				/* translators: %s: Facebook Graph API error code. */
				'title' => sprintf(__('Error %s: Permission Missing', 'custom-facebook-feed'), $error_code),
				'description' => __('The stored access token is valid but is missing a permission this feed needs.', 'custom-facebook-feed'),
				'action' => __('Open the source and approve the missing permission. A full reconnection is not required, because the token itself is still valid.', 'custom-facebook-feed'),
			),
		);

		if (isset($copy[ $route['copy_key'] ])) {
			$data = array_merge($data, $copy[ $route['copy_key'] ]);
		}

		return $data;
	}

	/**
	 * Returns structured human-readable message data for a Facebook API error code.
	 *
	 * @param int  $error_code    Facebook error code (104 already normalised to 999 by caller).
	 * @param int  $error_subcode Facebook error_subcode, 0 if absent.
	 * @param bool $ppca_error    True when error message contains "Public Content Access".
	 * @return array Keys: public (string), title (string), description (string), action (string), url (string), show_reconnect (bool).
	 */
	private function get_error_message_data($error_code, $error_subcode = 0, $ppca_error = false)
	{
		$docs_base = 'https://smashballoon.com/doc/facebook-api-errors/?facebook&utm_campaign=facebook-free&utm_source=error&utm_medium=docs#';

		if ($ppca_error) {
			return [
				'public'          => __('Error connecting to the Facebook API.', 'custom-facebook-feed'),
				'title'           => __('PPCA Error: Page not managed by you', 'custom-facebook-feed'),
				'description'     => __('Facebook no longer allows displaying feeds from Pages you are not an admin of.', 'custom-facebook-feed'),
				'action'          => __("Switch to a Facebook Page you manage, or use that Page's own access token.", 'custom-facebook-feed'),
				'url'             => 'https://smashballoon.com/facebook-api-changes-september-4-2020/?utm_campaign=facebook-free&utm_source=error&utm_medium=docs',
				'show_reconnect'  => false,
			];
		}

		if ($error_code === 190) {
			$subcode_messages = [
				463 => [
					'title'       => __('Error 190: Access Token Expired', 'custom-facebook-feed'),
					'description' => __('Your Facebook session has expired.', 'custom-facebook-feed'),
					'action'      => __('Reconnect your Facebook account in Sources to generate a new token.', 'custom-facebook-feed'),
				],
				460 => [
					'title'       => __('Error 190: Password Changed', 'custom-facebook-feed'),
					'description' => __('Your Facebook password was changed, which invalidated the stored access token.', 'custom-facebook-feed'),
					'action'      => __('Reconnect your Facebook account to generate a fresh token.', 'custom-facebook-feed'),
				],
				458 => [
					'title'       => __('Error 190: App Not Authorized', 'custom-facebook-feed'),
					'description' => __('The Smash Balloon app has not been authorized for this Facebook account.', 'custom-facebook-feed'),
					'action'      => __('Reconnect your account and approve the app permissions when prompted.', 'custom-facebook-feed'),
				],
				459 => [
					'title'       => __('Error 190: Security Checkpoint', 'custom-facebook-feed'),
					'description' => __('Facebook has placed a security checkpoint on this account, which invalidated the stored access token.', 'custom-facebook-feed'),
					'action'      => __('The account owner needs to log in to Facebook and clear the checkpoint first, then reconnect the account in Sources.', 'custom-facebook-feed'),
				],
				464 => [
					'title'       => __('Error 190: Account Unconfirmed', 'custom-facebook-feed'),
					'description' => __('This Facebook account is unconfirmed, so Facebook rejects the access token issued for it.', 'custom-facebook-feed'),
					'action'      => __('The account owner needs to confirm the account with Facebook first, then reconnect it in Sources.', 'custom-facebook-feed'),
				],
				467 => [
					'title'       => __('Error 190: Invalid Access Token', 'custom-facebook-feed'),
					'description' => __('The access token stored for this account is no longer valid.', 'custom-facebook-feed'),
					'action'      => __('Reconnect your Facebook account in Sources to get a new valid token.', 'custom-facebook-feed'),
				],
			];
			$subcode_data = isset($subcode_messages[$error_subcode]) ? $subcode_messages[$error_subcode] : [
				'title'       => __('Error 190: Invalid Access Token', 'custom-facebook-feed'),
				'description' => __('Your Facebook access token is invalid or has expired.', 'custom-facebook-feed'),
				'action'      => __('Go to Sources in the feed builder and reconnect your Facebook account.', 'custom-facebook-feed'),
			];
			return array_merge(
				[
					'public'         => __('Error connecting to the Facebook API.', 'custom-facebook-feed'),
					'url'            => $docs_base . '190',
					'show_reconnect' => true,
				],
				$subcode_data
			);
		}

		$messages = [
			4   => [
				'title'          => __('Error 4: Rate Limit Reached', 'custom-facebook-feed'),
				'description'    => __('Your feed has made too many requests to Facebook in a short period.', 'custom-facebook-feed'),
				'action'         => __('Wait a few minutes then reload the page. If this persists, increase the cache time in your feed settings.', 'custom-facebook-feed'),
				'show_reconnect' => false,
			],
			10  => [
				'title'          => __('Error 10: Permission Denied', 'custom-facebook-feed'),
				'description'    => __('The access token does not have permission to read the requested data.', 'custom-facebook-feed'),
				'action'         => __('Reconnect your account and ensure all required permissions are granted during the authorisation step.', 'custom-facebook-feed'),
				'show_reconnect' => true,
			],
			18  => [
				'title'          => __('Error 18: Request Throttled', 'custom-facebook-feed'),
				'description'    => __('Facebook has temporarily throttled requests from this account.', 'custom-facebook-feed'),
				'action'         => __('Your feed will automatically retry in 15 minutes. No action is needed right now.', 'custom-facebook-feed'),
				'show_reconnect' => false,
			],
			100 => [
				'title'          => __('Error 100: Invalid Parameter', 'custom-facebook-feed'),
				'description'    => __('The Page or Group ID is invalid, or the app is not installed on this account.', 'custom-facebook-feed'),
				'action'         => __('Check that the Page or Group ID is correct, then reconnect the account in Sources.', 'custom-facebook-feed'),
				'show_reconnect' => true,
			],
			200 => [
				'title'          => __('Error 200: Permissions Error', 'custom-facebook-feed'),
				'description'    => __('The access token does not have the required permissions for this feed type.', 'custom-facebook-feed'),
				'action'         => __('Reconnect your account and approve all permission requests when prompted.', 'custom-facebook-feed'),
				'show_reconnect' => true,
			],
			999 => [
				'title'          => __('Error 999: Token Decryption Failed', 'custom-facebook-feed'),
				'description'    => __('The stored access token for this account could not be decrypted on this server.', 'custom-facebook-feed'),
				'action'         => __('Reconnect your account to store a fresh token, or follow our guide for server-specific decryption issues.', 'custom-facebook-feed'),
				'url'            => 'https://smashballoon.com/doc/error-999-access-token-could-not-be-decrypted/?utm_campaign=facebook-free&utm_source=error&utm_medium=docs',
				'show_reconnect' => true,
			],
		];

		if (isset($messages[$error_code])) {
			$data = $messages[$error_code];
			$data['public'] = __('Error connecting to the Facebook API.', 'custom-facebook-feed');
			if (!isset($data['url'])) {
				$data['url'] = $docs_base . $error_code;
			}
			return $data;
		}

		return [
			'public'         => __('Error connecting to the Facebook API.', 'custom-facebook-feed'),
			/* translators: %s: Facebook Graph API error code. */
			'title'          => sprintf(__('API Error %s', 'custom-facebook-feed'), $error_code),
			'description'    => __('An unexpected error was returned by the Facebook API.', 'custom-facebook-feed'),
			'action'         => __('Check our documentation for this error code, or contact support if the issue persists.', 'custom-facebook-feed'),
			'url'            => $docs_base . $error_code,
			'show_reconnect' => false,
		];
	}

	/**
	 * Whether a Graph error message is the Public Content Access refusal.
	 *
	 * PPCA has no Graph error code of its own -- it rides in on code 10 with
	 * "(#10) ... Public Content Access" as the message -- so the routing table
	 * cannot express it and every surface that needs to know has to read the
	 * message. This is the one place that reads it, so the copy layer and the
	 * two source-invalidation write sites cannot end up disagreeing about what
	 * counts as PPCA.
	 *
	 * @param mixed $message Raw Graph error message.
	 *
	 * @return bool
	 *
	 * @since SMASH-1806
	 */
	public static function is_ppca_error($message)
	{
		return is_string($message) && strpos($message, 'Public Content Access') !== false;
	}

	/**
	 * Creates an array of information for easy display of API errors
	 *
	 * @param $response
	 * @param array $connected_account
	 *
	 * @return array
	 *
	 * @since 2.19
	 */
	public function generate_error_message($response, $connected_account = array( 'username' => '' ))
	{
		$error_message_return = array(
			'public_message' 		=> '',
			'admin_message' 		=> '',
			'frontend_directions' 	=> '',
			'backend_directions' 	=> '',
			'post_id' 				=> get_the_ID(),
			'errorno'				=> '',
			'time' 					=> time()
		);

		if (isset($response['error']['code'])) {
			$error_code    = (int)$response['error']['code'];
			$error_subcode = isset($response['error']['error_subcode']) ? (int)$response['error']['error_subcode'] : 0;
			$raw_message   = isset($response['error']['message']) ? $response['error']['message'] : '';
			$ppca_error    = self::is_ppca_error($raw_message);

			if ($error_code === 104) {
				$error_code = 999;
			}

			$data = $this->get_error_message_data($error_code, $error_subcode, $ppca_error);

			// PPCA is not a Graph error code, so it keeps its own answer; every
			// real code now takes its reconnect decision, and its copy for the
			// previously unhandled shapes, from the shared table.
			if (!$ppca_error) {
				$data = $this->apply_error_route(
					$data,
					MetaErrorMap::route($error_code, $error_subcode, $raw_message),
					$error_code
				);
			}

			$reconnect_url = admin_url('admin.php?page=cff-settings&connect_source=1');

			$error_message_return['public_message']      = $data['public'];
			$error_message_return['admin_message']       = '<strong>' . $data['title'] . '</strong><br>' . $data['description'] . '<br><em>' . $data['action'] . '</em>';
			$error_message_return['frontend_directions'] = '<p class="cff-error-directions"><a href="' . esc_url($data['url']) . '" target="_blank" rel="noopener">' . __('Directions on How to Resolve This Issue', 'custom-facebook-feed') . '</a></p>';

			$reconnect_btn = $data['show_reconnect']
				? '<a class="cff-notice-btn cff-btn-orange" href="' . esc_url($reconnect_url) . '">' . __('Reconnect Account', 'custom-facebook-feed') . '</a> '
				: '';
			$error_message_return['backend_directions'] = $reconnect_btn . '<a class="cff-notice-btn cff-btn-grey" href="' . esc_url($data['url']) . '" target="_blank" rel="noopener">' . __('Learn More', 'custom-facebook-feed') . '</a>';

			$error_message_return['errorno'] = $error_code;
		} else {
			$error_message_return['error_message'] = __('An unknown error has occurred.', 'custom-facebook-feed');
			$error_message_return['admin_message'] = json_encode($response);
		}

		return $error_message_return;
	}




	/**
	 * Certain API errors are considered critical and will trigger
	 * the various notifications to users to correct them.
	 *
	 * @param $details
	 *
	 * @return bool
	 *
	 * @since 2.7/5.10
	 */
	public function is_critical_error($details)
	{
		$error = MetaErrorMap::extract($details);

		return MetaErrorMap::isCritical($error['code'], $error['subcode'], $error['message']);
	}

	/**
	 * @param $type
	 *
	 * @since X.X.X
	 */
	public function remove_error($type, $connected_account = false)
	{
		$update = false;
		if (!empty($this->errors[$type])) {
			$this->errors[$type] = array();
			$this->add_action_log('Cleared ' . $type . ' error.');
			$update = true;
		}

		if (!empty($this->errors['revoked'])) {
			if (!is_array($this->errors['revoked'])) {
				$this->errors['revoked'] = array();
			}
			if (isset($connected_account['account_id']) && ($key = array_search($connected_account['account_id'], $this->errors['revoked'])) !== false) {
				unset($this->errors['revoked'][$key]);
			}
		}

		if ($update) {
			update_option($this->reporter_key, $this->errors, false);
		}
	}

	/**
	 * Clears everything stored against one Facebook account.
	 *
	 * This is the recovery path the per-account arm was missing. Before it,
	 * errors['accounts'][$id] was written on failure and removed by nothing
	 * short of deleting all platform data, so a single since-fixed 190 left
	 * are_critical_errors() true forever -- and because the builder gates its
	 * per-source clear on that same site-wide check, every source's Source
	 * Invalid flag became unclearable too. Cannot clear because critical,
	 * critical because never cleared.
	 *
	 * The one caller is CFF_API_Connect::connect(), on a fetch that returned a
	 * well-formed Graph payload with no error member -- the only place a success
	 * and the account it belongs to are both known. That is why the builder's
	 * site-wide gate can stay: it stops being a deadlock once something outside
	 * it can make the verdict go false.
	 *
	 * Deliberately a new method rather than a second argument on
	 * remove_error(): that one keys off an error TYPE and has eight call sites
	 * that all pass one argument. This one is addressed by an account and
	 * clears errors['accounts'][$id] -- and ONLY that.
	 *
	 * It must not touch errors['revoked']: that list drives the warning copy for
	 * a pending 7-day platform-data deletion, and dropping an id from it cancels
	 * the visible warning while Platform_Data's deletion timer keeps running.
	 * Its stale-latch problem is fixed at the READ instead, in
	 * was_app_permission_related_error().
	 *
	 * Typed mixed on purpose: there is no parameter type declaration, so a
	 * caller can hand this anything, and the is_scalar() guard below exists to
	 * absorb that. Declaring string|int would make the guard read as dead code.
	 *
	 * @param mixed $account_id Facebook account id.
	 *
	 * @return bool Whether anything was stored to clear.
	 *
	 * @since SMASH-1806
	 */
	public function clear_account_errors($account_id)
	{
		$account_id = is_scalar($account_id) ? (string)$account_id : '';
		if ($account_id === '') {
			return false;
		}

		// Nothing stored for this account means the successful fetch that called
		// this has nothing to report, and must not rewrite the option -- this
		// runs on every healthy fetch, which is the overwhelming majority.
		if (
			!isset($this->errors['accounts'])
			|| !is_array($this->errors['accounts'])
			|| !isset($this->errors['accounts'][ $account_id ])
		) {
			return false;
		}

		unset($this->errors['accounts'][ $account_id ]);
		$this->add_action_log('Cleared stored errors for account ' . $account_id . '.');
		update_option($this->reporter_key, $this->errors, false);

		return true;
	}

	public function remove_all_errors()
	{
		delete_option($this->reporter_key);
	}

	public function reset_api_errors()
	{
		$this->errors['connection'] = array();
		$this->errors['accounts'] = array();
		update_option($this->reporter_key, $this->errors, false);
	}

	/**
	 * The account ids to check for stored per-account errors.
	 *
	 * The sources table is the source of truth here, not the legacy
	 * cff_connected_accounts option. Entries in that option carry no
	 * 'account_id' key at all -- get_connected_accounts_list() maps a modern
	 * source's account_id into 'id' -- so a lookup keyed off 'account_id'
	 * skipped every entry and the per-account arm below never ran on a real
	 * site. The option's 'id' IS the page id, which keeps it usable as a
	 * fallback for a site whose sources table is empty or unavailable.
	 *
	 * @return array List of account id strings.
	 *
	 * @since SMASH-1806
	 */
	private function get_connected_account_ids()
	{
		$account_ids = CFF_Db::source_account_ids();

		if (!empty($account_ids)) {
			return $account_ids;
		}

		$account_ids = array();
		foreach (CFF_Utils::cff_get_connected_accounts() as $connected_account) {
			$connected_account = (array)$connected_account;
			if (!empty($connected_account['id'])) {
				$account_ids[] = $connected_account['id'];
			}
		}

		return $account_ids;
	}

	/**
	 * Drops per-account errors stored under something that cannot be a Facebook
	 * account id.
	 *
	 * Two writers produced unreachable keys before SMASH-1806: the Free edition
	 * keyed by the sources table's own primary key, and both editions keyed by
	 * '' when no account could be resolved. Nothing can ever read either back --
	 * are_critical_errors() looks accounts up by account id, and
	 * CFF_Source::add_error() matches the account_id column -- so they are swept
	 * once, in place, rather than left to accumulate.
	 *
	 * A key survives only while it is still in get_connected_account_ids() --
	 * deliberately the reader's own list, including its legacy-option fallback,
	 * so the prune can never drop a key are_critical_errors() would still look
	 * up. When that list is empty or unavailable nothing is pruned at all, so a
	 * failed lookup can never be read as "every stored error is an orphan".
	 *
	 * @return void
	 *
	 * @since SMASH-1806
	 */
	private function prune_orphaned_account_errors()
	{
		if (empty($this->errors['accounts']) || !is_array($this->errors['accounts'])) {
			return;
		}

		// Membership of the live account-id list decides this, not how long the
		// key looks. A length heuristic was unsafe in the one direction that
		// matters: 2007-2009-era page ids and especially group ids are commonly
		// nine digits or fewer, so the oldest sites -- the ones most likely to
		// hit a token error -- had their real per-account error silently deleted
		// on the next page load, with no signal.
		//
		// An empty list means the sources table is empty or the lookup failed, and
		// says nothing about which keys are orphans, so fail closed and delete
		// nothing. The lookup itself is memoised per request and only runs at all
		// when there is a stored per-account error to check (the guard above).
		$account_ids = array_map('strval', $this->get_connected_account_ids());
		if (empty($account_ids)) {
			return;
		}

		$pruned = false;
		foreach (array_keys($this->errors['accounts']) as $key) {
			// '' is what the pre-SMASH-1806 writers left when no account could be
			// resolved, and an account id is always numeric; anything else here is
			// a key no reader looks up any more.
			$candidate = (string)$key;
			if ($candidate === '' || !ctype_digit($candidate) || !in_array($candidate, $account_ids, true)) {
				unset($this->errors['accounts'][ $key ]);
				$pruned = true;
			}
		}

		if ($pruned) {
			update_option($this->reporter_key, $this->errors, false);
		}
	}

	/**
	 * @param $type
	 * @param $message
	 *
	 * @since 2.0/5.0
	 */
	public function add_frontend_error($message, $directions)
	{
		$this->frontend_error = $message . $directions;
	}

	public function remove_frontend_error()
	{
		$this->frontend_error = '';
	}

	/**
	 * @return string
	 *
	 * @since 2.0/5.0
	 */
	public function get_frontend_error()
	{
		return $this->frontend_error;
	}


	public function get_critical_errors()
	{
		if (!$this->are_critical_errors()) {
			return '';
		}

		$accounts_revoked_string = '';
		$accounts_revoked = '';

		if ($this->was_app_permission_related_error()) {
			$revoked_ids = $this->get_app_permission_related_error_ids();
			$revoked_ids = is_array($revoked_ids) ? $revoked_ids : array();

			// reset(), not [0]: the unset() in remove_error() does not reindex,
			// so after one id has been cleared the survivor's key is whatever it
			// always was. Reading [0] then raises an undefined-key warning on
			// PHP 8 and prints nothing.
			if (count($revoked_ids) > 1) {
				$accounts_revoked = implode(', ', $revoked_ids);
			} elseif (count($revoked_ids) === 1) {
				$accounts_revoked = (string)reset($revoked_ids);
			}

			// was_app_permission_related_error() already required a non-empty
			// list, so this should not be reachable -- but naming an account id
			// of '' in copy about pending data deletion is worse than saying
			// nothing.
			if ($accounts_revoked !== '') {
				$accounts_revoked_string = sprintf(__('Facebook Feed related data for the account(s) %s was removed due to permission for the Smash Balloon App on Facebook being revoked. <br><br> To prevent the automated data deletion for the account, please reconnect your account within 7 days.', 'custom-facebook-feed'), $accounts_revoked);
			}
		}

		// Which stored error this message describes. The connection slot is a
		// SINGLE value that every API error overwrites, so it can be empty --
		// or hold a since-superseded non-critical error -- while the
		// per-account scan is what made are_critical_errors() true. Source A
		// returns 190, source B then returns a rate limit and overwrites the
		// slot: the badge, Site Health, the email and the front-end box all
		// fire, and the settings page, the only surface that says what to do,
		// rendered nothing at all. The fallback reads the same stored error the
		// scan accepted and routes it through the same generate_error_message()
		// path, so the copy is the connection arm's copy, not a second set.
		$error_message_array = false;

		// Value, not presence: the flag is written on every stored error,
		// so a presence check reports a broken connection for any code.
		if (!empty($this->errors['connection']['critical'])) {
			$error = $this->errors['connection'];
			$error_message_array = isset($error['error_message']) ? $error['error_message'] : false;
		} else {
			$account_error = $this->get_first_critical_account_error();
			if ($account_error !== false) {
				$error_message_array = $this->generate_error_message($account_error['error']);
			}
		}

		$error_message = $directions = false;

		// Gated on a CURRENTLY pending revoke rather than on code 190: this
		// block names a 7-day data-deletion deadline, so it may only render
		// while that deletion is genuinely still scheduled. Claiming the
		// whole 190 family here meant the per-cause copy (expired, password
		// changed, app removed) never reached the admin notice at all, and
		// reading errors['revoked'] alone had the same effect for good once
		// any revoke had ever been recorded. See
		// was_app_permission_related_error().
		//
		// Deliberately NOT nested under the is_array($error_message_array)
		// check below. This branch reads only errors['revoked'] and the
		// Platform_Data deletion timer, never the stored message, so nesting
		// it made a critical connection error with a missing or legacy-string
		// error_message plus an armed revoke render nothing at all -- losing
		// the 7-day copy the pre-SMASH-1806 code did show.
		if ($this->was_app_permission_related_error()) {
			$error_message = '<strong>' . __('Action Required Within 7 Days', 'custom-facebook-feed') . '</strong><br>';
			$error_message .= __('An account admin has deauthorized the Smash Balloon app used to power the Facebook Feed plugin.', 'custom-facebook-feed');
			$error_message .= ' ' . sprintf(__('If the Facebook source is not reconnected within 7 days then all Facebook data will be automatically deleted on your website for this account (ID: %s) due to Facebook data privacy rules.', 'custom-facebook-feed'), $accounts_revoked);
			$error_message .= __('<br><br>To prevent the automated data deletion for the account, please reconnect your account within 7 days.', 'custom-facebook-feed');
			$error_message .= '<br><br><a href="https://smashballoon.com/doc/action-required-within-7-days/?facebook&utm_campaign=facebook-free&utm_source=error&utm_medium=notice&utm_content=More Information" target="_blank" rel="noopener">' . __('More Information', 'custom-facebook-feed') . '</a>';
			$directions = '';
		} elseif (is_array($error_message_array)) {
			$error_message = $error_message_array['admin_message'];
			if (!empty($accounts_revoked_string)) {
				$error_message .= $accounts_revoked_string . '<br><br>';
			}

			$directions = '<p class="cff-error-directions">';
			$directions .= $error_message_array['backend_directions'];
			if (!empty($error_message_array['post_id'])) {
				$directions .= '<button data-url="' . get_the_permalink($error_message_array['post_id']) . '" class="cff-clear-errors-visit-page cff-space-left cff-btn cff-notice-btn cff-btn-grey">' . __('View Feed and Retry', 'custom-facebook-feed') . '</button>';
			}
			$directions .= '</p>';
		}
		return [
			'error_message' => $error_message,
			'directions' => $directions
		];
	}

	public function are_critical_errors()
	{
		$errors = $this->get_errors();
		if (
			(isset($errors['connection']['critical']) && $errors['connection']['critical'] === true) ||
			CFF_Source::should_show_group_deprecation()
		) {
			return true;
		}

		return $this->get_first_critical_account_error() !== false;
	}

	/**
	 * The first stored per-account error the routing table calls critical.
	 *
	 * This is the per-account arm of are_critical_errors(), factored out so
	 * get_critical_errors() can describe exactly the error that made the site
	 * critical. Two copies of this scan would be free to drift, and the one
	 * that drifted would be the one that decides whether the admin sees a
	 * message at all.
	 *
	 * @return array|false array('account_id' => string, 'error' => array), or
	 *                     false when no stored per-account error is critical.
	 *
	 * @since SMASH-1806
	 */
	private function get_first_critical_account_error()
	{
		if (empty($this->errors['accounts']) || !is_array($this->errors['accounts'])) {
			return false;
		}

		foreach ($this->get_connected_account_ids() as $account_id) {
			// An account with nothing stored against it says nothing about
			// whether the site is broken, so it cannot end the scan either.
			if (!isset($this->errors['accounts'][ $account_id ]['api']['error'])) {
				continue;
			}

			// A non-critical account no longer ends the scan early —
			// otherwise a dead token on one source goes unreported when
			// another source's transient error was the last one stored.
			if ($this->is_critical_error($this->errors['accounts'][ $account_id ]['api'])) {
				return array(
					'account_id' => (string)$account_id,
					'error'      => $this->errors['accounts'][ $account_id ]['api'],
				);
			}
		}

		return false;
	}

	/**
	 * Stores a time stamped string of information about
	 * actions that might lead to correcting an error
	 *
	 * @param string $log_item
	 *
	 * @since 2.19
	 */
	public function add_action_log($log_item)
	{
		$current_log = $this->errors['action_log'];

		if (is_array($current_log) && count($current_log) >= 10) {
			reset($current_log);
			unset($current_log[ key($current_log) ]);
		}
		$current_log[] = date('m-d H:i:s') . ' - ' . $log_item;

		$this->errors['action_log'] = $current_log;
		update_option($this->reporter_key, $this->errors, false);
	}

	/**
	 * @return mixed
	 *
	 * @since 2.19
	 */
	public function get_action_log()
	{
		return $this->errors['action_log'];
	}


	/**
	 * Load the critical notice for logged in users.
	 */
	public function critical_error_notice()
	{
		// Don't do anything for guests.
		if (! is_user_logged_in()) {
			return;
		}

		// Only show this to users who are not tracked.
		if (! current_user_can('edit_posts')) {
			return;
		}

		if (! $this->are_critical_errors()) {
			return;
		}


		// Don't show if already dismissed.
		if (get_option('cff_dismiss_critical_notice', false)) {
			return;
		}

		/** TODO: Match real option */
		$options = get_option('cff_settings');
		if (isset($options['disable_admin_notice']) && $options['disable_admin_notice'] === 'on') {
			return;
		}

		?>
		<div class="cff-critical-notice cff-critical-notice-hide">
			<div class="cff-critical-notice-icon">
				<img src="<?php echo CFF_PLUGIN_URL . 'admin/assets/img/cff-icon.png'; ?>" width="45" alt="Custom Facebook Feed icon" />
			</div>
			<div class="cff-critical-notice-text">
				<h3><?php esc_html_e('Facebook Feed Critical Issue', 'custom-facebook-feed'); ?></h3>
				<p>
					<?php
					$doc_url = admin_url() . 'admin.php?page=cff-settings';
					// Translators: %s is the link to the article where more details about critical are listed.
					printf(esc_html__('An issue is preventing your Custom Facebook Feeds from updating. %1$sResolve this issue%2$s.', 'custom-facebook-feed'), '<a href="' . esc_url($doc_url) . '" target="_blank">', '</a>');
					?>
				</p>
			</div>
			<div class="cff-critical-notice-close">&times;</div>
		</div>
		<style type="text/css">
			.cff-critical-notice {
				position: fixed;
				bottom: 20px;
				right: 15px;
				font-family: Arial, Helvetica, "Trebuchet MS", sans-serif;
				background: #fff;
				box-shadow: 0 0 10px 0 #dedede;
				padding: 10px 10px;
				display: flex;
				align-items: center;
				justify-content: center;
				width: 325px;
				max-width: calc( 100% - 30px );
				border-radius: 6px;
				transition: bottom 700ms ease;
				z-index: 10000;
			}

			.cff-critical-notice h3 {
				font-size: 13px;
				color: #222;
				font-weight: 700;
				margin: 0 0 4px;
				padding: 0;
				line-height: 1;
				border: none;
			}

			.cff-critical-notice p {
				font-size: 12px;
				color: #7f7f7f;
				font-weight: 400;
				margin: 0;
				padding: 0;
				line-height: 1.2;
				border: none;
			}

			.cff-critical-notice p a {
				color: #7f7f7f;
				font-size: 12px;
				line-height: 1.2;
				margin: 0;
				padding: 0;
				text-decoration: underline;
				font-weight: 400;
			}

			.cff-critical-notice p a:hover {
				color: #666;
			}

			.cff-critical-notice-icon img {
				height: auto;
				display: block;
				margin: 0;
			}

			.cff-critical-notice-icon {
				padding: 0;
				border-radius: 4px;
				flex-grow: 0;
				flex-shrink: 0;
				margin-right: 12px;
				overflow: hidden;
			}

			.cff-critical-notice-close {
				padding: 10px;
				margin: -12px -9px 0 0;
				border: none;
				box-shadow: none;
				border-radius: 0;
				color: #7f7f7f;
				background: transparent;
				line-height: 1;
				align-self: flex-start;
				cursor: pointer;
				font-weight: 400;
			}
			.cff-critical-notice-close:hover,
			.cff-critical-notice-close:focus{
				color: #111;
			}

			.cff-critical-notice.cff-critical-notice-hide {
				bottom: -200px;
			}
		</style>
		<?php

		if (! wp_script_is('jquery', 'queue')) {
			wp_enqueue_script('jquery');
		}
		?>
		<script>
			if ( 'undefined' !== typeof jQuery ) {
				jQuery( document ).ready( function ( $ ) {
					/* Don't show the notice if we don't have a way to hide it (no js, no jQuery). */
					$( document.querySelector( '.cff-critical-notice' ) ).removeClass( 'cff-critical-notice-hide' );
					$( document.querySelector( '.cff-critical-notice-close' ) ).on( 'click', function ( e ) {
						e.preventDefault();
						$( this ).closest( '.cff-critical-notice' ).addClass( 'cff-critical-notice-hide' );
						$.ajax( {
							url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
							method: 'POST',
							data: {
								action: 'cff_dismiss_critical_notice',
								nonce: '<?php echo esc_js(wp_create_nonce('cff-critical-notice')); ?>',
							}
						} );
					} );
				} );
			}
		</script>
		<?php
	}

	/**
	 * Ajax handler to hide the critical notice.
	 */
	public function dismiss_critical_notice()
	{

		check_ajax_referer('cff-critical-notice', 'nonce');
		$cap = current_user_can('manage_custom_facebook_feed_options') ? 'manage_custom_facebook_feed_options' : 'manage_options';
		$cap = apply_filters('cff_settings_pages_capability', $cap);
		if (! current_user_can($cap)) {
			wp_send_json_error(); // This auto-dies.
		}

		update_option('cff_dismiss_critical_notice', 1, false);

		wp_die();
	}

	/**
	 * Builds and sends the weekly feed issue report email.
	 *
	 * @param array $staleness Optional BackupCacheMonitor::evaluate() state.
	 *                         When its tier is non-zero the report describes
	 *                         stale saved content instead of a connection
	 *                         error; pass nothing to keep the original
	 *                         critical-error copy.
	 *
	 * @return bool
	 */
	public function send_report_email($staleness = array())
	{
		$options = get_option('cff_style_settings', array());

		$to_string = ! empty($options['email_notification_addresses']) ? str_replace(' ', '', $options['email_notification_addresses']) : get_option('admin_email', '');

		$to_array_raw = explode(',', $to_string);
		$to_array = array();

		foreach ($to_array_raw as $email) {
			if (is_email($email)) {
				$to_array[] = $email;
			}
		}

		if (empty($to_array)) {
			return false;
		}
		$from_name = esc_html(wp_specialchars_decode(get_bloginfo('name')));
		$email_from = $from_name . ' <' . get_option('admin_email', $to_array[0]) . '>';
		$header_from  = "From: " . $email_from;

		$headers = array( 'Content-Type: text/html; charset=utf-8', $header_from );

		$header_image = CFF_PLUGIN_URL . 'admin/assets/img/balloon-120.png';
		$title = __('Custom Facebook Feed Report for ' . home_url());
		$link = admin_url('admin.php?page=cff-settings');
		// &tab=customize-advanced
		$footer_link = admin_url('admin.php?page=cff-style&tab=misc&flag=emails');

		// Staleness copy is used only when staleness is the reason we are
		// emailing at all; a critical error keeps the wording it always had.
		if (! empty($staleness['tier'])) {
			$stale_days = isset($staleness['worst_days']) ? (int)$staleness['worst_days'] : 0;
			$stale_feeds = isset($staleness['feed_count']) ? (int)$staleness['feed_count'] : 0;

			$bold = __('A Facebook Feed on Your Website is Showing Old Posts', 'custom-facebook-feed');
			/* translators: %d: number of days the feed has been serving saved posts. */
			$details = '<p>' . sprintf(__('A Facebook feed on your website has not been able to get new posts from Facebook for %d days, so visitors are seeing an old saved copy of your feed. Your website looks normal, which makes this easy to miss, but new Facebook posts will not appear until the connection is fixed.', 'custom-facebook-feed'), $stale_days) . '</p>';

			if ($stale_feeds > 1) {
				/* translators: %d: number of feeds on this site serving saved posts. */
				$details .= '<p>' . sprintf(__('%d feeds on this site are affected.', 'custom-facebook-feed'), $stale_feeds) . '</p>';
			}

			/* translators: %1$s: opening anchor tag for the settings page. %2$s: closing anchor tag. */
			$details .= '<p>' . sprintf(__('To check the connection and get it working again, please visit the %1$sFacebook Feed settings page%2$s on your website.', 'custom-facebook-feed'), '<a href="' . esc_url($link) . '">', '</a>') . '</p>';
		} else {
			$bold = __('There\'s an Issue with a Facebook Feed on Your Website', 'custom-facebook-feed');
			$details = '<p>' . __('A Custom Facebook Feed on your website is currently unable to connect to Facebook to retrieve new posts. Don\'t worry, your feed is still being displayed using a cached version, but is no longer able to display new posts.', 'custom-facebook-feed') . '</p>';
			$details .= '<p>' . sprintf(__('This is caused by an issue with your Facebook account connecting to the Facebook API. For information on the exact issue and directions on how to resolve it, please visit the %sCustom Facebook Feed settings page%s on your website.', 'custom-facebook-feed'), '<a href="' . esc_url($link) . '">', '</a>') . '</p>';
		}
		$message_content = '<h6 style="padding:0;word-wrap:normal;font-family:\'Helvetica Neue\',Helvetica,Arial,sans-serif;font-weight:bold;line-height:130%;font-size: 16px;color:#444444;text-align:inherit;margin:0 0 20px 0;Margin:0 0 20px 0;">' . $bold . '</h6>' . $details;
		$educator = new CFF_Education();
		$dyk_message = $educator->dyk_display();
		ob_start();
		include_once CFF_PLUGIN_DIR . 'email.php';
		$email_body = ob_get_contents();
		ob_get_clean();
		$sent = wp_mail($to_array, $title, $email_body, $headers);

		return $sent;
	}

	/**
	 * Should clear platform data
	 *
	 * @param $details
	 *
	 * @return bool
	 *
	 * @since 2.7/5.10
	 */
	public function is_app_permission_related($details)
	{
		$error_code = (int) $details['error']['code'];
		$critical_codes = array(
			190, // access token or permissions
		);
		return in_array($error_code, $critical_codes, true) && strpos($details['error']['message'], 'user has not authorized application') !== false;
	}

	/**
	 * Whether the "Feed Issue Email Reports" opt-in on Settings -> Advanced
	 * allows a report to be sent.
	 *
	 * The setting lives in `cff_style_settings` -- that is what the settings
	 * page writes and what the Pro edition's reporter reads. This guard used to
	 * read `cff_settings`, which is only a wp_localize_script handle and is
	 * never stored as an option, so the opt-out had no effect at all.
	 *
	 * The stored shape depends on which era wrote it: the current settings app
	 * saves a JSON boolean, the onboarding wizard saves `true`, the pre-4.0
	 * settings form saved 'on' when checked and '' when not, and a fresh install
	 * has no key at all (the defaults in CFF_Global_Settings are merged for
	 * display only, never persisted) -- so an absent value keeps the historical
	 * enabled-by-default behaviour.
	 *
	 * The falsey-plus-'off' test is deliberately identical to the plugin's other
	 * reader of this same setting, FacebookFreeReporter::get_global_settings(),
	 * which reports it as `! empty( $value ) && 'off' !== $value`. Previously
	 * the two disagreed: a stored 'off' was truthy here, so telemetry called the
	 * setting disabled while this guard still sent the email. If either reader
	 * changes, change both.
	 *
	 * @return bool
	 */
	private function is_email_report_enabled()
	{
		$options = get_option('cff_style_settings');

		if (! is_array($options) || ! isset($options['enable_email_report'])) {
			return true;
		}

		$value = $options['enable_email_report'];

		return ! empty($value) && 'off' !== $value;
	}

	public function maybe_trigger_report_email_send()
	{
		$are_critical_errors = $this->are_critical_errors();

		// Backup-cache staleness is a time-based signal that can be true with
		// no critical error at all: a feed can keep serving saved posts for
		// weeks while the site looks perfectly healthy. That is exactly the
		// case the staleness notice exists for, so it has to reach the weekly
		// report too, which previously sent nothing for it. Reuses this cron
		// run, its toggle and its recipients rather than adding a second
		// scheduler.
		$staleness = BackupCacheMonitor::evaluate();
		$is_stale = $staleness['tier'] > 0;

		if (! $are_critical_errors && ! $is_stale) {
			return;
		}

		if (! $this->is_email_report_enabled()) {
			return;
		}

		// One email per run. A critical error is the more actionable problem,
		// so it wins the copy when both are true.
		$this->send_report_email($are_critical_errors ? array() : $staleness);
	}

	public function admin_error_notices()
	{

		if (isset($_GET['page']) && in_array($_GET['page'], array( 'cff-settings' ))) {
			$errors = $this->get_errors();
			if (! empty($errors) && (! empty($errors['database_create']) || ! empty($errors['upload_dir']))) : ?>
			<div class="cff-admin-notices cff-critical-error-notice">
				<?php if (! empty($errors['database_create'])) {
					echo '<p>' . $errors['database_create'] . '</p>';
				} ?>
				<?php if (! empty($errors['upload_dir'])) {
					echo '<p>' . $errors['upload_dir'] . '</p>';
				} ?>
				<p><?php _e(sprintf('Visit our %s page for help', '<a href="https://smashballoon.com/custom-facebook-feed/faq/?utm_campaign=facebook-free&utm_source=error&utm_medium=docs" class="cff-notice-btn cff-btn-grey" target="_blank">FAQ</a>'), 'custom-facebook-feed'); ?></p>
			</div>

			<?php endif;
			$errors = $this->get_critical_errors();
			if ($this->are_critical_errors() && is_array($errors) && $errors['error_message'] !== false && $errors['directions'] !== false) :
				?>
				<div class="cff-admin-notices cff-critical-error-notice">
					<span class="sb-notice-icon sb-error-icon">
						<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M10 0C4.48 0 0 4.48 0 10C0 15.52 4.48 20 10 20C15.52 20 20 15.52 20 10C20 4.48 15.52 0 10 0ZM11 15H9V13H11V15ZM11 11H9V5H11V11Z" fill="#D72C2C"/>
						</svg>
					</span>
					<div class="cff-notice-body">
						<h3 class="sb-notice-title">
							<?php echo esc_html__('Custom Facebook Feed is encountering an error and your feeds may not be updating due to the following reasons:', 'custom-facebook-feed') ; ?>
						</h3>

						<p><?php echo wp_kses_post($errors['error_message']); ?></p>

						<div class="license-action-btns">
							<?php
							// kses allows data-* globally: the button keeps class/data-url, only onclick goes.
							echo wp_kses_post($errors['directions']);
							?>
						</div>
					</div>
				</div>
				<?php
			endif;

			/*
			$errors = $this->get_critical_errors();
			if ( $this->are_critical_errors() && ! empty( $errors ) ) :
				if ( isset( $errors['wp_remote_get'] ) ) {
					$error = $errors['wp_remote_get'];
					$error_message = $error['admin_message'];
					$button = $error['backend_directions'];
					$post_id = $error['post_id'];
					$directions = '<p class="cff-error-directions">';
					$directions .= $button;
					$directions .= '<button data-url="'.get_the_permalink( $post_id ).'" class="cff-clear-errors-visit-page cff-space-left button button-secondary">' . __( 'View Feed and Retry', 'custom-facebook-feed' )  . '</button>';
					$directions .=	'</p>';
				} elseif ( isset( $errors['api'] ) ) {
					$error = $errors['api'];
					$error_message = $error['admin_message'];
					$button = $error['backend_directions'];
					$post_id = $error['post_id'];
					$directions = '<p class="cff-error-directions">';
					$directions .= $button;
					$directions .= '<button data-url="'.get_the_permalink( $post_id ).'" class="cff-clear-errors-visit-page cff-space-left button button-secondary">' . __( 'View Feed and Retry', 'custom-facebook-feed' )  . '</button>';
					$directions .=	'</p>';
				} else {
					$error = $errors['accesstoken'];

					$tokens = array();
					$post_id = false;
					foreach ( $error as $token ) {
						$tokens[] = $token['accesstoken'];
						$post_id = $token['post_id'];
					}
					$error_message = sprintf( __( 'The access token %s is invalid or has expired.', 'custom-facebook-feed' ), implode( ', ', $tokens ) );
					$directions = '<p class="cff-error-directions">';
					$directions .= '<button class="button button-primary cff-reconnect">' . __( 'Reconnect Your Account', 'custom-facebook-feed' )  . '</button>';
					$directions .= '<button data-url="'.get_the_permalink( $post_id ).'" class="cff-clear-errors-visit-page cff-space-left button button-secondary">' . __( 'View Feed and Retry', 'custom-facebook-feed' )  . '</button>';
					$directions .=	'</p>';
				}
				?>
				<div class="notice notice-warning is-dismissible cff-admin-notice">
					<p><strong><?php echo esc_html__( 'Custom Facebook Feed is encountering an error and your feeds may not be updating due to the following reasons:', 'custom-facebook-feed') ; ?></strong></p>

					<?php echo $error_message; ?>

					<?php echo $directions; ?>
				</div>
			<?php endif;
			*/
		}
	}

	/**
	 * Whether a platform-data deletion is CURRENTLY pending for a revoked app
	 * permission.
	 *
	 * Two conditions, both required. errors['revoked'] names the accounts a
	 * revoke was ever recorded against, and on its own it latches for good --
	 * only remove_error()'s second argument clears it, and all eight call sites
	 * omit it. Since it gates copy announcing a 7-day data-deletion deadline,
	 * one historical revoke made every later critical error render the deletion
	 * warning and hide its own cause: a 190/463 expiry said "an admin
	 * deauthorized the app" instead of "reconnect, your token expired".
	 *
	 * The deadline itself belongs to Platform_Data:
	 * handle_app_permission_status() writes revoke_platform_data_timestamp
	 * seven days out, handle_app_permission_error() acts once it passes, and
	 * cleanup_revoked_account() deletes the option on reconnect or after the
	 * deletion. So the warning is true exactly while that timer is armed and
	 * still in the future. Reading that, rather than letting a feed path mutate
	 * errors['revoked'], is deliberate: dropping an id would cancel the visible
	 * warning while the real deletion timer kept running and its notification
	 * email had already gone out.
	 *
	 * Read defensively -- a missing option, a non-array, or a missing or
	 * non-numeric timestamp all mean "not armed", never "armed".
	 *
	 * @return bool
	 */
	public function was_app_permission_related_error()
	{
		if (empty($this->errors['revoked'])) {
			return false;
		}

		$revoke = get_option(Platform_Data::REVOKE_PLATFORM_DATA_OPTION_KEY, array());
		if (!is_array($revoke) || !isset($revoke['revoke_platform_data_timestamp'])) {
			return false;
		}

		$deletion_due = $revoke['revoke_platform_data_timestamp'];
		if (!is_numeric($deletion_due)) {
			return false;
		}

		return (int)$deletion_due > time();
	}

	public function get_app_permission_related_error_ids()
	{
		return $this->errors['revoked'];
	}


	public function platform_data_deleted_notice()
	{
		$errors = $this->get_errors();
		if (!empty($errors) && (!empty($errors['platform_data_deleted']))) {
			?>
						<div class="cff-admin-notices cff-critical-error-notice">
							<span class="sb-notice-icon sb-error-icon">
								<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M10 0C4.48 0 0 4.48 0 10C0 15.52 4.48 20 10 20C15.52 20 20 15.52 20 10C20 4.48 15.52 0 10 0ZM11 15H9V13H11V15ZM11 11H9V5H11V11Z" fill="#D72C2C"/>
								</svg>
							</span>
							<div class="cff-notice-body">
								<h3 class="sb-notice-title">
									<?php echo esc_html__('All Facebook Data has Been Removed:', 'custom-facebook-feed'); ?>
								</h3>
								<p><?php echo $errors['platform_data_deleted']; ?></p>
								<p><?php echo esc_html__('To fix your feeds, reconnect all accounts that were in use on the Settings page.', 'custom-facebook-feed'); ?></p>

							</div>
						</div>
					<?php
		}
	}

	public function platform_unused_feed_notice()
	{
		$errors = $this->get_errors();
		if (!empty($errors) && (!empty($errors['unused_feed']))) {
			?>
						<div class="cff-admin-notices cff-critical-error-notice">
							<span class="sb-notice-icon sb-error-icon">
								<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
									<path d="M10 0C4.48 0 0 4.48 0 10C0 15.52 4.48 20 10 20C15.52 20 20 15.52 20 10C20 4.48 15.52 0 10 0ZM11 15H9V13H11V15ZM11 11H9V5H11V11Z" fill="#D72C2C"/>
								</svg>
							</span>
							<div class="cff-notice-body">
								<h3 class="sb-notice-title">
									<?php echo esc_html__('Action Required Within 7 Days:', 'custom-facebook-feed'); ?>
								</h3>

								<p><?php echo $errors['unused_feed']; ?></p>
								<p><?php echo esc_html__('Or you can simply press the "Fix Usage" button to fix this issue.', 'custom-facebook-feed'); ?></p>
								<div class="license-action-btns">
									<button class="sbi-reset-unused-feed-usage sbi-space-left sbi-btn sbi-notice-btn sbi-btn-blue"><?php echo __('Fix Usage', 'custom-facebook-feed'); ?></button>
								</div>
							</div>
						</div>
					<?php
		}
	}
	/**
	 * Should Add deprecation error for Groups
	 *
	 * @param $group_id
	 *
	 * @since X.X.X
	 */
	public function add_group_deprecation_error($group_id)
	{
		$group_deprecation_error = [
			'group_ids' => []
		];
		if (isset($this->errors['group_deprecation'])) {
			$group_deprecation_error['group_ids'] = $this->errors['group_deprecation']['group_ids'];
		}
		if (!in_array($group_id, $group_deprecation_error['group_ids'])) {
			$group_deprecation_error['dimissed'] = false;
			array_push($group_deprecation_error['group_ids'], $group_id);
		}
		$this->errors['group_deprecation'] = $group_deprecation_error;

		update_option($this->reporter_key, $this->errors, false);
	}

	/**
	 * Dismiss Group Notice
	 *
	 * @param $group_id
	 *
	 * @since X.X.X
	 */
	public function dismiss_group_deprecation_error()
	{
		if (isset($this->errors['group_deprecation'])) {
			$this->errors['group_deprecation']['dismissed'] = true;
			update_option($this->reporter_key, $this->errors, false);
		}
	}
	public function group_deprecation_notice()
	{
		$errors = $this->get_errors();
		if (
			!empty($errors) && !empty($errors['group_deprecation']) &&
			(!isset($errors['group_deprecation']['dismissed']) || $errors['group_deprecation']['dismissed'] !== true)
		) {
			$close_href = add_query_arg(array('cff_dismiss_notice' => 'group_deprecation'));
			?>
			<div class="cff-admin-notices cff-critical-error-notice">
				<span class="sb-notice-icon sb-error-icon">
					<svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M10 0C4.48 0 0 4.48 0 10C0 15.52 4.48 20 10 20C15.52 20 20 15.52 20 10C20 4.48 15.52 0 10 0ZM11 15H9V13H11V15ZM11 11H9V5H11V11Z" fill="#D72C2C"/>
					</svg>
				</span>
				<div class="cff-notice-body">
					<h3 class="sb-notice-title sb-noticegroup-title">
						<?php echo esc_html__('Group feeds will no longer update as of April 22, 2024 :', 'custom-facebook-feed') ; ?>
					</h3>
					<p>
						<?php
							echo
							__('You have one or more feeds that will no longer update after April 22, 2024. This is caused by a change in Facebook\'s API, which we use to get new data for feed updates.', 'custom-facebook-feed') ;
						?>
					</p>
					<br/>

					<p class="cff-error-directions">
						<a
							class="cff-notice-btn cff-btn-blue" target="_blank" rel="noopener"
							href="https://smashballoon.com/doc/facebook-api-changes-affecting-groups-april-2024/?utm_campaign=facebook-free&utm_source=error&utm_medium=docs">
							<?php echo esc_html__('Learn More', 'custom-facebook-feed') ; ?>
						</a>
						<a class="cff-notice-btn" href="<?php echo esc_attr($close_href); ?>" rel="noopener"><?php echo esc_html__('Dismiss', 'custom-facebook-feed') ; ?></a>
					</p>


				</div>
			</div>
			<?php
		}
	}
}
