=== Ezoic CDN Manager ===
Tags: cdn, ezoic
Requires at least: 5.1
Tested up to: 5.6
Stable tag: 1.1.0
Requires PHP: 7.2
Contributors: ezoic
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatically instructs the Ezoic CDN to purge changed pages from its cache whenever a post or page is updated.

== Description ==

This plugin uses the Ezoic CDN API* to automatically purges pages from the Ezoic CDN whenever a post or page is updated.

In addition to purging the cache of a given post, it automatically discovers other pages that should be purged as well and purges the cache for them as well.

To use the Ezoic CDN you'll need access to the CDN API*, talk with your Optimization Specialist about enabling access to the API on your account.  Once you have the CDN API enabled, you can [find your API key here](https://pubdash.ezoic.com/settings/apigateway/app?action=load).

=== Automated Clearing ===

In this initual version, enabling the plugin will automate instructing the Ezoic CDN to recache all of the following whenever a page/post is updated:

- Posts
    - Post Page
    - Home Page or Blog Posts Listing Page
    - All categories that the post is a part of
    - All tags mentioned in the post
    - The Yearly archive the post belongs to
    - The Monthly archive the post belongs to
    - The Daily archive the post belongs to
    - The Main atom and RSS feeds
    - All related category and tag feeds
    - All related custom taxonomy pages and feeds
    - Author Posts URL (Supports multiple authors through the popular [Co-Authors Plus](https://wpvip.com/plugins/co-authors-plus/) plugin)
    - Author Post Feeds
    - The comment feeds for the post
    - If the update changes anything in any of these urls (examples: removing from a category, changing the date, changing the slug), both the old and new versions of the URLs will be recached accordingly.
- Pages and custom content type pages
    - Page itself
    - Any page type archive related to the page
    - Any categories or tags the page or custom content post type is marked in
    - All related custom taxonomy pages and feeds
    - Author Posts URL (Supports multiple authors through the popular [Co-Authors Plus](https://wpvip.com/plugins/co-authors-plus/) plugin)
    - Author Post Feeds
    - Feeds
    - If the update changes anything in any of these urls (for example changing the slug or changing the parent page), both the old and new versions of the URLs will be recached accordingly.

== Installation ==

Full instructions on enabling the cdn manager [can be found here]](https://support.ezoic.com/kb/article/how-can-i-set-up-the-ezoic-cdn-manager-plugin).

You will need to have API access to the Ezoic CDN enabled, contact your Ezoic Optimization Specialist for details about enabling API access on your account if you are unable to retrieve your API key.

1. Download the Ezoic CDN Manager plugin from the WordPress plugin directory.
2. Fetch your Ezoic API Key from the [settings area of your Ezoic admin dashboard](https://pubdash.ezoic.com/settings/apigateway/app?action=load)
3. In the WordPress admin, go to Settings => Ezoic CDN and enter the API key you retrieved from Ezoic.
4. Toggle the Automatic Cache purging switch from Disabled to Enabled and Save the settings.

That's it, at this point any time you update a post, the plugin will ping the Ezoic CDN API to ensure the appropriate pages are purged from the CDN's cache.

== Frequently Asked Questions ==

== Upgrade Notice ==

== Screenshots ==

== Changelog ==

### 1.1.0 - 2021-01-20
#### Added
- Added support for purging custom taxonomies
- Added support for purging author pages (including support for multiple authors)
#### Changed
- Documentation updates for new features and removal of Sitespeed+ Requirement (all Ezoic CDN users can now use this plugin).

### 1.0.3 - 2020-12-11
#### Changed
- Updated documentation, added missing details.

### 1.0.2 - 2020-12-10
#### Changed
- Documentation updates

### 1.0.1 - 2020-12-09
#### Fixed
- Minor patch release to prevent attempts to recache a listing page for "page" type

### 1.0.0 - 2020-12-09
#### Added
- Initial version 1.0 release of Ezoic CDN Plugin


