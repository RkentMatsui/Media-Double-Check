# Media Double Check

**Media Double Check** is a powerful WordPress utility plugin designed to provide a "second opinion" on media items flagged as unused by tools like *Media Cleaner*. It performs an exhaustive "Deep Scan" across your entire database to ensure that files marked for deletion aren't actually hidden in page builder data, meta fields, or custom configurations.

## 🚀 Key Features

### 🔍 Exhaustive Deep Scan
Scans beyond standard post content, including:
- **ACF Specialization**: Detects image usage in Advanced Custom Fields across Posts, Options Pages, and Taxonomies.
- **Page Builders**: Deep dives into JSON and shortcode data for Elementor, Divi, and BeBuilder (Muffin Builder).
- **E-commerce**: Checks WooCommerce product galleries and category thumbnails.
- **Global Metadata**: A comprehensive fallback search across all remaining `postmeta` keys.

### 🗑️ Internal Trash System
Safety first! The plugin implements an internal trash workflow:
- **No Permanent Deletion**: Items are moved to the WordPress trash status (`trash`) instead of being deleted from the disk.
- **One-Click Restore**: Easily recover trashed items directly from the plugin dashboard.
- **Dedicated Filters**: Filter results by "Active", "Truly Unused", "Used", and "Internal Trash".

### 🖱️ Advanced Bulk Actions
Efficiently manage large libraries:
- **Bulk Select Mode**: Precision management for large libraries.
- **Selection Bar**: Move selected items to Trash or Exclude them with one click.
- **Exclusion System**: Mark specific "unused" files as **Safe (Excluded)** to hide them from future scan results.

### ⚙️ Customizable Settings
Control the scan scope to maximize speed and accuracy:
- Toggle specific plugin integrations (Elementor, ACF, WooCommerce, etc.).
- Enable/Disable global metadata fallback search.

## 🛠️ Installation

1. Upload the `media-double-check` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the plugin via the **Media Double Check** menu in your admin sidebar.

## 🏗️ Technical Details

- **Database Optimized**: Uses a dedicated local results table (`wp_mdc_results`) to store scan data, keeping the interface snappy even with thousands of files.
- **AJAX Driven**: Real-time scanning and action processing without page reloads.
- **Modern UI**: A premium orange theme with a clean, responsive layout and interactive action components.

---

*Note: This plugin is intended as a safety verification layer for Media Cleaner or similar tools. Always perform a database backup before performing mass trashing operations.*
*Made with Google Antigravity*