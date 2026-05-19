# UWGS Alt Text Tool

A WordPress plugin built for the UW Graduate School that helps editors write meaningful alt text for every image and identify unused media for safe removal. It surfaces missing or weak alt text at every point in the editorial workflow — from upload through publish — and provides a governed workflow for finding and trashing media that is no longer referenced anywhere on the site.

**Version:** 3.2.0  
**Status:** Stable  
**Requires:** WordPress 6.0+, PHP 7.4+  
**License:** GPL-2.0+

---

## What It Does

### Media Library — List View
- Adds a sortable, filterable **Alt Text** column
- Inline-edit alt text directly in the column — no modal needed
- Sort by alt text status to bring missing images to the top
- Filter to images only when sorted by alt text

### Media Library — Grid View
- Badges on image thumbnails flag images missing or with weak alt text

### Attachment Details Modal
- Yellow warning banner when an image has no alt text or weak alt text
- Smart **suggestion button** when alt is blank — offers the image caption or a cleaned-up version of the filename as a one-click starting point
- Navigation warning when moving to the next/previous image while alt is still blank
- Close warning when dismissing the modal while alt is still blank (two-click bypass)

### Classic Editor
- Pre-save warning if any image in the post content or featured image is missing alt text
- Warning persists as an in-editor notice until alt text is added

### Block Editor (Gutenberg)
- Sidebar panel showing alt text status for images in the post
- Pre-publish check flags missing alt text
- Canvas warning notice for images without alt text

### Upload Flow
- Upload status messages prompt editors to add alt text immediately after upload
- Caption is automatically copied to alt text on attachment save (server-side) when alt is still blank

### Dashboard Widget
- Alt text coverage statistics across the media library
- Unused media summary (item count + recoverable disk size) once a scan has been run

### Media > Unused Media
- Scans the database and active theme files to identify attachments with no references anywhere on the site
- Confidence levels: **Unused** (no reference found), **Uncertain** (found only in trashed/serialized content)
- Batch AJAX scanner with a progress bar — no PHP timeout issues on large libraries
- Bulk **Move to Trash** with a confirm dialog (never permanent delete from the UI)
- Per-row **Exclude** action to mark items as intentionally kept
- GA4 pageview column (when configured) — filter to zero-traffic items for highest-confidence candidates
- Export CSV — filename, type, size, upload date, author, confidence, GA views

### Settings Page (three tabs)

**Alt Text tab** — existing quality rules and editor instructions.

**Unused Media tab**
- Scan scope: images only vs. all media types
- Enable/disable weekly scheduled scan (WP-Cron, Sundays at 2 am)
- Excluded items table with one-click unexclude

**Google Analytics tab**
- Auto-detects Google Site Kit if active and connected (no extra credentials needed)
- Manual fallback: service account JSON + GA4 Property ID + analysis window (30/60/90/180 days)
- [Sync GA4 data] button — stores pageview counts as postmeta per attachment

---

## Alt Text Quality Rules

Alt text is evaluated by a shared quality service (`UWGS_Alt_Quality` in PHP, mirrored exactly in `UWGSAltUtils` in JavaScript). Both must be kept in sync when rules change.

| Status | Meaning |
|--------|---------|
| **Good** | Two or more meaningful words; no flags below |
| **Weak** | Single meaningful word — better than nothing, but could be improved |
| **Invalid / needs attention** | Any of the conditions below |

Alt text is flagged as needing attention when it is:
- Empty
- Fewer than 3 characters
- A URL (`http://...`, `www....`)
- A filename with an image extension (`.jpg`, `.png`, etc.)
- Only digits
- A generic low-quality word: *image, photo, img, picture, screenshot, graphic, thumbnail, banner, logo, icon*
- A short code-like pattern (e.g. `img-1234`)

---

## Filename Suggestion Rules

When alt text is blank and no caption exists, the plugin can suggest a cleaned-up version of the filename. Suggestions are generated client-side by `UWGSAltUtils.sanitizeFilename()` and evaluated by `UWGSAltUtils.classifyFilename()`.

