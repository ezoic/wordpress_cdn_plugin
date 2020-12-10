=== Ezoic CDN Manager ===
Contributors: ezoic
Donate link: https://ezoic.com/
Tags: cdn, ezoic
Requires at least: 4.3
Tested up to: 5.6
Stable tag: 1.0.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

This plugin uses the Ezoic CDN API to automatically purges pages from the Ezoic CDN whenever a post or page is updated.

== Wordpress Ezoic CDN Cache Plugin ==

This plugin uses the Ezoic CDN API to automatically purges pages from the Ezoic CDN whenever a post or page is updated.

In addition to purging the cache of a given post, it automatically discovers other pages that should be purged as well and purges the cache for them as well.

To use the Ezoic CDN you'll need access to the CDN API, talk with your Optimization Specialist about enabling access to the API on your account.  Once you have the CDN API enabled, you can [find your API key here](https://pubdash.ezoic.com/settings/apigateway/app?action=load).

== Automated Clearing ==

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
    - The comment feeds for the post
    - If the update changes anything in any of these urls (examples: removing from a category, changing the date, changing the slug), both the old and new versions of the URLs will be recached accordingly.
- Pages and custom content type pages
    - Page itself
    - Any page type archive related to the page
    - Any categories or tags the page or custom content post type is marked in
    - Feeds
    - If the update changes anything in any of these urls (for example changing the slug or changing the parent page), both the old and new versions of the URLs will be recached accordingly.

In this current version it does not support automated recaching of custom taxonomies.