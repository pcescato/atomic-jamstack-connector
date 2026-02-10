=== Atomic Jamstack Connector ===
Contributors: pcescato
Tags: jamstack, hugo, github, devto, static-site, publishing, headless
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Flexible WordPress publishing with 5 strategies: WordPress-only, WordPress+dev.to syndication, GitHub headless, dev.to headless, or dual GitHub+dev.to publishing.

== Description ==

Atomic Jamstack Connector is a production-grade WordPress plugin that gives you complete control over where your content is published. Choose from 5 distinct publishing strategies to match your workflow, from traditional WordPress to fully headless JAMstack.

**Publishing Strategies:**

* **WordPress Only**: Keep your content on WordPress with no external sync (plugin configured but sync disabled)
* **WordPress + dev.to Syndication**: WordPress remains your public site while optionally syndicating posts to dev.to with proper canonical URLs
* **GitHub Only (Headless)**: WordPress becomes admin-only, all posts sync to Hugo/Jekyll on GitHub Pages
* **Dev.to Only (Headless)**: WordPress becomes admin-only, all posts sync to dev.to
* **Dual Publishing (GitHub + dev.to)**: WordPress becomes admin-only, posts sync to GitHub (canonical) with optional dev.to syndication

**Key Features:**

* **5 Publishing Strategies**: Choose the workflow that matches your needs
* **Per-Post Sync Control**: Checkbox on each post to control dev.to syndication (in wordpress_devto and dual modes)
* **Headless WordPress**: Automatic frontend redirects to your external site in headless modes
* **Canonical URL Management**: Smart canonical URL handling for SEO when syndicating to dev.to
* **Dev.to Integration**: Native dev.to API support with Markdown conversion optimized for dev.to
* **Atomic Commits**: All content and images uploaded in a single GitHub commit
* **Custom Front Matter Templates**: Define your own YAML or TOML Front Matter with placeholders for maximum Hugo theme compatibility
* **Tabbed Settings Interface**: Organized settings with General and Credentials tabs for better UX
* **Enhanced Security**: Encrypted token storage, masked in UI, never exposed
* **Clean Uninstall Option**: Choose to preserve or permanently delete all plugin data on uninstall
* **Async Processing**: Background sync using Action Scheduler (no blocking)
* **Image Optimization**: Automatic WebP and AVIF generation with Imagick
* **Deletion Management**: Automatic cleanup when posts are trashed/deleted
* **Bulk Sync**: Synchronize all published posts with one click
* **Page Support**: Sync both posts and pages to your Hugo site
* **Author Access**: Authors can sync their own posts and view their sync history
* **Monitoring Dashboard**: Track sync status, view GitHub commits, one-click resync
* **Clean Markdown**: WordPress HTML converted to platform-compatible Markdown
* **Advanced Logging**: Detailed debug logs with real-time feedback in admin UI
* **Retry Logic**: Automatic retry with exponential backoff on failures
* **Backward Compatible**: Automatic migration from older plugin versions

**Technical Highlights:**

* 5-strategy architecture with post-level control
* Headless redirect handler with 301 permanent redirects
* Dev.to API integration with canonical URL support
* Meta box for per-post sync control
* Customizable Front Matter templates with 7+ placeholders (title, date, author, slug, id, images)
* Compatible with any Hugo theme (PaperMod, Minimal, etc.)
* Supports both YAML (`---`) and TOML (`+++`) Front Matter formats
* Single source of truth: `_jamstack_sync_status` post meta
* WordPress native APIs only (no shell commands)
* GitHub Trees API for atomic commits (~70% fewer API calls)
* Smart duplicate prevention
* Featured image and content image processing
* Lock mechanism to prevent concurrent syncs
* Enhanced error handling with fallback logging
* Role-based access control (Authors can sync their own posts)
* Automatic settings migration from older versions

**Requirements:**

* WordPress 6.9+
* PHP 8.1+
* Imagick PHP extension (recommended) or GD
* GitHub Personal Access Token with repo permissions (for GitHub strategies)
* Dev.to API Key (for dev.to strategies)
* Hugo static site repository on GitHub (for GitHub strategies)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/atomic-jamstack-connector/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Jamstack Sync > Settings
4. **Choose Your Publishing Strategy** (General tab):
   - WordPress Only (no external sync)
   - WordPress + dev.to Syndication
   - GitHub Only (Headless)
   - Dev.to Only (Headless)
   - Dual Publishing (GitHub + dev.to)
