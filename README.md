# LocalPOC – WordPress Download POC

LocalPOC has two parts:

- A WordPress plugin (`plugin/localpoc.php`) that exposes authenticated REST endpoints.
- A CLI (`localpoc`), packaged as a PHAR, that connects to the plugin and downloads the site (files + database) efficiently.

You install the plugin on a site, copy the command it shows you, and run that command locally.

---

## 1. Install the CLI (PHAR)

One-liner install (Mac/Linux):

```bash
curl -L "https://github.com/joeguilmette/local-migrator-poc/releases/latest/download/localpoc.phar" -o localpoc \
  && chmod +x localpoc \
  && sudo mkdir -p /usr/local/bin \
  && sudo mv localpoc /usr/local/bin/localpoc \
  && localpoc --help
```

If the final command prints usage, the CLI is installed.

> Windows: save `localpoc.phar` somewhere and run it as `php localpoc.phar ...` or create a small `.bat` wrapper; details are left to the user.

---

## 2. Install the WordPress plugin
 Install the WordPress plugin

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

* Stream all `wp-content/` files plus the database into a temporary workspace.
* Build a single ZIP archive at `<output>/archives/<domain>-<YYYYmmdd-HHMMSS>.zip`.
  * The archive contains `wp-content/` (with the full tree) and `db.sql` at the root.
* Remove the workspace and print the final archive path plus transfer stats.

Exit codes:

* `0` – success
* `2` – bad or missing arguments
* `3` – HTTP / network error
* `4` – internal error

---

## 4. Development

These steps are for contributors and release maintainers.

### Requirements

* PHP ^8.0 with the cURL and Phar extensions
* Composer
* [humbug/box](https://github.com/box-project/box) listed in `cli/composer.json` (as a dev dependency)

### Build the PHAR from source

```bash
cd /path/to/localpenis/cli
composer install
vendor/bin/box compile
```

The build artifact is `cli/dist/localpoc.phar`.

### Install locally (for testing)

```bash
cd /path/to/localpenis/cli
sudo mkdir -p /usr/local/bin
sudo install -m 755 dist/localpoc.phar /usr/local/bin/localpoc
localpoc --help
```

Or run directly without installing:

```bash
php dist/localpoc.phar download --url=... --key=... --output=...
```

### Publish a GitHub release

Use the helper script (requires the [GitHub CLI](https://cli.github.com/) authenticated via `gh auth login`):

```bash
cd /path/to/localpenis
./scripts/release-phar.sh v0.1.0
```

Optionally supply a release-notes file as the second argument:

```bash
./scripts/release-phar.sh v0.1.0 notes.md
```

The script installs Composer dependencies, builds the PHAR with Box, and runs `gh release create` with `cli/dist/localpoc.phar` attached as the release asset.

### Publish the plugin ZIP to a release

Package the `plugin/` directory and upload it as a release asset (release must already exist or the script will create one):

```bash
cd /path/to/localpenis
./scripts/release-plugin.sh v0.1.0
```

Provide a notes file as the second argument to override the default release notes. If the release already exists, the script uploads `localpoc-plugin-v0.1.0.zip` via `gh release upload --clobber`.

---

## 5. Uninstall

* Remove the CLI:

  ```bash
  sudo rm /usr/local/bin/localpoc
  ```

* Remove the plugin:

  * Deactivate it from the WordPress Plugins screen.
  * Delete `wp-content/plugins/localpoc/`.

```
```
