<?php

declare(strict_types=1);

namespace iamntz\WpViteManifest\functions;

!defined('ABSPATH') && die();

/**
 * Vite integration for WordPress
 *
 * @package ViteForWp
 */

use Exception;

const VITE_CLIENT_SCRIPT_HANDLE = 'vite-client';

/**
 * Get manifest data
 *
 * @param string $manifest_dir Path to manifest directory.
 *
 * @return object Object containing manifest type and data.
 * @throws Exception Exception is thrown when the file doesn't exist, unreadble, or contains invalid data.
 *
 * @since 1.0.0
 *
 */
function get_manifest(string $manifest_dir): object
{
    static $manifests = [];

    $file_names = ['manifest'];

    foreach ($file_names as $file_name) {
        $manifest_path = "{$manifest_dir}/{$file_name}.json";

        if (isset($manifests[$manifest_path])) {
            return $manifests[$manifest_path];
        }

        if (is_readable($manifest_path)) {
            break;
        }

        unset($manifest_path);
    }

    $is_dev = false;

    if (isset($manifest_path)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $manifest_content = file_get_contents($manifest_path);
    } else {
        $manifest_path = 'dev';
        $is_dev = true;
        $manifest_content = apply_filters('iamntz/wp-vite-manifest/vite-manifest-dev', [
            "base" => "/",
            "origin" => getenv('WP_VITE_MANIFEST_ORIGIN') ?: "https://0.0.0.0",
            "port" => getenv('VITE_SERVER_PORT') ?: 3000,
            "plugins" => [],
            "manifest_dir" => $manifest_dir,
        ]);

        $manifest_content['origin'] .= ':' . $manifest_content['port'];

        $manifest_content = json_encode($manifest_content);
    }

    $manifest = json_decode($manifest_content);

    $manifests[$manifest_path] = (object) [
        'data' => $manifest,
        'dir' => $manifest_dir,
        'is_dev' => $is_dev,
    ];

    return $manifests[$manifest_path];
}

/**
 * Filter script tag
 *
 * This creates a function to be used as callback for the `script_loader` filter
 * which adds `type="module"` attribute to the script tag.
 *
 * @param string $handle Script handle.
 *
 * @return void
 * @since 1.0.0
 *
 */
function filter_script_tag(string $handle): void
{
    add_filter('script_loader_tag', fn(...$args) => set_script_type_attribute($handle, ...$args), 10, 3);
}

/**
 * Add `type="module"` to a script tag
 *
 * @param string $target_handle Handle of the script being targeted by the filter callback.
 * @param string $tag Original script tag.
 * @param string $handle Handle of the script that's currently being filtered.
 *
 * @return string Script tag with attribute `type="module"` added.
 * @since 1.0.0
 *
 */
function set_script_type_attribute(string $target_handle, string $tag, string $handle): string
{
    if ($target_handle !== $handle) {
        return $tag;
    }

    $attribute = 'type="module"';
    $script_type_regex = '/type=(["\'])([\w\/]+)(["\'])/';

    if (preg_match($script_type_regex, $tag)) {
        // Pre-HTML5.
        $tag = preg_replace($script_type_regex, $attribute, $tag);
    } else {
        $pattern = $handle === VITE_CLIENT_SCRIPT_HANDLE
            ? '#(<script)(.*)#'
            : '#(<script)(.*></script>)#';
        $tag = preg_replace($pattern, sprintf('$1 %s$2', $attribute), $tag);
    }

    preg_match_all('/<script(.+)/', $tag, $tags);


    foreach ($tags[0] as $_tag) {
        if (!str_contains($_tag, ' src=')) {
            $replacement = str_replace('type="module"', '', $_tag);
            $tag = str_replace($_tag, $replacement, $tag);
        }
    }

    return $tag;
}

/**
 * Generate development asset src
 *
 * @param object $manifest Asset manifest.
 * @param string $entry Asset entry name.
 *
 * @return string
 * @since 1.0.0
 *
 */
function generate_development_asset_src(object $manifest, string $entry): string
{
    $origin = get_site_url() . ":{$manifest->data->port}";
    return sprintf(
        '%s/%s',
        untrailingslashit($origin),
        trim(preg_replace('/[\/]{2,}/', '/', "{$manifest->data->base}/{$entry}"), '/')
    );
}

/**
 * Register vite client script
 *
 * @param object $manifest Asset manifest.
 *
 * @return void
 * @since 1.0.0
 *
 */
function register_vite_client_script(object $manifest): void
{
    if (wp_script_is(VITE_CLIENT_SCRIPT_HANDLE)) {
        return;
    }

    $src = generate_development_asset_src($manifest, '@vite/client');

    // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
    wp_register_script(VITE_CLIENT_SCRIPT_HANDLE, $src, [], null, false);
    filter_script_tag(VITE_CLIENT_SCRIPT_HANDLE);
}

/**
 * Get react refresh script preamble
 *
 * @param string $src React refresh script source URL.
 * @return string
 */
function get_react_refresh_script_preamble(string $src): string
{
    $script = <<< EOS
import RefreshRuntime from "{$src}";
RefreshRuntime.injectIntoGlobalHook(window);
window.__VITE_IS_MODERN__ = true;
window.\$RefreshReg$ = () => {};
window.\$RefreshSig$ = () => (type) => type;
window.__vite_plugin_react_preamble_installed__ = true;
EOS;

    return $script;
}

/**
 * Load development asset
 *
 * @param object $manifest Asset manifest.
 * @param string $entry Entrypoint to enqueue.
 * @param array $options Enqueue options.
 *
 * @return array|null Array containing registered scripts or NULL if the none was registered.
 * @since 1.0.0
 *
 */
