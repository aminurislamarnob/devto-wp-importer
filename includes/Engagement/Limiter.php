<?php

namespace WeLabs\DevtoWpImporter\Engagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps visitor claps and views honest without accounts.
 *
 * A visitor is a salted hash of their IP address, and everything is kept in
 * transients that expire on their own, so no IP addresses are stored:
 *
 * - a view counts once per visitor and post every 24 hours;
 * - a visitor can give a post up to CLAP_CAP claps every 24 hours (the
 *   browser also remembers its claps for good, this is the server's check);
 * - each visitor gets REQUESTS_PER_MINUTE engagement requests a minute.
 */
class Limiter {

	const CLAP_CAP            = 50;
	const WINDOW              = DAY_IN_SECONDS;
	const REQUESTS_PER_MINUTE = 60;

	const TRANSIENT_PREFIX = 'devto_wp_importer_';

	/**
	 * An opaque ID for a visitor's IP: the same IP always maps to the same ID,
	 * but the ID can't be turned back into the IP without the site's salt.
	 *
	 * @param string $ip Visitor IP address.
	 *
	 * @return string
	 */
	public function visitor_id( $ip ) {
		return substr( hash_hmac( 'sha256', (string) $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Most claps one visitor may give one post in a window.
	 *
	 * @return int
	 */
	public function clap_cap() {
		return max( 1, absint( apply_filters( 'devto_wp_importer_clap_cap', self::CLAP_CAP ) ) );
	}

	/**
	 * Count a request against the visitor's per-minute budget.
	 *
	 * @param string $visitor Visitor ID.
	 *
	 * @return bool False once the visitor is over the limit.
	 */
	public function allow_request( $visitor ) {
		$used = $this->add( 'rate_' . $visitor, 1, MINUTE_IN_SECONDS );

		return $used <= self::REQUESTS_PER_MINUTE;
	}

	/**
	 * Whether this view should count: the first from this visitor for this
	 * post in the window. Records it when it does.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $visitor Visitor ID.
	 *
	 * @return bool
	 */
	public function count_view( $post_id, $visitor ) {
		return $this->add( 'view_' . absint( $post_id ) . '_' . $visitor, 1, self::WINDOW ) === 1;
	}

	/**
	 * Claps the visitor can still give the post in this window.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $visitor Visitor ID.
	 *
	 * @return int
	 */
	public function claps_left( $post_id, $visitor ) {
		return max( 0, $this->clap_cap() - $this->get( 'clap_' . absint( $post_id ) . '_' . $visitor ) );
	}

	/**
	 * Take up to `$requested` claps from the visitor's allowance.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $visitor   Visitor ID.
	 * @param int    $requested Claps the visitor sent.
	 *
	 * @return int Claps accepted: `$requested`, fewer once the cap is near,
	 *             or 0 when it's used up.
	 */
	public function take_claps( $post_id, $visitor, $requested ) {
		$accepted = min( absint( $requested ), $this->claps_left( $post_id, $visitor ) );

		if ( $accepted > 0 ) {
			$this->add( 'clap_' . absint( $post_id ) . '_' . $visitor, $accepted, self::WINDOW );
		}

		return $accepted;
	}

	/**
	 * Current count in a window, 0 if it hasn't started or has expired.
	 */
	private function get( $name ) {
		$window = get_transient( self::TRANSIENT_PREFIX . $name );

		return is_array( $window ) ? absint( $window['count'] ?? 0 ) : 0;
	}

	/**
	 * Add to a fixed window, starting one if needed. The window keeps the
	 * expiry it started with: set_transient() would otherwise push it back on
	 * every write and a steady trickle would never reset.
	 */
	private function add( $name, $by, $ttl ) {
		$key    = self::TRANSIENT_PREFIX . $name;
		$now    = time();
		$window = get_transient( $key );

		if ( ! is_array( $window ) || absint( $window['expires'] ?? 0 ) <= $now ) {
			$window = [
				'count'   => 0,
				'expires' => $now + $ttl,
			];
		}

		$window['count'] = absint( $window['count'] ) + absint( $by );
		set_transient( $key, $window, max( 1, $window['expires'] - $now ) );

		return $window['count'];
	}
}
