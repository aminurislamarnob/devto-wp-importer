<?php

namespace WeLabs\DevtoWpImporter\Engagement;

use WeLabs\DevtoWpImporter\ApiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies Cloudflare Turnstile tokens sent with frontend comments.
 *
 * The secret key comes from this plugin's own setting when one is saved,
 * otherwise from Contact Form 7's Turnstile integration, which the site
 * already uses for its contact form, so there's usually nothing to set up.
 */
class Turnstile {

	const SECRET_OPTION = 'devto_wp_importer_turnstile_secret';
	const VERIFY_URL    = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Where the secret key comes from.
	 *
	 * @return string 'plugin', 'contact-form-7' or '' when there is none.
	 */
	public function secret_source() {
		if ( '' !== $this->own_secret() ) {
			return 'plugin';
		}

		return '' !== $this->contact_form_7_secret() ? 'contact-form-7' : '';
	}

	/**
	 * Whether a secret key is available to verify tokens with.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->secret();
	}

	/**
	 * Ask Cloudflare whether a token is valid.
	 *
	 * @param string $token Token from the frontend widget.
	 * @param string $ip    Visitor IP address.
	 *
	 * @return bool
	 */
	public function verify( $token, $ip = '' ) {
		$secret = $this->secret();

		if ( '' === $secret || '' === (string) $token ) {
			return false;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			[
				'timeout' => 10,
				'body'    => [
					'secret'   => $secret,
					'response' => (string) $token,
					'remoteip' => (string) $ip,
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) && ! empty( $body['success'] );
	}

	private function secret() {
		$secret = $this->own_secret();

		if ( '' === $secret ) {
			$secret = $this->contact_form_7_secret();
		}

		return (string) apply_filters( 'devto_wp_importer_turnstile_secret', $secret );
	}

	private function own_secret() {
		$encrypted = get_option( self::SECRET_OPTION, '' );

		return $encrypted ? ApiClient::decrypt( $encrypted ) : '';
	}

	private function contact_form_7_secret() {
		if ( ! class_exists( '\WPCF7_Turnstile' ) ) {
			return '';
		}

		$service = \WPCF7_Turnstile::get_instance();

		return (string) $service->get_secret( $service->get_sitekey() );
	}
}
