<?php
/**
 * Acowebs feedback: deactivation popup and newsletter opt-in notice.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-acowebs-feedback-client.php';

class AWCDP_Feedback {

	/**
	 * Canonical plugin name. The Free and PRO builds must send exactly this string.
	 */
	const PLUGIN_NAME = 'Deposits & Partial Payments';

	/**
	 * This codebase is the wordpress.org (free) build.
	 */
	const EDITION = 'free';

	/**
	 * Slug of the plugin's row on plugins.php (the wordpress.org slug).
	 */
	const ROW_SLUG = 'deposits-partial-payments-for-woocommerce';

	/**
	 * User-meta flag shared by all Acowebs plugins.
	 */
	const OPTIN_META = 'acowebs_optin_done';

	const NONCE = 'awcdp_feedback';

	/**
	 * @var string Main plugin file.
	 */
	private $file;

	/**
	 * @var string
	 */
	private $version;

	public function __construct( $file, $version ) {
		$this->file    = $file;
		$this->version = $version;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_footer', array( $this, 'render_deactivation_modal' ) );
		add_action( 'admin_notices', array( $this, 'render_newsletter_notice' ) );

		add_action( 'wp_ajax_awcdp_deactivation_feedback', array( $this, 'ajax_deactivation_feedback' ) );
		add_action( 'wp_ajax_awcdp_newsletter_optin', array( $this, 'ajax_newsletter_optin' ) );
		add_action( 'wp_ajax_awcdp_newsletter_dismiss', array( $this, 'ajax_newsletter_dismiss' ) );
	}

	/**
	 * Display name used in the UI copy.
	 *
	 * @return string
	 */
	private function display_name() {
		return __( 'Deposits & Partial Payments', 'deposits-partial-payments-for-woocommerce' );
	}

	/**
	 * @return string Current admin screen id, or ''.
	 */
	private function screen_id() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return ( $screen && isset( $screen->id ) ) ? (string) $screen->id : '';
	}

	private function is_plugins_screen() {
		return in_array( $this->screen_id(), array( 'plugins', 'plugins-network' ), true ) && current_user_can( 'activate_plugins' );
	}

	/**
	 * The notice shows on the dashboard and on this plugin's own admin pages,
	 * to administrators who have not subscribed or dismissed it yet.
	 */
	private function should_show_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), self::OPTIN_META, true ) ) {
			return false;
		}
		$screen_id = $this->screen_id();
		return 'dashboard' === $screen_id || false !== strpos( $screen_id, 'awcdp_' );
	}

	public function enqueue() {
		$on_plugins = $this->is_plugins_screen();
		$on_notice  = $this->should_show_notice();
		if ( ! $on_plugins && ! $on_notice ) {
			return;
		}

		$assets_url = trailingslashit( plugins_url( '/assets/', $this->file ) );

		// Shared handle: when several Acowebs plugins are active the kit is loaded once.
		wp_enqueue_style( 'acowebs-feedback-ui', $assets_url . 'css/acowebs-feedback-ui.css', array(), '1.0.0' );

		wp_enqueue_script( 'awcdp-feedback', $assets_url . 'js/awcdp-feedback.js', array(), $this->version, true );
		wp_localize_script(
			'awcdp-feedback',
			'awcdp_feedback',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'slug'     => self::ROW_SLUG,
				'basename' => plugin_basename( $this->file ),
				'i18n'     => array(
					'sending'     => __( 'Sending…', 'deposits-partial-payments-for-woocommerce' ),
					'subscribing' => __( 'Subscribing…', 'deposits-partial-payments-for-woocommerce' ),
					'error'       => __( 'Something went wrong. Please try again.', 'deposits-partial-payments-for-woocommerce' ),
				),
			)
		);
	}

	public function render_deactivation_modal() {
		if ( ! $this->is_plugins_screen() ) {
			return;
		}
		$user        = wp_get_current_user();
		$plugin_name = $this->display_name();
		$user_name   = $user->display_name;
		$user_email  = $user->user_email;
		include plugin_dir_path( __FILE__ ) . 'views/feedback-deactivation-modal.php';
	}

	public function render_newsletter_notice() {
		if ( ! $this->should_show_notice() ) {
			return;
		}
		// One opt-in notice per page, even when several Acowebs plugins are active.
		if ( ! empty( $GLOBALS['acowebs_optin_notice_rendered'] ) ) {
			return;
		}
		$GLOBALS['acowebs_optin_notice_rendered'] = true;

		$user        = wp_get_current_user();
		$plugin_name = $this->display_name();
		$user_email  = $user->user_email;
		include plugin_dir_path( __FILE__ ) . 'views/feedback-newsletter-notice.php';
	}

	/**
	 * @param string $key POST key.
	 * @return string Unslashed raw value, or ''.
	 */
	private function post_value( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified and sanitized by the callers.
		return ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	public function ajax_deactivation_feedback() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( null, 403 );
		}

		$consent = rest_sanitize_boolean( $this->post_value( 'consent' ) );

		$args = array(
			'plugin'         => self::PLUGIN_NAME,
			'edition'        => self::EDITION,
			'plugin_slug'    => dirname( plugin_basename( $this->file ) ),
			'plugin_version' => $this->version,
			'reason'         => mb_substr( sanitize_text_field( $this->post_value( 'reason' ) ), 0, 500 ),
			'reason_details' => mb_substr( sanitize_textarea_field( $this->post_value( 'reason_details' ) ), 0, 5000 ),
			'consent'        => $consent,
		);

		// Name and email only travel while the contact box is ticked.
		if ( $consent ) {
			$args['name']  = mb_substr( sanitize_text_field( $this->post_value( 'name' ) ), 0, 255 );
			$args['email'] = sanitize_email( $this->post_value( 'email' ) );
		}

		$client = new Acowebs_Feedback_Client();
		$client->send_deactivation( $args );

		wp_send_json_success();
	}

	public function ajax_newsletter_optin() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		// Honeypot: a real admin never fills this in.
		if ( '' !== $this->post_value( 'website_hp' ) ) {
			wp_send_json_success();
		}

		$email = sanitize_email( $this->post_value( 'email' ) );
		if ( ! is_email( $email ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Please enter a valid email address.', 'deposits-partial-payments-for-woocommerce' ) ),
				400
			);
		}

		$data = array(
			'email'     => $email,
			'list'      => 'deposit',
			'source'    => 'plugin-admin-notice',
			'consent'   => true,
			'plugin'    => self::PLUGIN_NAME,
			'edition'   => self::EDITION,
			'site_url'  => home_url(),
			'site_name' => get_bloginfo( 'name' ),
		);

		$name = mb_substr( sanitize_text_field( $this->post_value( 'name' ) ), 0, 255 );
		if ( '' !== $name ) {
			$data['name'] = $name;
		}
		$phone = mb_substr( sanitize_text_field( $this->post_value( 'phone' ) ), 0, 40 );
		if ( '' !== $phone ) {
			$data['phone'] = $phone;
		}

		$client   = new Acowebs_Feedback_Client();
		$response = wp_remote_post(
			$client->api_url() . '/v1/subscribe',
			array(
				'timeout' => 5,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $data ),
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			update_user_meta( get_current_user_id(), self::OPTIN_META, 1 );
			wp_send_json_success();
		}

		$message = __( 'We could not subscribe you right now. Please try again.', 'deposits-partial-payments-for-woocommerce' );
		if ( 422 === $code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$path = isset( $body['issues'][0]['path'] ) ? $body['issues'][0]['path'] : '';
			if ( 'phone' === $path ) {
				$message = __( 'Please check the phone number, or leave it empty.', 'deposits-partial-payments-for-woocommerce' );
			} elseif ( 'email' === $path ) {
				$message = __( 'Please enter a valid email address.', 'deposits-partial-payments-for-woocommerce' );
			}
		}
		wp_send_json_error( array( 'message' => $message ), 502 );
	}

	public function ajax_newsletter_dismiss() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		update_user_meta( get_current_user_id(), self::OPTIN_META, 1 );
		wp_send_json_success();
	}
}
