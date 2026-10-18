<?php

use PHPUnit\Framework\TestCase;
use WeLabs\DevtoWpImporter\ApiClient;
use WeLabs\DevtoWpImporter\Engagement\Counters;
use WeLabs\DevtoWpImporter\Importer;

/**
 * Re-importing from Dev.to must replace the Dev.to snapshot and never touch,
 * or double count, what visitors added on the site.
 */
class ReimportMergeTest extends TestCase {

	const POST_ID = 42;

	/**
	 * @var Importer
	 */
	private $importer;

	/**
	 * @var Counters
	 */
	private $counters;

	protected function setUp(): void {
		Fake_WP::reset();

		$this->importer = new Importer( new ApiClient() );
		$this->counters = new Counters();
	}

	public function test_first_import_shows_devto_counts() {
		$this->import( 120, 3 );

		$counts = $this->counters->get( self::POST_ID );

		$this->assertSame( [ 'devto' => 120, 'site' => 0, 'total' => 120 ], $counts['claps'] );
		$this->assertSame( [ 'devto' => 3, 'site' => 0, 'total' => 3 ], $counts['comments'] );
	}

	public function test_site_claps_add_to_devto_claps() {
		$this->import( 120, 3 );
		$this->counters->add_claps( self::POST_ID, 15 );

		$this->assertSame( 135, $this->counters->get( self::POST_ID )['claps']['total'] );
	}

	public function test_reimport_replaces_devto_counts_and_keeps_site_counts() {
		$this->import( 120, 3 );
		$this->counters->add_claps( self::POST_ID, 15 );
		$this->counters->add_view( self::POST_ID );
		Fake_WP::$comment_counts[ self::POST_ID ] = 2;

		// Dev.to's numbers grew in the meantime.
		$this->import( 150, 5 );

		$counts = $this->counters->get( self::POST_ID );

		$this->assertSame( [ 'devto' => 150, 'site' => 15, 'total' => 165 ], $counts['claps'] );
		$this->assertSame( [ 'devto' => 5, 'site' => 2, 'total' => 7 ], $counts['comments'] );
		$this->assertSame( 1, $counts['views']['site'] );
	}

	public function test_reimporting_the_same_numbers_changes_nothing() {
		$this->import( 150, 5 );
		$this->counters->add_claps( self::POST_ID, 15 );

		$this->import( 150, 5 );
		$this->import( 150, 5 );

		$this->assertSame( 165, $this->counters->get( self::POST_ID )['claps']['total'] );
	}

	public function test_devto_count_going_down_is_taken_as_is() {
		$this->import( 150, 5 );
		$this->counters->add_claps( self::POST_ID, 15 );

		// Someone removed a reaction on Dev.to.
		$this->import( 149, 5 );

		$this->assertSame( [ 'devto' => 149, 'site' => 15, 'total' => 164 ], $this->counters->get( self::POST_ID )['claps'] );
	}

	public function test_article_without_counts_leaves_stored_counts_alone() {
		$this->import( 120, 3 );

		$this->importer->save_counts( self::POST_ID, [ 'id' => 7 ] );

		$this->assertSame( 120, $this->counters->get( self::POST_ID )['claps']['devto'] );
	}

	public function test_post_never_imported_counts_only_site_engagement() {
		$this->counters->add_claps( self::POST_ID, 4 );

		$this->assertSame( [ 'devto' => 0, 'site' => 4, 'total' => 4 ], $this->counters->get( self::POST_ID )['claps'] );
	}

	private function import( $reactions, $comments ) {
		$this->importer->save_counts(
			self::POST_ID,
			[
				'id'                     => 7,
				'public_reactions_count' => $reactions,
				'comments_count'         => $comments,
			]
		);
	}
}
