<?php

namespace WeLabs\DevtoWpImporter\Engagement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Engagement in wp-admin: Claps and Views columns on the Posts list, and an
 * Engagement box on the post edit screen where the site's own counts can be
 * corrected (Dev.to's are shown read-only; the next import replaces them).
 */
class PostAdmin {

	const NONCE = 'devto_wp_importer_engagement';

	/**
	 * @var Counters
	 */
	private $counters;

	public function __construct( Counters $counters ) {
		$this->counters = $counters;

		add_filter( 'manage_post_posts_columns', [ $this, 'add_columns' ] );
		add_action( 'manage_post_posts_custom_column', [ $this, 'render_column' ], 10, 2 );
		add_action( 'add_meta_boxes_post', [ $this, 'add_meta_box' ] );
		add_action( 'save_post_post', [ $this, 'save_meta_box' ] );
		add_action( 'admin_head-edit.php', [ $this, 'column_styles' ] );
	}

	/**
	 * Put Claps and Views just before the Comments column.
	 */
	public function add_columns( $columns ) {
		$added = [
			'devto_wp_importer_claps' => __( 'Claps', 'devto-wp-importer' ),
			'devto_wp_importer_views' => __( 'Views', 'devto-wp-importer' ),
		];

		$position = array_search( 'comments', array_keys( $columns ), true );

		if ( false === $position ) {
			return $columns + $added;
		}

		return array_slice( $columns, 0, $position, true ) + $added + array_slice( $columns, $position, null, true );
	}

	public function render_column( $column, $post_id ) {
		$metric = [
			'devto_wp_importer_claps' => 'claps',
			'devto_wp_importer_views' => 'views',
		][ $column ] ?? '';

		if ( '' === $metric ) {
			return;
		}

		$count = $this->counters->get( $post_id )[ $metric ];

		printf(
			'<strong>%1$s</strong><br><span class="devto-wp-importer-split">%2$s</span>',
			esc_html( number_format_i18n( $count['total'] ) ),
			esc_html(
				sprintf(
					/* translators: 1: count from Dev.to, 2: count from this site */
					__( 'DEV %1$s · site %2$s', 'devto-wp-importer' ),
					number_format_i18n( $count['devto'] ),
					number_format_i18n( $count['site'] )
				)
			)
		);
	}

	public function column_styles() {
		echo '<style>.column-devto_wp_importer_claps,.column-devto_wp_importer_views{width:8em}.devto-wp-importer-split{color:#646970;font-size:12px}</style>';
	}

	public function add_meta_box() {
		add_meta_box(
			'devto-wp-importer-engagement',
			__( 'Engagement', 'devto-wp-importer' ),
			[ $this, 'render_meta_box' ],
			'post',
			'side',
			'low'
		);
	}

	public function render_meta_box( $post ) {
		$counts = $this->counters->get( $post->ID );

		$editable = [
			'claps' => __( 'Claps', 'devto-wp-importer' ),
			'views' => __( 'Views', 'devto-wp-importer' ),
		];

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<table class="widefat striped" style="border:0;box-shadow:none">
			<thead>
				<tr>
					<th></th>
					<th><?php esc_html_e( 'DEV', 'devto-wp-importer' ); ?></th>
					<th><?php esc_html_e( 'Site', 'devto-wp-importer' ); ?></th>
					<th><?php esc_html_e( 'Total', 'devto-wp-importer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $editable as $metric => $label ) : ?>
					<tr>
						<th scope="row"><label for="devto-wp-importer-site-<?php echo esc_attr( $metric ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><?php echo esc_html( number_format_i18n( $counts[ $metric ]['devto'] ) ); ?></td>
						<td>
							<input
								type="number"
								min="0"
								step="1"
								style="width:5.5em"
								id="devto-wp-importer-site-<?php echo esc_attr( $metric ); ?>"
								name="devto_wp_importer_site[<?php echo esc_attr( $metric ); ?>]"
								value="<?php echo esc_attr( $counts[ $metric ]['site'] ); ?>"
							/>
							<input type="hidden" name="devto_wp_importer_site_original[<?php echo esc_attr( $metric ); ?>]" value="<?php echo esc_attr( $counts[ $metric ]['site'] ); ?>" />
						</td>
						<td><?php echo esc_html( number_format_i18n( $counts[ $metric ]['total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Comments', 'devto-wp-importer' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $counts['comments']['devto'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $counts['comments']['site'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $counts['comments']['total'] ) ); ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Site counts come from visitors on your frontend; edit them to clean up spam. DEV counts are refreshed by every import. Site comments are this post\'s approved comments.', 'devto-wp-importer' ); ?>
		</p>
		<?php
	}

	/**
	 * Save edited site counts. Only a number you actually changed is written:
	 * saving the post for any other reason must not roll back claps or views
	 * that came in while the edit screen was open.
	 */
	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$submitted = isset( $_POST['devto_wp_importer_site'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['devto_wp_importer_site'] ) ) : [];
		$original  = isset( $_POST['devto_wp_importer_site_original'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['devto_wp_importer_site_original'] ) ) : [];
		$current   = $this->counters->get( $post_id );

		$claps = $current['claps']['site'];
		$views = $current['views']['site'];

		if ( isset( $submitted['claps'], $original['claps'] ) && $submitted['claps'] !== $original['claps'] ) {
			$claps = $submitted['claps'];
		}

		if ( isset( $submitted['views'], $original['views'] ) && $submitted['views'] !== $original['views'] ) {
			$views = $submitted['views'];
		}

		if ( $claps !== $current['claps']['site'] || $views !== $current['views']['site'] ) {
			$this->counters->set_site_counts( $post_id, $claps, $views );
		}
	}
}
