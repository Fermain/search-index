# Search Index

Generates a minimal JSON search index for your WordPress posts at:

`wp-content/uploads/search/index.json`

Intended for static sites exported by WP2Static (or similar). The index uses root‑relative URLs so links work after export.

## Installation

1. Copy this plugin directory into `wp-content/plugins/search-index`.
2. Activate “Search Index” in WordPress → Plugins.
3. Visit Tools → Search Index to view status, configure settings, and rebuild the index.

## Output format

```json
{
  "version": "1",
  "generatedAt": "2025-09-15T12:34:56Z",
  "items": [
    {
      "id": 123,
      "slug": "hello-world",
      "title": "Hello world",
      "content": "Stripped excerpt or full content depending on settings",
      "url": "/hello-world/",
      "categories": ["news"],
      "tags": ["intro"]
    }
  ]
}
```

Notes:
- `content` is a 40‑word excerpt from the rendered post body (shortcodes executed, then HTML stripped).
- Dates are intentionally omitted.
- URLs are root‑relative.

## Settings

Tools → Search Index → Settings:
- Resource tag export: Toggle generation of `resource-tags.json` for front-end filtering.

## Regeneration

- Automatically rebuilds when posts are published/trashed or edited (excluding revisions/autosaves).
- You can manually rebuild via the “Rebuild index” button.

## Notes for large sites

- The index currently loads all published posts in one query. If your site has many posts, consider sharding or adding pagination logic.


