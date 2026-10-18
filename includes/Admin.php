<?php

namespace WeLabs\DevtoWpImporter;

use WeLabs\DevtoWpImporter\Engagement\Turnstile;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	/**
	 * @var Importer
	 */
	private $importer;

	public function __construct( Importer $importer ) {
		$this->importer = $importer;

		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'wp_ajax_devto_wp_importer_import_articles', [ $this, 'ajax_import' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		add_filter( 'plugin_action_links_' . DEVTO_WP_IMPORTER_BASENAME, [ $this, 'add_plugin_action_links' ] );
	}

	/**
	 * Add plugin action links on the plugins listing page.
	 *
	 * @param array $links Existing plugin action links.
	 *
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'edit.php?page=devto-wp-importer' ) ),
			esc_html__( 'Settings', 'devto-wp-importer' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	public function register_menu() {
		add_submenu_page(
			'edit.php',
			__( 'Dev.to Importer', 'devto-wp-importer' ),
			__( 'Dev.to Importer', 'devto-wp-importer' ),
			'manage_options',
			'devto-wp-importer',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings() {
		register_setting(
			'devto_wp_importer_settings',
			'devto_wp_importer_api_key',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_api_key' ],
			]
		);

		register_setting(
			'devto_wp_importer_settings',
			'devto_wp_importer_post_status',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'publish',
			]
		);

		register_setting(
			'devto_wp_importer_settings',
			'devto_wp_importer_post_author',
			[
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
			]
		);

		register_setting(
			'devto_wp_importer_settings',
			'devto_wp_importer_import_scope',
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'published',
			]
		);

		register_setting(
			'devto_wp_importer_settings',
			Turnstile::SECRET_OPTION,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_turnstile_secret' ],
			]
		);
	}

	/**
	 * Encrypt a new Turnstile secret; a blank field keeps the saved one.
	 *
	 * @param string $value Submitted secret.
	 *
	 * @return string
	 */
	public function sanitize_turnstile_secret( $value ) {
		$value = sanitize_text_field( $value );

		if ( '' === $value ) {
			return get_option( Turnstile::SECRET_OPTION, '' );
		}

		return ApiClient::encrypt( $value );
	}

	public function sanitize_api_key( $value ) {
		$value = sanitize_text_field( $value );

		if ( empty( $value ) ) {
			return '';
		}

		return ApiClient::encrypt( $value );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'devto-wp-importer' ) );
		}

		include DEVTO_WP_IMPORTER_TEMPLATE_DIR . '/admin-page.php';
	}

	public function ajax_import() {
		check_ajax_referer( 'devto_wp_importer_import_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[
					'message' => __( 'You do not have permission to perform this action.', 'devto-wp-importer' ),
				]
			);
		}

		$results = $this->importer->run();

		wp_send_json_success( $results );
	}

	public function admin_notices() {
		$screen = get_current_screen();

		if ( $screen === null || $screen->id !== 'posts_page_devto-wp-importer' ) {
			return;
		}

		$api_key = get_option( 'devto_wp_importer_api_key', '' );
		if ( empty( $api_key ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Please configure your Dev.to API key to start importing articles.', 'devto-wp-importer' )
			);
		}
	}
}
