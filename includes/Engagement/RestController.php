<?php

namespace WeLabs\DevtoWpImporter\Engagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public REST routes the headless frontend calls from the visitor's browser:
 *
 * - POST /posts/{id}/view      count a view, answer with live counts
 * - POST /posts/{id}/claps     add claps (capped), answer with live counts
 * - GET  /posts/{id}/comments  approved comments, one level of replies
 * - POST /posts/{id}/comments  add a comment (Turnstile + honeypot)
 * - GET  /stats                claps and views over all posts, followers
 *
 * Only published, non-password-protected posts are accepted.
 */
class RestController {

	const REST_NAMESPACE = 'devto-wp-importer/v1';

	/**
	 * @var Counters
	 */
	private $counters;

	/**
	 * @var Limiter
	 */
	private $limiter;

	/**
	 * @var Comments
	 */
	private $comments;

	/**
	 * @var Turnstile
	 */
	private $turnstile;

	public function __construct( Counters $counters, Limiter $limiter, Comments $comments, Turnstile $turnstile ) {
		$this->counters  = $counters;
		$this->limiter   = $limiter;
		$this->comments  = $comments;
		$this->turnstile = $turnstile;

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		$post_id = [
			'id' => [
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			],
		];

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/view',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'record_view' ],
				'permission_callback' => '__return_true',
				'args'                => $post_id + [
					// The browser already counted this post today: send live
					// counts back without counting again.
					'seen' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/claps',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'add_claps' ],
				'permission_callback' => '__return_true',
				'args'                => $post_id + [
					'count' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
						'maximum'  => $this->limiter->clap_cap(),
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/comments',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_comments' ],
					'permission_callback' => '__return_true',
					'args'                => $post_id,
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_comment' ],
					'permission_callback' => '__return_true',
					'args'                => $post_id + [
						'author'    => [ 'type' => 'string' ],
						'email'     => [ 'type' => 'string' ],
						'content'   => [ 'type' => 'string' ],
						'parent'    => [
							'type'    => 'integer',
							'default' => 0,
						],
						'turnstile' => [ 'type' => 'string' ],
						// Honeypot: hidden from people, irresistible to bots.
						'website'   => [ 'type' => 'string' ],
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/stats',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function record_view( \WP_REST_Request $request ) {
		$post = $this->public_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$visitor = $this->visitor();
		if ( ! $this->limiter->allow_request( $visitor ) ) {
			return $this->too_many_requests();
		}

		if ( ! $request['seen'] && ! $this->is_bot() && $this->limiter->count_view( $post->ID, $visitor ) ) {
			$this->counters->add_view( $post->ID );
		}

		return $this->no_store(
			[
				'engagement' => $this->counters->get( $post->ID ),
				'claps_left' => $this->limiter->claps_left( $post->ID, $visitor ),
			]
		);
	}

	public function add_claps( \WP_REST_Request $request ) {
		$post = $this->public_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$visitor = $this->visitor();
		if ( ! $this->limiter->allow_request( $visitor ) ) {
			return $this->too_many_requests();
		}

		$accepted = $this->limiter->take_claps( $post->ID, $visitor, $request['count'] );
		if ( $accepted > 0 ) {
			$this->counters->add_claps( $post->ID, $accepted );
		}

		return $this->no_store(
			[
				'engagement' => $this->counters->get( $post->ID ),
				'accepted'   => $accepted,
				'claps_left' => $this->limiter->claps_left( $post->ID, $visitor ),
			]
		);
	}

	public function list_comments( \WP_REST_Request $request ) {
		$post = $this->public_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->no_store(
			[
				'comments' => $this->comments->for_post( $post ),
				'open'     => comments_open( $post->ID ),
			]
		);
	}

	public function create_comment( \WP_REST_Request $request ) {
		$post = $this->public_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$visitor = $this->visitor();
		if ( ! $this->limiter->allow_request( $visitor ) ) {
			return $this->too_many_requests();
		}

		// A bot filled the hidden field: answer as if the comment were held
		// for moderation, and drop it.
		if ( '' !== trim( (string) $request['website'] ) ) {
			return new \WP_REST_Response(
				[
					'status'  => 'pending',
					'comment' => null,
				],
				201
			);
		}

		if ( ! $this->turnstile->is_configured() ) {
			return new \WP_Error( 'devto_wp_importer_comments_unavailable', __( 'Comments are not available right now.', 'devto-wp-importer' ), [ 'status' => 503 ] );
		}

		if ( ! $this->turnstile->verify( (string) $request['turnstile'], $this->client_ip() ) ) {
			return new \WP_Error( 'devto_wp_importer_turnstile_failed', __( 'Please complete the security check and try again.', 'devto-wp-importer' ), [ 'status' => 400 ] );
		}

		$result = $this->comments->create(
			$post,
			[
				'author'  => $request['author'],
				'email'   => $request['email'],
				'content' => $request['content'],
				'parent'  => $request['parent'],
			],
			$this->client_ip(),
			substr( (string) $request->get_header( 'user_agent' ), 0, 254 )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['engagement'] = $this->counters->get( $post->ID );

		return new \WP_REST_Response( $result, 201 );
	}

	public function get_stats() {
		$stats = get_transient( 'devto_wp_importer_stats' );

		if ( ! is_array( $stats ) ) {
			$totals    = $this->counters->totals();
			$followers = get_option( 'devto_wp_importer_followers_count', '' );

			$stats = [
				'views'     => $totals['views'],
				'reactions' => $totals['claps'],
				'followers' => '' === $followers ? null : absint( $followers ),
			];

			set_transient( 'devto_wp_importer_stats', $stats, MINUTE_IN_SECONDS );
		}

		return $this->no_store( $stats );
	}

	/**
	 * A post visitors may engage with, or a 404 error.
	 */
	private function public_post( $post_id ) {
		$post = get_post( absint( $post_id ) );

		if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return new \WP_Error( 'devto_wp_importer_post_not_found', __( 'Post not found.', 'devto-wp-importer' ), [ 'status' => 404 ] );
		}

		return $post;
	}

	private function visitor() {
		return $this->limiter->visitor_id( $this->client_ip() );
	}

	/**
	 * The visitor's IP. The site sits behind Cloudflare, so REMOTE_ADDR is a
	 * Cloudflare edge; the visitor is in CF-Connecting-IP.
	 */
	private function client_ip() {
		$candidates = [
			isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '',
			isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
		];

		$ip = '';
		foreach ( $candidates as $candidate ) {
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
				$ip = $candidate;
				break;
			}
		}

		return (string) apply_filters( 'devto_wp_importer_visitor_ip', $ip );
	}

	private function is_bot() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';

		return '' === $agent || (bool) preg_match( '/bot|crawl|spider|slurp|headless|lighthouse|preview|curl|wget|python-requests/', $agent );
	}

	private function too_many_requests() {
		return new \WP_Error( 'devto_wp_importer_rate_limited', __( 'Too many requests. Please slow down.', 'devto-wp-importer' ), [ 'status' => 429 ] );
	}

	private function no_store( $data ) {
		$response = new \WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
