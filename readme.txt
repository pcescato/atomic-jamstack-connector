=== Atomic Jamstack Connector ===
Contributors: pcescato
Tags: jamstack, hugo, github, static-site, publishing
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Automated WordPress to Hugo publishing system with async GitHub API integration and atomic commits.

== Description ==

Atomic Jamstack Connector is a production-grade WordPress plugin that automatically synchronizes your WordPress posts and pages to a Hugo static site via GitHub API, using atomic commits for reliability.

**Key Features:**

* **Atomic Commits**: All content and images uploaded in a single GitHub commit
* **Async Processing**: Background sync using Action Scheduler (no blocking)
* **Image Optimization**: Automatic WebP and AVIF generation with Imagick
* **Deletion Management**: Automatic cleanup when posts are trashed/deleted
* **Bulk Sync**: Synchronize all published posts with one click
* **Page Support**: Sync both posts and pages to your Hugo site
* **Monitoring Dashboard**: Track sync status, view GitHub commits, one-click resync
* **Clean Markdown**: WordPress HTML converted to Hugo-compatible Markdown
* **Retry Logic**: Automatic retry with exponential backoff on failures

**Technical Highlights:**

* Single source of truth: `_jamstack_sync_status` post meta
* WordPress native APIs only (no shell commands)
* GitHub Trees API for atomic commits (~70% fewer API calls)
* Smart duplicate prevention
* Hugo Front Matter with YAML format
* Featured image and content image processing
* Lock mechanism to prevent concurrent syncs

**Requirements:**

* WordPress 6.9+
* PHP 8.1+
* Imagick PHP extension (recommended) or GD
* GitHub Personal Access Token with repo permissions
* Hugo static site repository on GitHub

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/atomic-jamstack-connector/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Jamstack Sync
4. Configure your GitHub credentials:
   - Personal Access Token
   - Repository (owner/repo)
   - Branch (default: main)
5. Test the connection
6. Save settings and start publishing!

== Frequently Asked Questions ==

= What is an atomic commit? =

An atomic commit means all files (Markdown post + all images) are committed to GitHub in a single operation. This results in cleaner git history and better reliability.

= Does this work with other static site generators? =

The plugin is designed for Hugo but uses an adapter pattern. You can create custom adapters for Jekyll, Eleventy, or other generators.

= What happens if a sync fails? =

Failed syncs are automatically retried up to 3 times with exponential backoff. You can monitor all sync operations in the Sync History tab.

= Can I sync existing posts? =

Yes! Use the "Synchronize All Posts" button in the Bulk Operations tab to sync all published posts.

= Are images optimized? =

Yes. The plugin generates WebP format (and AVIF if supported) with 85% quality, significantly reducing file sizes while maintaining visual quality.

= What if I delete a post in WordPress? =

When you trash or delete a post, the corresponding Markdown file and images are automatically deleted from your GitHub repository.

== Screenshots ==

1. Settings page with GitHub configuration
2. Bulk sync operations with live statistics
3. Sync history monitoring dashboard
4. Post list with sync status indicators
5. Admin column showing sync status

== Changelog ==

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
