<?php

/**
 * Class CFF_Utils
 *
 * Contains miscellaneous CFF functions
 *
 * @since 2.19
 */

namespace CustomFacebookFeed;

use CustomFacebookFeed\SB_Facebook_Data_Encryption;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}



class CFF_Utils
{
	/**
	 * Get JSON object of feed data
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_fetchUrl($url, $check_admin = true)
	{
		$response = wp_safe_remote_get($url);
		do_action('cff_api_connect_response', $response, $url);

		if (! CFF_Utils::cff_is_wp_error($response)) {
			$feedData = wp_remote_retrieve_body($response);

			if (! CFF_Utils::cff_is_fb_error($feedData)) {
				\cff_main()->cff_error_reporter->remove_error('connection');

				$feedData = apply_filters('cff_filter_api_data', $feedData);

				return $feedData;
			} else {
				if (strpos($url, '&limit=') !== false) {
					CFF_Utils::cff_log_fb_error($feedData, $url, $check_admin);
				}


				$error = json_decode($feedData, true);

				// Criticality is not the right question for this write: it decides
				// whether the SOURCE is unusable, which is what paints "Source
				// Invalid" plus a Reconnect prompt in the builder. A missing
				// permission (code 10) and a rate limit are both critical for
				// the feed while leaving the stored token perfectly valid, so
				// they must not reach this column.
				$route_error = \CustomFacebookFeed\Token_Health\MetaErrorMap::extract($error);
				$invalidates = \CustomFacebookFeed\Token_Health\MetaErrorMap::invalidatesSource(
					$route_error['code'],
					$route_error['subcode'],
					$route_error['message']
				);

				// PPCA is the one cause the table cannot route: it has no Graph
				// code of its own and arrives as "(#10) ... Public Content
				// Access", and code 10 alone means a scope is missing, which
				// leaves the source usable. PPCA does not -- the account does
				// not manage the page, so the feed can never work. Same single
				// predicate the copy layer uses, so the two cannot drift.
				if ($invalidates || \CustomFacebookFeed\CFF_Error_Reporter::is_ppca_error($route_error['message'])) {
					$parsed_url = parse_url($url);
					if (! empty($parsed_url['query'])) {
						parse_str($parsed_url['query'], $parsed);

						if (isset($parsed['access_token'])) {
							$args = [
								'access_token' => $parsed['access_token']
							];
							$source_data = \CustomFacebookFeed\Builder\CFF_Db::source_query($args);

							if ($source_data) {
								foreach ($source_data as $source) {
									\CustomFacebookFeed\Builder\CFF_Source::add_error($source['account_id'], $feedData);
								}
							}
						}
					}
				}

				return $feedData;
			}
		} else {
			if (strpos($url, '&limit=') !== false) {
				CFF_Utils::cff_log_wp_error($response, $url);
			}

			return '{}';
		}
	}


	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	/**
	 * Strips HTML markup from a free-text value fetched from the Facebook API without
	 * otherwise altering the text.
	 *
	 * Removes exactly what a browser's HTML tokenizer would treat as markup: a "<" followed
	 * by an ASCII letter, "/", "!" or "?" (a tag, end tag, comment/doctype or processing
	 * instruction), through the next ">" or, for an unterminated tag, the end of the string.
	 * A "<" followed by anything else ("I <3 this", "a < b") is emitted by browsers as literal
	 * text and is left alone. Runs until stable (bounded by the string length, which every pass
	 * shrinks) so a nested construct such as "<<b>img ...>" cannot reassemble into a tag after
	 * one pass, and fails closed if anything tag-shaped somehow survives.
	 *
	 * This is used instead of wp_kses() because the value is later bound as *text*
	 * (Vue v-text in the builder preview) and wp_kses() entity-normalizes the string
	 * ("&" becomes "&amp;", "<3" is treated as a tag and dropped), which shows up literally
	 * in a text sink. Output escaping still happens at every sink; this is defence in depth.
	 *
	 * @param string $text
	 *
	 * @return string
	 *
	 * @since 4.13.1
	 */
	public static function strip_untrusted_html( $text )
	{
		if (! is_string($text) || '' === $text) {
			return $text;
		}

		$text = wp_kses_no_null($text);

		// Every changing pass removes at least one "<", so the loop always reaches a fixed
		// point. Cap the passes anyway: beyond ~100 levels of deliberately nested "<" the
		// fail-closed branch below drops every "<" in one step instead of paying O(n^2).
		$max_passes = min(strlen($text) + 1, 100);
		$passes = 0;
		do {
			$previous = $text;
			$text = preg_replace('/<(?=[A-Za-z\/!?])[^>]*(?:>|$)/s', '', $text);
			$passes++;
		} while (null !== $text && $previous !== $text && $passes < $max_passes);

		if (null === $text) {
			return '';
		}

		// Fail closed: if anything tag-shaped is still present (preg_replace hit a limit),
		// drop every "<" rather than return live markup from a function named "strip".
		if (preg_match('/<[A-Za-z\/!?]/', $text)) {
			$text = str_replace('<', '', $text);
		}

		return $text;
	}

