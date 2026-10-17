<?php

namespace WeLabs\DevtoWpImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Importer {

	/**
	 * @var ApiClient
	 */
	private $api_client;

	/**
	 * @var array
	 */
	private $results = [
		'created'  => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'failed'   => 0,
		'messages' => [],
	];

	public function __construct( ApiClient $api_client ) {
		$this->api_client = $api_client;
	}

	public function run() {
		$this->results = [
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'messages' => [],
		];

		if ( ! $this->api_client->has_api_key() ) {
			$this->results['messages'][] = __( 'API key is not configured. Please add your Dev.to API key in settings.', 'devto-wp-importer' );
			return $this->results;
		}

		if ( get_transient( 'devto_wp_importer_import_lock' ) ) {
			$this->results['messages'][] = __( 'An import is already in progress. Please wait.', 'devto-wp-importer' );
			return $this->results;
		}

		set_transient( 'devto_wp_importer_import_lock', true, 10 * MINUTE_IN_SECONDS );

		do_action( 'devto_wp_importer_before_import' );

		$scope    = get_option( 'devto_wp_importer_import_scope', 'published' );
		$articles = $this->api_client->get_all_articles( $scope );

		if ( is_wp_error( $articles ) ) {
			delete_transient( 'devto_wp_importer_import_lock' );
			$this->results['messages'][] = sprintf(
				/* translators: %s: Error message from Dev.to API */
				__( 'Failed to fetch articles from Dev.to: %s', 'devto-wp-importer' ),
				$articles->get_error_message()
			);
			return $this->results;
		}

		if ( empty( $articles ) ) {
			delete_transient( 'devto_wp_importer_import_lock' );
			$this->results['messages'][] = __( 'No articles found on your Dev.to account.', 'devto-wp-importer' );
			return $this->results;
		}

		$this->results['messages'][] = sprintf(
			/* translators: %d: Number of articles found */
			__( 'Found %d articles on Dev.to. Starting import...', 'devto-wp-importer' ),
			count( $articles )
		);

		foreach ( $articles as $article_summary ) {
			$this->process_article( $article_summary );
		}

		delete_transient( 'devto_wp_importer_import_lock' );

		do_action( 'devto_wp_importer_after_import', $this->results );

		update_option(
			'devto_wp_importer_last_import_result',
			[
				'results'   => $this->results,
				'timestamp' => current_time( 'mysql' ),
			]
		);

		return $this->results;
	}

	private function process_article( $article_summary ) {
		$devto_id = absint( $article_summary['id'] ?? 0 );
		$title    = sanitize_text_field( $article_summary['title'] ?? __( 'Untitled article', 'devto-wp-importer' ) );

		if ( $devto_id === 0 ) {
			$this->results['failed']++;
			$this->results['messages'][] = __( 'Skipped article with missing ID.', 'devto-wp-importer' );
			return;
		}

		$should_import = apply_filters( 'devto_wp_importer_should_import_article', true, $article_summary );
		if ( ! $should_import ) {
			$this->results['skipped']++;
			$this->results['messages'][] = sprintf(
				/* translators: %s: Article title */
				__( 'Skipped "%s" by filter.', 'devto-wp-importer' ),
				$title
			);
			return;
		}

		$existing_post = $this->find_existing_post( $devto_id );

		if ( $existing_post ) {
			$this->maybe_update_post( $existing_post, $article_summary );
		} else {
			$this->create_post( $devto_id, $article_summary );
		}
	}

	private function find_existing_post( $devto_id ) {
		$posts = get_posts(
			[
				'post_type'   => 'post',
				'meta_key'    => '_devto_wp_importer_devto_id',
				'meta_value'  => absint( $devto_id ),
				'post_status' => 'any',
				'numberposts' => 1,
			]
		);

		return ! empty( $posts ) ? $posts[0] : null;
	}

