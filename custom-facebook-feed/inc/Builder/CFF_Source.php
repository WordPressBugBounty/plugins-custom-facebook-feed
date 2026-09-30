<?php

/**
 * Custom Facebook Feed Source
 *
 * @since 4.0
 */

namespace CustomFacebookFeed\Builder;

use CustomFacebookFeed\CFF_Response;
use CustomFacebookFeed\CFF_Utils;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

class CFF_Source
{
	const BATCH_SIZE = 15;

	/**
	 * AJAX hooks for various feed data related functionality
	 *
	 * @since 4.0
	 */
	public static function hooks()
	{
		add_action('wp_ajax_cff_source_builder_update', array( 'CustomFacebookFeed\Builder\CFF_Source', 'builder_update' ));
		add_action('wp_ajax_cff_source_builder_update_multiple', array( 'CustomFacebookFeed\Builder\CFF_Source', 'builder_update_multiple' ));
		add_action('wp_ajax_cff_source_get_page', array( 'CustomFacebookFeed\Builder\CFF_Source', 'get_page' ));
		add_action('wp_ajax_cff_source_get_featured_post_preview', array( 'CustomFacebookFeed\Builder\CFF_Source', 'get_featured_post_preview' ));
		add_action('wp_ajax_cff_source_get_playlist_post_preview', array( 'CustomFacebookFeed\Builder\CFF_Source', 'get_playlist_post_preview' ));
		add_action('admin_init', array( 'CustomFacebookFeed\Builder\CFF_Source', 'batch_process_legacy_source_queue' ));
	}

	/**
	 * Used in an AJAX call to update sources based on selections or
	 * input from a user. Makes an API request to add additiona info
	 * about the connected source.
	 *
	 * @since 4.0
	 */
	public static function builder_update()
	{
		$action = 'cff-admin';
		if (! empty($_POST['settings_page'])) {
			$action = 'cff_admin_nonce';
		}
		check_ajax_referer($action, 'nonce');

		$cap = current_user_can('manage_custom_facebook_feed_options') ? 'manage_custom_facebook_feed_options' : 'manage_options';
		$cap = apply_filters('cff_settings_pages_capability', $cap);
		if (! current_user_can($cap)) {
			wp_send_json_error(); // This auto-dies.
		}


		$source_data = array(
			'access_token' => sanitize_text_field($_POST['access_token']),
			'id'           => sanitize_text_field($_POST['id']),
			'type'         => sanitize_text_field($_POST['type']),
			'privilege'    => isset($_POST['privilege']) ? sanitize_text_field($_POST['privilege']) : '',
		);

		if (! empty($_POST['name'])) {
			$source_data['name'] = sanitize_text_field($_POST['name']);
		}

		$return = CFF_Source::process_connecting_source_data($source_data);

		echo $return;

		wp_die();
	}


	/**
	 * Add our update a source from raw API data.
	 *
	 * @param $source_data
	 *
	 * @return string
	 */
	public static function process_connecting_source_data($source_data)
	{
		$header_details = \CustomFacebookFeed\CFF_Utils::fetch_header_data($source_data['id'], $source_data['type'] === 'group', $source_data['access_token'], 0, false, '');

		if (! isset($header_details->name)) {
			$message = __('There was a problem connecting this account. Please make sure your access token and ID are correct.');
			$details = '';
			if (isset($header_details->cached_error)) {
				$details = $header_details->cached_error->message;
				$details = '<span class="sb-caption">API Response: ' . esc_html($details) . '</span>';
			} elseif (isset($header_details->error)) {
				$details = $header_details->error->message;
				$details = '<span class="sb-caption">API Response: ' . esc_html($details) . '</span>';
			}

			$return_html = '<div class="cff-groups-connect-actions sb-alerts-wrap"><div class="sb-alert">
                            <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M8.99935 0.666504C4.39935 0.666504 0.666016 4.39984 0.666016 8.99984C0.666016 13.5998 4.39935 17.3332 8.99935 17.3332C13.5993 17.3332 17.3327 13.5998 17.3327 8.99984C17.3327 4.39984 13.5993 0.666504 8.99935 0.666504ZM9.83268 13.1665H8.16602V11.4998H9.83268V13.1665ZM9.83268 9.83317H8.16602V4.83317H9.83268V9.83317Z" fill="#995C00"/>
                            </svg>
                            <span><strong>' . esc_html($message) . '</strong></span><br>
                            ' . $details . '
                        </div></div>';

			$return = array(
				'success' => false,
				'message' => $return_html
			);
			return \CustomFacebookFeed\CFF_Utils::cff_json_encode($return);
		}

		if (isset($header_details->shortcode_options)) {
			unset($header_details->shortcode_options);
		}

		if (isset($header_details->name)) {
			$source_data['name'] = $header_details->name;
		}
		$source_data['info'] = $header_details;

		// don't update or insert the access token if there is an API error
		if (! isset($header_details->error) && ! isset($header_details->cached_error)) {
			$source_data['error']                     = '';
			$source_data['info']->connected_version   = CFFVER;
			$source_data['info'] =
				self::maybe_attach_connect_baseline(
					$source_data['info'],
					$source_data['id'],
					$source_data['access_token']
				);
			CFF_Source::update_or_insert($source_data);
		}

		return \CustomFacebookFeed\CFF_Utils::cff_json_encode(CFF_Feed_Builder::get_source_list());
	}

