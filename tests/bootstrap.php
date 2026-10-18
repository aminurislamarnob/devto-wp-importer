<?php
/**
 * Unit test bootstrap.
 *
 * The engagement rules (merging Dev.to and site counts, the clap cap, view
 * and rate windows) only touch post meta, transients and a few helpers, so
 * the tests run against in-memory stand-ins for those WordPress functions
 * instead of a WordPress install and database.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

require dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * In-memory state behind the stand-in WordPress functions.
 */
class Fake_WP {

	public static $meta           = [];
	public static $transients     = [];
	public static $options        = [];
	public static $comment_counts = [];

	public static function reset() {
		self::$meta           = [];
		self::$transients     = [];
		self::$options        = [];
		self::$comment_counts = [];
	}

	/**
	 * Move time forward for the windows the Limiter keeps in transients: each
	 * window's expiry comes `$seconds` closer, and windows that run out are
	 * deleted, as WordPress would.
	 */
	public static function advance( $seconds ) {
		foreach ( self::$transients as $key => $value ) {
			if ( ! is_array( $value ) || ! isset( $value['expires'] ) ) {
				continue;
			}

			$value['expires'] -= $seconds;

			if ( $value['expires'] <= time() ) {
				unset( self::$transients[ $key ] );
			} else {
				self::$transients[ $key ] = $value;
			}
		}
	}
}

function absint( $value ) {
	return abs( (int) $value );
}

function apply_filters( $hook, $value ) {
	return $value;
}

function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-' . $scheme;
}

function get_post_meta( $post_id, $key, $single = false ) {
	return Fake_WP::$meta[ $post_id ][ $key ] ?? '';
}

function update_post_meta( $post_id, $key, $value ) {
	Fake_WP::$meta[ $post_id ][ $key ] = $value;

	return true;
}

function get_transient( $key ) {
	return Fake_WP::$transients[ $key ] ?? false;
}

function set_transient( $key, $value, $expiration = 0 ) {
	Fake_WP::$transients[ $key ] = $value;

	return true;
}

function get_option( $key, $default = false ) {
	return Fake_WP::$options[ $key ] ?? $default;
}

function update_option( $key, $value ) {
	Fake_WP::$options[ $key ] = $value;

	return true;
}

function wp_check_invalid_utf8( $text ) {
	return mb_check_encoding( $text, 'UTF-8' ) ? $text : '';
}

/**
 * Only the approved-comment count query Counters makes.
 */
function get_comments( $args ) {
	return Fake_WP::$comment_counts[ $args['post_id'] ] ?? 0;
}
