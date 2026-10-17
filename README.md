# Devto WP Importer

A WordPress plugin that imports your Dev.to articles into WordPress posts with one click.  
It includes duplicate prevention via Dev.to article ID mapping and incremental updates on subsequent imports.

## Features

- One-click import from the WordPress admin dashboard (`Tools -> Dev.to Importer`)
- Duplicate prevention using post meta mapping (`_devto_wp_importer_devto_id`)
- Smart updates using Dev.to `edited_at` change detection
- Full content import (title, HTML body, excerpt, tags, and metadata)
- Dev.to engagement import (reactions, comments, and view counts)
- Cover image sideloading to featured image
- Configurable post status, author, and import scope (published or all)
- Encrypted API key storage
- Extensible via plugin-specific actions and filters

## Installation

1. Place this plugin in your `wp-content/plugins/` directory.
2. Run `composer install`.
3. Activate the plugin from WordPress admin.
4. Go to `Tools -> Dev.to Importer`.
5. Enter your Dev.to API key (from [dev.to/settings/extensions](https://dev.to/settings/extensions)).
6. Click **Import Articles from Dev.to**.

## Development

Install development dependencies:

```bash
composer install
```

Update dependency versions from `composer.json` (modifies `composer.lock`):

```bash
composer update
```

Run PHPCS:

```bash
composer phpcs
```

Auto-fix with PHPCBF:

```bash
composer phpcbf
```

## Production Build

Install production dependencies only:

```bash
composer install --optimize-autoloader --no-dev -q
```

Build release package:

```bash
chmod +x bin/build.sh
bin/build.sh
```

## Architecture

See [DEVELOPMENT-PLAN.md](DEVELOPMENT-PLAN.md) for detailed architecture, API behavior, mapping rules, and import workflow.

## Extensibility

### Filters

- `devto_wp_importer_api_request_args`
- `devto_wp_importer_should_import_article`
- `devto_wp_importer_article_post_data`
- `devto_wp_importer_post_status`
- `devto_wp_importer_article_meta_data`

### Actions

- `devto_wp_importer_before_import`
- `devto_wp_importer_after_import`
- `devto_wp_importer_article_imported`
- `devto_wp_importer_article_updated`
- `devto_wp_importer_article_skipped`