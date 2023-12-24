<?php

namespace iamntz\WpViteManifest\Assets;

use function iamntz\WpViteManifest\functions\register_asset;

class Assets
{
    public function __construct(
        private readonly array $assets,
        private readonly string $baseURL,
        private readonly string $manifestDir,
        private readonly AssetsContainer $assetsContainer) {}

    public function hooks(): void
    {
        add_action('admin_enqueue_scripts', [$this, '_registerAssets']);
        add_action('wp_enqueue_scripts', [$this, '_registerAssets']);
    }

    public function _registerAssets(): void
    {
        foreach ($this->assets as $containerHandle => $item) {
            $this->assetsContainer->register($containerHandle, $this->register($item));

            if (!($item['enqueue'] ?? false)) {
                continue;
            }

            if (($item['frontend_only'] ?? false) && is_admin()) {
                continue;
            }

            if (($item['admin_only'] ?? false) && !is_admin()) {
                continue;
            }

            $this->assetsContainer->enqueue($containerHandle);
        }
    }

    private function register(array $item): ?array
    {
        return register_asset(
            $this->manifestDir,
            $item['src'],
            [
                'handle' => $item['handle'],
                'base-url' => $this->baseURL,
                'dependencies' => $item['dependencies'] ?? [],
            ]
        );
    }
}
