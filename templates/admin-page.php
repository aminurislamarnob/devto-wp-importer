<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$api_key_saved = ! empty( get_option( 'devto_wp_importer_api_key', '' ) );
$post_status   = get_option( 'devto_wp_importer_post_status', 'publish' );
$post_author   = absint( get_option( 'devto_wp_importer_post_author', 0 ) );
$import_scope  = get_option( 'devto_wp_importer_import_scope', 'published' );
$last_import   = get_option( 'devto_wp_importer_last_import_result', null );
$turnstile_src = welabs_devto_wp_importer()->turnstile ? welabs_devto_wp_importer()->turnstile->secret_source() : '';
?>

<div class="wrap devto-wp-importer-wrap">
	<h1><?php esc_html_e( 'Dev.to Importer', 'devto-wp-importer' ); ?></h1>

	<div class="devto-wp-importer-layout">
		<div class="devto-wp-importer-main">

			<!-- Settings Card -->
			<section class="devto-wp-importer-card">
				<h2><?php esc_html_e( 'Settings', 'devto-wp-importer' ); ?></h2>
				<form method="post" action="options.php">
					<?php settings_fields( 'devto_wp_importer_settings' ); ?>

					<div class="devto-wp-importer-field-group">
						<label for="devto_wp_importer_api_key">
							<?php esc_html_e( 'API Key', 'devto-wp-importer' ); ?>
						</label>
						<input
							type="password"
							name="devto_wp_importer_api_key"
							id="devto_wp_importer_api_key"
							value=""
							placeholder="<?php echo $api_key_saved ? esc_attr__( '***************** (key is saved)', 'devto-wp-importer' ) : esc_attr__( 'Enter your Dev.to API key', 'devto-wp-importer' ); ?>"
							autocomplete="off"
						/>
						<?php if ( $api_key_saved ) : ?>
							<span class="description">
								<?php esc_html_e( 'A key is already saved. Enter a new key to replace it, or leave blank to keep the existing key.', 'devto-wp-importer' ); ?>
							</span>
						<?php else : ?>
							<span class="description">
								<?php
								printf(
									/* translators: %s: URL to Dev.to settings */
									esc_html__( 'Generate your API key at %s', 'devto-wp-importer' ),
									'<a href="https://dev.to/settings/extensions" target="_blank" rel="noopener">dev.to/settings/extensions</a>'
								);
								?>
							</span>
						<?php endif; ?>
					</div>

					<div class="devto-wp-importer-field-group">
						<label for="devto_wp_importer_turnstile_secret">
							<?php esc_html_e( 'Turnstile Secret Key (comments)', 'devto-wp-importer' ); ?>
						</label>
						<input
							type="password"
							name="devto_wp_importer_turnstile_secret"
							id="devto_wp_importer_turnstile_secret"
							value=""
							placeholder="<?php echo 'plugin' === $turnstile_src ? esc_attr__( '***************** (key is saved)', 'devto-wp-importer' ) : esc_attr__( 'Optional', 'devto-wp-importer' ); ?>"
							autocomplete="off"
						/>
						<span class="description">
							<?php
							if ( 'plugin' === $turnstile_src ) {
								esc_html_e( 'Frontend comments are verified with this key. Enter a new key to replace it, or leave blank to keep it.', 'devto-wp-importer' );
							} elseif ( 'contact-form-7' === $turnstile_src ) {
								esc_html_e( 'Frontend comments are verified with Contact Form 7\'s Turnstile key; nothing to set here. Enter a key only to use a different one.', 'devto-wp-importer' );
							} else {
								esc_html_e( 'Frontend comments need a Cloudflare Turnstile secret key, from this field or Contact Form 7\'s Turnstile integration. Until one is set, the comment form is unavailable.', 'devto-wp-importer' );
							}
							?>
						</span>
					</div>

					<div class="devto-wp-importer-field-divider"></div>

					<div class="devto-wp-importer-fields-row">
						<div class="devto-wp-importer-field-group">
							<label for="devto_wp_importer_post_status">
								<?php esc_html_e( 'Import Post Status', 'devto-wp-importer' ); ?>
							</label>
							<select name="devto_wp_importer_post_status" id="devto_wp_importer_post_status">
								<option value="draft" <?php selected( $post_status, 'draft' ); ?>>
									<?php esc_html_e( 'Draft', 'devto-wp-importer' ); ?>
								</option>
								<option value="publish" <?php selected( $post_status, 'publish' ); ?>>
									<?php esc_html_e( 'Published', 'devto-wp-importer' ); ?>
								</option>
								<option value="pending" <?php selected( $post_status, 'pending' ); ?>>
									<?php esc_html_e( 'Pending Review', 'devto-wp-importer' ); ?>
								</option>
							</select>
							<span class="description">
								<?php esc_html_e( 'Unpublished Dev.to articles are always imported as drafts.', 'devto-wp-importer' ); ?>
							</span>
						</div>

						<div class="devto-wp-importer-field-group">
							<label for="devto_wp_importer_post_author">
								<?php esc_html_e( 'Post Author', 'devto-wp-importer' ); ?>
							</label>
							<?php
							wp_dropdown_users(
								[
									'name'              => 'devto_wp_importer_post_author',
									'id'                => 'devto_wp_importer_post_author',
									'selected'          => $post_author,
									'show_option_none'  => __( '- Current User -', 'devto-wp-importer' ),
									'option_none_value' => 0,
								]
							);
							?>
							<span class="description">
								<?php esc_html_e( 'WordPress user assigned as the author of imported posts.', 'devto-wp-importer' ); ?>
							</span>
						</div>

						<div class="devto-wp-importer-field-group">
							<label for="devto_wp_importer_import_scope">
								<?php esc_html_e( 'Import Scope', 'devto-wp-importer' ); ?>
							</label>
							<select name="devto_wp_importer_import_scope" id="devto_wp_importer_import_scope">
								<option value="published" <?php selected( $import_scope, 'published' ); ?>>
									<?php esc_html_e( 'Published articles only', 'devto-wp-importer' ); ?>
								</option>
								<option value="all" <?php selected( $import_scope, 'all' ); ?>>
									<?php esc_html_e( 'All articles (including drafts)', 'devto-wp-importer' ); ?>
								</option>
							</select>
						</div>
					</div>

					<button type="submit" name="submit" class="devto-wp-importer-btn-save">
						<?php esc_html_e( 'Save Settings', 'devto-wp-importer' ); ?>
					</button>
				</form>
			</section>

			<!-- Import Card -->
			<section class="devto-wp-importer-card devto-wp-importer-card-emphasis">
				<h2><?php esc_html_e( 'Import Articles', 'devto-wp-importer' ); ?></h2>
				<div class="devto-wp-importer-import-section">
					<p>
						<?php esc_html_e( 'Import your Dev.to posts into WordPress. Existing entries are matched by Dev.to ID and updated only when changes are detected.', 'devto-wp-importer' ); ?>
					</p>
					<div class="devto-wp-importer-import-actions">
						<button
							type="button"
							id="devto-wp-importer-import-btn"
							class="button"
							data-label="<?php esc_attr_e( 'Import Articles from Dev.to', 'devto-wp-importer' ); ?>"
						>
							<?php esc_html_e( 'Import Articles from Dev.to', 'devto-wp-importer' ); ?>
						</button>
						<span class="spinner" id="devto-wp-importer-spinner"></span>
					</div>
				</div>

				<div id="devto-wp-importer-results" class="devto-wp-importer-results">
					<div id="devto-wp-importer-results-summary"></div>
					<div id="devto-wp-importer-messages-log"></div>
				</div>
			</section>
		</div>

		<aside class="devto-wp-importer-sidebar">
			<?php if ( $last_import && ! empty( $last_import['results'] ) ) : ?>
				<section class="devto-wp-importer-card">
					<h2><?php esc_html_e( 'Last Import', 'devto-wp-importer' ); ?></h2>
					<div class="devto-wp-importer-last-import">
						<p class="devto-wp-importer-last-import-date">
							<strong><?php esc_html_e( 'Date:', 'devto-wp-importer' ); ?></strong>
							<?php echo esc_html( $last_import['timestamp'] ?? '-' ); ?>
						</p>
						<div class="devto-wp-importer-info-grid">
							<div class="devto-wp-importer-stat-created">
								<span class="devto-wp-importer-label"><?php esc_html_e( 'Created', 'devto-wp-importer' ); ?></span>
								<span class="devto-wp-importer-value"><?php echo absint( $last_import['results']['created'] ?? 0 ); ?></span>
							</div>
							<div class="devto-wp-importer-stat-updated">
								<span class="devto-wp-importer-label"><?php esc_html_e( 'Updated', 'devto-wp-importer' ); ?></span>
								<span class="devto-wp-importer-value"><?php echo absint( $last_import['results']['updated'] ?? 0 ); ?></span>
							</div>
							<div class="devto-wp-importer-stat-skipped">
								<span class="devto-wp-importer-label"><?php esc_html_e( 'Skipped', 'devto-wp-importer' ); ?></span>
								<span class="devto-wp-importer-value"><?php echo absint( $last_import['results']['skipped'] ?? 0 ); ?></span>
							</div>
							<div class="devto-wp-importer-stat-failed">
								<span class="devto-wp-importer-label"><?php esc_html_e( 'Failed', 'devto-wp-importer' ); ?></span>
								<span class="devto-wp-importer-value"><?php echo absint( $last_import['results']['failed'] ?? 0 ); ?></span>
							</div>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<section class="devto-wp-importer-card">
				<h2><?php esc_html_e( 'How It Works', 'devto-wp-importer' ); ?></h2>
				<ol class="devto-wp-importer-steps">
					<li><?php esc_html_e( 'Enter your Dev.to API key and save settings.', 'devto-wp-importer' ); ?></li>
					<li><?php esc_html_e( 'Run the import to sync your latest articles.', 'devto-wp-importer' ); ?></li>
					<li><?php esc_html_e( 'Future imports update only changed content without duplicates.', 'devto-wp-importer' ); ?></li>
					<li><?php esc_html_e( 'Tags, cover images, and metadata are synced automatically.', 'devto-wp-importer' ); ?></li>
				</ol>
			</section>
		</aside>
	</div>
</div>
