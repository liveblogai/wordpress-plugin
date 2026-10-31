<?php

namespace Liveblog\WordPress;

defined('ABSPATH') || exit;

/** 保存当前 PHP 请求中等待插入的片段，不进入 WordPress 持久缓存。 */
function &pending_fragments(): array {
    static $fragments = array();
    return $fragments;
}

/**
 * 文章格式化结束后再插入正文，避免智能引号和表情转换破坏 hydration。
 * @param html - 已逐项校验并清洗、由插件重建的 SSR 片段。
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
 * 在原生文章过滤器之后恢复完整片段，只替换本请求生成的随机标记。
 * @param content - 已完成 WordPress 格式化的文章内容。
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
 * 输出插件自己的已转义局部提示，重试只刷新当前页面。
 * @param message - 已翻译的纯文本提示。
 * @param retry - 是否提供显式重试。
 */
function notice_html(string $message, bool $retry = false): string {
    return '<div class="liveblog-notice" role="status"><p>' . esc_html($message) . '</p>'
        . ($retry ? '<button type="button" data-liveblog-retry>' . esc_html__('Retry LiveblogAI', 'liveblogai') . '</button>' : '')
        . '</div>';
}

/**
 * 读取公开 SSR 片段，经安全重建后输出且不存储跨请求副本。
 * @param id - 已校验的直播 ID。
 * @param theme - 恢复请求选择的不可变主题摘要或 default。
 * @param minimum_version - 恢复请求必须保留的读取水位。
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
 * 输出 JS 容器，正式引导器统一负责加载和连接。
 * @param id - 已校验的直播 ID。
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
 * 为区块和短代码共用 ID 校验、实例去重与前台渲染。
 * @param id - 作者输入的直播 ID。
 * @param mode - 作者选择的模式。
 */
function render_embed($id, $mode): string {
    static $instances = array();
    if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $id)) {
        return notice_html(__('Enter a valid liveblog ID.', 'liveblogai'));
    }
    // 编辑器和机器读取不会触发远程请求，也不返回可执行正文。
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
 * 输出动态区块外壳，外观仅使用 WordPress 的外层排版支持。
 * @param attributes - WordPress 解析后的区块属性。
 */
function render_block(array $attributes): string {
    $content = render_embed($attributes['liveblogId'] ?? '', $attributes['mode'] ?? '');
    return '<div ' . get_block_wrapper_attributes(array('class' => 'liveblog-wordpress')) . '>' . $content . '</div>';
}

/**
 * 将经典编辑器短代码接入同一渲染入口。
 * @param attributes - 短代码属性。
 */
function render_shortcode($attributes): string {
    $attributes = shortcode_atts(array('id' => '', 'mode' => ''), $attributes, SHORTCODE);
    return '<div class="liveblog-wordpress">' . render_embed($attributes['id'], $attributes['mode']) . '</div>';
}

/** 匿名只读恢复入口：主题兜底与重试复用首屏清洗，并把版本下限传回 Render。 */
function render_retry(): void {
    // 此端点不修改站点状态，不依赖登录或 nonce；只接受有界的公开读取参数。
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
    // 输出只来自 sanitize_render_fragment 重建的安全宿主或已转义提示，不透传远程正文。
    echo render_ssr($id, $theme, $version === '' ? null : (int) $version);
    exit;
}