	/**
	 * Used in an AJAX call to update Multiple sources based on selections or
	 * input from a user. Makes an API request to add additiona info
	 * about the connected source.
	 *
	 * @since 4.0
	 */
	public static function builder_update_multiple()
	{

		if (check_ajax_referer('cff_admin_nonce', 'nonce', false) || check_ajax_referer('cff-admin', 'nonce', false)) {
			$cap = current_user_can('manage_custom_facebook_feed_options') ? 'manage_custom_facebook_feed_options' : 'manage_options';
			$cap = apply_filters('cff_settings_pages_capability', $cap);
			if (! current_user_can($cap)) {
				wp_send_json_error(); // This auto-dies.
			}
			$has_error = false;
			if (isset($_POST['sourcesList']) && !empty($_POST['sourcesList'])  && is_array($_POST['sourcesList'])) {
				foreach ($_POST['sourcesList'] as $single_source) :
					$source_data = array(
						'access_token' => sanitize_text_field($single_source['access_token']),
						'id'           => sanitize_text_field($single_source['account_id']),
						'name'		   => isset($single_source['name']) ? sanitize_text_field($single_source['name']) : '',
						'type'         => sanitize_text_field($_POST['type']),
						'privilege'    => isset($single_source['privilege']) ? sanitize_text_field($single_source['privilege']) : '',
					);
					$header_details = \CustomFacebookFeed\CFF_Utils::fetch_header_data($source_data['id'], $source_data['type'] === 'group', $source_data['access_token'], 0, false, '');
					if (isset($header_details->shortcode_options)) {
						unset($header_details->shortcode_options);
					}
					if (isset($header_details->name)) {
						$source_data['name'] = $header_details->name;
					}
					$source_data['info'] = $header_details;
					// don't update or insert the access token if there is an API error
					if (! isset($header_details->error)) {
						$source_data['error']                     = '';
						$source_data['info']->connected_version   = CFFVER;
						$source_data['info'] =
							self::maybe_attach_connect_baseline(
								$source_data['info'],
								$source_data['id'],
								$source_data['access_token']
							);
						CFF_Source::update_or_insert($source_data);
					} else {
						$has_error = true;
					}
				endforeach;
			}
			$response = [
				'sourcesList' =>  CFF_Feed_Builder::get_source_list()
			];
			if ($has_error) {
				$response['hasError'] = true;
			}
			echo \CustomFacebookFeed\CFF_Utils::cff_json_encode($response);
		}

		wp_die();
	}

	/**
	 * Get a list of sources with a limit and offset like a page
	 *
	 * @since 4.0
	 */
	public static function get_page()
	{
		check_ajax_referer('cff-admin', 'nonce');

		$cap = current_user_can('manage_custom_facebook_feed_options') ? 'manage_custom_facebook_feed_options' : 'manage_options';
		$cap = apply_filters('cff_settings_pages_capability', $cap);
		if (! current_user_can($cap)) {
			wp_send_json_error(); // This auto-dies.
		}


		$args = array( 'page' => $_POST['page'] );
		$source_data = CFF_Db::source_query($args);

		echo \CustomFacebookFeed\CFF_Utils::cff_json_encode($source_data);

		wp_die();
	}

	/**
	 * Using the URL and source ID, info about a single post is returned
	 *
	 * @since 4.0
	 */
	public static function get_featured_post_preview()
	{
		check_ajax_referer('cff-admin', 'nonce');

		$cap = current_user_can('manage_custom_facebook_feed_options') ? 'manage_custom_facebook_feed_options' : 'manage_options';
		$cap = apply_filters('cff_settings_pages_capability', $cap);
		if (! current_user_can($cap)) {
			wp_send_json_error(); // This auto-dies.
		}


		$query_args = array(
			'id' => sanitize_text_field($_POST['source_id'])
		);
		$results = CFF_Db::source_query($query_args);

		if (! isset($results[0])) {
			echo '{"error":{"message":"No valid ID found"}}';
			wp_die();
		}
		$access_token = $results[0]['access_token'];

		$url_or_post_id = $_POST['url_or_id'];

		$id = CFF_Source::extract_id($url_or_post_id, 'album');


		if (isset($id)) {
			$data = CFF_Source::fetch_featured($id, sanitize_text_field($_POST['source_id']), $access_token);
			echo $data;
		} else {
			echo '{"error":{"message":"No valid ID found"}}';
		}

		wp_die();
	}

	/**
	 * Using the URL or ID, info about a single playlist is returned
	 *
	 * @since 4.0
	 */
	public static function get_playlist_post_preview()
	{
		check_ajax_referer('cff-admin', 'nonce');

		$cap = current_user_can('manage_custom_facebook_feed_options') ? 'manage_custom_facebook_feed_options' : 'manage_options';
		$cap = apply_filters('cff_settings_pages_capability', $cap);
		if (! current_user_can($cap)) {
			wp_send_json_error(); // This auto-dies.
		}


		$query_args = array(
			'id' => sanitize_text_field($_POST['source_id'])
		);
		$results = CFF_Db::source_query($query_args);

		if (! isset($results[0])) {
			echo '{"error":{"message":"No valid ID found"}}';
			wp_die();
		}
		$access_token = $results[0]['access_token'];

		$url_or_post_id = $_POST['url_or_id'];

		$id = CFF_Source::extract_id($url_or_post_id, 'playlist');

		if (! empty($id)) {
			$data = CFF_Source::fetch_playlist($id, $access_token);
			echo $data;
		} else {
			echo '{"error":{"message":"Not a valid playlist for this account"}}';
		}

		wp_die();
	}

	/**
	 * Makes an API request to the featured post endpoint
	 *
	 * @param $post_id $url
	 * @param string $source_id
	 * @param string $access_token
	 *
	 * @return bool|string
	 *
	 * @since 4.0
	 */
	public static function fetch_featured($post_id, $source_id, $access_token)
	{
		// need a connected business account for this to work
		if (empty($access_token)) {
			return false;
		}

		$full_id = $source_id . '_' . $post_id;
		if (function_exists('cff_featured_post_id')) {
			$featured_post_url = cff_featured_post_id($full_id, $access_token);
		} else {
			$featured_post_url = 'https://graph.facebook.com/v23.0/' . $full_id . '?fields=id,from{picture,id,name,link},message,message_tags,story,story_tags,picture,full_picture,status_type,created_time,backdated_time,attachments{title,description,media_type,unshimmed_url,target{id},multi_share_end_card,media{source,image},subattachments},shares,comments.summary(true){message,created_time},likes.summary(true).limit(0),call_to_action,privacy&access_token=' . $access_token;
		}
		if (function_exists('cff_featured_event_id')) {
			$featured_event_url = cff_featured_event_id($full_id, $access_token);
		} else {
			$featured_event_url = 'https://graph.facebook.com/v23.0/' . $full_id . '?fields=id,name,attending_count,ticket_uri,cover,start_time,end_time,timezone,place,description,interested_count&access_token=' . $access_token;
		}

		$response = wp_safe_remote_get($featured_post_url);
		$return = '{}';
		if (! \CustomFacebookFeed\CFF_Utils::cff_is_wp_error($response)) {
			if (! \CustomFacebookFeed\CFF_Utils::cff_is_fb_error($response['body'])) {
				return $response['body'];
			} else {
				$return = $response['body'];
			}
		}

		$response = wp_safe_remote_get($featured_event_url);
		if (! \CustomFacebookFeed\CFF_Utils::cff_is_wp_error($response)) {
			if (! \CustomFacebookFeed\CFF_Utils::cff_is_fb_error($response['body'])) {
				return $response['body'];
			}
		} else {
			return '{"error":{"message":"HTTP request error"}}';
		}

		return $return;
	}

