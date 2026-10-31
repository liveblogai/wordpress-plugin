<?php

namespace Liveblog\WordPress;

defined('ABSPATH') || exit;

/**
 * 校验固定资源 origin 与不可变路径，不接受凭据、编码路径、查询或片段。
 * @param url - 远程片段声明的资源 URL。
 * @param origin - 运维明确配置的可信 origin。
 * @param pattern - 对路径的完整匹配规则。
 */
function valid_resource_url(string $url, string $origin, string $pattern): bool {
    if ($origin === '' || validate_service_url($url, false) !== $url || !str_starts_with($url, $origin . '/')) {
        return false;
    }
    return preg_match($pattern, substr($url, strlen($origin))) === 1;
}

/**
 * 正文只允许文章标记、交互控件与无执行能力的 SVG 图形，不放行脚本或任意嵌入。
 * @param html - 未信任的远程正文，不包含插件重建的宿主结构。
 */
function sanitize_render_body(string $html): string {
    $styles = array();
    // 样式单独通过严格语法子集验证，避免 KSES 把样式内容变成可见正文。
    $html = preg_replace_callback('~<style\b[^>]*>(.*?)</style\s*>~is', static function ($match) use (&$styles) {
        $css = preg_replace('~/\*.*?\*/~s', '', $match[1]);
        if (!is_string($css) || !preg_match('/\A[a-zA-Z0-9\s{}:;,.#%()*_!@\[\]=+~>\/\x22\x27-]*\z/', $css)) {
            return '';
        }
        preg_match_all('/([a-zA-Z_-][a-zA-Z0-9_-]*)\s*\(/', $css, $functions);
        foreach ($functions[1] as $function) {
            if (!in_array(strtolower($function), array('var', 'calc', 'min', 'max', 'clamp', 'rgb', 'rgba', 'hsl', 'hsla', 'oklch', 'oklab', 'light-dark', 'color-mix', 'linear-gradient', 'radial-gradient', 'repeat', 'minmax', 'fit-content', 'translate', 'translatex', 'translatey', 'scale', 'rotate', 'cubic-bezier', 'steps', 'not', 'is', 'where', 'nth-child', 'nth-of-type'), true)) {
                return '';
            }
        }
        preg_match_all('/@([a-zA-Z-]+)/', $css, $rules);
        foreach ($rules[1] as $rule) {
            if (!in_array(strtolower($rule), array('media', 'supports', 'keyframes', 'container', 'layer'), true)) {
                return '';
            }
        }
        $marker = '<!-- liveblog-style-' . bin2hex(random_bytes(16)) . ' -->';
        // 白名单排除了小于号、反斜线转义及外部资源函数；仅在此局部允许经过验证的样式标签。
        $styles[$marker] = wp_kses('<style>' . $css . '</style>', array('style' => array()));
        return $marker;
    }, $html);
    if (!is_string($html)) {
        return '';
    }
    // 任意 iframe 只拥有脚本权限，没有同源、顶层跳转或表单权限；srcdoc 不能逃逸属性。
    $processor = new \WP_HTML_Tag_Processor($html);
    while ($processor->next_tag('IFRAME')) {
        $processor->set_attribute('sandbox', 'allow-scripts');
    }
    $html = $processor->get_updated_html();
    $allowed = wp_kses_allowed_html('post');
    // 不继承站点对可执行标记的全局放宽；初始化标签由下方独立生成。
    foreach (array('script', 'style', 'link', 'template', 'iframe', 'object', 'embed', 'form', 'input', 'base', 'meta', 'math') as $tag) {
        unset($allowed[$tag]);
    }
    $common = array('class' => true, 'id' => true, 'role' => true, 'aria-*' => true, 'data-*' => true, 'tabindex' => true, 'style' => true);
    $allowed['template'] = array('shadowrootmode' => array('values' => array('open')));
    $allowed['iframe'] = $common + array('src' => true, 'srcdoc' => true, 'sandbox' => array('values' => array('allow-scripts')), 'title' => true, 'width' => true, 'height' => true, 'loading' => true, 'allow' => true, 'allowfullscreen' => true, 'referrerpolicy' => true);
    $allowed['button'] = $common + array('type' => true, 'disabled' => true, 'title' => true);
    $svg = $common + array_fill_keys(array('xmlns', 'viewbox', 'width', 'height', 'fill', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'opacity', 'd', 'cx', 'cy', 'r', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'rx', 'ry', 'points', 'transform', 'focusable'), true);
    foreach (array('svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon') as $tag) {
        $allowed[$tag] = $svg;
    }
    foreach ($allowed as &$attributes) {
        foreach (array_keys($attributes) as $attribute) {
            if (str_starts_with(strtolower($attribute), 'on') || in_array($attribute, array('formaction'), true)) {
                unset($attributes[$attribute]);
            }
        }
    }
    unset($attributes);
    // 在正文自身范围内配平标签，丢弃无对应起点的结束标签，防止提前关闭外层 Shadow DOM。
    return strtr(force_balance_tags(wp_kses($html, $allowed, array('https', 'http', 'mailto', 'tel'))), $styles);
}

/**
 * 将固定 SSR 线协议拆为正文、数据与资源，逐项验证后重建；不透传远程脚本标签。
 * @param html - Render 返回的完整未信任片段。
 * @param id - 已校验的当前直播 ID。
 * @returns 安全片段；协议或资源不合法时返回空字符串。
 */
