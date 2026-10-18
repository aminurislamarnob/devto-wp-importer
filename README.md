# Devto WP Importer

A WordPress plugin that imports your Dev.to articles into WordPress posts with one click.  
It includes duplicate prevention via Dev.to article ID mapping and incremental updates on subsequent imports.

## Features

- One-click import from the WordPress admin dashboard (`Tools -> Dev.to Importer`)
- Duplicate prevention using post meta mapping (`_devto_wp_importer_devto_id`)
- Smart updates using Dev.to `edited_at` change detection
- Full content import (title, HTML body, excerpt, tags, and metadata)
- Dev.to engagement import (reactions, comments, and view counts), refreshed on every import
- Site engagement for a headless frontend: Medium-style claps, view counting and Markdown comments, added on top of the Dev.to counts (see [Engagement](#engagement))
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

Run the unit tests (no WordPress install needed; WordPress functions are stubbed in `tests/bootstrap.php`):

```bash
composer test
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

## Engagement

Claps, comments and views are kept as two counts per post and only added together when read:

| | Dev.to snapshot (replaced by every import) | Site (visitors on the frontend) |
| --- | --- | --- |
| Claps | `_devto_wp_importer_devto_reactions` | `_devto_wp_importer_site_claps` |
| Comments | `_devto_wp_importer_devto_comments` | approved WordPress comments |
| Views | `_devto_wp_importer_devto_views` | `_devto_wp_importer_site_views` |

So re-importing never double counts: run it as often as you like and the total is always Dev.to's latest number plus the site's. Posts written in WordPress just have Dev.to counts of 0.

Post REST responses carry an `engagement` field, `{ claps, comments, views }`, each `{ devto, site, total }`. `devto_meta` also has the article's Dev.to `url`.

Public routes under `/wp-json/devto-wp-importer/v1` (published, non-password-protected posts only):

| Route | |
| --- | --- |
| `POST /posts/{id}/view?seen=0\|1` | Counts a view, once per visitor and post every 24 hours (bots skipped; `seen=1` only reads). Returns `{ engagement, claps_left }` |
| `POST /posts/{id}/claps?count=N` | Adds claps, up to 50 per visitor and post every 24 hours (`devto_wp_importer_clap_cap` filter). Returns `{ engagement, accepted, claps_left }` |
| `GET /posts/{id}/comments` | Approved comments, oldest first, one level of replies: `{ comments, open }` |
| `POST /posts/{id}/comments` | `{ author, email, content, parent, turnstile, website }`. Cloudflare Turnstile is required, `website` is a honeypot. Moderation follows Settings → Discussion. Returns `{ status: approved\|pending, comment, engagement }` |
| `GET /stats` | Views and reactions over all published posts, Dev.to followers: `{ views, reactions, followers }` |

A visitor is a salted hash of their IP (`CF-Connecting-IP` behind Cloudflare, `devto_wp_importer_visitor_ip` filter), kept only in expiring transients; each visitor gets 60 engagement requests a minute.

Frontend comments are Markdown. They're stored with `&`, `<` and `>` as entities (so kses can't strip a code sample's `<div>`) and flagged with `_devto_wp_importer_markdown` comment meta; the API returns the Markdown exactly as typed. Comments written in wp-admin come back as plain text.

Turnstile tokens are verified with the **Turnstile Secret Key** setting on the importer page, or Contact Form 7's Turnstile key when that's empty.

In wp-admin, the Posts list has Claps and Views columns (total, with the DEV / site split), and the post edit screen has an Engagement box where the site's counts can be corrected.

## Extensibility

### Filters

- `devto_wp_importer_api_request_args`
- `devto_wp_importer_should_import_article`
- `devto_wp_importer_article_post_data`
- `devto_wp_importer_post_status`
- `devto_wp_importer_article_meta_data`
- `devto_wp_importer_clap_cap`
- `devto_wp_importer_visitor_ip`
- `devto_wp_importer_turnstile_secret`

### Actions

- `devto_wp_importer_before_import`
- `devto_wp_importer_after_import`
- `devto_wp_importer_article_imported`
- `devto_wp_importer_article_updated`
- `devto_wp_importer_article_skipped`