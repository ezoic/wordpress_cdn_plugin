---
Plugin Name: Ezoic CDN Manager
Plugin URI: https://www.wordpress.org/plugins/ezoic-cdn
Description: Automates clearing of Ezoic CDN Cache when posts are published
Version: 1.0.0
Author: Ezoic Inc.
Author URI: https://ezoic.com
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tested up to: 5.6
---
### Wordpress Ezoic CDN Cache Plugin

This plugin uses the Ezoic CDN API to automatically purges pages from the Ezoic CDN whenever a post or page is updated.

In addition to purging the cache of a given post, it automatically discovers other pages that should be purged as well and purges the cache for them as well.

To use the Ezoic CDN you'll need access to the CDN API, talk with your Optimization Specialist about enabling access to the API on your account.  Once you have the CDN API enabled, you can [find your API key here](https://pubdash.ezoic.com/settings/apigateway/app?action=load).