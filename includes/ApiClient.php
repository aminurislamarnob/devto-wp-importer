<?php

namespace WeLabs\DevtoWpImporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ApiClient {

	private const BASE_URL = 'https://dev.to/api';
	private const PER_PAGE = 100;
	private const FOLLOWERS_PER_PAGE = 1000;
	private const MAX_RETRIES = 3;

	/**
	 * Encrypted key storage option name.
	 *
	 * @var string
	 */
	private $api_key_option = 'devto_wp_importer_api_key';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key = '';

	public function __construct() {
		$this->api_key = $this->get_api_key();
	}

	public function has_api_key() {
		return ! empty( $this->api_key );
	}

	public function refresh_api_key() {
		$this->api_key = $this->get_api_key();
	}

	public function get_all_articles( $scope = 'published' ) {
		$all_articles = [];
		$page         = 1;

		while ( true ) {
			$articles = $this->get_articles_page( $page, $scope );

			if ( is_wp_error( $articles ) ) {
				return $articles;
			}

			if ( empty( $articles ) ) {
				break;
			}

			$all_articles = array_merge( $all_articles, $articles );
			$page++;

			usleep( 200000 );
		}

		return $all_articles;
	}

	public function get_article( $article_id ) {
		return $this->request( '/articles/' . absint( $article_id ) );
	}

	/**
	 * Count the account's Dev.to followers. The API only lists them, so this
	 * pages through the list (up to 1000 a page) and counts.
	 *
	 * @return int|\WP_Error
	 */
	public function get_followers_count() {
		$count = 0;
		$page  = 1;

		while ( true ) {
			$followers = $this->request(
				'/followers/users',
				[
					'page'     => $page,
					'per_page' => self::FOLLOWERS_PER_PAGE,
				]
			);

			if ( is_wp_error( $followers ) ) {
				return $followers;
			}

			if ( ! is_array( $followers ) ) {
				break;
			}

			$count += count( $followers );

			if ( count( $followers ) < self::FOLLOWERS_PER_PAGE ) {
				break;
			}

			++$page;
			usleep( 200000 );
		}

		return $count;
	}

	public function get_article_analytics( $article_id ) {
		return $this->request(
			'/analytics/totals',
			[
				'article_id' => absint( $article_id ),
			]
		);
	}

	public static function encrypt( $value ) {
		$value = sanitize_text_field( $value );

		if ( empty( $value ) ) {
			return '';
		}

		$salt = wp_salt( 'auth' );
		$key  = hash( 'sha256', $salt, true );
		$iv   = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );

		$encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, 0, $iv );

		return $encrypted !== false ? base64_encode( $encrypted ) : '';
	}

	private function get_api_key() {
		$encrypted = get_option( $this->api_key_option, '' );

		if ( empty( $encrypted ) ) {
			return '';
		}

		return self::decrypt( $encrypted );
	}

	public static function decrypt( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		$decoded = base64_decode( $value );
		if ( $decoded === false ) {
			return '';
		}

		$salt = wp_salt( 'auth' );
		$key  = hash( 'sha256', $salt, true );
		$iv   = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );

		$decrypted = openssl_decrypt( $decoded, 'AES-256-CBC', $key, 0, $iv );

		return $decrypted !== false ? $decrypted : '';
	}

	private function get_headers() {
		return [
			'api-key'      => $this->api_key,
			'Accept'       => 'application/vnd.forem.api-v1+json',
			'Content-Type' => 'application/json',
		];
	}

	private function request( $endpoint, $query_params = [] ) {
		$url = self::BASE_URL . $endpoint;

		if ( ! empty( $query_params ) ) {
			$url = add_query_arg( $query_params, $url );
		}

		$args = [
			'headers' => $this->get_headers(),
			'timeout' => 30,
		];

		$args = apply_filters( 'devto_wp_importer_api_request_args', $args, $endpoint );

		$retries  = 0;
		$response = null;

		while ( $retries <= self::MAX_RETRIES ) {
			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				$retries++;
				if ( $retries <= self::MAX_RETRIES ) {
					sleep( 2 ** $retries );
				}
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			if ( $code === 429 ) {
				$retries++;
				if ( $retries <= self::MAX_RETRIES ) {
					sleep( 2 ** $retries );
				}
				continue;
			}

			if ( $code < 200 || $code >= 300 ) {
				$body = wp_remote_retrieve_body( $response );

				return new \WP_Error(
					'devto_wp_importer_api_error',
					sprintf(
						/* translators: 1: HTTP status code, 2: Response body */
						__( 'Dev.to API returned HTTP %1$d: %2$s', 'devto-wp-importer' ),
						$code,
						$body
					)
				);
			}

			break;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new \WP_Error(
				'devto_wp_importer_json_error',
				__( 'Failed to parse Dev.to API response.', 'devto-wp-importer' )
			);
		}

		return $data;
	}

	private function get_articles_page( $page = 1, $scope = 'published' ) {
		$endpoint = $scope === 'all' ? '/articles/me/all' : '/articles/me/published';

		return $this->request(
			$endpoint,
			[
				'page'     => absint( $page ),
				'per_page' => self::PER_PAGE,
			]
		);
	}
}
