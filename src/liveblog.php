<?php
/**
 * Plugin Name: LiveblogAI
 * Description: Embed live coverage with server-rendered content and real-time updates.
 * Version: 0.1.5
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author: LiveblogAI
 * Author URI: https://liveblog.ai
 * Plugin URI: https://github.com/liveblogai/wordpress-plugin
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: liveblog
 */

/**
 * Copyright (C) 2026 LiveblogAI.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Liveblog\WordPress;

defined('ABSPATH') || exit;

const VERSION = '0.1.5';
const OPTION = 'liveblog_settings';
const SHORTCODE = 'liveblog';
const MODE_SSR = 'ssr';
const MODE_JS = 'js';
const MAX_HTML_BYTES = 2097152;
const REQUEST_TIMEOUT = 5;
const PAGE_REQUEST_BUDGET = 8;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/render.php';
require_once __DIR__ . '/cache.php';

/** 读取区块元数据，PHP 和编辑器使用同一个定义。 */
function block_metadata(): array {
    static $metadata = null;
    if ($metadata === null) {
        $metadata = json_decode(file_get_contents(__DIR__ . '/block.json'), true);
    }
    return $metadata;
}

/** 注册动态区块、短代码和站点自带的编辑器依赖。 */
function register_embed(): void {
    $asset = require __DIR__ . '/editor.asset.php';
    wp_register_script('liveblog-editor', plugins_url('editor.js', __FILE__), $asset['dependencies'], $asset['version'], true);
    wp_register_style('liveblog-editor', plugins_url('editor.css', __FILE__), array(), VERSION);
    wp_register_style('liveblog-frontend', plugins_url('frontend.css', __FILE__), array(), VERSION);
    // 先安装错误监听，再让浏览器请求延迟执行的远程引导器，避免下载失败事件早于监听。
    wp_register_script('liveblog-feedback', plugins_url('frontend.js', __FILE__), array(), VERSION, true);
    wp_set_script_translations('liveblog-editor', 'liveblog');
    wp_localize_script('liveblog-editor', 'liveblogWordPress', array(
        'renderOrigin' => service_config()['render'],
        'defaultMode' => resolve_mode(''),
        'modes' => array('ssr' => MODE_SSR, 'js' => MODE_JS),
        'settingsUrl' => current_user_can('manage_options') ? admin_url('options-general.php?page=liveblog') : '',
    ));
    register_block_type(__DIR__, array('render_callback' => __NAMESPACE__ . '\\render_block'));
    add_shortcode(SHORTCODE, __NAMESPACE__ . '\\render_shortcode');
}

/** 为正文中才出现的短代码补齐局部样式，WordPress 已输出的样式不会重复打印。 */
function print_late_styles(): void {
    if (wp_style_is('liveblog-frontend', 'enqueued') && !wp_style_is('liveblog-frontend', 'done')) {
        wp_print_styles(array('liveblog-frontend'));
    }
}

add_action('init', __NAMESPACE__ . '\\register_embed');
add_action('admin_init', __NAMESPACE__ . '\\register_settings');
add_action('admin_menu', __NAMESPACE__ . '\\register_settings_page');
add_action('admin_post_liveblog_check_connection', __NAMESPACE__ . '\\check_connection');
add_action('wp', __NAMESPACE__ . '\\prepare_page_cache', 0);
add_action('wp_footer', __NAMESPACE__ . '\\print_late_styles', 1);
add_filter('the_content', __NAMESPACE__ . '\\restore_fragments', PHP_INT_MAX);
