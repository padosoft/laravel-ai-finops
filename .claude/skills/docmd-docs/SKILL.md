---
name: docmd-docs
description: Use when creating or maintaining the docmd static documentation site under docs-site.
---

# docmd docs workflow

Work in `docs-site/` for the static documentation site.

Rules:
- Keep author-facing content in Markdown only. Do not use MDX, JSX, raw HTML, or `::: button`.
- Use docmd containers for rich layout: `callout`, `tabs`, `steps`, `collapsible`, `grids`, `grid`, and `card`.
- Keep `docmd.config.json` navigation explicit and include every Markdown page.
- Keep semantic search configured in `.docmd-search/config.json` with model `Xenova/all-MiniLM-L6-v2`, chunk size 512, overlap 64, incremental indexing, and topK 10.
- Run `npm run check` and `npm run build` from `docs-site/` before committing.
- Verify `_site/index.html`, `_site/llms.txt`, `_site/sitemap.xml`, and `_site/.docmd-search/manifest.json` after build when semantic search is available.

