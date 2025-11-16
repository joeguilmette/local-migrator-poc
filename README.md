# LocalPOC – WordPress Download POC

LocalPOC pairs a WordPress plugin (`plugin/localpoc.php`) with a cross-platform CLI (`localpoc/`) to download entire WordPress sites through authenticated REST endpoints. Install the plugin on a site to generate a CLI command, then run that command locally to pull files and the database via parallel transfers.

> **Quick CLI Install**
> 1. `cd localpoc`
> 2. `composer install`
> 3. `composer global require humbug/box`
> 4. `vendor/bin/box compile`
> 5. `sudo mv dist/localpoc.phar /usr/local/bin/localpoc && sudo chmod +x /usr/local/bin/localpoc`
> 6. `localpoc download --url=https://example.com --key=SECRET --output=./backup`

## Repository Layout
```
plugin/                 # WordPress plugin powering the REST endpoints
localpoc/               # PHP CLI packaged as a PHAR
```

## Requirements
- PHP ^8.0 with the cURL extension
- Composer
- [Box](https://github.com/box-project/box) for building the PHAR
- A WordPress site with the LocalPOC plugin installed

## Plugin Setup
1. Copy `plugin/localpoc.php` into a WordPress installation as a plugin and activate it.
2. Visit the “Site Downloader” admin menu to read the site URL, access key, and example CLI command.
3. Keep the command handy for the CLI step below.

## CLI Build Steps
1. `cd localpoc`
2. `composer install`
3. `composer global require humbug/box`
4. `vendor/bin/box compile`

The compiled artifact is `localpoc/dist/localpoc.phar`.

## CLI Installation Options
### PHAR (recommended)
```bash
sudo mv localpoc/dist/localpoc.phar /usr/local/bin/localpoc
sudo chmod +x /usr/local/bin/localpoc
```

### Composer Global (developer convenience)
```bash
composer global config repositories.localpoc path $(pwd)/localpoc
composer global require localpoc/localpoc:dev-main
```
Ensure Composer's global `vendor/bin` directory is on your `PATH` to invoke `localpoc` anywhere.

### Windows
Place `localpoc/dist/localpoc.phar` and `localpoc/windows/localpoc.bat` in a directory on your `PATH` (update the `.bat` file if `php.exe` lives elsewhere).

## Usage
```
localpoc download --url=<URL> --key=<KEY> [--output=<DIR>] [--concurrency=<N>]
```
- `--url` The WordPress site base URL (required)
- `--key` The plugin-provided access key (required)
- `--output` Destination directory (defaults to `./local-backup`)
- `--concurrency` Parallel download count (defaults to 4)

The CLI reports progress for the manifest, database stream, and concurrent file downloads. Final output summarizes the database export path, number of files downloaded, and any failures.

## Uninstall
- PHAR: `sudo rm /usr/local/bin/localpoc`
- Composer global: `composer global remove localpoc/localpoc`
- Windows: delete the `.phar` and `.bat` from your PATH directory.

## Development Notes
- Exit codes: `0` success, `2` usage error, `3` network/HTTP failure, `4` internal error.
- The CLI mirrors the remote file tree under the `--output` directory and streams the database export into `db.sql` within that directory.