	private function maybe_update_post( $existing_post, $article_summary ) {
		$devto_id      = absint( $article_summary['id'] );
		$title         = $article_summary['title'] ?? '';
		$stored_edited = get_post_meta( $existing_post->ID, '_devto_wp_importer_devto_edited_at', true );
		$remote_edited = $article_summary['edited_at'] ?? '';

		if ( ! empty( $stored_edited ) && $stored_edited === $remote_edited ) {
			$analytics = $this->api_client->get_article_analytics( $devto_id );

			if ( ! is_wp_error( $analytics ) ) {
				$this->save_article_analytics( $existing_post->ID, $analytics );
			}

			$this->results['skipped']++;
			$this->results['messages'][] = sprintf(
				/* translators: %s: Article title */
				__( 'Skipped "%s" (no changes).', 'devto-wp-importer' ),
				$title
			);
			do_action( 'devto_wp_importer_article_skipped', $existing_post->ID, $devto_id );
			return;
		}

		$full_article = $this->api_client->get_article( $devto_id );

		if ( is_wp_error( $full_article ) ) {
			$this->results['failed']++;
			$this->results['messages'][] = sprintf(
				/* translators: 1: Article title, 2: Error message */
				__( 'Failed to fetch full article "%1$s": %2$s', 'devto-wp-importer' ),
				esc_html( $title ),
				$full_article->get_error_message()
			);
			return;
		}

		usleep( 200000 );

		$analytics = $this->api_client->get_article_analytics( $devto_id );

		$post_data       = $this->map_article_to_post_data( $full_article );
		$post_data['ID'] = $existing_post->ID;
		$post_data       = apply_filters( 'devto_wp_importer_article_post_data', $post_data, $full_article, 'update' );
		$result          = wp_update_post( $post_data, true );

		if ( is_wp_error( $result ) ) {
			$this->results['failed']++;
			$this->results['messages'][] = sprintf(
				/* translators: 1: Article title, 2: Error message */
				__( 'Failed to update "%1$s": %2$s', 'devto-wp-importer' ),
				esc_html( $title ),
				$result->get_error_message()
			);
			return;
		}

		$this->save_article_meta( $existing_post->ID, $full_article );
		if ( ! is_wp_error( $analytics ) ) {
			$this->save_article_analytics( $existing_post->ID, $analytics );
		}
		$this->sync_tags( $existing_post->ID, $full_article );
		$this->sync_cover_image( $existing_post->ID, $full_article );

		$this->results['updated']++;
		$this->results['messages'][] = sprintf(
			/* translators: %s: Article title */
			__( 'Updated "%s".', 'devto-wp-importer' ),
			sanitize_text_field( $full_article['title'] ?? $title )
		);

		do_action( 'devto_wp_importer_article_updated', $existing_post->ID, $devto_id, $full_article );
	}

	private function create_post( $devto_id, $article_summary ) {
		$title = $article_summary['title'] ?? '';

		$full_article = $this->api_client->get_article( $devto_id );

		if ( is_wp_error( $full_article ) ) {
			$this->results['failed']++;
			$this->results['messages'][] = sprintf(
				/* translators: 1: Article title, 2: Error message */
				__( 'Failed to fetch full article "%1$s": %2$s', 'devto-wp-importer' ),
				esc_html( $title ),
				$full_article->get_error_message()
			);
			return;
		}

		usleep( 200000 );

		$analytics = $this->api_client->get_article_analytics( $devto_id );

		$post_data = $this->map_article_to_post_data( $full_article );
		$post_data = apply_filters( 'devto_wp_importer_article_post_data', $post_data, $full_article, 'create' );
		$post_id   = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			$this->results['failed']++;
			$this->results['messages'][] = sprintf(
				/* translators: 1: Article title, 2: Error message */
				__( 'Failed to create post for "%1$s": %2$s', 'devto-wp-importer' ),
				esc_html( $title ),
				$post_id->get_error_message()
			);
			return;
		}

		$this->save_article_meta( $post_id, $full_article );
		if ( ! is_wp_error( $analytics ) ) {
			$this->save_article_analytics( $post_id, $analytics );
		}
		$this->sync_tags( $post_id, $full_article );
		$this->sync_cover_image( $post_id, $full_article );

		$this->results['created']++;
		$this->results['messages'][] = sprintf(
			/* translators: %s: Article title */
			__( 'Created "%s".', 'devto-wp-importer' ),
			sanitize_text_field( $full_article['title'] ?? $title )
		);

