<?php

namespace Liveblog\WordPress;

defined('ABSPATH') || exit;

/** Keep pending fragments in request-local memory outside the persistent WordPress cache. */
function &pending_fragments(): array {
    static $fragments = array();
    return $fragments;
}

/**
 * Insert fragments after post formatting so smart quotes and emoji conversion cannot break hydration.
 * @param html - SSR fragment validated, sanitized and rebuilt by the plugin.
 */
function protect_fragment(string $html): string {
    if (!doing_filter('the_content')) {
        return $html;
    }
    $marker = '<!-- liveblog-fragment-' . bin2hex(random_bytes(16)) . ' -->';
    $fragments =& pending_fragments();
    $fragments[$marker] = $html;
    return $marker;
}

/**
 * Restore fragments after core post filters, replacing only random tokens generated for this request.
 * @param content - Post content after WordPress formatting.
 */
function restore_fragments(string $content): string {
    $fragments =& pending_fragments();
    foreach ($fragments as $marker => $html) {
        if (str_contains($content, $marker)) {
            $content = str_replace($marker, $html, $content);
            unset($fragments[$marker]);
        }
    }
    return $content;
}

/**
 * Render an escaped inline notice. Retrying reloads the current page.
 * @param message - Translated plain-text message.
 * @param retry - Whether to show a retry action.
 */
function notice_html(string $message, bool $retry = false): string {
    return '<div class="liveblog-notice" role="status"><p>' . esc_html($message) . '</p>'
        . ($retry ? '<button type="button" data-liveblog-retry>' . esc_html__('Retry LiveblogAI', 'liveblogai') . '</button>' : '')
        . '</div>';
}

/**
 * Fetch and rebuild a public SSR fragment without caching it across requests.
 * @param id - Validated liveblog ID.
 * @param theme - Immutable theme digest or default selected for recovery.
 * @param minimum_version - Minimum reader version to preserve during recovery.
 */
function render_ssr(string $id, string $theme = '', ?int $minimum_version = null): string {
    static $spent = 0.0;
    $origin = service_config()['render'];
    if ($origin === '') {
        return notice_html(__('The LiveblogAI service is not configured correctly.', 'liveblogai'));
    }
    $remaining = PAGE_REQUEST_BUDGET - $spent;
    if ($remaining < 0.5) {
        return notice_html(__('Live coverage could not be loaded in time.', 'liveblogai'), true);
    }
    $started = microtime(true);
    $url = $origin . '/v1/liveblogs/' . rawurlencode($id) . '/fragment';
    if ($theme !== '') {
        $url = add_query_arg('theme', $theme, $url);
    }
    if ($minimum_version !== null) {
        $url = add_query_arg('version', $minimum_version, $url);
    }
    $response = request_service($url, min(REQUEST_TIMEOUT, $remaining), MAX_HTML_BYTES + 1);
    $spent += microtime(true) - $started;
    if (is_wp_error($response)) {
        return notice_html(__('Live coverage could not be loaded. Please try again.', 'liveblogai'), true);
    }
    $status = wp_remote_retrieve_response_code($response);
    if ($status === 404) {
        return notice_html(__('This liveblog is not available to readers yet, or does not exist.', 'liveblogai'), true);
    }
    if ($status === 410) {
        return notice_html(__('This liveblog has been taken offline.', 'liveblogai'));
    }
    $html = wp_remote_retrieve_body($response);
    $type = wp_remote_retrieve_header($response, 'content-type');
    if ($status !== 200 || !is_string($type) || !preg_match('/\Atext\/html(?:\s*;|\s*\z)/i', $type)
        || $html === '' || strlen($html) > MAX_HTML_BYTES
        || !preg_match('/\A(?:<link\b[^>]*>\s*)*<section data-render-instance="' . preg_quote($id, '/') . '">/', $html)) {
        return notice_html(__('Live coverage could not be loaded. Please try again.', 'liveblogai'), true);
    }
    $safe_html = sanitize_render_fragment($html, $id);
    if ($safe_html === '') {
        return notice_html(__('Live coverage could not be loaded safely. Please try again.', 'liveblogai'), true);
    }
    return protect_fragment($safe_html);
}

