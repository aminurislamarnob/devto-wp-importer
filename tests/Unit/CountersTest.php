<?php

use PHPUnit\Framework\TestCase;
use WeLabs\DevtoWpImporter\Engagement\Counters;

class CountersTest extends TestCase {

	/**
	 * @var Counters
	 */
	private $counters;

	protected function setUp(): void {
		Fake_WP::reset();

		$this->counters = new Counters();
	}

	public function test_combine_totals_each_metric() {
		$counts = Counters::combine(
			[
				'claps'    => 10,
				'comments' => 2,
				'views'    => 300,
			],
			[
				'claps'    => 5,
				'comments' => 1,
				'views'    => 40,
			]
		);

		$this->assertSame( [ 'devto' => 10, 'site' => 5, 'total' => 15 ], $counts['claps'] );
		$this->assertSame( [ 'devto' => 2, 'site' => 1, 'total' => 3 ], $counts['comments'] );
		$this->assertSame( [ 'devto' => 300, 'site' => 40, 'total' => 340 ], $counts['views'] );
	}

	public function test_combine_treats_missing_and_negative_values_as_zero_or_positive() {
		$counts = Counters::combine( [], [ 'claps' => '7' ] );

		$this->assertSame( [ 'devto' => 0, 'site' => 7, 'total' => 7 ], $counts['claps'] );
		$this->assertSame( [ 'devto' => 0, 'site' => 0, 'total' => 0 ], $counts['views'] );
	}

	public function test_claps_and_views_accumulate() {
		$this->counters->add_claps( 1, 3 );
		$this->counters->add_claps( 1, 4 );
		$this->counters->add_view( 1 );
		$this->counters->add_view( 1 );

		$counts = $this->counters->get( 1 );

		$this->assertSame( 7, $counts['claps']['site'] );
		$this->assertSame( 2, $counts['views']['site'] );
	}

	public function test_site_comments_are_approved_comments() {
		Fake_WP::$comment_counts[1] = 4;

		$this->assertSame( 4, $this->counters->get( 1 )['comments']['site'] );
	}

	public function test_set_site_counts_overwrites_only_site_numbers() {
		update_post_meta( 1, Counters::DEVTO_CLAPS_KEY, 20 );
		$this->counters->add_claps( 1, 500 );

		$this->counters->set_site_counts( 1, 12, 30 );

		$counts = $this->counters->get( 1 );

		$this->assertSame( [ 'devto' => 20, 'site' => 12, 'total' => 32 ], $counts['claps'] );
		$this->assertSame( 30, $counts['views']['site'] );
	}
}
