# Dev.to to WordPress Importer — Detailed Development Plan

## 1. Project Overview

A WordPress plugin that imports articles from Dev.to into WordPress posts via the Forem API v1. The plugin provides a one-click "Import" button in the WordPress admin dashboard, maps Dev.to article IDs to WordPress post meta to prevent duplicates, and updates existing posts on subsequent imports.

---

## 2. Dev.to (Forem) API Analysis

### 2.1 Authentication

- **Method:** API key passed via the `api-key` HTTP header.
- **Obtaining a key:** Dev.to -> Settings -> Extensions -> Generate API Key.
- **Accept header (v1):** `application/vnd.forem.api-v1+json`

### 2.2 Key Endpoints Used

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `/api/articles/me/published` | GET | Yes | List the authenticated user's published articles (paginated, default 30/page, max 1000/page) |
| `/api/articles/me/all` | GET | Yes | List all articles (published + unpublished, includes `page_views_count`) |
| `/api/articles/{id}` | GET | No* | Fetch a single article with full content (`body_html`, `body_markdown`) |

> *The list endpoints do NOT return `body_html` or `body_markdown`. A follow-up call to `/api/articles/{id}` is required for each article to get the full content.*

### 2.3 Article Response Schema (Single Article — Full)

```json
{
  "type_of": "article",
  "id": 251,
  "title": "Article Title",
  "description": "Short description",
  "slug": "article-title-4aca",
  "path": "/username/article-title-4aca",
  "url": "https://dev.to/username/article-title-4aca",
  "canonical_url": "https://dev.to/username/article-title-4aca",
  "cover_image": "https://...",
  "social_image": "https://...",
  "body_html": "<p>Full HTML content...</p>",
  "body_markdown": "Full **markdown** content...",
  "published": true,
  "published_at": "2023-04-14T14:45:32Z",
  "created_at": "2023-04-14T14:45:32Z",
  "edited_at": "2023-04-14T14:45:33Z",
  "reading_time_minutes": 5,
  "tag_list": ["javascript", "webdev"],
  "tags": "javascript, webdev",
  "comments_count": 12,
  "public_reactions_count": 45,
  "positive_reactions_count": 45,
  "user": {
    "name": "Author Name",
    "username": "authorusername",
    "profile_image": "https://..."
  }
}
```

### 2.4 Pagination

- Parameters: `page` (1-based), `per_page` (1–1000, default 30)
- Strategy: Loop pages until an empty array is returned.

### 2.5 Rate Limits

- **Read:** Generally generous (not strictly documented for reads).
- **Write/Update:** 30 requests/30 seconds (update), 10 requests/30 seconds (create).
- **Strategy:** Add a small delay between individual article fetch requests to be respectful.

---

## 3. Plugin Architecture

### 3.1 Plugin Structure

```
devto-wp-importer/
├── devto-wp-importer.php          # Main plugin file (bootstrap, constants, Composer autoload)
├── includes/
│   ├── DevtoWpImporter.php        # Singleton container + plugin lifecycle/hooks
│   ├── Admin.php                  # Admin page, settings, AJAX endpoint
│   ├── ApiClient.php              # Dev.to API communication layer
│   ├── Importer.php               # Core import/update logic
│   └── Assets.php                 # Enqueue admin scripts/styles for tools page
├── assets/
│   ├── admin/
│   │   ├── js/
│   │   │   └── importer.js        # AJAX import UI handler
│   │   └── css/
│   │       └── importer.css       # Admin page styles
│   └── images/
│       └── (branding assets)
├── templates/
│   └── admin-page.php             # Admin page template
├── .cursor/
│   └── rules/
│       ├── devto-plugin-architecture.mdc
│       ├── php-wordpress-standards.mdc
│       └── wp-hooks-assets-i18n.mdc
├── phpcs.xml                      # WPCS configuration
├── composer.json                  # PSR-4 autoload + tooling
└── README.md
```

### 3.2 Namespace & Autoloading

- **Namespace:** `WeLabs\DevtoWpImporter`
- **Autoloading:** PSR-4 via Composer (`"WeLabs\\DevtoWpImporter\\": "includes/"`).
- **Bootstrap flow:** `devto-wp-importer.php` loads `vendor/autoload.php`, then calls `welabs_devto_wp_importer()` to initialize the singleton.

### 3.3 Main Plugin Class (Singleton)

The main class in `includes/DevtoWpImporter.php` controls lifecycle and composition:
- Registers activation/deactivation hooks
- Defines plugin constants (`DEVTO_WP_IMPORTER_*`)
- Initializes container services in `init_classes()`:
  - `api_client` (`ApiClient`)
  - `importer` (`Importer`)
  - `admin` (`Admin`)
  - `scripts` (`Assets`)