	/**
	 * Makes an API request to the playlist endpoint
	 *
	 * @param $playlist_id $url
	 * @param string $access_token
	 *
	 * @return bool|string
	 *
	 * @since 4.0
	 */
	public static function fetch_playlist($playlist_id, $access_token)
	{
		// need a connected business account for this to work
		if (empty($access_token)) {
			return false;
		}

		$url = 'https://graph.facebook.com/v3.2/' . $playlist_id . '/videos/?access_token=' . $access_token . '&fields=published,source,updated_time,created_time,title,description,embed_html,format{picture}&locale=en_US&limit=5';

		$response = wp_safe_remote_get($url);
		$return = '{}';
		if (! \CustomFacebookFeed\CFF_Utils::cff_is_wp_error($response)) {
			if (! \CustomFacebookFeed\CFF_Utils::cff_is_fb_error($response['body'])) {
				return json_encode(
					array_merge(
						json_decode($response['body'], true),
						[
							'playlistID' => $playlist_id
						]
					),
					true
				);
			} else {
				$return = $response['body'];
			}
		}

		return $return;
	}

	/**
	 * Connection URLs are based on the website connecting accounts so that is
	 * configured here and returned
	 *
	 * @return array
	 *
	 * @since 4.0
	 */
	public static function get_connection_urls($is_settings = false)
	{
		$urls            	= array();
		$admin_url_state 	= $is_settings ?  admin_url('admin.php?page=cff-settings') : admin_url('admin.php?page=cff-feed-builder');
		$sb_admin_email 	= get_option('admin_email');
		$nonce           	= wp_create_nonce('cff_con');
		$sw_flag         	= !empty($_GET['sw-feed']) ? true : false;
		$groups_number      = CFF_Db::check_group_source() > 0 ? true : false;


		// If the admin_url isn't returned correctly then use a fallback
		if (
			$admin_url_state == '/wp-admin/admin.php?page=cff-feed-builder'
			 || $admin_url_state == '/wp-admin/admin.php?page=cff-feed-builder&tab=configuration'
		) {
			$admin_url_state = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
		}

		$urls['page'] 	= [
			'connect' 			=> CFF_CONNECT_URL,
			'wordpress_user'   => $sb_admin_email,
			'v'                => 'free',
			'vn'               => CFFVER,
			'has_group'        => $groups_number,
			'cff_con'          => $nonce,
			'sw_feed'          => $sw_flag
		];

		$urls['group'] 	= [
			'connect' 			=> CFF_CONNECT_URL,
			'wordpress_user'   => $sb_admin_email,
			'v'                => 'free',
			'vn'               => CFFVER,
			'has_group'        => $groups_number,
			'cff_con'          => $nonce,
			'sw_feed'          => $sw_flag
		];
		$urls['stateURL'] = $admin_url_state;
		return $urls;
	}

	/**
	 * Used as a listener for the account connection process. If
	 * data is returned from the account connection processed it's used
	 * to generate the list of possible sources to chose from.
	 *
	 * @return array|bool
	 *
	 * @since 4.0
	 */
	public static function maybe_source_connection_data()
	{
		$nonce = !empty($_GET['cff_con']) ? sanitize_key($_GET['cff_con']) : '';
		if (!wp_verify_nonce($nonce, 'cff_con')) {
			return false;
		}

		if (
			isset($_GET['cff_access_token'])
			 && isset($_GET['cff_final_response'])
			 && $_GET['cff_final_response'] == 'true'
		) {
			// clear access token errors since the user is reconnecting accounts
			$reporter = \CustomFacebookFeed\CFF_Utils::cff_is_pro_version() ? cff_main_pro()->cff_error_reporter : cff_main()->cff_error_reporter;
			$reporter->remove_error('accesstoken');

			$access_token = sanitize_text_field($_GET['cff_access_token']);
			// Not sent by the broker yet; captured the moment it starts forwarding one.
			// A data_access_expiration_time of 0 is a real Meta value meaning
			// "no data-access expiry", so test isset() + is_numeric() rather
			// than !empty(): null is reserved for "the flow didn't send one".
			$data_access_expires_at = isset($_GET['cff_data_access_expiration_time'])
				&& is_numeric($_GET['cff_data_access_expiration_time'])
					? (int)$_GET['cff_data_access_expiration_time']
					: null;
			if (isset($_GET['cff_group'])) {
				$return = CFF_Source::retrieve_available_groups($access_token, $data_access_expires_at);
			} else {
				$return = CFF_Source::retrieve_available_pages($access_token, $data_access_expires_at);
			}

			if ($return) {
				return $return;
			} else {
				return array( 'error' => __('Unable to connect to Facebook to retrieve account information.', 'custom-facebook-feed') );
			}
		}

		return false;
	}