	public static function cff_desc_tags($description)
	{
		preg_match_all("/@\[(.*?)\]/", $description, $cff_tag_matches);
		$replace_strings_arr = array();
		foreach ($cff_tag_matches[1] as $cff_tag_match) {
			$cff_tag_parts = explode(':', $cff_tag_match);
			$replace_strings_arr[] = '<a href="https://facebook.com/' . $cff_tag_parts[0] . '">' . $cff_tag_parts[2] . '</a>';
		}
		$cff_tag_iterator = 0;
		$cff_description_tagged = '';
		$cff_text_split = preg_split("/@\[(.*?)\]/", $description);
		foreach ($cff_text_split as $cff_desc_split) {
			if ($cff_tag_iterator < count($replace_strings_arr)) {
				$cff_description_tagged .= $cff_desc_split . $replace_strings_arr[ $cff_tag_iterator ];
			} else {
				$cff_description_tagged .= $cff_desc_split;
			}
			$cff_tag_iterator++;
		}

		return $cff_description_tagged;
	}


	/**
	 * Sort message tags by offset value
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cffSortTags($a, $b)
	{
		return $a['offset'] - $b['offset'];
	}


	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_is_wp_error($response)
	{
		return is_wp_error($response);
	}


	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_log_wp_error($response, $url)
	{
		if (is_wp_error($response)) {
			delete_option('cff_dismiss_critical_notice');

			$admin_message_error = '';
			if (isset($response) && isset($response->errors)) {
				foreach ($response->errors as $key => $item) {
					$admin_message_error .= ' ' . $key . ' - ' . $item[0];
				}
			}

			$admin_message = __('Error connecting to the Facebook API:', 'custom-facebook-feed') . ' ' . $admin_message_error;
			$public_message = __('Unable to make remote requests to the Facebook API. Log in as an admin to view more details.', 'custom-facebook-feed');
			$frontend_directions = '<p class="cff-error-directions"><a href="https://smashballoon.com/custom-facebook-feed/docs/errors/?utm_campaign=facebook-free&utm_source=error-message&utm_medium=docs" target="_blank" rel="noopener">' . __('Directions on How to Resolve This Issue', 'custom-facebook-feed')  . '</a></p>';
			$backend_directions = '<a class="button button-primary" href="https://smashballoon.com/custom-facebook-feed/docs/errors/?utm_campaign=facebook-free&utm_source=error-message&utm_medium=docs" target="_blank" rel="noopener">' . __('Directions on How to Resolve This Issue', 'custom-facebook-feed')  . '</a>';
			$error = array(
				'accesstoken' => 'none',
				'public_message' => $public_message,
				'admin_message' => $admin_message,
				'frontend_directions' => $frontend_directions,
				'backend_directions' => $backend_directions,
				'post_id' => get_the_ID(),
				'errorno' => 'wp_remote_get'
			);
			\cff_main()->cff_error_reporter->add_error('wp_remote_get', $error);
		} else {
			\cff_main()->cff_error_reporter->remove_error('connection');
		}
	}


	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_is_fb_error($response)
	{
		return ($response !== null && strpos($response, '{"error":') === 0);
	}


	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_log_fb_error($response, $url, $check_admin = true)
	{
		if (is_admin() && $check_admin == true) {
			return;
		}
		delete_option('cff_dismiss_critical_notice');

		$access_token_refresh_errors = array( 10, 4, 200 );

		$response = json_decode($response, true);
		$api_error_code = $response['error']['code'];

		// Page Public Content Access error
		$ppca_error = false;
		if (strpos($response['error']['message'], 'Public Content Access') !== false) {
			$ppca_error = true;
		}

		if (in_array((int)$api_error_code, $access_token_refresh_errors, true) && !$ppca_error) {
			$pieces = explode('access_token=', $url);
			$accesstoken_parts = isset($pieces[1]) ? explode('&', $pieces[1]) : 'none';
			$accesstoken = $accesstoken_parts[0];

			$api_error_number_message = sprintf(__('API Error %s:', 'custom-facebook-feed'), $api_error_code);
			$link = admin_url('admin.php?page=cff-settings');
			$error = array(
				'accesstoken' => $accesstoken,
				'post_id' => get_the_ID(),
				'errorno' => $api_error_code
			);

			\cff_main()->cff_error_reporter->add_error('accesstoken', $error);
		} else {
			\cff_main()->cff_error_reporter->add_error('api', $response);
		}
	}


	/**
	 * Make links into span instead when the post text is made clickable
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_wrap_span($text)
	{
		$pattern  = '#\b(([\w-]+://?|www[.])[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/)))#';
		return preg_replace_callback($pattern, array('CustomFacebookFeed\CFF_Utils','cff_wrap_span_callback'), $text);
	}

	/**
	 * @return Json Encode
	 *
	 * @since 2.1.1
	 */
	public static function cff_json_encode($thing)
	{
		return wp_json_encode($thing);
	}

	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_wrap_span_callback($matches)
	{
		$max_url_length = 100;
		$max_depth_if_over_length = 2;
		$ellipsis = '&hellip;';
		$target = 'target="_blank"';
		$url_full = $matches[0];
		$url_short = '';
		if (strlen($url_full) > $max_url_length) {
			$parts = parse_url($url_full);
			$url_short = $parts['scheme'] . '://' . preg_replace('/^www\./', '', $parts['host']) . '/';
			$path_components = explode('/', trim($parts['path'], '/'));
			foreach ($path_components as $dir) {
				$url_string_components[] = $dir . '/';
			}
			if (!empty($parts['query'])) {
				$url_string_components[] = '?' . $parts['query'];
			}
			if (!empty($parts['fragment'])) {
				$url_string_components[] = '#' . $parts['fragment'];
			}
			for ($k = 0; $k < count($url_string_components); $k++) {
				$curr_component = $url_string_components[$k];
				if ($k >= $max_depth_if_over_length || strlen($url_short) + strlen($curr_component) > $max_url_length) {
					if ($k == 0 && strlen($url_short) < $max_url_length) {
					// Always show a portion of first directory
						$url_short .= substr($curr_component, 0, $max_url_length - strlen($url_short));
					}
					$url_short .= $ellipsis;
					break;
				}
				$url_short .= $curr_component;
			}
		} else {
			$url_short = $url_full;
		}
		return "<span class='cff-break-word'>$url_short</span>";
	}