- Exposes extension points through `do_action()` and `apply_filters()`
- Keeps feature registration centralized through hooks (`plugins_loaded`, `init`, `rest_api_init`)

### 3.4 Cursor Rules Alignment

The repository includes Cursor rule files to keep architecture and coding patterns consistent while developing:
- `devto-plugin-architecture.mdc` (always apply architecture constraints)
- `php-wordpress-standards.mdc` (PHP/WP security and coding standards)
- `wp-hooks-assets-i18n.mdc` (hooks/assets/i18n patterns)

---

## 4. Feature Breakdown

### 4.1 Settings Page (Admin -> Tools -> Dev.to Importer)

**Fields:**
| Setting | Type | Storage Key | Description |
|---|---|---|---|
| API Key | Password input | `sdwp_api_key` | Dev.to API key (stored encrypted via `wp_options`) |
| Post Status | Select | `sdwp_post_status` | Status for imported posts: `publish`, `draft`, `pending` (default: `draft`) |
| Post Author | Select | `sdwp_post_author` | WordPress user to assign as post author |
| Import Scope | Select | `sdwp_import_scope` | `published` (default) or `all` (includes drafts) |

**Actions:**
- **Import Articles** button — triggers the import process via AJAX.
- **Import Log** — displays results of the last import (count of created, updated, skipped, failed).

### 4.2 API Client (`Api_Client.php`)

Responsibilities:
- Communicates with Dev.to API v1.
- Methods:
  - `get_articles_list( int $page, int $per_page, string $scope )` — calls `/api/articles/me/published` or `/api/articles/me/all`
  - `get_article( int $article_id )` — calls `/api/articles/{id}` for full content
  - `get_all_articles( string $scope )` — paginated loop to fetch all articles from list endpoint
- Uses `wp_remote_get()` for HTTP requests.
- Returns `WP_Error` on failure.

### 4.3 Importer (`Importer.php`)

This is the core engine. It orchestrates the import process:

#### Import Flow:

```
1. Fetch all article summaries from Dev.to (paginated list)
2. For each article:
   a. Check if a WP post exists with meta `_sdwp_devto_id` = article.id
   b. If EXISTS -> compare `edited_at` timestamp with stored `_sdwp_devto_edited_at`
      - If unchanged -> SKIP (no update needed)
      - If changed -> Fetch full article -> UPDATE existing WP post
   c. If NOT EXISTS -> Fetch full article -> CREATE new WP post
3. Return summary: { created: N, updated: N, skipped: N, failed: N }
```

#### Duplicate Prevention — Post Meta Mapping:

| Meta Key | Value | Purpose |
|---|---|---|
| `_sdwp_devto_id` | Dev.to article `id` (integer) | **Primary mapping key** — prevents duplicates |
| `_sdwp_devto_url` | Full Dev.to article URL | Reference link back to original |
| `_sdwp_devto_slug` | Dev.to slug | Slug reference |
| `_sdwp_devto_edited_at` | `edited_at` timestamp | Change detection for update optimization |
| `_sdwp_devto_published_at` | `published_at` timestamp | Original publish date |
| `_sdwp_devto_canonical_url` | Canonical URL | SEO reference |
| `_devto_wp_importer_devto_reactions` | Reaction count | Engagement metric |
| `_devto_wp_importer_devto_comments` | Comment count | Engagement metric |
| `_devto_wp_importer_devto_views` | View count from `/api/analytics/totals` | Engagement metric |
| `_devto_wp_importer_devto_reading_time` | Reading time in minutes | Display metric |
| `_devto_wp_importer_last_imported` | Timestamp of last import | Audit trail |

#### Lookup Query (Checking for Existing Post):

```php
$existing = get_posts( array(
    'post_type'   => 'post',
    'meta_key'    => '_sdwp_devto_id',
    'meta_value'  => $devto_article_id,
    'post_status' => 'any',
    'numberposts' => 1,
) );
```

#### Field Mapping (Dev.to -> WordPress):

| Dev.to Field | WordPress Field | Notes |
|---|---|---|
| `title` | `post_title` | Direct mapping |
| `body_html` | `post_content` | HTML content used as post content |
| `description` | `post_excerpt` | Used as excerpt |
| `published_at` | `post_date` / `post_date_gmt` | Converted to WP timezone |
| `tag_list` | Tags taxonomy (`post_tag`) | Each tag created/mapped via `wp_set_post_tags()` |
| `cover_image` | Featured image | Downloaded via `media_sideload_image()` and set as thumbnail |
| `published` | `post_status` | `true` -> configured status; `false` -> `draft` |
| `canonical_url` | Post meta `_sdwp_devto_canonical_url` | Stored for SEO plugins |
| `url` | Post meta `_sdwp_devto_url` | Original Dev.to URL |

### 4.4 Cover Image Handling

