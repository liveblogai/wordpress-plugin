<?php

namespace Liveblog\WordPress;

defined('ABSPATH') || exit;

/**
 * Allow explicitly configured local HTTP services only in development.
 * @param url - Full service URL to validate.
 */
function is_local_service(string $url): bool {
    $host = wp_parse_url($url, PHP_URL_HOST);
    return in_array(wp_get_environment_type(), array('local', 'development'), true)
        && in_array($host, array('localhost', '127.0.0.1', '[::1]'), true);
}

/**
 * Validate the configured URL and reject credentials, queries, fragments and insecure schemes.
 * @param url - Configured URL.
 * @param origin_only - Whether the URL must contain only the origin.
 */
function validate_service_url($url, bool $origin_only): string {
    if (!is_string($url) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
        return '';
    }
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }
    if ($parts['scheme'] !== 'https' && !($parts['scheme'] === 'http' && is_local_service($url))) {
        return '';
    }
    if ($origin_only && isset($parts['path']) && !in_array($parts['path'], array('', '/'), true)) {
        return '';
    }
    return $origin_only ? rtrim($url, '/') : $url;
}

/** Get the fixed service endpoints used independently of post and shortcode attributes. */
function service_config(): array {
    static $config = null;
    if ($config === null) {
        $config = array(
            'render' => validate_service_url(defined('LIVEBLOG_RENDER_ORIGIN') ? constant('LIVEBLOG_RENDER_ORIGIN') : 'https://render.liveblog.ai', true),
            'assets' => validate_service_url(defined('LIVEBLOG_ASSET_ORIGIN') ? constant('LIVEBLOG_ASSET_ORIGIN') : 'https://cdn.liveblog.ai', true),
            'reader' => validate_service_url(defined('LIVEBLOG_READER_ORIGIN') ? constant('LIVEBLOG_READER_ORIGIN') : 'https://reader.api.liveblog.ai', true),
            'script' => validate_service_url(defined('LIVEBLOG_EMBED_SCRIPT_URL') ? constant('LIVEBLOG_EMBED_SCRIPT_URL') : 'https://render.liveblog.ai/embed.js', false),
        );
    }
    return $config;
}

/**
 * Resolve the supported display mode from the site default or an explicit override.
 * @param mode - Saved block or shortcode mode. An empty value uses the site default.
 */
function resolve_mode($mode): string {
    if (in_array($mode, array(MODE_SSR, MODE_JS), true)) {
        return $mode;
    }
    $settings = get_option(OPTION, array());
    return is_array($settings) && ($settings['mode'] ?? '') === MODE_JS ? MODE_JS : MODE_SSR;
}

/**
 * Save only the supported mode and discard extra fields.
 * @param input - WordPress Value submitted by the WordPress settings form.
 */
function sanitize_settings($input): array {
    return array('mode' => is_array($input) && ($input['mode'] ?? '') === MODE_JS ? MODE_JS : MODE_SSR);
}

/** Register native WordPress settings with capability and nonce checks. */
function register_settings(): void {
    register_setting(OPTION, OPTION, array('type' => 'array', 'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings', 'default' => array('mode' => MODE_SSR)));
}

/** Add the plugin settings page for administrators. */
function register_settings_page(): void {
    add_options_page(__('LiveblogAI', 'liveblogai'), __('LiveblogAI', 'liveblogai'), 'manage_options', 'liveblog', __NAMESPACE__ . '\\settings_page');
}

/**
 * Request a fixed endpoint without forwarding visitor cookies or authorization headers.
 * @param url - URL built from trusted service configuration and a fixed plugin path.
 * @param timeout - Request timeout in seconds.
 * @param limit - Maximum response body size in bytes.
 */
function request_service(string $url, float $timeout, int $limit) {
    $args = array(
        'timeout' => $timeout,
        'redirection' => 0,
        'limit_response_size' => $limit,
        'cookies' => array(),
        'headers' => array('Accept' => 'text/html, application/json', 'Cache-Control' => 'no-cache'),
        'user-agent' => 'LiveblogAI-WordPress/' . VERSION,
        'sslverify' => true,
    );
    // Allow only local development services selected in wp-config to bypass the public IP check. Keep redirects disabled.
    return is_local_service($url) ? wp_remote_get($url, $args) : wp_safe_remote_get($url, $args);
}