	/**
	 * Use the timezone to offset the date as all post dates are in UTC +0000
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_set_timezone($original, $cff_timezone)
	{
		$cff_date_time = new \DateTime(date('m/d g:i a'), new \DateTimeZone('UTC'));
		$cff_date_time->setTimeZone(new \DateTimeZone($cff_timezone));
		$cff_date_time_offset = $cff_date_time->getOffset();

		$original = $original + $cff_date_time_offset;

		return $original;
	}


	/**
	 * Time stamp functison - used for posts
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_getdate($original, $date_format, $custom_date, $cff_date_translate_strings, $cff_timezone)
	{
		// Offset the date by the timezone
		$new_time = CFF_Utils::cff_set_timezone($original, $cff_timezone);
		switch ($date_format) {
			case '2':
				$print = date_i18n('F jS, g:i a', $new_time);
				break;
			case '3':
				$print = date_i18n('F jS', $new_time);
				break;
			case '4':
				$print = date_i18n('D F jS', $new_time);
				break;
			case '5':
				$print = date_i18n('l F jS', $new_time);
				break;
			case '6':
				$print = date_i18n('D M jS, Y', $new_time);
				break;
			case '7':
				$print = date_i18n('l F jS, Y', $new_time);
				break;
			case '8':
				$print = date_i18n('l F jS, Y - g:i a', $new_time);
				break;
			case '9':
				$print = date_i18n("l M jS, 'y", $new_time);
				break;
			case '10':
				$print = date_i18n('m.d.y', $new_time);
				break;
			case '11':
				$print = date_i18n('m/d/y', $new_time);
				break;
			case '12':
				$print = date_i18n('d.m.y', $new_time);
				break;
			case '13':
				$print = date_i18n('d/m/y', $new_time);
				break;
			case '14':
				$print = date_i18n('d-m-Y, G:i', $new_time);
				break;
			case '15':
				$print = date_i18n('jS F Y, G:i', $new_time);
				break;
			case '16':
				$print = date_i18n('d M Y, G:i', $new_time);
				break;
			case '17':
				$print = date_i18n('l jS F Y, G:i', $new_time);
				break;
			case '18':
				$print = date_i18n('m.d.y - G:i', $new_time);
				break;
			case '19':
				$print = date_i18n('d.m.y - G:i', $new_time);
				break;
			default:
				$cff_second = $cff_date_translate_strings['cff_translate_second'];
				$cff_seconds = $cff_date_translate_strings['cff_translate_seconds'];
				$cff_minute = $cff_date_translate_strings['cff_translate_minute'];
				$cff_minutes = $cff_date_translate_strings['cff_translate_minutes'];
				$cff_hour = $cff_date_translate_strings['cff_translate_hour'];
				$cff_hours = $cff_date_translate_strings['cff_translate_hours'];
				$cff_day = $cff_date_translate_strings['cff_translate_day'];
				$cff_days = $cff_date_translate_strings['cff_translate_days'];
				$cff_week = $cff_date_translate_strings['cff_translate_week'];
				$cff_weeks = $cff_date_translate_strings['cff_translate_weeks'];
				$cff_month = $cff_date_translate_strings['cff_translate_month'];
				$cff_months = $cff_date_translate_strings['cff_translate_months'];
				$cff_year = $cff_date_translate_strings['cff_translate_years'];
				$cff_years = $cff_date_translate_strings['cff_translate_years'];
				$cff_ago = $cff_date_translate_strings['cff_translate_ago'];

				$periods = array($cff_second, $cff_minute, $cff_hour, $cff_day, $cff_week, $cff_month, $cff_year, "decade");
				$periods_plural = array($cff_seconds, $cff_minutes, $cff_hours, $cff_days, $cff_weeks, $cff_months, $cff_years, "decade");

				$lengths = array("60","60","24","7","4.35","12","10");
				$now = time();

			// is it future date or past date
				if ($now > $original) {
					$difference = $now - $original;
					$tense = $cff_ago;
				} else {
					$difference = $original - $now;
					$tense = $cff_ago;
				}
				for ($j = 0; $difference >= $lengths[$j] && $j < count($lengths) - 1; $j++) {
					$difference /= $lengths[$j];
				}

				$difference = round($difference);

				if ($difference != 1) {
					$periods[$j] = $periods_plural[$j];
				}
				$print = "$difference $periods[$j] {$tense}";

				break;
		}
		if (!empty($custom_date)) {
			$print = date_i18n($custom_date, $new_time);
		}

		return $print;
	}


	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_eventdate($original, $date_format, $custom_date)
	{
		switch ($date_format) {
			case '2':
				$print = date_i18n('<k>F jS, </k>g:ia', $original);
				break;
			case '3':
				$print = date_i18n('g:ia<k> - F jS</k>', $original);
				break;
			case '4':
				$print = date_i18n('g:ia<k>, F jS</k>', $original);
				break;
			case '5':
				$print = date_i18n('<k>l F jS - </k> g:ia', $original);
				break;
			case '6':
				$print = date_i18n('<k>D M jS, Y, </k>g:iA', $original);
				break;
			case '7':
				$print = date_i18n('<k>l F jS, Y, </k>g:iA', $original);
				break;
			case '8':
				$print = date_i18n('<k>l F jS, Y - </k>g:ia', $original);
				break;
			case '9':
				$print = date_i18n("<k>l M jS, 'y</k>", $original);
				break;
			case '10':
				$print = date_i18n('<k>m.d.y - </k>g:iA', $original);
				break;
			case '11':
				$print = date_i18n('<k>m/d/y, </k>g:ia', $original);
				break;
			case '12':
				$print = date_i18n('<k>d.m.y - </k>g:iA', $original);
				break;
			case '13':
				$print = date_i18n('<k>d/m/y, </k>g:ia', $original);
				break;
			case '14':
				$print = date_i18n('<k>M j, </k>g:ia', $original);
				break;
			case '15':
				$print = date_i18n('<k>M j, </k>G:i', $original);
				break;
			case '16':
				$print = date_i18n('<k>d-m-Y, </k>G:i', $original);
				break;
			case '17':
				$print = date_i18n('<k>jS F Y, </k>G:i', $original);
				break;
			case '18':
				$print = date_i18n('<k>d M Y, </k>G:i', $original);
				break;
			case '19':
				$print = date_i18n('<k>l jS F Y, </k>G:i', $original);
				break;
			case '20':
				$print = date_i18n('<k>m.d.y - </k>G:i', $original);
				break;
			case '21':
				$print = date_i18n('<k>d.m.y - </k>G:i', $original);
				break;
			default:
				$print = date_i18n('<k>F j, Y, </k>g:ia', $original);
				break;
		}
		if (!empty($custom_date)) {
			$print = date_i18n($custom_date, $original);
		}
		return $print;
	}


	/**
	 * Use custom stripos function if it's not available (only available in PHP 5+)
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function stripos($haystack, $needle)
	{
		if (empty(stristr($haystack, $needle))) {
			$haystack = is_array($haystack) ? implode(" ", $haystack) : $haystack;
		}
		if (empty(stristr($haystack, $needle))) {
			return false;
		}
		return strpos($haystack, stristr($haystack, $needle));
	}


	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_stripos_arr($haystack, $needle)
	{
		if (!is_array($needle)) {
			$needle = array($needle);
		}
		foreach ($needle as $what) {
			if (($pos = CFF_Utils::stripos($haystack, ltrim($what))) !== false) {
				return $pos;
			}
		}
		return false;
	}


	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_mb_substr_replace($string, $replacement, $start, $length = null)
	{
		if (is_array($string)) {
			$num = count($string);
			// $replacement
			$replacement = is_array($replacement) ? array_slice($replacement, 0, $num) : array_pad(array($replacement), $num, $replacement);
			// $start
			if (is_array($start)) {
				$start = array_slice($start, 0, $num);
				foreach ($start as $key => $value) {
					$start[$key] = is_int($value) ? $value : 0;
				}
			} else {
				$start = array_pad(array($start), $num, $start);
			}
			// $length
			if (!isset($length)) {
				$length = array_fill(0, $num, 0);
			} elseif (is_array($length)) {
				$length = array_slice($length, 0, $num);
				foreach ($length as $key => $value) {
					$length[$key] = isset($value) ? (is_int($value) ? $value : $num) : 0;
				}
			} else {
				$length = array_pad(array($length), $num, $length);
			}
			// Recursive call
			return array_map(__FUNCTION__, $string, $replacement, $start, $length);
		}
		preg_match_all('/./us', (string)$string, $smatches);
		preg_match_all('/./us', (string)$replacement, $rmatches);
		if ($length === null) {
			$length = mb_strlen($string);
		}
		array_splice($smatches[0], $start, $length, $rmatches[0]);
		return join($smatches[0]);
	}


	/**
	 * Push to assoc array
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_array_push_assoc($array, $key, $value)
	{
		$array[$key] = $value;
		return $array;
	}


	/**
	 * Convert string to slug
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_to_slug($string)
	{
		return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $string)));
	}


	/**
	 * Convert string to slug
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_get_utc_offset()
	{
		return get_option('gmt_offset', 0) * HOUR_IN_SECONDS;
	}


	public static function cff_schedule_report_email()
	{
		$options = get_option('cff_style_settings');
		$input = isset($options[ 'email_notification' ]) ? $options[ 'email_notification' ] : 'monday';
		$timestamp = strtotime('next ' . $input);
		$timestamp = $timestamp + (3600 * 24 * 7);
		$six_am_local = $timestamp + CFF_Utils::cff_get_utc_offset() + (6 * 60 * 60);
		wp_schedule_event($six_am_local, 'cffweekly', 'cff_feed_issue_email');
	}


	/**
	 * Safely decode a JSON payload.
	 *
	 * PHP 8 throws a TypeError when json_decode() receives a non-string, and the
	 * feed pipeline can legitimately hand us data that is already decoded — e.g.
	 * the customizer path yields an empty array when the API returns an error
	 * and no backup cache exists. Pass arrays/objects through untouched, decode
	 * strings, and return null for anything else (PHP 7 only warned here; null is
	 * what every caller already tolerates).
	 *
	 * @param mixed $json  Raw JSON string, or an already-decoded value.
	 * @param bool  $assoc Return associative arrays instead of objects.
	 *
	 * @return mixed
	 *
	 * @since 4.13.1
	 */
	public static function cff_json_decode($json, $assoc = false)
	{
		if (is_array($json) || is_object($json)) {
			return $json;
		}
		if (!is_string($json) || $json === '') {
			return null;
		}
		return json_decode($json, $assoc);
	}

	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_is_pro_version()
	{
		return defined('CFFWELCOME_VER');
	}