- If `cover_image` URL exists, use `media_handle_sideload()` to download and attach to the post.
- Store the attachment ID in post meta `_sdwp_devto_cover_image_url` (original URL) to avoid re-downloading on update if unchanged.
- Set as featured image via `set_post_thumbnail()`.

### 4.5 AJAX Import Handler

- **Action:** `wp_ajax_sdwp_import_articles`
- **Nonce:** `sdwp_import_nonce` (verified on every request)
- **Capability check:** `manage_options`
- **Response:** JSON with `{ success, data: { created, updated, skipped, failed, messages } }`

### 4.6 Admin UI

The admin page under **Tools -> Dev.to Importer** contains:
1. **Settings Section** — API key, post status, author, scope.
2. **Import Section** — "Import Now" button with a progress indicator.
3. **Results Section** — After import, shows a summary table of results.
4. **Import History** — Last import timestamp and counts.

---

## 5. Security Considerations

| Concern | Implementation |
|---|---|
| API Key Storage | Stored in `wp_options` — encrypted with `wp_salt()` before storage |
| Nonce Verification | All AJAX requests verified with `check_ajax_referer()` |
| Capability Checks | `current_user_can( 'manage_options' )` on all admin actions |
| Input Sanitization | `sanitize_text_field()`, `absint()`, `esc_url()` on all inputs |
| Output Escaping | `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()` on all outputs |
| Content Sanitization | `wp_kses_post()` on imported HTML content |
| SQL Injection | Using WordPress API (`get_posts`, `update_post_meta`) — no raw SQL |

---

## 6. Error Handling

| Scenario | Handling |
|---|---|
| Invalid/missing API key | Admin notice with link to settings |
| API rate limit (429) | Retry with exponential backoff (1s, 2s, 4s) up to 3 retries |
| API network failure | `WP_Error` propagated, logged, and shown in results |
| Malformed API response | Validated before processing, skipped with error log |
| Image download failure | Post imported without featured image, warning logged |
| Single article failure | Logged, import continues with remaining articles |

---

## 7. Performance Considerations

- **Batch fetching:** List endpoints fetched with `per_page=100` to minimize API calls.
- **Smart updates:** `edited_at` comparison skips unchanged articles without fetching full content.
- **Transient caching:** Store import lock transient to prevent concurrent imports.
- **Image deduplication:** Check if cover image URL has changed before re-downloading.
- **Memory:** Process articles one at a time (no bulk array of full articles in memory).

---

## 8. Database Schema

No custom tables needed. All data stored via:
- `wp_posts` — Imported articles as standard WordPress posts.
- `wp_postmeta` — All mapping metadata (prefixed `_sdwp_`).
- `wp_options` — Plugin settings (`sdwp_api_key`, `sdwp_post_status`, `sdwp_post_author`, `sdwp_import_scope`, `sdwp_last_import_result`).

---

## 9. Hooks & Extensibility

### Filters:
| Filter | Description |
|---|---|
| `sdwp_article_post_data` | Modify the `wp_insert_post` data array before insert/update |
| `sdwp_article_meta_data` | Modify the meta data array before saving |
| `sdwp_import_post_status` | Override the post status for a specific article |
| `sdwp_should_import_article` | Return `false` to skip importing a specific article |
| `sdwp_api_request_args` | Modify wp_remote_get args for API requests |

### Actions:
| Action | Description |
|---|---|
| `sdwp_before_import` | Fires before the import process starts |
| `sdwp_after_import` | Fires after the import process completes |
| `sdwp_article_imported` | Fires after a single article is created |
| `sdwp_article_updated` | Fires after a single article is updated |
| `sdwp_article_skipped` | Fires when an article is skipped (no changes) |

---

## 10. Implementation Phases

### Phase 1: Core Plugin Setup
- Main plugin file with constants, autoloading, singleton bootstrap.
- Settings page with API key input and post options.
- Options API integration for storing/retrieving settings.

### Phase 2: API Client
- `Api_Client` class with `wp_remote_get()` calls.
- Paginated article list fetching.
- Single article fetching.
- Error handling and rate limit awareness.

### Phase 3: Importer Engine
- Duplicate detection via `_sdwp_devto_id` post meta lookup.
- Create new posts from Dev.to articles.
- Update existing posts when `edited_at` changes.
- Tag mapping.
- Cover image sideloading.
- All post meta population.

### Phase 4: Admin UI & AJAX
- Admin page template with settings form and import button.
- AJAX endpoint for import trigger.
- JavaScript for button click, progress feedback, and result display.
- CSS for clean admin styling.

### Phase 5: Polish & Security
- Nonce verification on all endpoints.
- Capability checks.
- Input sanitization and output escaping.
- Admin notices for errors/success.
- Import lock (transient-based) to prevent concurrent runs.
