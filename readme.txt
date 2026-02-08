=== Atomic Jamstack Connector ===
Contributors: pcescato
Tags: jamstack, hugo, github, static-site, publishing
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automated WordPress to Hugo publishing system with async GitHub API integration, atomic commits, and customizable Front Matter templates.

== Description ==

Atomic Jamstack Connector is a production-grade WordPress plugin that automatically synchronizes your WordPress posts and pages to a Hugo static site via GitHub API, using atomic commits for reliability.

**Key Features:**

* **Atomic Commits**: All content and images uploaded in a single GitHub commit
* **Custom Front Matter Templates**: Define your own YAML or TOML Front Matter with placeholders for maximum Hugo theme compatibility (NEW!)
* **Tabbed Settings Interface**: Organized settings with General and GitHub Credentials tabs for better UX (NEW!)
* **Enhanced Security**: GitHub token masking and preservation, never exposed in UI (NEW!)
* **Async Processing**: Background sync using Action Scheduler (no blocking)
* **Image Optimization**: Automatic WebP and AVIF generation with Imagick
* **Deletion Management**: Automatic cleanup when posts are trashed/deleted
* **Bulk Sync**: Synchronize all published posts with one click
* **Page Support**: Sync both posts and pages to your Hugo site
* **Author Access**: Authors can sync their own posts and view their sync history (NEW!)
* **Monitoring Dashboard**: Track sync status, view GitHub commits, one-click resync
* **Clean Markdown**: WordPress HTML converted to Hugo-compatible Markdown
* **Advanced Logging**: Detailed debug logs with real-time feedback in admin UI (IMPROVED!)
* **Retry Logic**: Automatic retry with exponential backoff on failures

**Technical Highlights:**

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

**Requirements:**

* WordPress 6.9+
* PHP 8.1+
* Imagick PHP extension (recommended) or GD
* GitHub Personal Access Token with repo permissions
* Hugo static site repository on GitHub

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/atomic-jamstack-connector/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Atomic Jamstack Connector
4. Navigate to the "GitHub Credentials" tab
5. Configure your GitHub credentials:
   - Personal Access Token (masked for security)
   - Repository (owner/repo)
   - Branch (default: main)
6. Test the connection
7. Go to the "General" tab to configure:
   - Custom Front Matter template (optional)
   - Content types to sync
   - Image quality settings
   - Debug mode
8. Save settings and start publishing!

== Frequently Asked Questions ==

= What is an atomic commit? =

An atomic commit means all files (Markdown post + all images) are committed to GitHub in a single operation. This results in cleaner git history and better reliability.

= How do I customize the Front Matter for my Hugo theme? =

Go to Settings > General > Hugo Configuration. You can define your own Front Matter template using placeholders like {{title}}, {{date}}, {{author}}, {{id}}, {{slug}}, {{image_avif}}, {{image_webp}}, and {{image_original}}. The plugin supports both YAML (`---`) and TOML (`+++`) formats. See the documentation for examples compatible with PaperMod and other themes.

= Can I use TOML instead of YAML for Front Matter? =

Yes! Simply include the TOML delimiters (`+++`) in your custom template. The plugin doesn't enforce any format - you define your own structure.

= Does this work with other static site generators? =

The plugin is designed for Hugo but uses an adapter pattern. You can create custom adapters for Jekyll, Eleventy, or other generators.

= What happens if a sync fails? =

Failed syncs are automatically retried up to 3 times with exponential backoff. You can monitor all sync operations in the Sync History tab.

= Can Authors sync their own posts? =

Yes! Authors have access to the "Sync History" page where they can view and sync their own posts. They cannot access the Settings page or modify GitHub credentials.

= Can I sync existing posts? =

Yes! Use the "Synchronize All Posts" button in the Bulk Operations tab to sync all published posts.

= Are images optimized? =

Yes. The plugin generates WebP format (and AVIF if supported) with 85% quality, significantly reducing file sizes while maintaining visual quality.

= What if I delete a post in WordPress? =

When you trash or delete a post, the corresponding Markdown file and images are automatically deleted from your GitHub repository.

= How do I enable debug logging? =

Go to Settings > General > Debug Settings and check "Enable detailed logging for debugging". The page will show you the log file path and status. Logs are stored in `wp-content/uploads/atomic-jamstack-logs/` with daily rotation.

= Is my GitHub token secure? =

Yes! The token is encrypted before storage and masked in the UI (displayed as ••••••••••). The token is never exposed in plain text and is only used for GitHub API calls.

== Screenshots ==

1. Settings page with tabbed interface (General tab)
2. GitHub Credentials tab with masked token
3. Custom Front Matter template editor with placeholders
4. Bulk sync operations with live statistics
5. Sync history monitoring dashboard
6. Post list with sync status indicators
7. Admin column showing sync status and commit links
8. Debug logging with real-time file status

== Changelog ==

= 1.1.0 =
* NEW: Customizable Front Matter templates with 7+ placeholders for Hugo theme compatibility
* NEW: Support for both YAML and TOML Front Matter formats
* NEW: Tabbed settings interface (General and GitHub Credentials tabs)
* NEW: GitHub token masking and enhanced security
* NEW: Token preservation logic (empty input keeps existing token)
* NEW: Author access - Authors can sync their own posts
* NEW: Role-based sync history filtering
* NEW: {{id}} placeholder for dynamic post ID in Front Matter
* IMPROVED: Enhanced debug logging with real-time file path and size display
* IMPROVED: Upload directory error handling with fallback to WordPress debug.log
* IMPROVED: Log file protection with .htaccess and index.php
* IMPROVED: Settings UI with clearer descriptions and contextual help
* IMPROVED: Better UX with active tab preservation after save
* FIXED: Commit link building (missing repository information)
* FIXED: Image path generation ({{id}} placeholder not working)
* FIXED: Logging system (files not being created)
* FIXED: WordPress Coding Standards compliance (translation comments, escape output, nonce verification)
* Documentation: Added Front Matter template examples for popular Hugo themes

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

= 1.1.0 =
Major update with customizable Front Matter templates, enhanced security, improved UI, and author access. Recommended for all users. After upgrading, re-sync your posts to apply the new Front Matter format.

= 1.0.0 =
Initial release of Atomic Jamstack Connector.

== Development ==

This plugin follows WordPress coding standards and uses:

* PHP 8.1+ with strict types
* PSR-4 autoloading
* Namespaced architecture
* Action Scheduler for background processing
* Intervention Image for media processing
* League CommonMark and HTML-to-Markdown for content conversion

GitHub repository: https://github.com/pcescato/atomic-jamstack-connector

== Privacy ==

This plugin does not collect any user data. All communication is between your WordPress installation and your GitHub repository using your provided credentials.

== Support ==

For bug reports and feature requests, please use the GitHub issue tracker.
