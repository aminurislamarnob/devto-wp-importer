<?php

namespace WeLabs\DevtoWpImporter\Engagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Claps, comments and views for a post: the Dev.to snapshot the importer
 * stores plus what visitors add on this site.
 *
 * The two live in separate meta keys and are only added together when read,
 * so an import can overwrite the Dev.to snapshot as often as it likes without
 * touching, or double counting, the site's own numbers.
 */
class Counters {

	const DEVTO_CLAPS_KEY    = '_devto_wp_importer_devto_reactions';
	const DEVTO_COMMENTS_KEY = '_devto_wp_importer_devto_comments';
	const DEVTO_VIEWS_KEY    = '_devto_wp_importer_devto_views';
	const SITE_CLAPS_KEY     = '_devto_wp_importer_site_claps';
	const SITE_VIEWS_KEY     = '_devto_wp_importer_site_views';

	const METRICS = [ 'claps', 'comments', 'views' ];

	/**
	 * Counts for a post, each split into its Dev.to and site parts.
	 *
	 * Site comments are the post's approved WordPress comments; Dev.to's
	 * comments are only ever a number.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array { claps|comments|views: { devto: int, site: int, total: int } }
	 */
	public function get( $post_id ) {
		$devto = [
			'claps'    => $this->meta_int( $post_id, self::DEVTO_CLAPS_KEY ),
			'comments' => $this->meta_int( $post_id, self::DEVTO_COMMENTS_KEY ),
			'views'    => $this->meta_int( $post_id, self::DEVTO_VIEWS_KEY ),
		];

		$site = [
			'claps'    => $this->meta_int( $post_id, self::SITE_CLAPS_KEY ),
			'comments' => $this->approved_comments( $post_id ),
			'views'    => $this->meta_int( $post_id, self::SITE_VIEWS_KEY ),
		];

		return self::combine( $devto, $site );
	}

	/**
	 * Add each metric's Dev.to and site parts together.
	 *
	 * @param array $devto Dev.to counts keyed by metric.
	 * @param array $site  Site counts keyed by metric.
	 *
	 * @return array
	 */
	public static function combine( array $devto, array $site ) {
		$counts = [];

		foreach ( self::METRICS as $metric ) {
			$from_devto = absint( $devto[ $metric ] ?? 0 );
			$from_site  = absint( $site[ $metric ] ?? 0 );

			$counts[ $metric ] = [
				'devto' => $from_devto,
				'site'  => $from_site,
				'total' => $from_devto + $from_site,
			];
		}

		return $counts;
	}

	/**
	 * Add visitor claps to the site count.
	 *
	 * @param int $post_id Post ID.
	 * @param int $count   Claps to add.
	 *
	 * @return int The new site count.
	 */
	public function add_claps( $post_id, $count ) {
		return $this->increment( $post_id, self::SITE_CLAPS_KEY, $count );
	}

	/**
	 * Count one visitor view.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int The new site count.
	 */
	public function add_view( $post_id ) {
		return $this->increment( $post_id, self::SITE_VIEWS_KEY, 1 );
	}

	/**
	 * Overwrite the site's own claps and views, e.g. to clean up spam from
	 * the post edit screen. Dev.to's numbers aren't editable: the next import
	 * would replace them anyway.
	 *
	 * @param int $post_id Post ID.
	 * @param int $claps   Site claps.
	 * @param int $views   Site views.
	 *
	 * @return void
	 */
	public function set_site_counts( $post_id, $claps, $views ) {
		update_post_meta( $post_id, self::SITE_CLAPS_KEY, absint( $claps ) );
		update_post_meta( $post_id, self::SITE_VIEWS_KEY, absint( $views ) );
	}

	/**
	 * Claps and views summed over every published post, Dev.to and site parts
	 * together.
	 *
	 * @return array { claps: int, views: int }
	 */
	public function totals() {
		$post_ids = get_posts(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'fields'      => 'ids',
				'numberposts' => -1,
			]
		);

		// One query for every post's meta instead of one per post.
		update_meta_cache( 'post', $post_ids );

		$totals = [
			'claps' => 0,
			'views' => 0,
		];

		foreach ( $post_ids as $post_id ) {
			$totals['claps'] += $this->meta_int( $post_id, self::DEVTO_CLAPS_KEY ) + $this->meta_int( $post_id, self::SITE_CLAPS_KEY );
			$totals['views'] += $this->meta_int( $post_id, self::DEVTO_VIEWS_KEY ) + $this->meta_int( $post_id, self::SITE_VIEWS_KEY );
		}

		return $totals;
	}

	private function increment( $post_id, $key, $by ) {
		$value = $this->meta_int( $post_id, $key ) + absint( $by );
		update_post_meta( $post_id, $key, $value );

		return $value;
	}

	private function meta_int( $post_id, $key ) {
		return absint( get_post_meta( $post_id, $key, true ) );
	}

	private function approved_comments( $post_id ) {
		return absint(
			get_comments(
				[
					'post_id' => $post_id,
					'status'  => 'approve',
					'type'    => 'comment',
					'count'   => true,
				]
			)
		);
	}
}
