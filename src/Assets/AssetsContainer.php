<?php

namespace iamntz\WpViteManifest\Assets;

!defined('ABSPATH') && die();

class AssetsContainer
{
    private static AssetsContainer $instance;
    private array $assets = [];
    private array $enqueued = [];

    public function __construct() {}

    public static function instance(): AssetsContainer
    {
        self::$instance ??= new self();

        return self::$instance;
    }

    public function register(string $name, array $asset): static
    {
        $this->assets[$name] = $asset;

        return $this;
    }

    public function frontendEnqueue(string $name, ?string $inline_js_var_name = null, array $inline_js_data = []): void
    {
        if (did_action('wp_enqueue_scripts')) {
            $this->enqueue($name, $inline_js_var_name, $inline_js_data);
        } else {
            add_action('wp_enqueue_scripts', fn() => $this->enqueue($name, $inline_js_var_name, $inline_js_data), 10, 1);
        }
    }

    public function adminEnqueue(string $name, ?string $inline_js_var_name = null, array $inline_js_data = []): void
    {
        if (did_action('admin_enqueue_scripts')) {
            $this->enqueue($name, $inline_js_var_name, $inline_js_data);
        } else {
            add_action('admin_enqueue_scripts', fn() => $this->enqueue($name, $inline_js_var_name, $inline_js_data), 10, 1);
        }
    }

    public function enqueue(string $name, ?string $inline_js_var_name = null, array $inline_js_data = []): static
    {
        do_action("iamntz/wp-vite-manifest/assets/register/{$name}", $this);

        foreach ($this->assets[$name]['scripts'] as $handle) {
            do_action("iamntz/wp-vite-manifest/assets/register/{$handle}", $this, $name);

            if ($inline_js_var_name) {
                $this->localize($handle, $inline_js_var_name, $inline_js_data);
            }

            wp_enqueue_script($handle);
        }

        if (did_action('wp_enqueue_scripts') || did_action('admin_enqueue_scripts')) {
            $styles = wp_styles();

            foreach ($this->assets[$name]['styles'] as $handle) {
                if (!isset($styles->registered[$handle])) {
                    continue;
                }

                if ($this->enqueued[$handle] ?? false) {
                    continue;
                }

                $this->enqueued[$handle] = true;

                printf("<link rel='stylesheet' href='%s' type='text/css' media='%s' />", $styles->registered[$handle]->src, $styles->registered[$handle]->args);
            }

            return $this;
        }

        foreach ($this->assets[$name]['styles'] as $handle) {
            wp_enqueue_style($handle);
        }

        return $this;
    }

    public function localize(string $handle, $object_name, array $data = []): void
    {
        $data = apply_filters("iamntz/wp-vite-manifest/localize/{$handle}", $data, $object_name);

        wp_add_inline_script($handle, "const {$object_name} = " . json_encode($data), 'before');
    }
}