# Checkout Transfer

Seamlessly transfer WooCommerce cart and checkout sessions between different domains. This plugin is designed for multi-site setups where one site acts as a product showcase (Sender) and another handles the secure checkout process (Receiver).

## Table of Contents
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
    - [General Settings](#general-settings)
    - [Sender Mode Setup](#sender-mode-setup)
    - [Receiver Mode Setup](#receiver-mode-setup)
- [Product Synchronization](#product-synchronization)
- [Stock Synchronization](#stock-synchronization)
- [Security](#security)
- [Debugging & Logging](#debugging--logging)
- [Technical Details](#technical-details)

---

## Features

- **Cart Transfer**: Automatically encodes and transfers cart contents (products, quantities, variations) from the Sender site to the Receiver site.
- **Session Continuity**: Redirects users back to the Sender site after a successful checkout, clearing the cart on the Sender site to maintain consistency.
- **REST API Integration**: Secure communication between sites using a shared secret.
- **Product Sync**: Pull products (including images, categories, attributes, and variations) from the Receiver site to the Sender site with matching IDs.
- **Stock Sync**: Automatically updates stock levels on the Sender site when an order is completed on the Receiver site.
- **Access Control**: Configure which pages are accessible on each site and which should be redirected.
- **Developer Friendly**: Built-in debug logging and system status monitoring.

## Requirements

- **WordPress**: 5.0 or higher
- **WooCommerce**: 4.0 or higher
- **PHP**: 7.4 or higher
- **SSL**: Required for secure API communication and checkout.

## Installation

1. Upload the `checkout-transfer` folder to your `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress on **both** the Sender and Receiver sites.

## Configuration

Navigate to **Settings > Checkout Transfer** in your WordPress admin.

### General Settings
- **Enable Logic**: Toggle the plugin functionality on/off.
- **Site Role**: Choose whether this site is a **Sender** or a **Receiver**.
- **Shared Secret**: A secure token used for API requests. On the **Receiver** site, this is generated automatically. You must copy it and paste it into the **Sender** site.

### Sender Mode Setup
1. Set **Site Role** to "Sender Site".
2. Enter the **Receiver Site URL** (e.g., `https://checkout.yourstore.com`).
3. Paste the **Shared Secret** copied from the Receiver site.
4. **Allowed Pages**: Select pages that should remain accessible on the Sender site (e.g., Shop, Product pages). Unselected WooCommerce pages (like Checkout) will automatically redirect to the Receiver site.

### Receiver Mode Setup
1. Set **Site Role** to "Receiver Site".
2. Enter the **Sender Site URL** (e.g., `https://yourstore.com`).
3. Copy the **Shared Secret** provided.
4. **Allowed Pages**: Select pages accessible on the Receiver site (typically Cart, Checkout, My Account). Visitors accessing other parts of the site will be redirected back to the Sender.

---

## Product Synchronization

> [!NOTE]
> Product Sync is only available on the **Sender** site to pull data from the **Receiver**.

1. Go to the **Product Sync** tab on the Sender site.
2. Click **Fetch Products from Receiver**.
3. Select the products you wish to sync.
4. Click **Sync Selected Products**.
5. **Warning**: This will overwrite existing products on the Sender site if they share the same ID.

## Stock Synchronization

Stock synchronization happens automatically from **Receiver → Sender**. When a customer completes an order on the Receiver site, it pings the Sender site's API to decrease stock for the corresponding items, ensuring inventory levels remain consistent across both domains.

---

## Security

- **Shared Secret**: All REST API requests require an `X-CT-Secret` header. This prevents unauthorized sites from fetching product data or modifying stock levels.
- **Sanitization**: All input data, including URLs and cart payloads, are sanitized and validated before processing.
- **Encoded Payloads**: Cart data is transferred via Base64 encoded JSON strings to prevent injection or corruption during redirection.

## Debugging & Logging

If you encounter issues:
1. Enable **Debug Logging** in the General tab.
2. Check the **Debug** tab for system status and recent activity logs.
3. Common status checks:
    - WooCommerce Active status.
    - Site URL mismatch.
    - REST API connectivity.

## Technical Details

### REST API Endpoints (Prefix: `wp-json/ct/v1`)
- `GET /products`: List all published products (ID and Title).
- `GET /product/{id}`: Fetch full product details for synchronization.
- `POST /stock/update`: Update stock levels for specific items.

### Key Classes
- `CheckoutTransfer`: Main entry point and activation logic.
- `CT_Admin`: Handles the settings interface and tabs.
- `CT_Logic`: Manages redirection and cart transfer logic.
- `CT_Sync`: Handles product and stock synchronization.
- `CT_API`: Registers and handles REST API routes.
- `CT_Logger`: Simple logging utility for troubleshooting.