5. **Configure URLs** (General tab):
   - GitHub Site URL: Your deployed Hugo/Jekyll site URL (e.g., https://username.github.io/repo)
   - Dev.to Site URL: Your dev.to profile URL or WordPress URL for canonical links
6. **Configure Credentials** (Credentials tab):
   - GitHub: Personal Access Token, Repository (owner/repo), Branch
   - Dev.to: API Key
7. **Test Connections** using the test buttons
8. **Optional Configuration** (General tab):
   - Custom Front Matter template
   - Content types to sync
   - Debug mode
   - Clean uninstall option
9. Save settings and start publishing!

**Per-Post Sync Control:**

In wordpress_devto and dual_github_devto modes, you'll see a "Jamstack Publishing" meta box in the post editor sidebar. Check "Publish to dev.to" to syndicate that specific post to dev.to. The meta box shows sync status and dev.to article links.

== Frequently Asked Questions ==

= What are the different publishing strategies? =

**WordPress Only**: No external sync. Your WordPress site remains fully public and functional. The plugin is configured but all sync operations are disabled.

**WordPress + dev.to Syndication**: Your WordPress site is your primary publication (canonical). You can optionally syndicate posts to dev.to on a per-post basis. Dev.to articles include canonical_url pointing back to WordPress for SEO.

**GitHub Only (Headless)**: WordPress becomes headless (admin-only). All published posts automatically sync to your Hugo/Jekyll site on GitHub. Frontend visitors are redirected to your static site.

**Dev.to Only (Headless)**: WordPress becomes headless. All published posts automatically sync to dev.to. Frontend visitors are redirected to your dev.to profile.

**Dual Publishing (GitHub + dev.to)**: WordPress becomes headless. All posts sync to GitHub (canonical), with optional per-post syndication to dev.to. Dev.to articles include canonical_url pointing to your Hugo site.

= How does headless mode work? =

In headless modes (github_only, devto_only, dual_github_devto), the plugin automatically redirects frontend requests to your external site using 301 permanent redirects. Logged-in administrators can still access the WordPress admin area normally. Configure your redirect URLs in Settings > General.

= Can I control which posts sync to dev.to? =

Yes! In wordpress_devto and dual_github_devto modes, each post has a "Jamstack Publishing" meta box in the editor sidebar. Check "Publish to dev.to" to syndicate that specific post. Unchecked posts won't sync to dev.to.

= How are canonical URLs handled? =

The plugin manages canonical URLs automatically based on your strategy:
- **wordpress_devto**: Dev.to canonical points to your WordPress URL
- **dual_github_devto**: Dev.to canonical points to your Hugo/Jekyll site
- **devto_only**: No canonical URL (dev.to is primary)
- **github_only**: No canonical URL (GitHub is primary)

= What is an atomic commit? =

An atomic commit means all files (Markdown post + all images) are committed to GitHub in a single operation. This results in cleaner git history and better reliability.

= How do I customize the Front Matter for my Hugo theme? =

Go to Settings > General > Hugo Configuration. You can define your own Front Matter template using placeholders like {{title}}, {{date}}, {{author}}, {{id}}, {{slug}}, {{image_avif}}, {{image_webp}}, and {{image_original}}. The plugin supports both YAML (`---`) and TOML (`+++`) formats.

= Can I migrate from an older version of the plugin? =

Yes! The plugin automatically detects old settings (adapter_type, devto_mode) and migrates them to the new publishing_strategy system. Your credentials and existing content remain intact.

= Does this work with other static site generators? =

The plugin is designed for Hugo but uses an adapter pattern. Dev.to support is built-in. You can create custom adapters for Jekyll, Eleventy, or other generators.

= What happens if a sync fails? =

Failed syncs are automatically retried up to 3 times with exponential backoff. You can monitor all sync operations in the Sync History tab.

= Can Authors sync their own posts? =

Yes! Authors have access to the "Sync History" page where they can view and sync their own posts. They cannot access the Settings page or modify GitHub credentials.

= Can I sync existing posts? =

Yes! Use the "Synchronize All Posts" button in the Bulk Operations tab to sync all published posts.

= Are images optimized? =

Yes. The plugin generates WebP format (and AVIF if supported) with 85% quality, significantly reducing file sizes while maintaining visual quality.

= What if I delete a post in WordPress? =

When you trash or delete a post, the corresponding Markdown file and images are automatically deleted from your GitHub repository (if using GitHub strategy).

= How do I enable debug logging? =

Go to Settings > General > Debug Settings and check "Enable detailed logging for debugging". The page will show you the log file path and status. Logs are stored in `wp-content/uploads/atomic-jamstack-logs/` with daily rotation.

= Is my GitHub token secure? =

Yes! The token is encrypted before storage and masked in the UI (displayed as ••••••••••). The token is never exposed in plain text and is only used for GitHub API calls.

= What happens to my data when I uninstall the plugin? =

By default, all settings and sync data are **preserved** when you uninstall the plugin. This allows you to reinstall without reconfiguring. If you want to permanently delete all plugin data, enable the "Delete data on uninstall" checkbox in Settings > General > Debug Settings before uninstalling.

== Screenshots ==

1. Settings page - Publishing Strategy selection (5 strategies)
2. Settings page - URL configuration for headless modes
3. GitHub Credentials tab with masked token
4. Dev.to Credentials tab with API key
5. Custom Front Matter template editor with placeholders
6. Post editor - Jamstack Publishing meta box with dev.to checkbox
7. Bulk sync operations with live statistics
8. Sync history monitoring dashboard
9. Post list with sync status indicators and dev.to links
10. Headless configuration notice page

== Changelog ==

= 1.2.0 =
* NEW: 5 Publishing Strategies (wordpress_only, wordpress_devto, github_only, devto_only, dual_github_devto)
* NEW: Per-post dev.to sync control via meta box checkbox
* NEW: Headless WordPress mode with automatic frontend redirects
* NEW: Dev.to integration with native API support
* NEW: Canonical URL management for SEO when syndicating
* NEW: GitHub Site URL and Dev.to Site URL configuration fields
* NEW: Post meta box showing sync status and dev.to article links
* NEW: Headless redirect handler with 301 permanent redirects
* NEW: Configuration notice page for headless modes
* NEW: Automatic migration from old settings (adapter_type/devto_mode)
* IMPROVED: Settings UI restructured for 5-strategy system
* IMPROVED: Sync runner refactored with strategy-based routing
* IMPROVED: Dev.to adapter accepts canonical URL as parameter
* IMPROVED: Security - all $_SERVER['REQUEST_URI'] properly sanitized
* IMPROVED: Redirect URLs validated during settings save
* FIXED: External redirects now work correctly (wp_redirect vs wp_safe_redirect)
* FIXED: Field name consistency (github_site_url, devto_site_url)
* FIXED: Meta box only shows in relevant publishing strategies
* SECURITY: Added PHPCS annotations for intentional wp_redirect() usage
* SECURITY: Enhanced URL validation and sanitization
* Documentation: Comprehensive readme update for 5-strategy system

= 1.1.0 =
* NEW: Customizable Front Matter templates with 7+ placeholders for Hugo theme compatibility
* NEW: Support for both YAML and TOML Front Matter formats
* NEW: Tabbed settings interface (General and Credentials tabs)
* NEW: GitHub token masking and enhanced security
* NEW: Token preservation logic (empty input keeps existing token)
* NEW: Author access - Authors can sync their own posts
* NEW: Role-based sync history filtering
* NEW: {{id}} placeholder for dynamic post ID in Front Matter
* NEW: Conditional clean uninstall - User control over data deletion
* NEW: Settings merge logic to prevent data loss across tab saves
* IMPROVED: Enhanced debug logging with real-time file path and size display
* IMPROVED: Upload directory error handling with fallback to WordPress debug.log
* IMPROVED: Log file protection with .htaccess and index.php
* IMPROVED: Settings UI with clearer descriptions and contextual help
* IMPROVED: Better UX with active tab preservation after save
* IMPROVED: Error handling with try-catch-finally blocks
* IMPROVED: Lock management guarantees release even on fatal errors
* IMPROVED: GitHub API logging with detailed status codes and messages
* IMPROVED: All API timeouts increased to 60 seconds
* FIXED: Commit link building (missing repository information)
* FIXED: Image path generation ({{id}} placeholder not working)
* FIXED: Logging system (files not being created)
* FIXED: PHP 8 type errors (explode on null values)
* FIXED: Status management - Posts never stuck in "processing" state
* FIXED: Settings data loss when saving from different tabs
* FIXED: WordPress Coding Standards compliance
* SECURITY: Added parse_repo() validation
* SECURITY: Implemented safety timeout (5 minutes) for stuck syncs

= 1.0.0 =
* Initial release
* Atomic commits using GitHub Trees API
* WebP and AVIF image generation
* Async processing with Action Scheduler
* Deletion management
* Bulk synchronization
* Monitoring dashboard
* Page support

== Upgrade Notice ==

= 1.2.0 =
Major architectural update introducing 5 publishing strategies, dev.to integration, headless WordPress mode, and per-post sync control. Includes automatic migration from previous versions. Recommended for all users seeking flexible publishing workflows.

= 1.1.0 =
Major update with customizable Front Matter templates, enhanced security, improved UI, author access, and robust error handling. Includes PHP 8 fixes and optional clean uninstall. Recommended for all users.

= 1.0.0 =
Initial release of Atomic Jamstack Connector.

== Development ==

This plugin follows WordPress coding standards and uses:

* PHP 8.1+ with strict types
* PSR-4 autoloading
* Namespaced architecture
* 5-strategy publishing system
* Headless WordPress redirects
* Dev.to API integration
* Action Scheduler for background processing
* Intervention Image for media processing
* League CommonMark and HTML-to-Markdown for content conversion

GitHub repository: https://github.com/pcescato/atomic-jamstack-connector

== Privacy ==

This plugin does not collect any user data. Communication occurs between:
- Your WordPress installation and your GitHub repository (using your credentials)
- Your WordPress installation and dev.to (using your API key)

No data is sent to third parties beyond the services you explicitly configure.

== Support ==

For bug reports and feature requests, please use the GitHub issue tracker.
