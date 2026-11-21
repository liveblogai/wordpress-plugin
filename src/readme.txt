=== LiveblogAI ===
Contributors: liveai
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: liveblog, live coverage, embed

Publish live coverage on WordPress with LiveblogAI. Keep readers up to date on sports, breaking news, community events and financial markets.

== Description ==

The LiveblogAI plugin brings the coverage you publish on the LiveblogAI platform to your WordPress site and keeps it up to date in real time. Cover sports, breaking news, community events or financial markets around the clock.

Live coverage with LiveblogAI can keep readers on your site longer and help improve your site's SEO.

= Support =

If you have a question or need help using the plugin, email us at [support@liveblog.ai](mailto:support@liveblog.ai).

= Website =

Learn more about LiveblogAI on our [official website](https://liveblog.ai/). [Plugin source on GitHub](https://github.com/liveblogai/wordpress-plugin).

== Installation ==

1. Upload liveblog.zip in Plugins > Add New > Upload Plugin and activate it.
2. Add a LiveblogAI block and enter a public liveblog ID, or use [liveblog id="YOUR_ID"].
3. Click Preview LiveblogAI to see the public content, then publish your post.
4. For server rendering, exclude that page from all full-page caches and purge existing cached copies. Otherwise select JavaScript mode in Settings > LiveblogAI or use [liveblog id="YOUR_ID" mode="js"].

== Frequently Asked Questions ==

= Where do my reporters write updates? =

Create and publish your liveblog in LiveblogAI. In WordPress, add its public ID to the article once. Reporters can then publish updates from LiveblogAI while the article stays live.

= Do I need to connect a LiveblogAI account to WordPress? =

You only need the ID of a public LiveblogAI liveblog to embed it. Your team uses a LiveblogAI account to manage coverage. WordPress stores the ID and display settings, and readers can follow public coverage without signing in.

Previewing or viewing an embed connects to LiveblogAI to load coverage. LiveblogAI receives the liveblog ID and the browser’s IP address. Server-rendered requests also send the plugin version. [Terms of service](https://liveblog.ai/terms) · [Privacy policy](https://liveblog.ai/privacy).

= Can I use the Classic Editor? =

Yes. Paste `[liveblog id="YOUR_ID"]` on its own line in your post and replace `YOUR_ID` with the public liveblog ID. In the block editor, search for the LiveblogAI block instead.

= Will readers see updates if they already have the article open? =

Yes. Once the live feed connects, new posts appear in the embedded feed. Readers can keep the article open throughout the event.

= Will it work with my caching plugin or CDN? =

Yes. For a page that needs full-page caching, choose JavaScript mode under Settings > LiveblogAI, or use `[liveblog id="YOUR_ID" mode="js"]` for one embed. If you choose server rendering, exclude that page from your caching plugin, host cache and CDN, then purge any existing cached copy.

= Does each update become a separate WordPress post? =

No. All updates appear in the article where you placed the embed. LiveblogAI hosts the coverage, and WordPress manages the article's URL, title and sharing metadata.

== Changelog ==

= 0.1.7 =
* Sanitize server-rendered content and reconstruct validated hydration resources.
* Encode initialization data safely while retaining server rendering and live updates.
* Apply the same sanitization to theme recovery and retry responses.

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