	/**
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function cff_get_set_cache($cff_posts_json_url, $transient_name, $cff_cache_time, $cache_seconds, $data_att_html, $cff_show_access_token, $access_token, $backup = false)
	{
		$cache_seconds = max($cache_seconds, 60);

		// Trying to use new cache table
		$shortcode_atts = is_array($data_att_html) ? $data_att_html : json_decode(str_replace('&quot;', '"', $data_att_html), true);

		if (! empty($shortcode_atts['feed'])) {
			$feed_id = (int)$shortcode_atts['feed'];
			$is_legacy = false;
		} else {
			$feed_id = $transient_name;
			$is_legacy = true;
		}

		$feed_page = isset($shortcode_atts['feedPage']) ? $shortcode_atts['feedPage'] : 1;
		$feed_cache = new CFF_Cache($feed_id, $feed_page, $cache_seconds, $is_legacy);
		$feed_cache->retrieve_and_set();

		$cache_type = 'posts';
		if (strpos($transient_name, 'cff_header_') !== false) {
			$cache_type = 'header';
		}

		// no pages in free
		$cache_type_page = $cache_type;
		if ($cache_type === 'posts' && $feed_page > 1) {
			$cache_type_page = 'posts_' . $feed_page;
		}

		if ($feed_cache->is_expired($cache_type)) {
			// Get the contents of the Facebook page
			$posts_json = CFF_Utils::cff_fetchUrl($cff_posts_json_url);

			if ($is_legacy && $cache_type === 'header') {
				$feed_cache->update_or_insert($cache_type, $posts_json);
			}

			// Check whether any data is returned from the API. If it isn't then don't cache the error response and instead keep checking the API on every page load until data is returned.
			$FBdata = json_decode($posts_json);

			// Both captured before the ->data unwrap below, while $FBdata is still
			// the decoded response object. A successful-but-empty payload has to
			// be distinguishable from a transport failure, an error payload, and
			// -- the case a substring test gets wrong -- a body that was cut off
			// mid-transfer but still begins {"data": (SMASH-1808).
			$cff_api_errored = isset($FBdata->error);
			$cff_api_zero_posts = isset($FBdata->data)
				&& is_array($FBdata->data)
				&& count($FBdata->data) === 0;

			// Check whether the JSON is wrapped in a "data" property as if it doesn't then it's a featured post
			$prefix_data = '{"data":';
			(substr($posts_json, 0, strlen($prefix_data)) == $prefix_data) ? $cff_featured_post = false : $cff_featured_post = true;

			// Add API URL to beginning of JSON array
			$prefix = '{';
			if (substr($posts_json, 0, strlen($prefix)) == $prefix) {
				$posts_json = substr($posts_json, strlen($prefix));
			}

			// Encode and replace quotes so can be stored as a string
			$data_att_html = str_replace('"', '&quot;', json_encode($data_att_html));
			$posts_json = '{"api_url":"' . $cff_posts_json_url . '", "shortcode_options":"' . $data_att_html . '", ' . $posts_json;

			// If it's a featured post then it doesn't contain 'data'
			if (!$cff_featured_post) {
				$FBdata = $FBdata->data;
			}

			// Check the API response
			if (!empty($FBdata)) {
				// Error returned by API
				if (isset($FBdata->error)) {
					// Cache the error JSON so doesn't keep making repeated requests
					// See if a backup cache exists
					if (false !== $feed_cache->get($cache_type . '_backup')) {
						$posts_cache = $feed_cache->get($cache_type . '_backup');
						$posts_json = is_null($posts_cache) ? '{}' : $posts_cache;
						// Add error message to backup cache so can be displayed at top of feed
						isset($FBdata->error->message) ? $error_message = $FBdata->error->message : $error_message = '';
						isset($FBdata->error->type) ? $error_type = $FBdata->error->type : $error_type = '';
						$prefix = '{';
						if (substr($posts_json, 0, strlen($prefix)) == $prefix) {
							$posts_json = substr($posts_json, strlen($prefix));
						}
						$error_json = '{"cached_error": { "message": "' . $error_message . '", "type": "' . $error_type . '" }';
						$error_json .= !empty($posts_json) ? ', ' . $posts_json : '}';
						$posts_json = $error_json;

						// Legacy feed served from backup after an API error — only
						// when backup content actually replaced the feed: the guard
						// above is always-true for the header path, whose backup is
						// never hydrated (SMASH-1808).
						if ('posts' === $cache_type && !empty($posts_cache)) {
							BackupCacheMonitor::record_backup_serve($transient_name);
						}
					}

					// Posts data returned by API
				} else {
					// If a backup should be created for this data then create one
					if ($backup) {
						$feed_cache->update_or_insert($cache_type . '_backup', $posts_json);
					}

					if ($cache_type === 'posts') {
						$feed_cache->after_new_posts_retrieved();
					}

					// Fresh content committed — the feed is healthy again.
					if ('posts' === $cache_type) {
						BackupCacheMonitor::record_fresh_content($transient_name);
					}
				}

				$feed_cache->update_or_insert($cache_type, $posts_json);
			}

			// A successful response with zero posts -- an empty page, or every
			// post filtered out by the feed's own options -- is still proof the
			// connection works, so it has to clear any staleness entry. It does
			// not reach the branch above: the ->data unwrap turns {"data":[]}
			// into an empty array, and the !empty($FBdata) guard then skips the
			// whole block.
			//
			// The test is deliberately on the DECODED object, not on the body's
			// prefix. An earlier version gated on !$cff_featured_post, which is
			// a raw substring test -- so a response truncated mid-transfer
			// ({"data":[{"id":"1","message":"hel) still matched the prefix,
			// decoded to null, and cleared the entry as if the feed were
			// healthy. That silently defeats the very signal this feature
			// exists to raise. isset() also excludes {"data":null}, and reading
			// the decoded object fixes the mirror-image miss, where a payload
			// with leading whitespace failed the prefix test and never cleared.
			if ('posts' === $cache_type && !$cff_api_errored && $cff_api_zero_posts) {
				BackupCacheMonitor::record_fresh_content($transient_name);
			}
		} else {
			$posts_json = $feed_cache->get($cache_type_page);
			if (strpos($posts_json, '"error":{"message":') !== false) {
				// Use backup cache if exists
				$posts_json = $feed_cache->get($cache_type . '_backup');

				// Legacy feed served from backup on a warm cache hit (SMASH-1808).
				if ('posts' === $cache_type && is_string($posts_json) && '' !== $posts_json) {
					BackupCacheMonitor::record_backup_serve($transient_name);
				}
			}

			// If we can't find the transient then fall back to just getting the json from the api
			if ($posts_json == false) {
				$posts_json = CFF_Utils::cff_fetchUrl($cff_posts_json_url);
			}
		}

		return $posts_json;
	}


	/**
	 * Check if On
	 * Function to check if a shortcode options is set to ON or TRUE
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 * @return boolean
	 */
	public static function check_if_on($value)
	{
		return ( isset($value) && !empty($value) && ( $value == 'true' || $value == 'on') ) ?  true : false;
	}