**Sanitization steps:**
1. Strip image file extension
2. Strip camera prefix + serial number when followed by meaningful content (`IMG-1603-Jane-Smith` → `Jane Smith`)
3. Split CamelCase words (`ThreeMinuteThesis` → `Three Minute Thesis`)
4. Replace hyphens and underscores with spaces
5. Remove dimension tokens (`800x600`), long date tokens, years, and the word "scaled"
6. Remove trailing sequence numbers (`-0007`, `-940`)
7. Capitalize each word

**A suggestion is offered only when the sanitized result is classified as "good":**
- Not a URL or bare image extension
- Not a single low-quality generic word
- Not a pure camera name (`IMG_1234`, `DSC_5678`)
- Contains at least one meaningful word (not all-caps abbreviation, not all digits)
- Fewer than half of its tokens are junk (digits or short all-uppercase codes)

---

## Unused Media Scanner

### What gets scanned

| Location | Detail |
|----------|--------|
| `post_content` | Gutenberg block attributes (JSON in HTML comments), classic editor `<img>` tags, `uw_stories` block `"id":NNN` patterns |
| `postmeta` | Featured images, ACF fields, serialized page builder data |
| `wp_options` | Widgets, theme customizer, logo/favicon settings |
| Active theme files | `.php`, `.css`, `.js` files — CSS `url(...)`, JS path matches, PHP hardcoded URLs |

Items found only in trashed posts or ambiguous serialized data are marked **Uncertain** rather than **Unused**.

### Recommended workflow on Pantheon

**Trashing** (`Move to Trash` in the UI) calls `wp_trash_post()` — a database-only operation. No files are touched. This is safe to run on any Pantheon environment including live.

**Permanent deletion** (emptying the trash via Media > Library > Trash) removes the physical files from `/wp-content/uploads/`. That directory is on a writable NFS mount on all Pantheon environments, so it works on live — but the files are gone for good. There is no undo beyond a backup restore.

The important constraint is **environment isolation**: each Pantheon environment (dev/test/live) has its own database and its own uploads filesystem. They are not shared. This means:

1. Run the scan on **live** — that's the only environment with the real production database and real upload files.
2. Review results and move items to Trash on live.
3. Let items sit in trash for your comfort window (WordPress keeps them for 30 days by default).
4. Empty trash via Media > Library > Trash, or with WP-CLI: `terminus wp <site>.live -- post delete $(terminus wp <site>.live -- post list --post_type=attachment --post_status=trash --format=ids)`

Do not run the scan on test and then trash on live — the databases are different and attachment IDs will not correspond.

### postmeta keys

| Key | Values |
|-----|--------|
| `_uwgs_unused_status` | `unused` \| `uncertain` \| `in_use` \| `excluded` |
| `_uwgs_unused_scanned_at` | Unix timestamp of last evaluation |
| `_uwgs_unused_first_seen` | Unix timestamp when first flagged unused (set once, never overwritten) |
| `_uwgs_ga_pageviews` | Integer pageview count from GA4 |
| `_uwgs_ga_synced_at` | Unix timestamp of last GA4 sync |

### Scheduled scan (Phase 3)

When enabled in Settings > Unused Media:
- Runs every Sunday at 2 am via WP-Cron
- Sets `set_time_limit(0)` to handle large libraries without timeout
- Attempts a GA4 sync after scan completes
- Logs scan results to the **WP Stream** activity log (connector: `uwgs-unused-media`)
- Shows a one-time dismissible admin notice to each admin listing new items found since their last visit

**Requires WP Stream** for activity logging. A warning appears in settings if Stream is not active.

---

## GA4 Integration

The plugin needs a valid GA4 access token to call the Data API. It tries two sources in order:

1. **Google Site Kit** — if active and connected, reads the stored OAuth token automatically (no additional setup)
2. **Service account JSON** — manual fallback; upload JSON credentials in Settings > Google Analytics

The GA4 sync calls `runReport` with dimension `pagePath` and metric `screenPageViews`, filtered to `/wp-content/uploads/*` paths, and stores results as `_uwgs_ga_pageviews` postmeta.

**Note on Pantheon environments:** Site Kit tokens are encrypted with environment-specific keys (`LOGGED_IN_KEY` / `LOGGED_IN_SALT`). Tokens established on the dev environment cannot be decrypted on test or live. Always connect Site Kit on the environment where you intend to run GA4 syncs.