/** Check service health on explicit administrator request without probing automatically or storing the response body. */
function check_connection(): void {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You cannot manage LiveblogAI settings.', 'liveblogai'), '', array('response' => 403));
    }
    check_admin_referer('liveblog_check_connection');
    $origin = service_config()['render'];
    $response = $origin !== '' ? request_service($origin . '/health', REQUEST_TIMEOUT, 2048) : null;
    $ok = is_array($response) && wp_remote_retrieve_response_code($response) === 200;
    wp_safe_redirect(add_query_arg('liveblog_connection', $ok ? 'ok' : 'failed', admin_url('options-general.php?page=liveblog')));
    exit;
}

/** Render native settings with external service details, cache requirements and a manual connection check. */
function settings_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }
    $config = service_config();
    $mode = resolve_mode('');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LiveblogAI', 'liveblogai'); ?></h1>
        <p><?php esc_html_e('Add a LiveblogAI block or shortcode to any post or page. Only the liveblog ID and display settings are saved in WordPress.', 'liveblogai'); ?></p>
        <?php settings_errors(); ?>
        <?php if (isset($_GET['liveblog_connection'])) : ?>
            <div class="notice notice-info"><p><?php echo esc_html($_GET['liveblog_connection'] === 'ok' ? __('The Render service is reachable. Preview a liveblog to check its content.', 'liveblogai') : __('The Render service could not be reached. Check the service configuration and your server connection.', 'liveblogai')); ?></p></div>
        <?php endif; ?>
        <form action="options.php" method="post">
            <?php settings_fields(OPTION); ?>
            <table class="form-table" role="presentation"><tr>
                <th scope="row"><label for="liveblog-mode"><?php esc_html_e('Default display mode', 'liveblogai'); ?></label></th>
                <td><select id="liveblog-mode" name="<?php echo esc_attr(OPTION); ?>[mode]">
                    <option value="<?php echo esc_attr(MODE_SSR); ?>" <?php selected($mode, MODE_SSR); ?>><?php esc_html_e('Server-rendered content', 'liveblogai'); ?></option>
                    <option value="<?php echo esc_attr(MODE_JS); ?>" <?php selected($mode, MODE_JS); ?>><?php esc_html_e('JavaScript embed', 'liveblogai'); ?></option>
                </select>
                <p class="description"><?php esc_html_e('Server rendering includes the first posts in the page HTML. JavaScript mode loads current content in the browser and works with full-page caching.', 'liveblogai'); ?></p></td>
            </tr></table>
            <?php submit_button(); ?>
        </form>
        <h2><?php esc_html_e('Page caching', 'liveblogai'); ?></h2>
        <p><?php esc_html_e('Exclude pages containing server-rendered liveblogs from your cache plugin, hosting cache and CDN, and purge existing copies. The plugin sends no-store headers, but cannot override a cache that responds before WordPress runs. Use JavaScript mode when these pages cannot be excluded.', 'liveblogai'); ?></p>
        <p><?php esc_html_e('Keep LiveblogAI scripts, JSON and Shadow DOM markup out of script combining and delayed execution features. Page titles, descriptions and sharing images remain managed by your WordPress site.', 'liveblogai'); ?></p>
        <h2><?php esc_html_e('External service', 'liveblogai'); ?></h2>
        <p><?php esc_html_e('Adding an embed connects your server and visitors to LiveblogAI to display public coverage and receive updates. Preview connects only when you click its button. No LiveblogAI account credentials are stored by this plugin.', 'liveblogai'); ?></p>
        <p><strong><?php esc_html_e('Render service:', 'liveblogai'); ?></strong> <code><?php echo esc_html($config['render']); ?></code></p>
        <p><strong><?php esc_html_e('Embed script:', 'liveblogai'); ?></strong> <code><?php echo esc_html($config['script']); ?></code></p>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <input type="hidden" name="action" value="liveblog_check_connection">
            <?php wp_nonce_field('liveblog_check_connection'); ?>
            <?php submit_button(__('Check Render connection', 'liveblogai'), 'secondary'); ?>
        </form>
    </div>
    <?php
}
