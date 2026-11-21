# WordPress plugin development

This repository contains the standalone LiveblogAI WordPress plugin, licensed under GPL-2.0-or-later.

- Integrate through public HTTP and embed protocols. Keep private application code and configuration out of the plugin.
- Keep runtime code in `src/` and local build and packaging tools in `scripts/`.
- Write identifiers, comments, logs, errors and user-facing strings in English. Use WordPress i18n for user-facing strings.
- Document named functions and methods with JSDoc or PHPDoc, except for React function components and anonymous callbacks.
- Do not add or run automated tests. Verify changes with `pnpm lint`, `pnpm format:check`, `pnpm typecheck`, `pnpm build` and manual checks in WordPress.
- Preserve copyright, license, brand and external service notices. The plugin license covers the plugin only.
- Obtain approval before creating or restructuring directories. Commits, pushes, releases and pull requests require explicit authorization.
- Before release, synchronize the version in `package.json`, `src/block.json`, `src/liveblog.php` and `src/readme.txt`.
