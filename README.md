# LocalPOC – WordPress Download POC

LocalPOC has two parts:

- A WordPress plugin (`plugin/localpoc.php`) that exposes authenticated REST endpoints.
- A CLI (`localpoc`), packaged as a PHAR, that connects to the plugin and downloads the site (files + database) efficiently.

You install the plugin on a site, copy the command it shows you, and run that command locally.

---

## 1. Install the CLI (PHAR)

### macOS / Linux

1. Download the latest PHAR from GitHub Releases:

   ```bash
   curl -L "https://github.com/joeguilmette/local-migrator-poc/releases/latest/download/localpoc.phar" -o localpoc
````

2. Make it executable and put it on your `PATH`:

   ```bash
   chmod +x localpoc
   sudo mkdir -p /usr/local/bin
   sudo mv localpoc /usr/local/bin/localpoc
   ```

3. Check it’s available:

   ```bash
   localpoc --help
   ```

If that prints usage, the CLI is installed.

> Windows: save `localpoc.phar` somewhere and run it as `php localpoc.phar ...` or create a small `.bat` wrapper; details are left to the user.

---

## 2. Install the WordPress plugin

1. Install and activate `plugin/localpoc.php` in your WordPress install.

2. A “LocalPOC” / “Site Downloader” menu item will appear in the admin. Open it:

   * It shows the **Site URL**.
   * It shows the **Access Key**.
   * It shows a ready‑to‑run CLI command.

Copy that command; you’ll use it in the next step.

---

## 3. Run a download

Basic command:

```bash
localpoc download --url=<URL> --key=<KEY> [--output=<DIR>] [--concurrency=<N>]
```

* `--url`          Site base URL (from the plugin page), e.g. `https://example.com`
* `--key`          Access key (from the plugin page)
* `--output`       Local destination directory (default: `./local-backup`)
* `--concurrency`  Number of parallel file downloads (default: `4`)

Example:

```bash
localpoc download \
  --url="https://example.com" \
  --key="ACCESS_KEY_FROM_PLUGIN" \
  --output="./example-backup"
```

On success, LocalPOC will:

* Stream the database into `<output>/db.sql`.
* Mirror the remote file tree under `<output>/`.
* Print a summary of successes and failures.

Exit codes:

* `0` – success
* `2` – bad or missing arguments
* `3` – HTTP / network error
* `4` – internal error

---

## 4. Uninstall

* Remove the CLI:

  ```bash
  sudo rm /usr/local/bin/localpoc
  ```

* Remove the plugin:

  * Deactivate it from the WordPress Plugins screen.
  * Delete `wp-content/plugins/localpoc/`.

```
```
