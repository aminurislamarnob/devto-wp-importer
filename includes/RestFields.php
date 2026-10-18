<?php

namespace WeLabs\DevtoWpImporter;

use WeLabs\DevtoWpImporter\Engagement\Counters;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * RestFields class
 *
 * Exposes imported Dev.to metadata on the core post REST responses.
 */
class RestFields {

    /**
     * @var Counters
     */
    private $counters;

    /**
     * Constructor.
     *
     * @param Counters $counters Engagement counters.
     */
    public function __construct( Counters $counters ) {
        $this->counters = $counters;

        add_action( 'rest_api_init', [ $this, 'register_rest_fields' ] );
    }

    /**
     * Expose imported Dev.to metadata on the post REST responses.
     *
     * Adds a single `devto_meta` key to both the list (`/wp/v2/posts`) and
     * single (`/wp/v2/posts/{id}`) responses containing the stored Dev.to fields.
     *
     * @return void
     */
    public function register_rest_fields() {
        register_rest_field(
            'post',
            'post_image_original',
            [
                'get_callback' => [ $this, 'get_post_image_original_rest_field' ],
                'schema'       => [
                    'description' => __( 'Original featured image URL.', 'devto-wp-importer' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                ],
            ]
        );

        register_rest_field(
            'post',
            'devto_meta',
            [
                'get_callback' => [ $this, 'get_devto_meta_rest_field' ],
                'schema'       => [
                    'description' => __( 'Imported Dev.to article metadata.', 'devto-wp-importer' ),
                    'type'        => 'object',
                    'context'     => [ 'view', 'edit' ],
                    'properties'  => [
                        'url'           => [ 'type' => [ 'string', 'null' ] ],
                        'edited_at'     => [ 'type' => [ 'string', 'null' ] ],
                        'published_at'  => [ 'type' => [ 'string', 'null' ] ],
                        'body_markdown' => [ 'type' => [ 'string', 'null' ] ],
                        'reactions'     => [ 'type' => [ 'integer', 'null' ] ],
                        'comments'      => [ 'type' => [ 'integer', 'null' ] ],
                        'views'         => [ 'type' => [ 'integer', 'null' ] ],
                        'reading_time'  => [ 'type' => [ 'integer', 'null' ] ],
                    ],
                ],
            ]
        );

        $count_schema = [
            'type'       => 'object',
            'properties' => [
                'devto' => [ 'type' => 'integer' ],
                'site'  => [ 'type' => 'integer' ],
                'total' => [ 'type' => 'integer' ],
            ],
        ];

        register_rest_field(
            'post',
            'engagement',
            [
                'get_callback' => [ $this, 'get_engagement_rest_field' ],
                'schema'       => [
                    'description' => __( 'Claps, comments and views: the Dev.to snapshot, the site\'s own count and their total.', 'devto-wp-importer' ),
                    'type'        => 'object',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                    'properties'  => [
                        'claps'    => $count_schema,
                        'comments' => $count_schema,
                        'views'    => $count_schema,
                    ],
                ],
            ]
        );
    }

    /**
     * Build the `engagement` REST field value for a post.
     *
     * @param array $post Post data array from the REST response.
     *
     * @return array
     */
    public function get_engagement_rest_field( $post ) {
        return $this->counters->get( isset( $post['id'] ) ? absint( $post['id'] ) : 0 );
    }

    /**
     * Build the `devto_meta` REST field value for a post.
     *
     * @param array $post Post data array from the REST response.
     *
     * @return array
     */
    public function get_devto_meta_rest_field( $post ) {
        $post_id = isset( $post['id'] ) ? absint( $post['id'] ) : 0;

        $string_keys = [
            'url'          => '_devto_wp_importer_devto_url',
            'edited_at'    => '_devto_wp_importer_devto_edited_at',
            'published_at' => '_devto_wp_importer_devto_published_at',
        ];

        $int_keys = [
            'reactions'    => '_devto_wp_importer_devto_reactions',
            'comments'     => '_devto_wp_importer_devto_comments',
            'views'        => '_devto_wp_importer_devto_views',
            'reading_time' => '_devto_wp_importer_devto_reading_time',
        ];

        $meta = [];

        foreach ( $string_keys as $field => $meta_key ) {
            $value          = get_post_meta( $post_id, $meta_key, true );
            $meta[ $field ] = ( '' === $value || false === $value ) ? null : sanitize_text_field( $value );
        }

        foreach ( $int_keys as $field => $meta_key ) {
            $value          = get_post_meta( $post_id, $meta_key, true );
            $meta[ $field ] = ( '' === $value || false === $value ) ? null : absint( $value );
        }

        $body_markdown         = get_post_meta( $post_id, '_devto_wp_importer_devto_body_markdown', true );
        $meta['body_markdown'] = ( '' === $body_markdown || false === $body_markdown ) ? null : (string) $body_markdown;

        return $meta;
    }

    /**
     * Build the `post_image_original` REST field value for a post.
     *
     * @param array $post Post data array from the REST response.
     *
     * @return string|null
     */
    public function get_post_image_original_rest_field( $post ) {
        $post_id      = isset( $post['id'] ) ? absint( $post['id'] ) : 0;
        $thumbnail_id = $post_id ? get_post_thumbnail_id( $post_id ) : 0;

        if ( ! $thumbnail_id ) {
            return null;
        }

        $url = wp_get_attachment_image_url( $thumbnail_id, 'full' );

        return $url ? esc_url_raw( $url ) : null;
    }
}
