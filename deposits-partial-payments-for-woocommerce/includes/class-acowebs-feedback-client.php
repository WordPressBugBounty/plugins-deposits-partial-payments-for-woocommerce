<?php
/**
 * Shared client for the Acowebs Feedback API.
 *
 * Several Acowebs plugins ship this same file, so the class, the constant and
 * the filter names must stay identical in all of them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ACOWEBS_FEEDBACK_API' ) ) {
	define( 'ACOWEBS_FEEDBACK_API', 'https://audience.acowebs.com' );
}

if ( ! class_exists( 'Acowebs_Feedback_Client' ) ) {

	class Acowebs_Feedback_Client {

		/**
		 * Base URL of the API, without a trailing slash.
		 *
		 * @return string
		 */
		public static function api_url() {
			return untrailingslashit( (string) apply_filters( 'acowebs_feedback_api_url', ACOWEBS_FEEDBACK_API ) );
		}

		/**
		 * Environment fields sent with a deactivation.
		 *
		 * @return array
		 */
		public static function site_info() {
			return array(
				'site_url'      => home_url(),
				'site_name'     => get_bloginfo( 'name' ),
				'wp_version'    => get_bloginfo( 'version' ),
				'wc_version'    => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'php_version'   => PHP_VERSION,
				'site_language' => get_locale(),
			);
		}

		/**
		 * Send a deactivation to the API without waiting for the answer.
		 * Never throws and never prints anything.
		 *
		 * @param array $args Fields for POST /v1/deactivation (plugin, edition, reason, ...).
		 * @return void
		 */
		public static function send_deactivation( array $args ) {
			try {
				$data = array_merge( self::site_info(), $args );

				// Drop null/empty values, but keep booleans (consent: false) and zeros.
				foreach ( $data as $key => $value ) {
					if ( null === $value || '' === $value || array() === $value ) {
						unset( $data[ $key ] );
					}
				}

				wp_remote_post(
					self::api_url() . '/v1/deactivation',
					array(
						'blocking' => false,
						'timeout'  => 5,
						'headers'  => array( 'Content-Type' => 'application/json' ),
						'body'     => wp_json_encode( $data ),
					)
				);
			} catch ( \Throwable $e ) {
				// Feedback must never get in the way of deactivating the plugin.
				unset( $e );
			}
		}
	}
}
