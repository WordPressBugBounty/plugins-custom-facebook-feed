<?php
/**
 * Sends the usage report payload to the API.
 *
 * @package CustomFacebookFeed\UsageTracking\Core
 * @since 4.0
 */

namespace CustomFacebookFeed\UsageTracking\Core;

use CustomFacebookFeed\UsageTracking\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sender {

	/**
	 * Send the usage report payload to the API.
	 * Skips send if payload exceeds max size to avoid timeouts; uses longer timeout for large bodies.
	 *
	 * @param array $payload Full JSON-serializable payload.
	 * @return bool True if request was sent successfully (2xx), false otherwise.
	 */
	public function send( array $payload ) {
		$url  = Config::get_usage_report_url();
		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return false;
		}

		$max_bytes = (int) apply_filters( 'cff_smash_usage_tracking_max_payload_bytes', Config::MAX_PAYLOAD_BYTES );
		if ( $max_bytes > 0 && strlen( $body ) > $max_bytes ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && function_exists( 'error_log' ) ) {
				error_log( '[CFF Usage Tracking] Payload size ' . strlen( $body ) . ' exceeds max ' . $max_bytes . ', send skipped.' );
			}
			return false;
		}

		$timeout = (int) apply_filters( 'cff_smash_usage_tracking_request_timeout', Config::REQUEST_TIMEOUT );
		$timeout = max( 15, min( 120, $timeout ) );

		$response = wp_remote_post(
            $url,
            array(
				'method'      => 'POST',
				'timeout'     => $timeout,
				'redirection' => 5,
				'httpversion' => '1.1',
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
				),
				'body'        => $body,
				'user-agent'  => 'CFF/' . (defined( 'CFFVER' ) ? CFFVER : '') . '; ' . get_bloginfo( 'url' ),
            )
        );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}