	/**
	 * Check if On
	 * Function to check if a shortcode options is set to ON or TRUE
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 * @return boolean
	 */
	public static function check_if_onexist($value)
	{
		return ( ( isset($value) )  ) ?  true : false;
	}

	/**
	 * Check Value
	 * Function to check a value if exists or return a default one
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 * @return mixed
	 */
	public static function return_value($value, $default = '')
	{
		return ( isset($value) && !empty($value) ) ?  $value  : $default;
	}


	/**
	 * Get CSS value
	 * Checks if the value is a valid CSS distance
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 * @return string
	 */
	public static function get_css_distance($value)
	{
		return ( is_numeric(substr($value, -1, 1)) ) ? $value . 'px' : $value;
	}


	/**
	 *
	 *
	 * This function will get the Profile Pic, Cover, Name, About
	 * For the visual header display
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function fetch_header_data($page_id, $cff_is_group, $access_token, $cff_cache_time, $cff_multifeed_active = false, $data_att_html = array())
	{
		 // Create Transient Name
			$transient_name = 'cff_header_' . $page_id;
			$transient_name = substr($transient_name, 0, 45);

			// These fields only apply to pages
			!$cff_is_group ? $page_only_fields = ',fan_count,about' : $page_only_fields = '';

			$header_access_token = $access_token;
		if (is_array($access_token)) {
			$header_access_token = reset($access_token);
			if (empty($header_access_token)) {
				$header_access_token = key($access_token);
			}
		}

			$encryption = new SB_Facebook_Data_Encryption();
			$header_access_token = $encryption->decrypt($header_access_token) ? $encryption->decrypt($header_access_token) : $header_access_token;


			$header_details_json_url = 'https://graph.facebook.com/v23.0/' . $page_id . '?fields=id,picture.height(150).width(150),cover,name,link' . $page_only_fields . '&access_token=' . $header_access_token;

			// Get the data
			$header_details = CFF_Utils::cff_get_set_cache($header_details_json_url, $transient_name, $cff_cache_time, WEEK_IN_SECONDS, $data_att_html, false, $access_token, true);
			$header_details = json_decode($header_details);
			return $header_details;
	}


	/**
	 *
	 *
	 * Print Template
	 * returns an HTML Template
	 *
	 * @access public
	 * @static
	 * @since 2.19
	 */
	public static function print_template_part($template_name, $args = array(), $this_class = null)
	{
		extract($args);
		ob_start();
		include trailingslashit(CFF_PLUGIN_DIR) . 'templates/' . $template_name . '.php';
		$template = ob_get_contents();
		ob_get_clean();
		return $template;
	}