function load_development_asset(object $manifest, string $entry, array $options): ?array
{
    register_vite_client_script($manifest);

    $dependencies = array_merge(
        [VITE_CLIENT_SCRIPT_HANDLE],
        $options['dependencies']
    );

    if (in_array('vite:react-refresh', $manifest->data->plugins, true)) {
        $react_refresh_script_src = generate_development_asset_src($manifest, '@react-refresh');
        wp_add_inline_script(
            VITE_CLIENT_SCRIPT_HANDLE,
            get_react_refresh_script_preamble($react_refresh_script_src),
            'after'
        );
    }

    $src = generate_development_asset_src($manifest, $entry);

    filter_script_tag($options['handle']);

    // This is a development script, browsers shouldn't cache it.
    // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
    if (!wp_register_script($options['handle'], $src, $dependencies, null, $options['in-footer'])) {
        return null;
    }

    $assets = [
        'scripts' => [$options['handle']],
        'styles' => $options['css-dependencies'],
    ];

    /**
     * Filter registered development assets
     *
     * @param array $assets Registered assets.
     * @param object $manifest Manifest object.
     * @param string $entry Entrypoint file.
     * @param array $options Enqueue options.
     */
    $assets = apply_filters('vite_for_wp__development_assets', $assets, $manifest, $entry, $options);

    return $assets;
}

/**
 * Load production asset
 *
 * @param object $manifest Asset manifest.
 * @param string $entry Entrypoint to enqueue.
 * @param array $options Enqueue options.
 *
 * @return array|null Array containing registered scripts & styles or NULL if there was an error.
 * @since 1.0.0
 *
 */
function load_production_asset(object $manifest, string $entry, array $options, string $handleSuffix = ''): ?array
{
    $url = $options['base-url'];
    $options['handle'] .= $handleSuffix;

    if (!isset($manifest->data->{$entry})) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_die(esc_html(sprintf('[Vite] Entry %s not found.', $entry)));
        }

        return null;
    }

    $assets = [
        'scripts' => [],
        'styles' => [],
    ];

    $item = $manifest->data->{$entry};
    $src = "{$url}/{$item->file}";

    if (!empty($item->imports)) {
        foreach ($item->imports as $k => $import) {
            $imports = load_production_asset($manifest, $import, $options, '_' . $handleSuffix . $k);
            if (!$options['skip-js-dependencies']) {
                $options['dependencies'] = array_merge($options['dependencies'], $imports['scripts'] ?? []);
            }

            if (!$options['skip-css-dependencies']) {
                $options['css-dependencies'] = array_merge($options['dependencies'], $imports['styles'] ?? []);
            }
        }
    }

    if (!$options['css-only']) {
        filter_script_tag($options['handle']);

        // Don't worry about browser caching as the version is embedded in the file name.
        // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
        if (wp_register_script($options['handle'], $src, $options['dependencies'], '', $options['in-footer'])) {
            $assets['scripts'][] = $options['handle'];
        }
    }

    if (!empty($item->css)) {
        foreach ($item->css as $index => $css_file_path) {
            $style_handle = "{$options['handle']}-{$index}";
            // Don't worry about browser caching as the version is embedded in the file name.
            // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
            if (wp_register_style($style_handle, "{$url}/{$css_file_path}", $options['css-dependencies'], '', $options['css-media'])) {
                $assets['styles'][] = $style_handle;
            }
        }
    }

    $assets['styles'] = array_unique(array_merge($assets['styles'], ($options['css-dependencies'] ?? [])));

    return $assets;
}

/**
 * Parse register/enqueue options
 *
 * @param array $options Array of options.
 *
 * @return array Array of options merged with defaults.
 * @since 1.0.0
 *
 */
function parse_options(array $options): array
{
    $defaults = [
        'css-dependencies' => [],
        'css-media' => 'all',
        'css-only' => false,
        'dependencies' => [],
        // js deps is imported via js `import * from ....`. This remains here for edge cases/legacy.
        'skip-js-dependencies' => true,
        // css deps are automatically imported, but you have the option to disable this.
        'skip-css-dependencies' => false,
        'handle' => '',
        'in-footer' => false,
        'base-url' => '',
    ];

    return apply_filters('iamntz/wp-vite-manifest/options', wp_parse_args($options, $defaults));
}


/**
 * Register asset
 *
 * @param string $manifest_dir Path to directory containing manifest file, usually `build` or `dist`.
 * @param string $entry Entrypoint to enqueue.
 * @param array $options Enqueue options.
 *
 * @return array
 * @see load_development_asset
 * @see load_production_asset
 *
 * @since 1.0.0
 *
 */
function register_asset(string $manifest_dir, string $entry, array $options): ?array
{
    try {
        $manifest = get_manifest($manifest_dir);
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_die(esc_html($e->getMessage()));
        }

        return null;
    }

    $options = parse_options($options);
    $assets = $manifest->is_dev
        ? load_development_asset($manifest, $entry, $options)
        : load_production_asset($manifest, $entry, $options);

    return $assets;
}

/**
 * Enqueue asset
 *
 * @param string $manifest_dir Path to directory containing manifest file, usually `build` or `dist`.
 * @param string $entry Entrypoint to enqueue.
 * @param array $options Enqueue options.
 *
 * @return bool
 * @since 1.0.0
 *
 * @see register_asset
 *
 */
function enqueue_asset(string $manifest_dir, string $entry, array $options): bool
{
    $assets = register_asset($manifest_dir, $entry, $options);

    if (is_null($assets)) {
        return false;
    }

    $map = [
        'scripts' => 'wp_enqueue_script',
        'styles' => 'wp_enqueue_style',
    ];

    foreach ($assets as $group => $handles) {
        $func = $map[$group];

        foreach ($handles as $handle) {
            $func($handle);
        }
    }

    return true;
}