---

## File Structure

```
uwgs-alt-text-tool/
├── uwgs-alt-text-tool.php         Main plugin file — all PHP classes and hooks
└── js/
    ├── uwgs-alt-utils.js          Shared quality + suggestion utilities (no WP deps)
    ├── uwgs-list-view.js          Media library list view column + inline editor
    ├── uwgs-media-grid.js         Media library grid view badges
    ├── uwgs-attachment-details.js Attachment details modal warnings + suggestions
    ├── uwgs-attachment-edit.js    Attachment edit screen (wp-admin/post.php?post=N)
    ├── uwgs-block-editor.js       Gutenberg sidebar, pre-publish check, canvas notice
    ├── uwgs-classic-presave.js    Classic editor pre-save warning
    ├── uwgs-media-modal.js        Add Media modal caption-copy helper
    ├── uwgs-stories.js            uw_stories ACF block save intercept
    ├── uwgs-upload-page.js        Upload page prompt
    ├── uwgs-unused.js             Unused Media page — scan progress, exclude, bulk trash
    └── uwgs-ga-settings.js        Google Analytics settings — save credentials, sync
```

---

## REST API

The plugin registers a REST namespace at `uwgs-alt-text/v1`. All endpoints require the `upload_files` capability.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/attachments/{id}` | Get alt text and quality status for one attachment |
| `POST` | `/attachments/{id}` | Save alt text for one attachment |
| `POST` | `/attachments/check` | Batch quality check — body: `{ ids: [1, 2, ...] }` |
| `POST` | `/attachments/bulk` | Bulk save high-confidence suggestions — body: `{ updates: [{id, alt_text}] }` |

JavaScript save calls are REST-first with an `admin-ajax.php` fallback for compatibility with aggressive caching environments.

---

## Development Notes

### Version bumping
**Always increment the plugin version** (`Version:` header and `const VERSION`) whenever any JS or CSS file changes. The Pantheon Fastly CDN caches static assets by URL — the version string is the query parameter that busts the cache. Both the plugin header `* Version:` and `const VERSION` must be updated together.

### Keeping PHP and JS in sync
`UWGS_Alt_Quality` (PHP) and `UWGSAltUtils` (JS) must stay exactly in sync. The constants `IMAGE_EXTENSIONS` and `LOW_QUALITY_WORDS`, and the logic in `needs_attention()` / `classify()`, are mirrored in both. When a rule changes, update both files and the `get_rules_description()` documentation on the settings page.

### Pushing to the standalone plugin repo
This plugin lives inside a larger monorepo. To push the latest commits to the standalone GitHub repo:

```bash
git push-plugin
```

This is a git alias for:

```bash
git subtree push --prefix=wp-content/plugins/uwgs-alt-text-tool plugin-repo main --rejoin
```

The `--rejoin` flag records a merge commit that marks the split point, so each push only processes new commits rather than re-walking the full history.

If the alias or remote aren't set up yet:

```bash
git remote add plugin-repo https://github.com/bjvogt/UW-GS-Alt-Text-Tool.git
git config alias.push-plugin "subtree push --prefix=wp-content/plugins/uwgs-alt-text-tool plugin-repo main --rejoin"
```

### Known edge cases
- ACF gallery and multi-image fields that store comma-separated IDs may not parse correctly
- ACF image fields inside repeaters or flexible-content blocks that are not in the DOM at save time are not checked
- Custom `uw_stories` blocks whose image inputs don't match known ACF selectors will be missed
- Site Kit GA4 tokens expire after ~1 hour; if auto-sync fails, visit the Site Kit dashboard to refresh the token

---

## Background

Built for the [UW Graduate School](https://grad.uw.edu) to support their ongoing accessibility compliance work. The goal is to make alt text feel like a natural part of the editorial workflow rather than an afterthought — surfacing the right prompt at the right moment, offering smart defaults, and never blocking publishing while still making the problem visible. The unused media feature reduces wasted alt text effort by letting editors safely remove files that are no longer in use before tackling quality improvements.
