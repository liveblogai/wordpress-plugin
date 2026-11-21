<?php

namespace Liveblog\WordPress;

defined('ABSPATH') || exit;

/** Disable full-page caching for this response. Site operators must also exclude it from upstream caches. */
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
 * Inspect blocks and synced patterns, disabling page caching on cycles or excessive nesting.
 * @param blocks - Parsed block tree.
 * @param visited - Synced pattern IDs on the current recursion path.
 * @param depth - Current recursion depth.
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
 * Detect SSR shortcodes and blocks before output without fetching or rendering post content.
 * @param content - Raw post or synced pattern content.
 * @param visited - Synced pattern IDs on the current recursion path.
 * @param depth - Current recursion depth.
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

/** Inspect queried posts before template output to set headers and cache flags early. */
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
