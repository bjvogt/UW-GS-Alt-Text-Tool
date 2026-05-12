# UWGS Alt Text Tool

A WordPress plugin built for the UW Graduate School that helps editors write meaningful alt text for every image. It surfaces missing or weak alt text at every point in the editorial workflow — from upload through publish — and offers smart suggestions where possible.

**Version:** 3.0.1  
**Status:** Stable — ready for testing  
**Requires:** WordPress 6.0+  
**License:** GPL-2.0+

---

## What It Does

### Media Library — List View
- Adds a sortable, filterable **Alt Text** column
- Inline-edit alt text directly in the column — no modal needed
- Sort by alt text status to bring missing images to the top
- Filter to images only when sorted by alt text

### Media Library — Grid View
- Badges on image thumbnails flag images missing or with weak alt text ("Please provide alt text")

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

### Dashboard
- Widget showing alt text coverage statistics across the media library

### Settings Page
- Documents the quality-evaluation rules so editors understand what "good" alt text means

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

## File Structure

```
uwgs-alt-text-tool/
├── uwgs-alt-text-tool.php   Main plugin file, all PHP classes and hooks
└── js/
    ├── uwgs-alt-utils.js         Shared quality + suggestion utilities (no WP deps)
    ├── uwgs-list-view.js         Media library list view column + inline editor
    ├── uwgs-media-grid.js        Media library grid view badges
    ├── uwgs-attachment-details.js Attachment details modal warnings + suggestions
    ├── uwgs-attachment-edit.js   Attachment edit screen (wp-admin/post.php?post=N)
    ├── uwgs-block-editor.js      Gutenberg sidebar, pre-publish check, canvas notice
    ├── uwgs-classic-presave.js   Classic editor pre-save warning
    ├── uwgs-media-modal.js       Add Media modal caption-copy helper
    ├── uwgs-stories.js           uw_stories ACF block save intercept
    └── uwgs-upload-page.js       Upload page prompt
```

---

## REST API

The plugin registers a REST namespace at `uwgs-alt-text/v1` with three endpoints. All require the `upload_files` capability.

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
**Always increment the plugin version** (`Version:` header and `const VERSION`) whenever any JS or CSS file changes. The Pantheon Fastly CDN caches static assets by URL — the version string is the query parameter that busts the cache. If you forget, deploy will appear to have no effect.

### Keeping PHP and JS in sync
`UWGS_Alt_Quality` (PHP) and `UWGSAltUtils` (JS) must stay exactly in sync. The constants `IMAGE_EXTENSIONS` and `LOW_QUALITY_WORDS`, and the logic in `needs_attention()` / `classify()`, are mirrored in both. When a rule changes, update both files and the `get_rules_description()` documentation on the settings page.

### Known edge cases
- ACF gallery and multi-image fields that store comma-separated IDs may not parse correctly
- ACF image fields inside repeaters or flexible-content blocks that are not in the DOM at save time are not checked
- Custom `uw_stories` blocks whose image inputs don't match known ACF selectors will be missed

---

## Background

Built for the [UW Graduate School](https://grad.uw.edu) to support their ongoing accessibility compliance work. The goal is to make alt text feel like a natural part of the editorial workflow rather than an afterthought — surfacing the right prompt at the right moment, offering smart defaults, and never blocking publishing while still making the problem visible.