/**
 * Render the JavaScript container. The bootstrap script handles loading and connecting.
 * @param id - Validated liveblog ID.
 */
function render_js(string $id): string {
    $script = service_config()['script'];
    if ($script === '') {
        return notice_html(__('The LiveblogAI service is not configured correctly.', 'liveblogai'));
    }
    wp_enqueue_script('liveblog-embed', $script, array('liveblog-feedback'), null, array('strategy' => 'defer', 'in_footer' => true));
    return '<div data-liveblog-js><div data-liveblog-id="' . esc_attr($id) . '"><p>' . esc_html__('Loading LiveblogAI…', 'liveblogai') . '</p></div>'
        . '<template data-liveblog-script-error>' . notice_html(__('The LiveblogAI script could not be loaded.', 'liveblogai'), true) . '</template>'
        . '<noscript>' . esc_html__('Enable JavaScript to read this liveblog.', 'liveblogai') . '</noscript></div>';
}

/**
 * Validate the ID, suppress duplicate instances and render both blocks and shortcodes.
 * @param id - Liveblog ID entered by the author.
 * @param mode - Display mode selected by the author.
 */
function render_embed($id, $mode): string {
    static $instances = array();
    if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $id)) {
        return notice_html(__('Enter a valid liveblog ID.', 'liveblogai'));
    }
    // Editor and machine-readable responses skip remote requests and executable content.
    if (is_admin() || is_feed() || doing_filter('get_the_excerpt') || doing_filter('the_excerpt') || (defined('REST_REQUEST') && REST_REQUEST)) {
        return notice_html(__('Live coverage is available on the published page.', 'liveblogai'));
    }
    wp_enqueue_style('liveblog-frontend');
    wp_enqueue_script('liveblog-feedback');
    if (isset($instances[$id])) {
        return notice_html(__('This liveblog already appears on this page.', 'liveblogai'));
    }
    $instances[$id] = true;
    if (resolve_mode($mode) === MODE_JS) {
        return render_js($id);
    }
    prevent_page_cache();
    return render_ssr($id);
}

/**
 * Render the dynamic block wrapper using WordPress layout support for the outer element.
 * @param attributes - WordPress Block attributes parsed by WordPress.
 */
function render_block(array $attributes): string {
    $content = render_embed($attributes['liveblogId'] ?? '', $attributes['mode'] ?? '');
    return '<div ' . get_block_wrapper_attributes(array('class' => 'liveblog-wordpress')) . '>' . $content . '</div>';
}

/**
 * Pass classic editor shortcodes to the shared renderer.
 * @param attributes - Shortcode attributes.
 */
function render_shortcode($attributes): string {
    $attributes = shortcode_atts(array('id' => '', 'mode' => ''), $attributes, SHORTCODE);
    return '<div class="liveblog-wordpress">' . render_embed($attributes['id'], $attributes['mode']) . '</div>';
}

/** Apply first-render sanitization to anonymous theme recovery and retries, forwarding the minimum version to Render. */
function render_retry(): void {
    // This read-only endpoint accepts bounded public parameters and requires no login or nonce.
    $id = isset($_GET['id']) && is_string($_GET['id']) ? wp_unslash($_GET['id']) : '';
    $theme = isset($_GET['theme']) && is_string($_GET['theme']) ? wp_unslash($_GET['theme']) : '';
    $version = isset($_GET['version']) && is_string($_GET['version']) ? wp_unslash($_GET['version']) : '';
    if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $id)
        || !preg_match('/\A(?:default|[a-f0-9]{64})\z/', $theme)
        || ($version !== '' && (!preg_match('/\A(?:0|[1-9][0-9]{0,15})\z/', $version) || (float) $version > 9007199254740991))) {
        status_header(400);
        exit;
    }
    prevent_page_cache();
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    // Return only the host rebuilt by sanitize_render_fragment or an escaped notice.
    echo render_ssr($id, $theme, $version === '' ? null : (int) $version);
    exit;
}