	/**
	 * Retrieves the permissions granted to the connecting user's token,
	 * parsed into granted and declined scope lists for the connect
	 * baseline. Returns null when the request fails so a connect is
	 * never blocked by this call.
	 *
	 * @param string $access_token The user access token from the connect flow.
	 *
	 * @return array|null
	 */
	public static function fetch_connect_permissions($access_token)
	{
		$url = 'https://graph.facebook.com/me/permissions?access_token=' . $access_token;
		$permissions_data = \CustomFacebookFeed\CFF_Utils::cff_fetchUrl($url);

		if (empty($permissions_data)) {
			return null;
		}

		$permissions_data_arr = json_decode($permissions_data, true);

		if (!is_array($permissions_data_arr) || !isset($permissions_data_arr['data'])) {
			return null;
		}

		$permission_sets = array(
			'scopes' => array(),
			'declined_scopes' => array(),
		);
		foreach ($permissions_data_arr['data'] as $permission) {
			if (empty($permission['permission']) || empty($permission['status'])) {
				continue;
			}
			if ($permission['status'] === 'granted') {
				$permission_sets['scopes'][] = $permission['permission'];
			} elseif ($permission['status'] === 'declined') {
				$permission_sets['declined_scopes'][] = $permission['permission'];
			}
		}

		return $permission_sets;
	}

	/**
	 * Builds the connect-time baseline stored with each source: the
	 * granted/declined scope set, the asset ids visible to the token,
	 * and the data-access expiry when the flow provides one. Fields the
	 * current flow can't provide are stored as null so sources with a
	 * baseline are distinguishable from sources connected before one
	 * was captured.
	 *
	 * Asset lists are bounded by the connect flow's limit=500 accounts
	 * request — the stored size budget depends on that cap.
	 *
	 * @param array|null $permission_sets Parsed sets from fetch_connect_permissions(), or null when unavailable.
	 * @param array|null $accounts_data Response of /me/accounts, or null when unavailable.
	 * @param int|null   $data_access_expires_at Data-access expiry timestamp when the flow provides one.
	 * @param array|null $group_ids Ids of the groups visible to the token, or null when not a group connect.
	 *
	 * @return array
	 */
	public static function build_connect_baseline(
		$permission_sets,
		$accounts_data,
		$data_access_expires_at = null,
		$group_ids = null
	) {
		$defaults = array(
			'scopes' => null,
			'declined_scopes' => null,
		);
		$permission_sets = is_array($permission_sets) ? array_merge($defaults, $permission_sets) : $defaults;

		$asset_targets = null;
		$page_assets = isset($accounts_data['data']) ? $accounts_data['data'] : null;
		if (null !== $page_assets || is_array($group_ids)) {
			$asset_targets = array(
				'pages' => array(),
				'groups' => array(),
			);
			foreach ((array)$page_assets as $asset) {
				if (!empty($asset['id'])) {
					$asset_targets['pages'][] = (string)$asset['id'];
				}
			}
			foreach ((array)$group_ids as $group_id) {
				$asset_targets['groups'][] = (string)$group_id;
			}
		}

		// States why scopes are null: the token family exposes no scope
		// surface at all, or a scope surface exists but the fetch failed.
		$capture_status = 'scopes_captured';
		if (null === $permission_sets['scopes']) {
			$capture_status = null === $asset_targets ? 'no_scope_surface' : 'scopes_unavailable';
		}

		return array(
			'baseline_version' => 1,
			'capture_status' => $capture_status,
			'captured_at' => time(),
			'data_access_expires_at' => null !== $data_access_expires_at ? (int)$data_access_expires_at : null,
			'scopes' => $permission_sets['scopes'],
			'declined_scopes' => $permission_sets['declined_scopes'],
			'asset_targets' => $asset_targets,
		);
	}

