<?php

namespace WeLabs\DevtoWpImporter;

use WeLabs\DevtoWpImporter\Engagement\Comments;
use WeLabs\DevtoWpImporter\Engagement\Counters;
use WeLabs\DevtoWpImporter\Engagement\Limiter;
use WeLabs\DevtoWpImporter\Engagement\PostAdmin;
use WeLabs\DevtoWpImporter\Engagement\RestController;
use WeLabs\DevtoWpImporter\Engagement\Turnstile;

/**
 * DevtoWpImporter class
 *
 * @class DevtoWpImporter The class that holds the entire DevtoWpImporter plugin
 */
final class DevtoWpImporter {

    /**
     * Plugin version
     *
     * @var string
     */
    public $version = '0.1.0';

    /**
     * Instance of self
     *
     * @var DevtoWpImporter
     */
    private static $instance = null;

    /**
     * Holds various class instances
     *
     * @since 2.6.10
     *
     * @var array
     */
    private $container = [];

    /**
     * Constructor for the DevtoWpImporter class
     *
     * Sets up all the appropriate hooks and actions
     * within our plugin.
     */
    private function __construct() {
        $this->define_constants();

        register_activation_hook( DEVTO_WP_IMPORTER_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( DEVTO_WP_IMPORTER_FILE, [ $this, 'deactivate' ] );

        add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
        add_action( 'woocommerce_flush_rewrite_rules', [ $this, 'flush_rewrite_rules' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
    }

    /**
     * Initializes the DevtoWpImporter() class
     *
     * Checks for an existing DevtoWpImporter instance
     * and if it doesn't find one then create a new one.
     *
     * @return DevtoWpImporter
     */
    public static function init() {
        if ( self::$instance === null ) {
			self::$instance = new self();
		}

        return self::$instance;
    }

    /**
     * Magic getter to bypass referencing objects
     *
     * @since 2.6.10
     *
     * @param string $prop
     *
     * @return Class Instance
     */
    public function __get( $prop ) {
		if ( array_key_exists( $prop, $this->container ) ) {
            return $this->container[ $prop ];
		}
    }

    /**
     * Placeholder for activation function
     *
     * Nothing is being called here yet.
     */
    public function activate() {
        $defaults = [
            'devto_wp_importer_api_key'      => '',
            'devto_wp_importer_post_status'  => 'publish',
            'devto_wp_importer_post_author'  => 0,
            'devto_wp_importer_import_scope' => 'published',
        ];

        foreach ( $defaults as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }

        // Rewrite rules during devto_wp_importer activation
        if ( $this->has_woocommerce() ) {
            $this->flush_rewrite_rules();
        }
    }

    /**
	 * Register plugin REST routes
	 *
	 * @return void
	 */
	public function register_rest_route() {
        // Register your REST routes here
	}

    /**
     * Flush rewrite rules after devto_wp_importer is activated or woocommerce is activated
     *
     * @since 3.2.8
     */
    public function flush_rewrite_rules() {
        // fix rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Placeholder for deactivation function
     *
     * Nothing being called here yet.
     */
    public function deactivate() {
        delete_transient( 'devto_wp_importer_import_lock' );
    }

    /**
     * Define all constants
     *
     * @return void
     */
    public function define_constants() {
        defined( 'DEVTO_WP_IMPORTER_PLUGIN_VERSION' ) || define( 'DEVTO_WP_IMPORTER_PLUGIN_VERSION', $this->version );
        defined( 'DEVTO_WP_IMPORTER_DIR' ) || define( 'DEVTO_WP_IMPORTER_DIR', dirname( DEVTO_WP_IMPORTER_FILE ) );
        defined( 'DEVTO_WP_IMPORTER_INC_DIR' ) || define( 'DEVTO_WP_IMPORTER_INC_DIR', DEVTO_WP_IMPORTER_DIR . '/includes' );
        defined( 'DEVTO_WP_IMPORTER_TEMPLATE_DIR' ) || define( 'DEVTO_WP_IMPORTER_TEMPLATE_DIR', DEVTO_WP_IMPORTER_DIR . '/templates' );
        defined( 'DEVTO_WP_IMPORTER_PLUGIN_ASSET' ) || define( 'DEVTO_WP_IMPORTER_PLUGIN_ASSET', plugins_url( 'assets', DEVTO_WP_IMPORTER_FILE ) );
        defined( 'DEVTO_WP_IMPORTER_PLUGIN_ADMIN_ASSET' ) || define( 'DEVTO_WP_IMPORTER_PLUGIN_ADMIN_ASSET', DEVTO_WP_IMPORTER_PLUGIN_ASSET . '/admin' );
        defined( 'DEVTO_WP_IMPORTER_PLUGIN_PUBLIC_ASSET' ) || define( 'DEVTO_WP_IMPORTER_PLUGIN_PUBLIC_ASSET', DEVTO_WP_IMPORTER_PLUGIN_ASSET . '/public' );

        // give a way to turn off loading styles and scripts from parent theme
        defined( 'DEVTO_WP_IMPORTER_LOAD_STYLE' ) || define( 'DEVTO_WP_IMPORTER_LOAD_STYLE', true );
        defined( 'DEVTO_WP_IMPORTER_LOAD_SCRIPTS' ) || define( 'DEVTO_WP_IMPORTER_LOAD_SCRIPTS', true );
    }

    /**
     * Load the plugin after WP User Frontend is loaded
     *
     * @return void
     */
    public function init_plugin() {
        $this->includes();
        $this->init_hooks();

        do_action( 'devto_wp_importer_loaded' );
    }

    /**
     * Initialize the actions
     *
     * @return void
     */
    public function init_hooks() {
        // initialize the classes
        add_action( 'init', [ $this, 'init_classes' ], 4 );
        add_action( 'plugins_loaded', [ $this, 'after_plugins_loaded' ] );
    }

    /**
     * Include all the required files
     *
     * @return void
     */
    public function includes() {
        // include_once STUB_PLUGIN_DIR . '/functions.php';
    }

    /**
     * Init all the classes
     *
     * @return void
     */
    public function init_classes() {
        $this->container['api_client'] = new ApiClient();
        $this->container['importer']   = new Importer( $this->container['api_client'] );
        $this->container['admin']      = new Admin( $this->container['importer'] );
        $this->container['scripts']    = new Assets();
        $this->container['counters']   = new Counters();
        $this->container['limiter']    = new Limiter();
        $this->container['turnstile']  = new Turnstile();
        $this->container['rest_fields'] = new RestFields( $this->container['counters'] );
        $this->container['engagement_api'] = new RestController(
            $this->container['counters'],
            $this->container['limiter'],
            new Comments(),
            $this->container['turnstile']
        );
        $this->container['post_admin'] = new PostAdmin( $this->container['counters'] );
    }

    /**
     * Executed after all plugins are loaded
     *
     * At this point devto_wp_importer Pro is loaded
     *
     * @since 2.8.7
     *
     * @return void
     */
    public function after_plugins_loaded() {
        // Initiate background processes and other tasks
    }

    /**
     * Check whether woocommerce is installed and active
     *
     * @since 2.9.16
     *
     * @return bool
     */
    public function has_woocommerce() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Check whether woocommerce is installed
     *
     * @since 3.2.8
     *
     * @return bool
     */
    public function is_woocommerce_installed() {
        return in_array( 'woocommerce/woocommerce.php', array_keys( get_plugins() ), true );
    }

    /**
	 * Get the plugin url.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', DEVTO_WP_IMPORTER_FILE ) );
	}

    /**
     * Get the template file path to require or include.
     *
     * @param string $name
     * @return string
     */
    public function get_template_path( $name ) {
        $template = untrailingslashit( DEVTO_WP_IMPORTER_TEMPLATE_DIR ) . '/' . untrailingslashit( $name );

        return apply_filters( 'devto-wp-importer_template', $template, $name );
    }

    /**
     * Get templates passing attributes and including the file.
     * You can use this method to load php template file by following:
     * Example-1: welabs_devto_wp_importer()->get_template( 'admin/custom-meta-fields.php' );
     * Example-2: welabs_devto_wp_importer()->get_template( 'admin/custom-meta-fields.php', [
			'loop' => $loop,
			'variation_data' => $variation_data,
			'variation' => $variation
		] );
     *
     * @param mixed  $template_name
     * @param array  $args          (default: array())
     * @param string $template_path (default: '')
     * @param string $default_path  (default: '')
     *
     * @return void
     */
    public function get_template( $template_name, $args = [] ) {
        if ( $args && is_array( $args ) ) {
            extract( $args ); // phpcs:ignore
        }

        $template_path = $this->get_template_path( $template_name );

        if ( ! file_exists( $template_path ) ) {
            _doing_it_wrong( __FUNCTION__, sprintf( '<code>%s</code> does not exist.', esc_html( $template_path ) ), DEVTO_WP_IMPORTER_PLUGIN_VERSION );

            return;
        }

        do_action( 'devto_wp_importer_before_template_part', $template_name, $args );

        include $this->get_template_path( $template_name );

        do_action( 'devto_wp_importer_after_template_part', $template_name, $args );
    }
}