function sanitize_render_fragment(string $html, string $id): string {
    $config = service_config();
    // 这里只解析固定宿主协议，不把正则用于清洗任意 HTML；正文统一交给 KSES。
    $prefix = '~\A(?:(<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="([^"]*)">))?'
        . '<section data-render-instance="' . preg_quote($id, '~') . '">'
        . '<template shadowrootmode="open"><link data-render-style rel="stylesheet" href="([^"]*)" integrity="(sha256-[A-Za-z0-9+/]{43}=)" crossorigin="anonymous"><div data-embed-root>~';
    if (!preg_match($prefix, $html, $head)) {
        return '';
    }
    $boundary = strrpos($html, '</div></template>');
    if ($boundary === false || $boundary < strlen($head[0])) {
        return '';
    }
    $suffix = '~\A</div></template><script type="application/json" data-render-state>([^<]*)</script>'
        . '<script type="module" data-render-runtime src="([^"]*)" integrity="(sha256-[A-Za-z0-9+/]{43}=)" crossorigin="anonymous"></script>'
        . '<script type="module" data-render-theme src="([^"]*)" integrity="(sha256-[A-Za-z0-9+/]{43}=)" crossorigin="anonymous"></script>'
        . '</section><script defer data-render-bootstrap src="([^"]*)"></script>\z~';
    if (!preg_match($suffix, substr($html, $boundary), $tail)) {
        return '';
    }
    $css = html_entity_decode($head[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $runtime = html_entity_decode($tail[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $theme = html_entity_decode($tail[4], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $bootstrap = html_entity_decode($tail[6], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (!valid_resource_url($css, $config['assets'], '~\A/themes/[a-f0-9]{64}/[A-Za-z0-9_-]+\.css\z~')
        || !valid_resource_url($theme, $config['assets'], '~\A/themes/[a-f0-9]{64}/[A-Za-z0-9_-]+\.m?js\z~')
        || dirname($css) !== dirname($theme)
        || !valid_resource_url($runtime, $config['assets'], '~\A/runtimes/(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)/[A-Za-z0-9_-]+\.m?js\z~')
        || !valid_resource_url($bootstrap, $config['render'], '~\A/ssr/[a-f0-9]{64}/hydrate\.js\z~')) {
        return '';
    }
    $state = json_decode($tail[1]);
    if (!is_object($state) || ($state->liveblogId ?? null) !== $id
        || !is_object($state->initialSnapshot ?? null)
        || !is_int($state->minimumVersion ?? null) || $state->minimumVersion < 0
        || $config['reader'] === '' || !is_string($state->readerBaseUrl ?? null)
        || rtrim($state->readerBaseUrl, '/') !== $config['reader']
        || !is_string($state->identifierPrefix ?? null)
        || !is_string($state->readerCapability ?? null)
        || !is_string($state->factory ?? null)
        || !preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*\z/', $state->factory)
        || !is_object($state->runtimeExports ?? null)) {
        return '';
    }
    foreach (array('react', 'renderer', 'sdk', 'ui') as $key) {
        if (!is_string($state->runtimeExports->$key ?? null) || !preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*\z/', $state->runtimeExports->$key)) {
            return '';
        }
    }
    // 重试与兜底经过本站匿名只读端点，复用同一清洗过程并保留版本下限。
    $base = add_query_arg(array('action' => 'liveblog_render_fragment', 'id' => $id), admin_url('admin-ajax.php'));
    $state->fallback = ($state->fallback ?? false) === true;
    $state->versionParameter = 'version';
    $state->fallbackUrl = add_query_arg('theme', 'default', $base);
    $state->retryUrl = add_query_arg('theme', $state->fallback ? 'default' : basename(dirname($theme)), $base);
    $json = wp_json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    if ($json === false) {
        return '';
    }
    $font = '';
    if ($head[1] !== '') {
        $url = html_entity_decode($head[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('~\Ahttps://fonts\.googleapis\.com/css2\?[^\x00-\x20\x7f\\\\#]*\z~', $url)) {
            return '';
        }
        $font = '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="' . esc_url($url) . '">';
    }
    // 宿主结构和属性由插件生成；远程正文不能自行注册脚本、Shadow Root 或 JSON 节点。
    $body = sanitize_render_body(substr($html, strlen($head[0]), $boundary - strlen($head[0])));
    $result = $font . '<section data-render-instance="' . esc_attr($id) . '">'
        . '<template shadowrootmode="open"><link data-render-style rel="stylesheet" href="' . esc_url($css)
        . '" integrity="' . esc_attr($head[4]) . '" crossorigin="anonymous"><div data-embed-root>'
        . $body . '</div></template>'
        . wp_get_inline_script_tag($json, array('type' => 'application/json', 'data-render-state' => true))
        . wp_get_script_tag(array('type' => 'module', 'data-render-runtime' => true, 'src' => esc_url($runtime), 'integrity' => $tail[3], 'crossorigin' => 'anonymous'))
        . wp_get_script_tag(array('type' => 'module', 'data-render-theme' => true, 'src' => esc_url($theme), 'integrity' => $tail[5], 'crossorigin' => 'anonymous'))
        . '</section>';
    // 内容寻址 URL 自带版本，同页多个实例复用同一接管器。
    wp_enqueue_script('liveblog-hydrate-' . basename(dirname($bootstrap)), $bootstrap, array('liveblog-feedback'), null, array('strategy' => 'defer', 'in_footer' => true));
    return $result;
}
