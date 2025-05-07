Add Vite integration for WP. `vite-manifest.php` is a forked version of [kucrut/vite-for-wp](https://github.com/kucrut/vite-for-wp).

## How to configure vite:

Update your `vite.config` so it will include `server` settings & enable manifest:

### If you're using DDEV:

Run `npm install dotenv`. Run the build as `ddev npm run vite`

Add this in your `.ddev/config.yaml`:

```yaml
web_extra_exposed_ports:
    - name: vite
      container_port: 3027
      http_port: 3026
      https_port: 3027
```

```javascript
import {resolve} from 'path';
import {fileURLToPath, URL} from 'node:url'
import fs from "fs";

import {defineConfig, splitVendorChunkPlugin} from 'vite'
import vue from '@vitejs/plugin-vue'
import dotenv from 'dotenv';

dotenv.config(); // load env vars from .env

const port = 3027;
const origin = `${process.env.DDEV_PRIMARY_URL}:${port}`;

// https://vitejs.dev/config/
export default defineConfig({
  server: {
      // respond to all network requests:
      host: "0.0.0.0",
      port,
      origin,
      strictPort: true,
      allowedHosts: true,
      cors: { // read this: https://dev.to/mandrasch/vite-is-suddenly-not-working-anymore-due-to-cors-error-ddev-3673
          methods:"*",
          preflightContinue: true
      },
  },

  esbuild: {
    drop: process.env.NODE_ENV === 'development' ? [] :  ['console', 'debugger'],
    sourcemap: false,
  },

  plugins: [vue(), splitVendorChunkPlugin()],
  build: {
    manifest: true,
    cssCodeSplit: true,
    sourcemap: true,
    assetsDir: '',
    minify: 'esbuild',
    rollupOptions: {
      // https://rollupjs.org/configuration-options/
      input: {
        'your-script-name': resolve(__dirname, 'src/your-script-name.js'),
      },
    },
  },

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  }
})
```

Additionally, you may need to override the URL (useful in multisite installs):

```php
// wp-content/mu-plugins/vite.php
add_filter('iamntz/wp-vite-manifest/development_assets_origin', function ($origin, $manifest, $entry) {
    return "https://your-url:3027";
}, 10, 3);
```

Bonus: detect if vite is running or not and either load pre-compiled assets or vite assets:
```php
// wp-content/mu-plugins/vite.php
add_filter('iamntz/wp-vite-manifest/the-manifest', function ($data) {
    $hasDevRunning = (int) wp_remote_retrieve_response_code(wp_remote_get( 'https://your-url:3027/@vite/client', ['timeout' => 10]));

    if ($hasDevRunning !== 200) {
        return $data;
    }

    $manifest = [
        "base" => "/",
        "origin" => getenv('DDEV_PRIMARY_URL') ?: "https://0.0.0.0:3027",
        "port" => 3027,
        "plugins" => [],
        "manifest_dir" => $data->dir,
    ];

    $data = [
        'data' => (object) $manifest,
        'dir' => $data->dir,
        'is_dev' => true,
    ];

    return (object) $data;
}, 10);
```

---

### If you're NOT using DDEV:

Inside `package.json` scripts, add `--host` argument: `"dev": "vite --host"` & run `npm install dotenv`.

```javascript
import {resolve} from 'path';
import {fileURLToPath, URL} from 'node:url'
import fs from "fs";

import {defineConfig, splitVendorChunkPlugin} from 'vite'
import vue from '@vitejs/plugin-vue'
import dotenv from 'dotenv';

dotenv.config(); // load env vars from .env

// https://github.com/idleberg/php-wordpress-vite-assets
const _server = {
  port: 3003,
  strictPort: true,
  cors: false, // read this: https://dev.to/mandrasch/vite-is-suddenly-not-working-anymore-due-to-cors-error-ddev-3673
  headers: {
    'Access-Control-Allow-Origin': '*',
  },
  hmr: {
    protocol: 'ws',
  },
};

const server = {
  ..._server,
};

if (process.env.VITE_SERVER_HOST) {
  server.host = process.env.VITE_SERVER_HOST;
  server.hmr.host = process.env.VITE_SERVER_HOST;
  server.origin = process.env.VITE_SERVER_HOST;
}

if (process.env.VITE_SERVER_PORT) {
  server.port = process.env.VITE_SERVER_PORT;
  server.hmr.port = process.env.VITE_SERVER_PORT;
}

if (process.env.VITE_HTTPS_KEY && process.env.VITE_HTTPS_CERT) {
  if (fs.existsSync(process.env.VITE_HTTPS_KEY) && fs.existsSync(process.env.VITE_HTTPS_CERT)) {
    server.https = {
      key: fs.readFileSync(process.env.VITE_HTTPS_KEY),
      cert: fs.readFileSync(process.env.VITE_HTTPS_CERT),
    };
    server.hmr = 'wss';
  }
}

// https://vitejs.dev/config/
export default defineConfig({
  server,

  plugins: [vue(), splitVendorChunkPlugin()],
  build: {
    manifest: true,
    cssCodeSplit: true,
    sourcemap: true,
    assetsDir: '',
    rollupOptions: {
      // https://rollupjs.org/configuration-options/
      input: {
        'your-script-name': resolve(__dirname, 'src/your-script-name.js'),
      },
    },
  },

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  }
})

```

Your `.env` should contain the matching `server` items:

```
VITE_SERVER_HOST=your-host.local-dev <<< the same host you're using on WP
VITE_SERVER_PORT=3003

VITE_HTTPS_CERT=/etc/ssl/certs/custom_certs/_cert.pem
VITE_HTTPS_KEY=/etc/ssl/certs/custom_certs/_cert-key.pem
```

----


## How to use inside your WP plugin:

Register assets like so:

```php
$assetsContainer = new AssetsContainer();
$assets = new Assets(
  [
    'my-custom-script-name' => ['handle' => 'your-script-name-handle-registered-to-wp', 'src' => 'your-script-name-as-it-is-inside-manifest'],
    // ... register as many assets as you need
  ],
  plugins_url('assets/dist', __FILE__), // <<< the URL of where the build files are stored
  plugin_dir_path(__FILE__) . 'assets/dist', // <<< the PATH of the manifest' **directory**
  $assetsContainer
);

$assets->hooks();
```

Note that you don't have to worry about `wp_enqueue_scripts` hook; this must be run as early as possible!

### Enqueue assets:

Note that you don't have to use the WP handle, but the handle you used to register the asset inside the `Assets` class!

```php
$assetsContainer->enqueue('my-custom-script-name'); // this must be called _after_ `wp_enqueue_scripts` was triggered
$assetsContainer->frontendEnqueue('my-custom-script-name'); // this must be called at any time
$assetsContainer->adminEnqueue('my-custom-script-name'); // this must be called at any time
```

You can also add an inline script (e.g. used for JS config):

```php
$assetsContainer->enqueue('your-script-name', 'my_inline_js', [
  'foo' => 'bar'
]);
```

You can even have a callable as a third param, so you have a lazy callback:
```php
$assetsContainer->enqueue('your-script-name', 'my_inline_js', fn() => [
  'foo' => 'bar'
]);
```

Then in your JS code you'll use:

```javascript
console.log(my_inline_js)
```

If you want, you can also have the second argument as a tuple, so you can use the generated component on multiple instances (e.g. on Elementor Widgets):

```php
$assetsContainer->enqueue('your-script-name', ['the_global_object_var_name', 'the_id'], fn() => [
  'foo' => 'bar'
]);
```

Which internally will generate a code similar to this:

```javascript
window.the_global_object_var_name = window.the_global_object_var_name || {}
window.the_global_object_var_name['the_id'] = { foo: 'bar' }
```

You'll then use it like so:

```javascript
console.log( the_global_object_var_name['the_id'] )
```
