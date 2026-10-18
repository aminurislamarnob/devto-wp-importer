<?php

use PHPUnit\Framework\TestCase;
use WeLabs\DevtoWpImporter\Engagement\Limiter;

class LimiterTest extends TestCase {

	const POST_ID = 9;

	/**
	 * @var Limiter
	 */
	private $limiter;

	/**
	 * @var string
	 */
	private $visitor;

	protected function setUp(): void {
		Fake_WP::reset();

		$this->limiter = new Limiter();
		$this->visitor = $this->limiter->visitor_id( '203.0.113.7' );
	}

	public function test_visitor_id_is_stable_and_does_not_contain_the_ip() {
		$this->assertSame( $this->visitor, $this->limiter->visitor_id( '203.0.113.7' ) );
		$this->assertNotSame( $this->visitor, $this->limiter->visitor_id( '203.0.113.8' ) );
		$this->assertStringNotContainsString( '203.0.113.7', $this->visitor );
	}

	public function test_claps_are_capped_at_fifty_per_visitor_and_post() {
		$this->assertSame( 50, $this->limiter->claps_left( self::POST_ID, $this->visitor ) );

		$this->assertSame( 30, $this->limiter->take_claps( self::POST_ID, $this->visitor, 30 ) );
		// Only what's left of the cap is accepted.
		$this->assertSame( 20, $this->limiter->take_claps( self::POST_ID, $this->visitor, 30 ) );
		$this->assertSame( 0, $this->limiter->take_claps( self::POST_ID, $this->visitor, 1 ) );
		$this->assertSame( 0, $this->limiter->claps_left( self::POST_ID, $this->visitor ) );
	}

	public function test_clap_cap_is_per_visitor_and_per_post() {
		$this->limiter->take_claps( self::POST_ID, $this->visitor, 50 );

		$this->assertSame( 50, $this->limiter->take_claps( self::POST_ID + 1, $this->visitor, 50 ) );
		$this->assertSame( 50, $this->limiter->take_claps( self::POST_ID, $this->limiter->visitor_id( '198.51.100.1' ), 50 ) );
	}

	public function test_clap_allowance_comes_back_after_24_hours() {
		$this->limiter->take_claps( self::POST_ID, $this->visitor, 50 );

		Fake_WP::advance( DAY_IN_SECONDS - 60 );
		$this->assertSame( 0, $this->limiter->claps_left( self::POST_ID, $this->visitor ) );

		Fake_WP::advance( 60 );
		$this->assertSame( 50, $this->limiter->claps_left( self::POST_ID, $this->visitor ) );
	}

	public function test_window_does_not_slide_with_each_clap() {
		$this->limiter->take_claps( self::POST_ID, $this->visitor, 10 );

		// More claps late in the window must not push its reset back.
		Fake_WP::advance( DAY_IN_SECONDS - 60 );
		$this->limiter->take_claps( self::POST_ID, $this->visitor, 40 );
		Fake_WP::advance( 60 );

		$this->assertSame( 50, $this->limiter->claps_left( self::POST_ID, $this->visitor ) );
	}

	public function test_zero_or_negative_claps_are_ignored() {
		$this->assertSame( 0, $this->limiter->take_claps( self::POST_ID, $this->visitor, 0 ) );
		$this->assertSame( 50, $this->limiter->claps_left( self::POST_ID, $this->visitor ) );
	}

	public function test_a_view_counts_once_per_visitor_post_and_day() {
		$this->assertTrue( $this->limiter->count_view( self::POST_ID, $this->visitor ) );
		$this->assertFalse( $this->limiter->count_view( self::POST_ID, $this->visitor ) );

		// A different post, or a different visitor, still counts.
		$this->assertTrue( $this->limiter->count_view( self::POST_ID + 1, $this->visitor ) );
		$this->assertTrue( $this->limiter->count_view( self::POST_ID, $this->limiter->visitor_id( '198.51.100.1' ) ) );

		Fake_WP::advance( DAY_IN_SECONDS );
		$this->assertTrue( $this->limiter->count_view( self::POST_ID, $this->visitor ) );
	}

	public function test_requests_are_limited_per_minute() {
		for ( $i = 0; $i < Limiter::REQUESTS_PER_MINUTE; $i++ ) {
			$this->assertTrue( $this->limiter->allow_request( $this->visitor ) );
		}

		$this->assertFalse( $this->limiter->allow_request( $this->visitor ) );
		$this->assertTrue( $this->limiter->allow_request( $this->limiter->visitor_id( '198.51.100.1' ) ) );

		Fake_WP::advance( MINUTE_IN_SECONDS );
		$this->assertTrue( $this->limiter->allow_request( $this->visitor ) );
	}
}
