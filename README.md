# LiveblogAI for WordPress

Official WordPress plugin for [LiveblogAI](https://liveblog.ai). Embed live coverage with a native block or the `[liveblog]` shortcode.

- Server-rendered initial content with real-time browser updates.
- JavaScript mode for sites that use full-page caching.
- On-demand, isolated previews in the block editor.
- No LiveblogAI account credentials or reader content stored in WordPress.

Requires WordPress 6.3+ and PHP 8.1+. A public LiveblogAI liveblog is required.

## Build from source

Use the Node.js version in `.nvmrc` and pnpm 10.32.1.

```bash
git clone https://github.com/liveblogai/wordpress-plugin.git
cd wordpress-plugin
pnpm install --frozen-lockfile
pnpm lint
pnpm format:check
pnpm typecheck
pnpm build
```

Upload `dist/liveblog.zip` through **Plugins → Add New → Upload Plugin** in WordPress. The plugin uses WordPress's React and editor APIs; it does not bundle another React runtime.

The build validates PHP 8.1 syntax with a static parser. Before release, also run `php -l` on the PHP sources with PHP 8.1+ and check the plugin in a real WordPress installation. No automated test suite is included.

## Usage

Add the **LiveblogAI** block, enter a public liveblog ID, click **Preview LiveblogAI**, and save the post. Classic editor examples:

```text
[liveblog id="YOUR_LIVEBLOG_ID"]
[liveblog id="YOUR_LIVEBLOG_ID" mode="js"]
```

The default rendering mode is configured under **Settings → LiveblogAI**. Individual blocks and shortcodes can override it. The same liveblog can appear only once on a page.

Server rendering includes only the initial posts. The browser handles subsequent updates and pagination; the plugin does not create historical pagination URLs or page-level SEO metadata.

## Caching

For server-rendered embeds, exclude the page from cache plugins, host caches, and CDNs, then purge existing cached copies. The plugin sends `no-store` and marks the request with `DONOTCACHEPAGE`, but cannot override cached responses served before PHP executes. Templates, widgets, and page builders also need explicit cache exclusions.

Use JavaScript mode when cache exclusion is unavailable. Exclude embed markup from script combining, HTML rewriting, and delayed JavaScript execution. Preserve module scripts, JSON, integrity attributes, and declarative Shadow DOM.

SSR fragments are not saved in Transients, object caches, or the database. Network failures show a local retry message rather than stale content. Feeds and REST content use placeholders instead of executable fragments.

## Server-rendered output safety

Remote fragments are separated into content, initialization data and resource descriptors before output. Content passes through a local WordPress KSES allowlist and balanced markup; event handlers and executable URLs are removed. Nested Shadow DOM, basic SVG and supported CSS are retained. Inline CSS is restricted to a subset without escapes or external-resource functions. Embedded frames receive an opaque-origin sandbox with script permission only; custom styling and third-party embeds that require other capabilities need compatibility checks.

The plugin validates the liveblog ID, Reader origin, immutable asset origins and paths, and SHA-256 integrity format. Initialization JSON is decoded and re-encoded with HTML-sensitive characters escaped. WordPress script APIs generate the module tags and enqueue the content-addressed hydration script. Invalid fragments fail closed with an escaped retry message.

Theme recovery and retries use a public, read-only WordPress AJAX endpoint, which validates parameters and applies the same fragment sanitization. The Reader version lower bound is forwarded to Render. This endpoint neither modifies WordPress content nor forwards visitor credentials. There is no global relaxation of WordPress HTML filtering.

The settings sanitizer accepts only the supported `js` / `ssr` modes and discards unknown fields. SSR still includes the initial posts, and the existing browser runtime handles live updates and pagination.

## External services and privacy

This repository contains the WordPress integration only. The LiveblogAI cloud service, reader runtime, and other applications are separate and are not licensed by this repository's license.

Activation alone makes no remote requests. Adding an embed connects to LiveblogAI; editor previews load only when explicitly opened.

- Server rendering requests public content from `https://render.liveblog.ai` and sends the liveblog ID and plugin version. Visitor cookies, authorization headers, and WordPress credentials are not forwarded.
- Reader browsers load rendering assets from `https://render.liveblog.ai` and `https://cdn.liveblog.ai`, and fetch content and updates from `https://reader.api.liveblog.ai` over HTTPS and WSS. These services receive normal network information, including IP addresses. Post media can load from its own source URLs.
- The settings connection check verifies Render health only. It does not verify an individual liveblog or browser CSP configuration.

## Custom service configuration

Only site operators can override service URLs in `wp-config.php`:

```php
define('LIVEBLOG_RENDER_ORIGIN', 'https://render.liveblog.ai');
define('LIVEBLOG_EMBED_SCRIPT_URL', 'https://render.liveblog.ai/embed.js');
define('LIVEBLOG_ASSET_ORIGIN', 'https://cdn.liveblog.ai');
define('LIVEBLOG_READER_ORIGIN', 'https://reader.api.liveblog.ai');
```

Production URLs require HTTPS and must not contain credentials, queries, or fragments. Explicit HTTP loopback URLs are accepted only when `WP_ENVIRONMENT_TYPE` is `local` or `development`. Redirects remain disabled. Authors cannot select arbitrary service URLs.

## Releases

Keep versions synchronized in `package.json`, `src/block.json`, `src/liveblog.php` (both header and constant), and the stable tag / changelog in `src/readme.txt`, then run:

```bash
pnpm release
```

This creates `dist/liveblog-<version>.zip` and its `.sha256` file, plus `dist/liveblog.zip`. Check the checksum from `dist/` with `shasum -a 256 -c liveblog-<version>.zip.sha256`. Since 0.1.6, the ZIP uses the top-level directory `liveblogai/`, matching its translation domain and assigned WordPress.org slug. Deactivate local preview installations through 0.1.5, which used `liveblog/`, before installing the new package. Existing `liveblog/embed` blocks, `[liveblog]` shortcodes and saved settings remain compatible. Rebuilding replaces `dist/`, so archive prior releases separately.

Release commands produce local files only. They do not publish to GitHub or WordPress.org, deploy services, or create Git tags. Distribution files exclude TypeScript sources and build tooling. Internal code comments are stripped; legal notices and the license are retained. Source comments remain available in this repository.

Before publishing, check block insertion and saving, shortcodes, previews, SSR source HTML and browser takeover, JavaScript mode, duplicate and invalid IDs, unavailable content, network failures, cache behavior, narrow screens, browser errors, and theme compatibility in WordPress.

## Contributing

Open issues and pull requests in this repository. Contributions are provided under GPL-2.0-or-later. Keep the plugin independent of private applications and use public HTTP and embed protocols. Source comments are currently written in Chinese; identifiers and user-facing source strings use English, with WordPress i18n for UI text.

## License and branding

Copyright (C) 2026 LiveblogAI. Licensed under [GNU GPL v2 or later](LICENSE), SPDX identifier `GPL-2.0-or-later`. You may use, modify, and redistribute this plugin, including commercially, under that license. Redistribution must preserve required copyright and license notices and provide corresponding source as required by the GPL.

The software license does not grant trademark rights to the LiveblogAI name or logo. Forks must not imply official endorsement or affiliation. This statement does not restrict rights granted by the GPL or lawful descriptive use of the brand.