		do_action( 'devto_wp_importer_article_imported', $post_id, $devto_id, $full_article );
	}

	private function map_article_to_post_data( $article ) {
		$post_status = get_option( 'devto_wp_importer_post_status', 'publish' );
		$post_author = absint( get_option( 'devto_wp_importer_post_author', 0 ) );

		if ( $post_author === 0 ) {
			$post_author = get_current_user_id();
		}

		$status = apply_filters( 'devto_wp_importer_post_status', $post_status, $article );

		$post_date     = '';
		$post_date_gmt = '';
		$published_at  = $article['published_at'] ?? '';

		if ( ! empty( $published_at ) ) {
			$timestamp     = strtotime( $published_at );
			$post_date     = gmdate( 'Y-m-d H:i:s', $timestamp );
			$post_date_gmt = $post_date;
		}

		$data = [
			'post_title'   => sanitize_text_field( $article['title'] ?? '' ),
			'post_content' => wp_kses_post( $article['body_html'] ?? '' ),
			'post_excerpt' => sanitize_text_field( $article['description'] ?? '' ),
			'post_status'  => $status,
			'post_author'  => $post_author,
			'post_type'    => 'post',
		];

		if ( ! empty( $post_date ) ) {
			$data['post_date']     = $post_date;
			$data['post_date_gmt'] = $post_date_gmt;
		}

		return $data;
	}

	private function save_article_meta( $post_id, $article ) {
		$meta = [
			'_devto_wp_importer_devto_id'            => absint( $article['id'] ?? 0 ),
			'_devto_wp_importer_devto_url'           => esc_url_raw( $article['url'] ?? '' ),
			'_devto_wp_importer_devto_slug'          => sanitize_text_field( $article['slug'] ?? '' ),
			'_devto_wp_importer_devto_edited_at'     => sanitize_text_field( $article['edited_at'] ?? '' ),
			'_devto_wp_importer_devto_published_at'  => sanitize_text_field( $article['published_at'] ?? '' ),
			'_devto_wp_importer_devto_canonical_url' => esc_url_raw( $article['canonical_url'] ?? '' ),
			'_devto_wp_importer_devto_reactions'     => absint( $article['public_reactions_count'] ?? 0 ),
			'_devto_wp_importer_devto_comments'      => absint( $article['comments_count'] ?? 0 ),
			'_devto_wp_importer_devto_reading_time'  => absint( $article['reading_time_minutes'] ?? 0 ),
			'_devto_wp_importer_devto_body_markdown' => isset( $article['body_markdown'] ) && is_string( $article['body_markdown'] ) ? $article['body_markdown'] : '',
			'_devto_wp_importer_last_imported'       => current_time( 'mysql' ),
		];

		$meta = apply_filters( 'devto_wp_importer_article_meta_data', $meta, $article, $post_id );

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	private function save_article_analytics( $post_id, $analytics ) {
		$views = 0;

		if ( isset( $analytics['page_views']['total'] ) ) {
			$views = absint( $analytics['page_views']['total'] );
		} elseif ( isset( $analytics['views'] ) ) {
			$views = absint( $analytics['views'] );
		}

		update_post_meta( $post_id, '_devto_wp_importer_devto_views', $views );
	}

	private function sync_tags( $post_id, $article ) {
		$tag_list = $article['tag_list'] ?? [];

		if ( is_string( $tag_list ) ) {
			$tag_list = array_map( 'trim', explode( ',', $tag_list ) );
		}

		$tag_list = array_filter( array_map( 'sanitize_text_field', $tag_list ) );

		if ( ! empty( $tag_list ) ) {
			wp_set_post_tags( $post_id, $tag_list, false );
		}
	}

	private function sync_cover_image( $post_id, $article ) {
		$cover_image_url = esc_url_raw( $article['cover_image'] ?? '' );

		if ( empty( $cover_image_url ) ) {
			return;
		}

		$stored_url = get_post_meta( $post_id, '_devto_wp_importer_devto_cover_image_url', true );
		if ( $stored_url === $cover_image_url && has_post_thumbnail( $post_id ) ) {
			return;
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		$tmp = download_url( $cover_image_url );

		if ( is_wp_error( $tmp ) ) {
			return;
		}

		$path_info = wp_parse_url( $cover_image_url, PHP_URL_PATH );
		$file_info = wp_check_filetype( basename( $path_info ? $path_info : 'image.jpg' ) );
		$ext       = ! empty( $file_info['ext'] ) ? $file_info['ext'] : 'jpg';

		$file_array = [
			'name'     => sanitize_file_name( 'devto-' . absint( $article['id'] ?? 0 ) . '-cover.' . $ext ),
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return;
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, '_devto_wp_importer_devto_cover_image_url', $cover_image_url );
	}
}
