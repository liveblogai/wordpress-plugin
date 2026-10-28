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
 * @param html - 已验证来源的完整 SSR 片段。
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
        . ($retry ? '<button type="button" data-liveblog-retry>' . esc_html__('Retry LiveblogAI', 'liveblog') . '</button>' : '')
        . '</div>';
}

/**
 * 读取公开 SSR 片段，保留完整标记且不存储跨请求副本。
 * @param id - 已校验的直播 ID。
 */
function render_ssr(string $id): string {
    static $spent = 0.0;
    $origin = service_config()['render'];
    if ($origin === '') {
        return notice_html(__('The LiveblogAI service is not configured correctly.', 'liveblog'));
    }
    $remaining = PAGE_REQUEST_BUDGET - $spent;
    if ($remaining < 0.5) {
        return notice_html(__('Live coverage could not be loaded in time.', 'liveblog'), true);
    }
    $started = microtime(true);
    $response = request_service($origin . '/v1/liveblogs/' . rawurlencode($id) . '/fragment', min(REQUEST_TIMEOUT, $remaining), MAX_HTML_BYTES + 1);
    $spent += microtime(true) - $started;
    if (is_wp_error($response)) {
        return notice_html(__('Live coverage could not be loaded. Please try again.', 'liveblog'), true);
    }
    $status = wp_remote_retrieve_response_code($response);
    if ($status === 404) {
        return notice_html(__('This liveblog is not available to readers yet, or does not exist.', 'liveblog'), true);
    }
    if ($status === 410) {
        return notice_html(__('This liveblog has been taken offline.', 'liveblog'));
    }
    $html = wp_remote_retrieve_body($response);
    $type = wp_remote_retrieve_header($response, 'content-type');
    if ($status !== 200 || !is_string($type) || !preg_match('/\Atext\/html(?:\s*;|\s*\z)/i', $type)
        || $html === '' || strlen($html) > MAX_HTML_BYTES
        || !str_starts_with($html, '<section data-render-instance="' . $id . '">')) {
        return notice_html(__('Live coverage could not be loaded. Please try again.', 'liveblog'), true);
    }
    // 这是固定可信 Render 的可执行嵌入合同；不能用文章 KSES 删除脚本、快照或 Shadow DOM。
    // 作者输入只参与 ID 路径，不参与服务选择、HTML 拼接或脚本地址。
    return protect_fragment($html);
}

/**
 * 输出 JS 容器，正式引导器统一负责加载和连接。
 * @param id - 已校验的直播 ID。
 */
function render_js(string $id): string {
    $script = service_config()['script'];
    if ($script === '') {
        return notice_html(__('The LiveblogAI service is not configured correctly.', 'liveblog'));
    }
    wp_enqueue_script('liveblog-embed', $script, array('liveblog-feedback'), null, array('strategy' => 'defer', 'in_footer' => true));
    return '<div data-liveblog-js><div data-liveblog-id="' . esc_attr($id) . '"><p>' . esc_html__('Loading LiveblogAI…', 'liveblog') . '</p></div>'
        . '<template data-liveblog-script-error>' . notice_html(__('The LiveblogAI script could not be loaded.', 'liveblog'), true) . '</template>'
        . '<noscript>' . esc_html__('Enable JavaScript to read this liveblog.', 'liveblog') . '</noscript></div>';
}

/**
 * 为区块和短代码共用 ID 校验、实例去重与前台渲染。
 * @param id - 作者输入的直播 ID。
 * @param mode - 作者选择的模式。
 */
function render_embed($id, $mode): string {
    static $instances = array();
    if (!is_string($id) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $id)) {
        return notice_html(__('Enter a valid liveblog ID.', 'liveblog'));
    }
    // 编辑器和机器读取不会触发远程请求，也不返回可执行正文。
    if (is_admin() || is_feed() || doing_filter('get_the_excerpt') || doing_filter('the_excerpt') || (defined('REST_REQUEST') && REST_REQUEST)) {
        return notice_html(__('Live coverage is available on the published page.', 'liveblog'));
    }
    wp_enqueue_style('liveblog-frontend');
    wp_enqueue_script('liveblog-feedback');
    if (isset($instances[$id])) {
        return notice_html(__('This liveblog already appears on this page.', 'liveblog'));
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
