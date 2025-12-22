# UPOS Payments for WooCommerce

[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html)
[![WooCommerce](https://img.shields.io/badge/WooCommerce->=5.0-purple.svg)](https://woocommerce.com/)
[![WordPress](https://img.shields.io/badge/WordPress->=5.8-blue.svg)](https://wordpress.org/)

Official UPOS Payments gateway for WooCommerce. Accept crypto payments easily in your store.

*Note: Planning for official submission to the WordPress Plugin Directory is currently underway.*

## Ecosystem

Looking for other ways to integrate UPOS? Check out our other repositories:

- [**Web SDK**](https://github.com/upos-pay/web-sdk)

## Features

- Automatic environment detection (Test/Live) based on API keys
- Comprehensive transaction logging
- Support for WooCommerce HPOS (High-Performance Order Storage)

## System Requirements

- **WordPress**: 5.8 or higher
- **WooCommerce**: 5.0 or higher
- **PHP**: 7.4 or higher

## Installation

**Important: This plugin requires [WooCommerce](https://wordpress.org/plugins/woocommerce/) to be installed and activated first.**

### Option 1: WordPress Admin (Recommended)

1. In your WordPress admin, go to **Plugins > Add New**.
2. Click **Upload Plugin** at the top.
3. Select the `upos-woocommerce.zip` file from your build directory.
4. Click **Install Now** and then **Activate**.

### Option 2: Manual Upload

1. Unzip the `upos-woocommerce.zip` archive.
2. Upload the `upos-woocommerce` folder to the `/wp-content/plugins/` directory.
3. Activate the plugin through the **Plugins** menu in WordPress.

## Configuration

1. Navigate to **WooCommerce > Settings > Payments > UPOS Payments**.
2. Enter your **API Key** and **API Secret** (e.g., `pk_test_...` for testing).
3. Save changes and start accepting payments.

## FAQ

**How do I get an API Key?**

> Please contact [UPOS](https://upos.fi) payment services to obtain your API credentials.

**How do I test the payment functionality?**

> Simply enter your test environment API keys (starting with `pk_test_`). The plugin automatically detects and switches to Test Mode.

## Development

The project includes a Docker-based development environment.

**Note:** The following credentials are set for **local development only** in `compose.yaml` and `docker/` configuration. Do not use these in production:

- **WordPress Admin**: `admin` / `admin`
- **Database Password**: `wordpress`
- **Database Root**: `root`

## License

This project is licensed under the **GPLv2 or later**. See the [LICENSE](./LICENSE) file for details.
