# Rule: docmd docs sync

When code changes alter public behavior, update the `docs-site/` docmd site in the same branch.

Checklist:
- Add or update Markdown pages only.
- Add every page to `docs-site/docmd.config.json` navigation.
- Keep README's official docs link near the top.
- Run `npm run check` and `npm run build` from `docs-site/`.
- Confirm generated docs do not leak raw `:::` markers into HTML.
