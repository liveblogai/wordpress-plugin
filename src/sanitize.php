<?php

namespace Liveblog\WordPress;

defined('ABSPATH') || exit;

/**
 * Validate the configured asset origin and immutable path. Reject credentials, encoded paths, queries and fragments.
 * @param url - Asset URL declared by the remote fragment.
 * @param origin - Trusted origin configured by the operator.
 * @param pattern - Pattern that must match the entire path.
 */
function valid_resource_url(string $url, string $origin, string $pattern): bool {
    if ($origin === '' || validate_service_url($url, false) !== $url || !str_starts_with($url, $origin . '/')) {
        return false;
    }
    return preg_match($pattern, substr($url, strlen($origin))) === 1;
}

/**
 * Allow article markup, interactive controls and non-executable SVG shapes in the body. Exclude scripts and arbitrary embeds.
 * @param html - Untrusted remote body without the host structure rebuilt by the plugin.
 */
function sanitize_render_body(string $html): string {
    $styles = array();
    // Validate styles separately against a restricted grammar so KSES does not turn CSS into visible text.
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
        // The allowlist excludes angle-opening brackets, backslash escapes and external resource functions. Allow validated style tags only here.
        $styles[$marker] = wp_kses('<style>' . $css . '</style>', array('style' => array()));
        return $marker;
    }, $html);
    if (!is_string($html)) {
        return '';
    }
    // Frames may run scripts but lack same-origin, top-navigation and form permissions. Keep srcdoc inside its attribute.
    $processor = new \WP_HTML_Tag_Processor($html);
    while ($processor->next_tag('IFRAME')) {
        $processor->set_attribute('sandbox', 'allow-scripts');
    }
    $html = $processor->get_updated_html();
    $allowed = wp_kses_allowed_html('post');
    // Ignore site-wide exceptions for executable markup. Generate initialization tags separately below.
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
    // Balance tags within the body and discard unmatched closing tags to keep the outer shadow tree intact.
    return strtr(force_balance_tags(wp_kses($html, $allowed, array('https', 'http', 'mailto', 'tel'))), $styles);
}

/**
 * Split the SSR payload into content, data and resources. Validate each part and rebuild the fragment locally.
 * @param html - Render Complete untrusted fragment returned by Render.
 * @param id - Validated ID of the current liveblog.
 * @returns Sanitized fragment, or an empty string for invalid protocol data or resources.
 */
function sanitize_render_fragment(string $html, string $id): string {
    $config = service_config();
    // Parse only the fixed host format here. KSES handles untrusted body HTML.
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
    // Route retries and fallbacks through the site's public read-only endpoint to repeat sanitization and preserve the minimum version.
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
    // Generate host markup and attributes locally so remote content cannot register scripts, shadow roots or JSON nodes.
    $body = sanitize_render_body(substr($html, strlen($head[0]), $boundary - strlen($head[0])));
    $result = $font . '<section data-render-instance="' . esc_attr($id) . '">'
        . '<template shadowrootmode="open"><link data-render-style rel="stylesheet" href="' . esc_url($css)
        . '" integrity="' . esc_attr($head[4]) . '" crossorigin="anonymous"><div data-embed-root>'
        . $body . '</div></template>'
        . wp_get_inline_script_tag($json, array('type' => 'application/json', 'data-render-state' => true))
        . wp_get_script_tag(array('type' => 'module', 'data-render-runtime' => true, 'src' => esc_url($runtime), 'integrity' => $tail[3], 'crossorigin' => 'anonymous'))
        . wp_get_script_tag(array('type' => 'module', 'data-render-theme' => true, 'src' => esc_url($theme), 'integrity' => $tail[5], 'crossorigin' => 'anonymous'))
        . '</section>';
    // Content-addressed URLs include the version so instances on the same page share the hydration script.
    wp_enqueue_script('liveblog-hydrate-' . basename(dirname($bootstrap)), $bootstrap, array('liveblog-feedback'), null, array('strategy' => 'defer', 'in_footer' => true));
    return $result;
}