	/**
	 *
	 * Get Connected Accounts
	 *
	 * @since 2.19
	 */
	public static function cff_get_connected_accounts()
	{
		$cff_connected_accounts = get_option('cff_connected_accounts', array());
		if (!is_array($cff_connected_accounts) && !empty($cff_connected_accounts)) {
			$cff_connected_accounts = str_replace('\"', '"', $cff_connected_accounts);
			$cff_connected_accounts = str_replace("\'", "'", $cff_connected_accounts);
			$cff_connected_accounts = json_decode($cff_connected_accounts, true);
		}
		if (!is_array($cff_connected_accounts) || $cff_connected_accounts == null) {
			$cff_connected_accounts = [];
		}
		return $cff_connected_accounts;
	}
	/**
	 *
	 * Get Connected Accounts
	 *
	 * @since 3.18
	 */
	public static function cff_get_connected_accounts_object()
	{
		$cff_connected_accounts = get_option('cff_connected_accounts', array());
		if (!empty($cff_connected_accounts)) {
			$cff_connected_accounts = str_replace('\"', '"', $cff_connected_accounts);
			$cff_connected_accounts = str_replace("\'", "'", $cff_connected_accounts);
			$cff_connected_accounts = json_decode($cff_connected_accounts);
		}
		$check_array = (array) $cff_connected_accounts;
		if (empty($check_array)) {
			$cff_connected_accounts = false;
		}
		return $cff_connected_accounts;
	}

	/**
	 * Summary of get_post_text
	 *
	 * @param array $post
	 *
	 * @return string
	 */
	public static function get_post_text($post)
	{
		if (!empty($post['story'])) {
			return $post['story'];
		}
		if (!empty($post['message'])) {
			return $post['message'];
		}
		if (!empty($post['name']) && empty($post['story']) && empty($post['message'])) {
			return $post['name'];
		}
		return '';
	}

	/**
	 * Summary of parse_json_data
	 *
	 * @param mixed $data
	 *
	 * @return array
	 */
	public static function parse_json_data($data)
	{
		$json = json_decode($data, true);
		if (is_string($json)) {
			$json = json_decode($json, true);
		}
		return is_array($json) ? $json : [];
	}
}
