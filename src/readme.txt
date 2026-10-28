=== LiveblogAI ===
Contributors: liveai
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: liveblog, live coverage, embed

Embed live coverage in WordPress with a LiveblogAI block or shortcode.

== Description ==

Use server rendering for the first posts, followed by LiveblogAI's real-time browser updates. JavaScript mode is available for sites that must retain full-page caching.

WordPress saves the liveblog ID and display settings. It does not store live coverage, account credentials, or a copy of the LiveblogAI reader runtime.

== Installation ==

1. Upload liveblog.zip in Plugins > Add New > Upload Plugin and activate it.
2. Add a LiveblogAI block and enter a public liveblog ID, or use [liveblog id="YOUR_ID"].
3. Click Preview LiveblogAI to see the public content, then publish your post.
4. For server rendering, exclude that page from all full-page caches and purge existing cached copies. Otherwise select JavaScript mode in Settings > LiveblogAI or use [liveblog id="YOUR_ID" mode="js"].

== External services ==

This plugin requires the LiveblogAI service at https://liveblog.ai. Adding an embed authorizes requests needed to display public coverage. Activation alone makes no remote request. Editor previews load only when explicitly opened.

Server rendering sends the liveblog ID and plugin version to https://render.liveblog.ai. It does not forward visitor cookies, authorization headers, or WordPress credentials.

Reader browsers load rendering code and styles from https://render.liveblog.ai and https://cdn.liveblog.ai, and fetch public content and updates from https://reader.api.liveblog.ai over HTTPS and WSS. These services receive network information such as the client's IP address as part of normal requests. Post media may load from its own source URLs.

The administrator's Check Render connection button requests only the Render health endpoint. It does not verify a particular liveblog or the browser's content security policy.

Service terms: https://liveblog.ai/terms
Privacy policy: https://liveblog.ai/privacy

== Source code and build instructions ==

The complete, uncompressed plugin source and build tools are publicly available at https://github.com/liveblogai/wordpress-plugin.

Build instructions: https://github.com/liveblogai/wordpress-plugin#build-from-source

Use the Node.js version in .nvmrc and pnpm 10.32.1. Run `pnpm install --frozen-lockfile`, then `pnpm build` to create `dist/liveblog.zip`. JavaScript is bundled from the TypeScript source in src/; PHP and CSS are packaged by scripts/build.ts. The plugin uses WordPress's own React and editor APIs.

== Caching and compatibility ==

Server-rendered pages must be excluded from cache plugins, host caches and CDNs. No-store headers and DONOTCACHEPAGE cannot override a response served before PHP executes. This includes embeddings in templates, widgets and page builders. JavaScript mode can be used on cached pages.

Exclude LiveblogAI markup from script combining, HTML rewriting and delayed JavaScript execution. Preserve module scripts, JSON, integrity attributes and declarative Shadow DOM.

Previews are isolated iframes. Frontend server rendering embeds the HTML fragment directly. RSS and REST responses include a placeholder instead of executable content. WordPress continues to own the page URL, title, description and sharing metadata.

Only the initial posts are included in server-rendered HTML. The plugin does not create historical pagination URLs. The same liveblog can appear only once on a page.

== Frequently Asked Questions ==

= Do I need a LiveblogAI account? =

You need a public liveblog hosted by LiveblogAI. Creating and publishing coverage is managed in the LiveblogAI service, outside WordPress. This plugin embeds existing public coverage and does not store LiveblogAI account credentials.

= Does this plugin store liveblog posts in WordPress? =

No. WordPress stores only the liveblog ID and display settings. Content is retrieved from the LiveblogAI service.

= Can I use this with a full-page cache? =

Use JavaScript mode on cached pages. Server-rendered embeds require excluding their pages from cache plugins, host caches and CDNs, and purging existing cached copies.

== Changelog ==

= 0.1.6 =
* Accept Render font links before the server-rendered instance.
* Use the distinctive liveblogai installation directory and translation domain.

= 0.1.5 =
* Remove internal comments from distribution files while retaining the plugin header and legal notices.
* Add versioned release archives with SHA-256 checksums.

= 0.1.4 =
* Support WordPress 6.3 and later with the native block editor and shortcode.

= 0.1.3 =
* Preserve the logo's outline smile when the block editor applies icon fill colors.

= 0.1.2 =
* Display the full-color LiveblogAI logo in the block editor.

= 0.1.1 =
* Use LiveblogAI consistently across plugin branding and interface labels.

= 0.1.0 =
* Dynamic block and shortcode with server rendering and JavaScript modes.
* On-demand content preview, cache controls and connection diagnostics.
