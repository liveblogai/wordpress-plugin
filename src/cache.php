<?php

namespace Liveblog\WordPress;

defined('ABSPATH') || exit;

/** 标记本次响应不可整页缓存；上游已命中的缓存仍需站点运维排除。 */
function prevent_page_cache(): void {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!headers_sent()) {
        nocache_headers();
        header('Cache-Control: no-store, private, max-age=0');
    }
}

/**
 * 检查区块和同步样板，循环引用或过深结构保守禁用页面缓存。
 * @param blocks - 已解析的区块树。
 * @param visited - 当前递归路径上的同步样板 ID。
 * @param depth - 当前递归深度。
 */
function blocks_have_ssr(array $blocks, array $visited = array(), int $depth = 0): bool {
    if ($depth > 32) {
        return true;
    }
    foreach ($blocks as $block) {
        if ($block['blockName'] === block_metadata()['name'] && resolve_mode($block['attrs']['mode'] ?? '') === MODE_SSR) {
            return true;
        }
        if ($block['blockName'] === 'core/block' && !empty($block['attrs']['ref'])) {
            $id = (int) $block['attrs']['ref'];
            if (in_array($id, $visited, true)) {
                return true;
            }
            $reference = get_post($id);
            if ($reference instanceof \WP_Post && content_has_ssr($reference->post_content, array_merge($visited, array($id)), $depth + 1)) {
                return true;
            }
        }
        if (!empty($block['innerBlocks']) && blocks_have_ssr($block['innerBlocks'], $visited, $depth + 1)) {
            return true;
        }
    }
    return false;
}

/**
 * 在输出页面前识别 SSR 短代码和区块，不请求或渲染正文。
 * @param content - 文章或同步样板的原始内容。
 * @param visited - 当前递归路径上的同步样板 ID。
 * @param depth - 当前递归深度。
 */
function content_has_ssr(string $content, array $visited = array(), int $depth = 0): bool {
    if (preg_match_all('/' . get_shortcode_regex(array(SHORTCODE)) . '/s', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            if ($match[1] === '[' && $match[6] === ']') {
                continue;
            }
            $attributes = shortcode_parse_atts($match[3]);
            if (resolve_mode(is_array($attributes) ? ($attributes['mode'] ?? '') : '') === MODE_SSR) {
                return true;
            }
        }
    }
    return blocks_have_ssr(parse_blocks($content), $visited, $depth);
}

/** 在模板输出前检查查询中的文章，尽早写入响应头和缓存插件标记。 */
function prepare_page_cache(): void {
    if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    global $wp_query;
    foreach ($wp_query->posts ?? array() as $post) {
        if ($post instanceof \WP_Post && content_has_ssr($post->post_content)) {
            prevent_page_cache();
            return;
        }
    }
}
