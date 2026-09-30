<?php

namespace CustomFacebookFeed\Integrations;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Class CFF_API_Connect
 * Connect to the Facebook Graph and make API Calls
 *
 * @since 4.X
 */
class CFF_API_Connect
{
	/**
	 * API url call
	 *
	 * @var string
	 */
	private $url;

	/**
	 * API url response
	 *
	 * @var object
	 */
	private $response;

	/**
	 * API call params
	 *
	 * @var array
	 */
	private $params;

	/**
	 * CFF_API_Connect constructor.
	 *
	 * @param mixed|array|string $connected_account_or_url either the connected account.
	 *  data for this request or the complete url for the request.
	 * @param string             $endpoint (optional) is optional only if the complete url is provided.
	 *             otherwise is they key for the endpoint needed for the request (ex. "header").
	 * @param array              $params (optional) used with the connected account and endpoint to add.
	 *               additional query parameters to the url if needed.
	 * @since 5.0
	 */
	public function __construct($connected_account_or_url, $endpoint = '', $params = array())
	{
		if (is_array($connected_account_or_url) && isset($connected_account_or_url['access_token'])) {
			$this->set_url($connected_account_or_url, $endpoint, $params);
		} elseif (!is_array($connected_account_or_url) && strpos($connected_account_or_url, 'https') !== false) {
			$this->url = $connected_account_or_url;
		} else {
			$this->url = '';
		}
		$this->params = $params;
	}

	/**
	 * If url needs to be generated from the connected account, endpoint,
	 * and params, this function is used to do so.
	 *
	 * @param string $url (API URL).
	 */
	public function set_url_from_args($url)
	{
		$this->url = $url;
	}

	/**
	 * GET API URL
	 *
	 * @return string
	 *
	 * @since 5.0
	 */
	public function get_url()
	{
		return $this->url;
	}

	/**
	 * If the server is unable to connect to the url, returns true
	 *
	 * @return bool
	 *
	 * @since 5.0
	 */
	public function is_wp_error()
	{
		return is_wp_error($this->response);
	}

	/**
	 * Connect to the Facebook API and record the response
	 *
	 * @since 5.0
	 */
	public function connect()
	{
		if (empty($this->url)) {
			$this->response = array();
			return;
		}
		$args = array(
			'timeout' => 20
		);
		$response = wp_safe_remote_get($this->url, $args);
		$body = json_decode(wp_remote_retrieve_body($response), true);
		$this->response = $response;
		if (is_wp_error($body) || isset($body['error'])) {
			$this->log_fb_error();
		} elseif (is_array($body) && array_key_exists('data', $body)) {
			// The mirror of log_fb_error(), and the recovery path the per-account
			// error bucket never had: a well-formed Graph payload with a 'data'
			// member and no 'error' member is proof this page is reachable with
			// the stored token, so anything recorded against it has to come out.
			//
			// Here and not in the feed render loops: those see only the merged
			// blob, cannot tell which source produced it, and one runs right
			// after the fetch that RECORDED the error. This method is the only
			// place that knows both that a fetch succeeded and which page id it
			// was for -- log_fb_error() resolves the same params['page_id'] on the
			// failure side, so the two arms cannot drift apart.
			//
			// is_array() is what keeps the failure shapes out, since each decodes
			// to null: a WP_Error and a 200 with an empty body both retrieve as
			// '', and an HTML error page or a body truncated mid-transfer is not
			// valid JSON. array_key_exists() rather than a truthiness test
			// because an empty 'data' array MUST still clear -- an empty page is
			// still proof the page was reachable.
			$page_id = isset($this->params['page_id']) ? $this->params['page_id'] : false;
			if (!empty($page_id)) {
				\cff_main()->cff_error_reporter->clear_account_errors($page_id);
			}

			// And the site-wide connection slot, which until now only
			// cff_fetchUrl() ever cleared (CFF_Utils.php:39, :191) -- a
			// different fetch path, used by the builder's source list, comments,
			// events and groups. That slot's contract has always been "the last
			// error sets it, the next success clears it"; this fetch path simply
			// never got the mirror, so a site whose last stored error was itself
			// critical stayed critical off the connection arm until one of those
			// other fetches happened to succeed.
			//
			// Safe because the slot is no longer load-bearing for either the
			// verdict or the copy: a still-dead account keeps
			// are_critical_errors() true through the per-account arm, and
			// get_critical_errors()'s per-account fallback renders that
			// account's own cause. Nothing goes silent.
			//
			// Outside the page_id guard on purpose: the slot is a single
			// site-wide value, not an account-addressed bucket, so it is
			// clearable on the strength of the successful fetch alone.
			\cff_main()->cff_error_reporter->remove_error('connection');
		}
	}

	/**
	 * Returns the response data from Facebook
	 *
	 * @return array|object
	 *
	 * @since 5.0
	 */
	public function get_data($only_body = false)
	{
		if ($this->is_wp_error()) {
			return array();
		}
		if (!empty($this->response['body'])) {
			$body = json_decode($this->response['body']);
			return $body;
		} else {
			return $this->response;
		}
	}


	/**
	 * Returns the response data from Facebook
	 *
	 * @return string
	 *
	 * @since 5.0
	 */
	public function get_json_data()
	{
		return wp_json_encode($this->get_data(), true);
	}

	/**
	 * Returns the response from Facebook
	 *
	 * @return array|object
	 *
	 * @since 5.0
	 */
	public function get_response()
	{
		return $this->response;
	}


	/**
	 * Log Error
	 *
	 * @since 5.0
	 */
	public function log_fb_error()
	{
		delete_option('cff_dismiss_critical_notice');
		$access_token_refresh_errors = array(10, 4, 200);
		$response = json_decode($this->response['body'], true);
		$page_id = isset($this->params['page_id']) ? $this->params['page_id'] : false;
		$api_error_code = $response['error']['code'];
		$ppca_error = false;
		if (strpos($response['error']['message'], 'Public Content Access') !== false) {
			$ppca_error = true;
		}

		if (in_array((int) $api_error_code, $access_token_refresh_errors, true) && !$ppca_error) {
			$pieces = explode('access_token=', $this->url);
			$accesstoken_parts = isset($pieces[1]) ? explode('&', $pieces[1]) : 'none';
			$accesstoken = $accesstoken_parts[0];

			$error = array(
				'accesstoken' => $accesstoken,
				'post_id' => get_the_ID(),
				'errorno' => $api_error_code
			);
			\cff_main()->cff_error_reporter->add_error('accesstoken', $error, $page_id);
		} else {
			\cff_main()->cff_error_reporter->add_error('api', $response, $page_id);
		}
	}
}