	/**
	 * Holds the connect baseline briefly so the source rows written when
	 * the user picks accounts (a separate request) receive it.
	 *
	 * The stash is bound to the token it describes: alongside each asset
	 * id we record a keyed fingerprint of the access token that asset will
	 * be saved with — an HMAC-SHA256 keyed with wp_salt('nonce'), not a
	 * bare digest, so the value that lands in wp_options is useless as an
	 * offline verifier to anyone who can only read the database.
	 *
	 * A baseline describes one token's grants, so the token is the key the
	 * baseline is actually about — an asset id alone
	 * cannot tell this connect's token apart from a token pasted by hand
	 * for a page that merely appeared in this connect's picker. Only the
	 * fingerprints are stored; the raw tokens never are.
	 *
	 * Pages are saved with their own page token (and a location page with
	 * its parent page's token), groups with the user token, so the
	 * fingerprint is recorded per asset rather than once for the connect.
	 *
	 * Deliberately not deleted on first use: one connect can persist
	 * several picked sources across requests within the TTL, and the
	 * fingerprint already rejects every other token, so the stash simply
	 * expires. Consuming it per asset would instead re-arm the TTL on
	 * every save — turning a fixed 15-minute window into a sliding one —
	 * and its read-modify-write would race concurrent saves.
	 *
	 * @param array $connect_baseline The baseline built at connect time.
	 * @param array $asset_tokens Map of asset id => the access token that asset will be saved with.
	 */
	public static function stash_connect_baseline($connect_baseline, $asset_tokens)
	{
		$ids = array();
		$token_hashes = array();
		foreach ((array)$asset_tokens as $id => $token) {
			$id = (string)$id;
			$ids[] = $id;
			if (!empty($token) && is_string($token)) {
				$token_hashes[$id] = hash_hmac('sha256', $token, wp_salt('nonce'));
			}
		}

		set_transient(
			'cff_connect_baseline_' . get_current_user_id(),
			array(
				'asset_ids' => $ids,
				'token_hashes' => $token_hashes,
				'baseline' => $connect_baseline,
			),
			15 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Attaches the stashed connect baseline to a source's info data when
	 * the source is one of the assets the baseline was captured for and
	 * the token being saved is the token the baseline describes.
	 *
	 * The token fingerprint is the load-bearing half of that pair: a
	 * manual token paste for an id that was merely visible in this
	 * connect's picker must not adopt a baseline describing a different
	 * token's grants. The candidate token is re-keyed with the same
	 * wp_salt('nonce') HMAC and compared with hash_equals against the
	 * stashed fingerprint; the token itself is never logged or persisted
	 * here. Rotating the site's salts invalidates outstanding stashes,
	 * which is harmless given the 15-minute TTL.
	 *
	 * @param object $info The source's info data.
	 * @param string $source_id The account id of the source being saved.
	 * @param string $access_token The access token the source is being saved with.
	 *
	 * @return object
	 */
	public static function maybe_attach_connect_baseline($info, $source_id, $access_token)
	{
		$stashed = get_transient('cff_connect_baseline_' . get_current_user_id());
		$source_id = (string)$source_id;
		$stashed_hash = isset($stashed['token_hashes'][$source_id])
			? $stashed['token_hashes'][$source_id]
			: null;
		$token_matches = !empty($stashed_hash)
			&& is_string($stashed_hash)
			&& !empty($access_token)
			&& is_string($access_token)
			&& hash_equals($stashed_hash, hash_hmac('sha256', $access_token, wp_salt('nonce')));

		if (
			$token_matches
			&& !empty($stashed['baseline']) && is_array($stashed['baseline'])
			&& !empty($stashed['asset_ids']) && is_array($stashed['asset_ids'])
			&& in_array($source_id, $stashed['asset_ids'], true)
			&& is_object($info)
		) {
			$info->connect_baseline = $stashed['baseline'];
		}

		return $info;
	}

	/**
	 * Uses the Facebook API to retrieve a list of pages for the
	 * access token
	 *
	 * @param string   $access_token           A Facebook user access token.
	 * @param int|null $data_access_expires_at Data-access expiry timestamp when the flow provides one.
	 *
	 * @return array|bool
	 *
	 * @since 4.0
	 */
	public static function retrieve_available_pages($access_token, $data_access_expires_at = null)
	{

		// Get User Info
		$user_url     = 'https://graph.facebook.com/me?fields=name,id,picture&access_token=' . $access_token;
		$user_id_data = \CustomFacebookFeed\CFF_Utils::cff_fetchUrl($user_url);
		$user_id_data_arr = json_decode($user_id_data);

		$url            = 'https://graph.facebook.com/me/accounts?fields=access_token,name,id&limit=500&access_token=' . $access_token;
		$pages_data     = \CustomFacebookFeed\CFF_Utils::cff_fetchUrl($url);
		$pages_data_arr = json_decode($pages_data, true);

		if (isset($pages_data_arr['data'])) {
			// Each page is saved with its own page token, so the baseline is
			// stashed against the token each asset will actually be saved with.
			$asset_tokens = array();
			foreach ((array)$pages_data_arr['data'] as $page_asset) {
				if (!empty($page_asset['id'])) {
					$asset_tokens[(string)$page_asset['id']] =
						isset($page_asset['access_token']) ? $page_asset['access_token'] : '';
				}
			}

			self::stash_connect_baseline(
				self::build_connect_baseline(
					self::fetch_connect_permissions($access_token),
					$pages_data_arr,
					$data_access_expires_at
				),
				$asset_tokens
			);

			$return = array(
				'user'  => $user_id_data_arr,
				'pages'  => $pages_data_arr['data'],
			);

			return $return;
		} elseif (isset($pages_data_arr['error'])) {
			return $pages_data_arr;
		} else {
			return [
				'error' => [
					'code' => 'HTTP Request',
					'message' => __('Your server could not complete a remote request to Facebook\'s API. Your host may be blocking access or there may be a problem with your server.', 'custom-facebook-feed')
				]
			];
		}
	}

	/**
	 * Uses the Facebook API to retrieve a list of groups for the
	 * access token split into "admin" and "member" groupings
	 *
	 * @param string   $access_token           A Facebook user access token.
	 * @param int|null $data_access_expires_at Data-access expiry timestamp when the flow provides one.
	 *
	 * @return array|bool
	 *
	 * @since 4.0
	 */
	public static function retrieve_available_groups($access_token, $data_access_expires_at = null)
	{
		// Extend the user token by making a call to /me/accounts. User must be an admin of a page for this to work as won't work if the response is empty.
		$url = 'https://graph.facebook.com/me/accounts?limit=500&access_token=' . $access_token;

		$accounts_data        = \CustomFacebookFeed\CFF_Utils::cff_fetchUrl($url);
		if (empty($accounts_data)) {
			return [
				'error' => [
					'code' => 'HTTP Request',
					'message' => __('Your server could not complete a remote request to Facebook\'s API. Your host may be blocking access or there may be a problem with your server.', 'custom-facebook-feed')
				]
			];
		}
		$accounts_data_arr    = json_decode($accounts_data);
		$cff_token_expiration = 'never';
		if (empty($accounts_data_arr->data)) {
			$cff_token_expiration = '60 days';
		}

		if (! empty($accounts_data_arr->error)) {
			return $accounts_data_arr;
		}

		// Get User Info
		$user_url     = 'https://graph.facebook.com/me?fields=name,id,picture&access_token=' . $access_token;
		$user_id_data = \CustomFacebookFeed\CFF_Utils::cff_fetchUrl($user_url);

		if (! empty($user_id_data)) {
			$user_id_data_arr = json_decode($user_id_data);

			$user_id          = $user_id_data_arr->id;

			$admin_ids = [];
			// Get groups they're admin of
			$groups_admin_url      = 'https://graph.facebook.com/' . $user_id . '/groups?admin_only=true&fields=name,id,picture&access_token=' . $access_token;
			$groups_admin_data     = \CustomFacebookFeed\CFF_Utils::cff_fetchUrl($groups_admin_url);
			$groups_admin_data_arr = json_decode($groups_admin_data, true);
			$admin_groups          = array();
			if (isset($groups_admin_data_arr['data'])) {
				foreach ($groups_admin_data_arr['data'] as $single_group) {
					$single_group['expiration'] = $cff_token_expiration;
					$single_group['access_token'] = $access_token;
					$admin_groups[]             = $single_group;
					$admin_ids[] = $single_group['id'];
				}
			}

			// Get member groups
			$groups_url      = 'https://graph.facebook.com/' . $user_id . '/groups?admin_only=false&fields=name,id,picture&access_token=' . $access_token;
			$groups_data     = \CustomFacebookFeed\CFF_Utils::cff_fetchUrl($groups_url);
			$groups_data_arr = json_decode($groups_data, true);
			$member_groups   = array();
			$group_ids       = $admin_ids;
			if (isset($groups_data_arr['data'])) {
				foreach ($groups_data_arr['data'] as $single_group) {
					if (! in_array($single_group['id'], $admin_ids, true)) {
						$single_group['expiration'] = $cff_token_expiration;
						$single_group['access_token'] = $access_token;
						$member_groups[]            = $single_group;
						$group_ids[]                = $single_group['id'];
					}
				}
			}

			// Every group is saved with the user token (set on each group
			// above), so all of them fingerprint to that one token.
			self::stash_connect_baseline(
				self::build_connect_baseline(
					self::fetch_connect_permissions($access_token),
					json_decode($accounts_data, true),
					$data_access_expires_at,
					$group_ids
				),
				array_fill_keys($group_ids, $access_token)
			);

			$return = array(
				'user'  => $user_id_data_arr,
				'admin'  => $admin_groups,
				'member' => $member_groups
			);

			return $return;
		} elseif (isset($accounts_data_arr['error'])) {
			return $accounts_data;
		} else {
			return [
				'error' => [
					'code' => 'HTTP Request',
					'message' => __('Your server could not complete a remote request to Facebook\'s API. Your host may be blocking access or there may be a problem with your server.', 'custom-facebook-feed')
				]
			];
		}
	}

	/**
	 * Used to update or insert connected accounts (sources)
	 *
	 * @param array $source_data
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	public static function update_or_insert($source_data)
	{
		if (! isset($source_data['id'])) {
			return false;
		}

		if (isset($source_data['info'])) {
			// data from an API request related to the source is saved as a JSON string
			if (is_object($source_data['info']) || is_array($source_data['info'])) {
				$source_data['info'] = \CustomFacebookFeed\CFF_Utils::cff_json_encode($source_data['info']);
			}
		}

		if (CFF_Source::exists_in_database($source_data)) {
			$source_data['last_updated'] = date('Y-m-d H:i:s');
			CFF_Source::update($source_data);
		} else {
			if (! isset($source_data['access_token'])) {
				return false;
			}
			CFF_Source::insert($source_data);
		}

		CFF_Source::after_update_or_insert($source_data);
	}

	/**
	 * Whether or not the source exists in the database
	 *
	 * @param array $args
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	public static function exists_in_database($args)
	{
		$results = CFF_Db::source_query($args);

		return isset($results[0]);
	}

	/**
	 * Add a new source as a row in the cff_sources table
	 *
	 * @param array $source_data
	 *
	 * @return false|int
	 *
	 * @since 4.0
	 */
	public static function insert($source_data)
	{
		$source_data['username'] = $source_data['name'];
		$data                    = $source_data;

		return CFF_Db::source_insert($data);
	}

	/**
	 * Update info in rows that match the source data
	 *
	 * @param array $source_data
	 *
	 * @return false|int
	 *
	 * @since 4.0
	 */
	public static function update($source_data, $where_privilige = true)
	{
		$where = array( 'id' => $source_data['id'] );
		unset($source_data['id']);

		if ($where_privilige && isset($source_data['privilege'])) {
			$where['privilege'] = $source_data['privilege'];
		}

		// usernames are more common in the other plugins so
		// that is the name of the column that is used as the
		// page or group "name" data
		if (isset($source_data['name'])) {
			$source_data['username'] = $source_data['name'];
		}
		$data = $source_data;

		return CFF_Db::source_update($data, $where);
	}

	/**
	 * Do something after a source is updated or inserted
	 *
	 * @param array $source_data
	 * @since 4.0.6/4.0.9
	 */
	public static function after_update_or_insert($source_data)
	{

		// check to see if all groups updated
		$cff_statuses_option = get_option('cff_statuses', array());

		if (empty($cff_statuses_option['groups_need_update'])) {
			return;
		}
		$groups = \CustomFacebookFeed\Builder\CFF_Db::source_query(array( 'type' => 'group' ));

		$cff_statuses_option['groups_need_update'] = false;
		if (empty($groups)) {
			update_option('cff_statuses', $cff_statuses_option, false);
		} else {
			$encryption         = new \CustomFacebookFeed\SB_Facebook_Data_Encryption();
			$groups_need_update = false;
			foreach ($groups as $source) {
				$info   = ! empty($source['info']) ? json_decode($encryption->decrypt($source['info'])) : array();
				if (\CustomFacebookFeed\Builder\CFF_Source::needs_update($source, $info)) {
					$groups_need_update = true;
				}
			}
			$cff_statuses_option['groups_need_update'] = $groups_need_update;
			update_option('cff_statuses', $cff_statuses_option, false);
		}
	}

	/**
	 * @param array $source
	 * @param array $source_info
	 *
	 * @return bool
	 * @since 4.0.6/4.0.9
	 */
	public static function needs_update($source, $source_info)
	{
		if ('group' === $source['account_type']) {
			$connected_version = is_object($source_info) && isset($source_info->connected_version) ? $source_info->connected_version : 0;
			if (version_compare($connected_version, '4.0.9', '<')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Attempts to find the album or playlist ID from
	 * a Facebook URL
	 *
	 * @param string $url_or_post_id
	 * @param string $type
	 *
	 * @return bool|mixed|string
	 *
	 * @since 4.0
	 */
	public static function extract_id($url_or_post_id, $type)
	{
		$id = false;

		if ($type === 'album') {
			if (strpos($url_or_post_id, '/') === false) {
				$id = sanitize_text_field($url_or_post_id);
			} elseif (strpos($url_or_post_id, '&set=a.') !== false) {
				$things = explode('&set=a.', $url_or_post_id);

				$id = $things[1];
			} else {
				$regex = '/(?:https?:\/\/)?(?:www\.)?facebook\.com\/(?:(?:\w\.)*#!\/)?(?:pages\/)?(?:[\w\-\.]*\/)*([\w\-\.]*)/';
				if (preg_match($regex, $url_or_post_id, $matches)) {
					if (isset($matches[0])) {
						$id = end($matches);
					}
				}
			}
		} elseif ($type === 'playlist') {
			if (strpos($url_or_post_id, '/') === false) {
				$id = sanitize_text_field($url_or_post_id);
			} else { // https://www.facebook.com/watch/539051002877739/1979731855647067/?more=less // https://www.facebook.com/videos/vl.1234567890/
				$regex = "~(?:vl\.\d+/)?(\d+)~i";
				if (stripos($url_or_post_id, 'videos') !== false && preg_match($regex, $url_or_post_id, $matches)) {
					if (isset($matches[0])) {
						$id_pieces = end($matches);

						if (strpos($id_pieces, '.') !== false) {
							$id = explode('.', $id_pieces)[1];
						} else {
							$id = $matches[0];
						}
					}
				} else {
					if (strpos($url_or_post_id, '?') !== false) {
						$url_or_post_id = explode('?', $url_or_post_id)[0];
					}

					$parts = explode('/', $url_or_post_id);


					$id = $parts[ count($parts) - 1 ];

					if (empty($id)) {
						$id = $parts[ count($parts) - 2 ];
					}

					$id = (int) filter_var($id, FILTER_SANITIZE_NUMBER_INT);
				}
			}
		}

		return $id;
	}

	/**
	 * Creates a queue of connected accounts that need to be added to
	 * the sources table
	 *
	 * @since 4.0.3
	 */
	public static function set_legacy_source_queue()
	{
		$cff_statuses_option = get_option('cff_statuses', array());
		$connected_accounts = (array)json_decode(stripcslashes(get_option('cff_connected_accounts')), true);

		$cff_statuses_option['legacy_source_queue'] = array_chunk(array_keys($connected_accounts), CFF_Source::BATCH_SIZE);

		update_option('cff_statuses', $cff_statuses_option);
	}

	/**
	 * Whether or not there are still sources in the queue and
	 * this isn't disabled
	 *
	 * @return bool
	 *
	 * @since 4.0.3
	 */
	public static function should_do_source_updates()
	{
		$cff_statuses_option = get_option('cff_statuses', array());

		$should_do_source_updates = isset($cff_statuses_option['legacy_source_queue']) ? ! empty($cff_statuses_option['legacy_source_queue']) : false;

		return apply_filters('should_do_source_updates', $should_do_source_updates);
	}

	/**
	 * Processes one set of connected accounts
	 *
	 * @since 4.0.3
	 */
	public static function batch_process_legacy_source_queue()
	{
		if (! CFF_Source::should_do_source_updates()) {
			return;
		}

		$cff_statuses_option = get_option('cff_statuses', array());
		$batch = array_shift($cff_statuses_option['legacy_source_queue']);
		update_option('cff_statuses', $cff_statuses_option); // updated early just in case there is a fatal error

		if (empty($batch)) {
			return;
		}
		$connected_accounts = (array)json_decode(stripcslashes(get_option('cff_connected_accounts')), true);
		foreach ($batch as $account_key) {
			$connected_account = isset($connected_accounts[ $account_key ]) ? $connected_accounts[ $account_key ] : false;

			if ($connected_account) {
				CFF_Source::update_single_source($connected_account);
			}
		}
	}

	/**
	 * Transfer data from a connected account to the sources table
	 * after it's been validated with an API call
	 *
	 * @param array $connected_account
	 *
	 * @since 4.0.3
	 */
	public static function update_single_source($connected_account)
	{
		$cff_page_slugs = get_option('cff_page_slugs', array());
		$access_token = $connected_account['accesstoken'];
		$id = str_replace(' ', '', $connected_account['id']);
		$header_details = \CustomFacebookFeed\CFF_Utils::fetch_header_data($id, $connected_account['pagetype'] === 'group', $access_token, 0, false, '');

		$source_data = array(
			'access_token' => $connected_account['accesstoken'],
			'id'           => $connected_account['id'],
			'type'         => $connected_account['pagetype'],
			'name'         => $connected_account['name'],
			'privilege'    => '', // see if events token?
		);

		if (! is_numeric($id)) {
			if (! isset($cff_page_slugs[ $id ])) {
				$cff_page_slugs[ $id ] = $header_details->id;
				update_option('cff_page_slugs', $cff_page_slugs);
			}
			$source_data['id'] = $header_details->id;
		}

		if (isset($header_details->shortcode_options)) {
			unset($header_details->shortcode_options);
		}

		if (isset($header_details->name)) {
			$source_data['name'] = $header_details->name;
		}
		$source_data['info'] = $header_details;
		$source_data['error'] = '';

		if (isset($header_details->error) || isset($header_details->cached_error)) {
			$source_data['error'] = isset($header_details->error) ? \CustomFacebookFeed\CFF_Utils::cff_json_encode($header_details->error) : \CustomFacebookFeed\CFF_Utils::cff_json_encode($header_details->cached_error);
		}

		CFF_Source::update_or_insert($source_data);

		$source_data['record_id'] = 0;
		$source_data['account_id'] = $connected_account['id'];
		$source_data['account_type'] = $connected_account['pagetype'];
		$source_data['username'] = $source_data['name'];

		return $source_data;
	}

	/**
	 * Creates a source from the access token and
	 * source ID saved in 3.x settings
	 *
	 * @since 4.0.3
	 */
	public static function update_source_from_legacy_settings()
	{
		$db_access_token_option = get_option('cff_access_token');
		$db_page_access_token  = get_option('cff_page_access_token');
		$db_page_id_option  = get_option('cff_page_id');
		$db_page_type = get_option('cff_page_type');
		$cff_page_slugs = get_option('cff_page_slugs', array());

		if (
			(! empty($db_access_token_option) || ! empty($db_page_access_token))
			 && ! empty($db_page_id_option)
		) {
			$db_access_tokens = explode(',', str_replace(' ', '', $db_access_token_option));
			$db_page_ids = explode(',', str_replace(' ', '', $db_page_id_option));

			$i = 0;
			foreach ($db_access_tokens as $db_access_token) {
				if (strpos($db_access_token, ':') !== false) {
					$id_at_arr = explode(':', $db_access_token);
					$db_page_id = $id_at_arr[0];
					$db_access_token = $id_at_arr[1];
				} else {
					$db_page_id = $db_page_ids[ $i ];
				}
				$source_data = array(
					'access_token' =>  ! empty($db_page_access_token) ? $db_page_access_token : $db_access_token,
					'id'           => $db_page_id,
					'type'         => $db_page_type === 'group' ? 'group' : 'page',
					'name'         => $db_page_id,
					'privilege'    => '', // see if events token?
				);

				$header_details = \CustomFacebookFeed\CFF_Utils::fetch_header_data($source_data['id'], $source_data['type'] === 'group', $source_data['access_token'], 0, false, '');

				if (isset($header_details->shortcode_options)) {
					unset($header_details->shortcode_options);
				}

				if (isset($header_details->name)) {
					$source_data['name'] = $header_details->name;
				}

				if (! is_numeric($source_data['id']) && isset($header_details->id)) {
					if (! isset($cff_page_slugs[ $source_data['id'] ])) {
						$cff_page_slugs[ $source_data['id'] ] = $header_details->id;
						update_option('cff_page_slugs', $cff_page_slugs);
					}
					$source_data['id'] = $header_details->id;
				}

				$source_data['info'] = $header_details;

				// don't update or insert the access token if there is an API error
				if (! isset($header_details->error) && ! isset($header_details->cached_error)) {
					\CustomFacebookFeed\Builder\CFF_Source::update_or_insert($source_data);
				} else {
					if (! empty($db_page_access_token) && ! empty($db_access_token)) {
						$source_data = array(
							'access_token' => $db_access_token,
							'id'           => $db_page_id,
							'type'         => $db_page_type === 'group' ? 'group' : 'page',
							'name'         => $db_page_id,
							'privilege'    => '', // see if events token?
						);

						$header_details = \CustomFacebookFeed\CFF_Utils::fetch_header_data($source_data['id'], $source_data['type'] === 'group', $source_data['access_token'], 0, false, '');

						if (isset($header_details->shortcode_options)) {
							unset($header_details->shortcode_options);
						}

						if (isset($header_details->name)) {
							$source_data['name'] = $header_details->name;
						}
						$source_data['info'] = $header_details;

						if (! isset($header_details->error) && ! isset($header_details->cached_error)) {
							\CustomFacebookFeed\Builder\CFF_Source::update_or_insert($source_data);
						}
					}
				}
				$i++;
			}
		}
	}

	/**
	 * If the plugin is still updating legacy sources this function
	 * can be used to udpate a single source if needed before
	 * the update is done.
	 *
	 * @param string $slug_or_id
	 *
	 * @return array|bool
	 */
	public static function maybe_one_off_connected_account_update($slug_or_id)
	{
		if (! CFF_Source::should_do_source_updates()) {
			return false;
		}

		$connected_accounts = (array)json_decode(stripcslashes(get_option('cff_connected_accounts')), true);
		$connected_account = isset($connected_accounts[ $slug_or_id ]) ? $connected_accounts[ $slug_or_id ] : false;

		if ($connected_account) {
			return CFF_Source::update_single_source($connected_account);
		}

		return false;
	}

	/**
	 * Get the Facebook ID using an alphanumeric page "slug" if
	 * it has been created in the DB
	 *
	 * @param string $slug
	 *
	 * @return bool|string
	 *
	 * @since 4.0.3
	 */
	public static function get_id_from_slug($slug)
	{
		$cff_page_slugs = get_option('cff_page_slugs', array());
		if (isset($cff_page_slugs[ $slug ])) {
			return $cff_page_slugs[ $slug ];
		}
		return false;
	}

	/**
	 * Clears the "error" column in the cff_sources table for a specific
	 * account
	 *
	 * @param string $account_id
	 *
	 * @return bool
	 *
	 * @since 4.0.3
	 */
	public static function clear_error($account_id)
	{
		$source_data = array(
			'id' => $account_id,
			'error' => ''
		);
		return \CustomFacebookFeed\Builder\CFF_Source::update_or_insert($source_data);
	}

	/**
	 * Adds an error to the error table by account ID
	 *
	 * @param string              $account_id
	 * @param string|object|array $error
	 *
	 * @return bool
	 *
	 * @since 4.0.3
	 */
	public static function add_error($account_id, $error)
	{
		$source_data = array(
			'id' => $account_id,
			'error' => is_string($error) ? $error : \CustomFacebookFeed\CFF_Utils::cff_json_encode($error)
		);
		return \CustomFacebookFeed\Builder\CFF_Source::update_or_insert($source_data);
	}


	/**
	 * Add error to the option table "cff_error_reporter"
	 *
	 * @param string              $account_id
	 * @param string|object|array $error
	 *
	 * @return bool
	 *
	 * @since 4.0.3
	 */
	public static function add_report_error_option($account)
	{
		$errors = get_option('cff_error_reporter', []);
		$add_error = (isset($errors['connection']) && isset($errors['connection']['error_id']) && $errors['connection']['error_id'] == 190) ? false : true;
		if ($add_error) {
			$url = 'https://graph.facebook.com/v23.0/' . $account['account_id'] . '/posts?fields=id,updated_time,from{picture,id,name,link},message,message_tags,story,story_tags,status_type,created_time,backdated_time,call_to_action,attachments{title,description,media_type,unshimmed_url,target{id},media{source}}&access_token=' . $account['access_token'] . '&limit=7&locale=en_US';
			CFF_Utils::cff_fetchUrl($url, false);
		}
	}


	/**
	 * Get single source info By Id
	 *
	 * @since 4.2.0
	 */
	public static function get_single_source_info($source_id)
	{
		$query_args = array(
			'id' => sanitize_text_field(wp_unslash($source_id))
		);
		$results = CFF_Db::source_query_byid($query_args);
		if (!isset($results[0])) {
			return false;
		}
		return $results[0];
	}

	/**
	 * Should show group Notice
	 *
	 * @since 4.2.0
	 */
	public static function should_show_group_deprecation()
	{
		$cff_statuses = get_option('cff_statuses', array());
		return (
				!isset($cff_statuses['cff_group_deprecation_dismiss']) ||
				$cff_statuses['cff_group_deprecation_dismiss'] !== true
			) && CFF_Db::check_group_source() > 0;
	}
}
