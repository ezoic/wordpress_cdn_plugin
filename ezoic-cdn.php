<?php
/**
 * Plugin Name: Ezoic CDN Manager
 * Plugin URI: https://www.ezoic.com/site-speed/
 * Description: Automatically instructs the Ezoic CDN to purge changed pages from its cache whenever a post or page is updated.
 * Version: 1.1.1
 * Requires at least: 5.1
 * Requires PHP: 7.0
 * Author: Ezoic Inc
 * Author URI: https://www.ezoic.com/
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */


function ezoic_cdn_is_enabled($refresh = false)
{
    static $cdnEnabled = null;
    if (!ezoic_cdn_api_key()) {
        return false;
    }
    if ($cdnEnabled === null || $refresh) {
        $cdnEnabled = (get_option('ezoic_cdn_enabled','off') == "on");
    }
    return $cdnEnabled;
}

function ezoic_cdn_get_domain($default = false)
{
    static $cdnDomain = null;

    if ($cdnDomain === null && !$default) {
        $cdnDomain = get_option('ezoic_cdn_domain');
    }
    if (!$cdnDomain || $default) {
        $cdnDomain = parse_url(get_site_url(), PHP_URL_HOST);
        $cdnDomain = preg_replace("@^www\.@msi", "", $cdnDomain);
    }

    return $cdnDomain;
}



function ezoic_cdn_api_key($refresh = false)
{
    static $apiKey = null;
    if ($apiKey === null || $refresh) {
        $apiKey = get_option('ezoic_cdn_api_key');
    }
    return $apiKey;
}

function ezoic_cdn_post_updated($postID, $oldPost, $newPost)
{
    if (!ezoic_cdn_is_enabled()) { return true; }
    if (wp_is_post_revision($newPost)) { return true; }

    if ($oldPost->post_status != 'publish' && $newPost->post_status != 'publish') {
        return true;
    }

    $urls = ezoic_cdn_get_recache_urls_by_post($postID, $oldPost);
    $urls = array_merge($urls, ezoic_cdn_get_recache_urls_by_post($postID, $newPost));
    $urls = array_unique($urls);

    $results = ezoic_cdn_clear_urls($urls);
}
add_action('post_updated', 'ezoic_cdn_post_updated', 10, 3);

function ezoic_cdn_post_deleted($postID, $oldPost)
{
    if (!ezoic_cdn_is_enabled()) { return true; }
    if (wp_is_post_revision($oldPost)) { return true; }

    if ($oldPost->post_status != 'publish') {
        return true;
    }

    $urls = ezoic_cdn_get_recache_urls_by_post($postID, $oldPost);

    $results = ezoic_cdn_clear_urls($urls);
}
add_action('after_delete_post', 'ezoic_cdn_post_deleted', 10, 2);

function ezoic_cdn_clear_url($url = null)
{
    $url = "https://api-gateway.ezoic.com/gateway/cdnservices/clearcache?developerKey=" . ezoic_cdn_api_key();

    $args = [
        'timeout'     => 45,
        'blocking'    => false,
        'httpversion' => '1.1',
        'headers'     => ['Content-Type' => 'application/json'],
        'body'        => wp_json_encode(['url' => $url]),
    ];

    return wp_remote_post($url, $args);
}

function ezoic_cdn_clear_urls($urls = [])
{
    $url = "https://api-gateway.ezoic.com/gateway/cdnservices/bulkclearcache?developerKey=" . ezoic_cdn_api_key();

    $args = [
        'timeout'     => 45,
        'blocking'    => false,
        'httpversion' => '1.1',
        'headers'     => ['Content-Type' => 'application/json'],
        'body'        => wp_json_encode(['urls' => $urls]),
    ];

    return wp_remote_post($url, $args);
}

function ezoic_cdn_purge($domain = null)
{
    $url = "https://api-gateway.ezoic.com/gateway/cdnservices/purgecache?developerKey=" . ezoic_cdn_api_key();

    $args = [
        'timeout'     => 45,
        'blocking'    => true,
        'httpversion' => '1.1',
        'headers'     => ['Content-Type' => 'application/json'],
        'body'        => wp_json_encode(['domain' => $domain]),
    ];

    wp_remote_post($url, $args);
}

function ezoic_cdn_get_recache_urls_by_post($postID, $post = null)
{
    if (!$post) {
        $post = get_post($postID);
    }

    $urls = [];

    $urls[] = get_permalink($post);
    if ($post->post_type != 'page') {
        $urls[] = get_post_type_archive_link($post->post_type);
    }

    $categories = wp_get_post_categories($postID, ['fields' => 'all']);
    if ($categories) {
        foreach ($categories as $category) {
            $urls[] = get_term_link($category);
            $urls[] = get_category_feed_link($category->term_id, 'atom');
            $urls[] = get_category_feed_link($category->term_id, 'rss2');
        }
    }

    $tags = wp_get_post_tags($postID, ['fields' => 'all']);
    if ($tags) {
        foreach ($tags as $tag) {
            $urls[] = get_term_link($tag);
            $urls[] = get_tag_feed_link($tag->term_id, 'atom');
            $urls[] = get_tag_feed_link($tag->term_id, 'rss2');
        }
    }

    $taxonomies = get_object_taxonomies($post, 'names');
    if ($taxonomies) {
        foreach ($taxonomies as $taxonomy) {
            if ($taxonomy == 'category' || $taxonomy == 'post_tag' || $taxonomy == 'author') {
                continue;
            }
    
            $terms = get_the_terms($post, $taxonomy);
            if ($terms) {
                foreach ($terms as $term) {
                    $urls[] = get_term_link($term, $taxonomy);
                    $urls[] = get_term_feed_link($term->term_id, $taxonomy, 'atom');
                    $urls[] = get_term_feed_link($term->term_id, $taxonomy, 'rss2');
                }
            }
        }
    }
    
    $urls[] = get_author_posts_url($post->post_author);
    $urls[] = get_author_feed_link($post->post_author, 'atom');
    $urls[] = get_author_feed_link($post->post_author, 'rss2');

    if (function_exists('coauthors')) {
        $authors = get_coauthors($postID);
        if ($authors) {
            foreach ($authors as $author) {
                $urls[] = get_author_posts_url($author->ID, $author->user_nicename);
                $urls[] = get_author_feed_link($author->ID, 'atom');
                $urls[] = get_author_feed_link($author->ID, 'rss2');
            }
        }
    }

    if (comments_open($post)) {
        $urls[] = get_bloginfo('comments_atom_url');
        $urls[] = get_bloginfo('comments_rss2_url');
        $urls[] = get_post_comments_feed_link($postID, 'atom');
        $urls[] = get_post_comments_feed_link($postID, 'rss2');
    }

    if ($post->post_type != 'post') {
        return $urls;
    }

    $urls[] = get_bloginfo('atom_url');
    $urls[] = get_bloginfo('rss_url');
    $urls[] = get_bloginfo('rss2_url');
    $urls[] = get_bloginfo('rdf_url');

    $date = strtotime($post->post_date);
    $urls[] = get_year_link(date("Y", $date));
    $urls[] = get_month_link(date("Y", $date), date("m", $date));
    $urls[] = get_day_link(date("Y", $date), date("m", $date), date("j", $date));

    return $urls;
}

function ezoic_cdn_admin_menu()
{
    add_options_page('Ezoic CDN', 'Ezoic CDN', 'manage_options', 'ezoic_cdn', 'ezoic_cdn_admin_page');
}
add_action('admin_menu', 'ezoic_cdn_admin_menu');

function ezoic_cdn_admin_init()
{
    add_settings_section(
        'ezoic_cdn_settings_section',
        'Ezoic CDN Settings',
        'ezoic_cdn_settings_section_callback',
        'ezoic_cdn'
    );

    add_settings_field(
        'ezoic_cdn_api_key',
        'Ezoic API Key',
        'ezoic_cdn_api_key_field',
        'ezoic_cdn',
        'ezoic_cdn_settings_section'
    );

    add_settings_field(
        'ezoic_cdn_domain',
        'Ezoic Domain',
        'ezoic_cdn_domain_field',
        'ezoic_cdn',
        'ezoic_cdn_settings_section'
    );

    add_settings_field(
        'ezoic_cdn_enabled',
        'Automatic Recaching',
        'ezoic_cdn_enabled_field',
        'ezoic_cdn',
        'ezoic_cdn_settings_section'
    );

    register_setting('ezoic_cdn', 'ezoic_cdn_api_key');
    register_setting('ezoic_cdn', 'ezoic_cdn_domain');
    register_setting('ezoic_cdn', 'ezoic_cdn_enabled');
}
add_action('admin_init', 'ezoic_cdn_admin_init');

function ezoic_cdn_settings_section_callback() {}

function ezoic_cdn_admin_page()
{
    require_once(__DIR__."/ezoic-cdn-admin.php");
}

function ezoic_cdn_api_key_field()
{
    $value = get_option('ezoic_cdn_api_key');
    echo "<input type=\"text\" name=\"ezoic_cdn_api_key\" value=\"{$value}\" />";
}

function ezoic_cdn_domain_field()
{
    $value = ezoic_cdn_get_domain();
    echo "<input type=\"text\" name=\"ezoic_cdn_domain\" value=\"{$value}\" /> <em>Main domain only, must match domain in ezoic, no subdomains</em>";
}

function ezoic_cdn_enabled_field()
{
    $value = ezoic_cdn_is_enabled(true);
    echo "<input type=\"radio\" id=\"ezoic_cdn_enabled_on\" name=\"ezoic_cdn_enabled\" value=\"on\" ";
    if ($value) { echo 'checked="checked"'; }
    echo " /><label for=\"ezoic_cdn_enabled_on\">Enabled</label> <input type=\"radio\" id=\"ezoic_cdn_enabled_off\" name=\"ezoic_cdn_enabled\" value=\"off\" ";
    if (!$value) { echo 'checked="checked"'; }
    echo " /><label for=\"ezoic_cdn_enabled_off\">Disabled</label>";

}

// When W3TC is instructed to purge cache for entire site, also purge cache from Ezoic CDN
add_action('w3tc_flush_posts', 'ezoic_cdn_cachehook_purge_posts_action', 2100);
add_action('w3tc_flush_all', 'ezoic_cdn_cachehook_purge_posts_action', 2100);
// Also hook into WP Super Cache's wp_cache_cleared action
add_action('wp_cache_cleared', 'ezoic_cdn_cachehook_purge_posts_action', 2100);

function ezoic_cdn_cachehook_purge_posts_action($extras = [])
{
    if (!ezoic_cdn_is_enabled()) {
        return;
    }

    ezoic_cdn_purge(ezoic_cdn_get_domain());
}

// When W3TC is instructed to purge cache for a post, also purge cache from Ezoic CDN
add_action('w3tc_flush_post', 'ezoic_cdn_cachehook_purge_post_action', 2100, 1);

function ezoic_cdn_cachehook_purge_post_action($postID = null)
{
    if (!ezoic_cdn_is_enabled() || !$postID) {
        return;
    }
    $urls = ezoic_cdn_get_recache_urls_by_post($postID);

    $results = ezoic_cdn_clear_urls($urls);
    return true;
}

// WP-Rocket Purge Cache Hook
add_action('rocket_purge_cache', 'ezoic_cdn_rocket_purge_action', 2100, 4);

function ezoic_cdn_rocket_purge_action($type = 'all', $id = 0, $taxonomy = '', $url = '')
{
    if (!ezoic_cdn_is_enabled()) {
        return;
    }
    switch ($type) {
        case 'all':
            return ezoic_cdn_purge(ezoic_cdn_get_domain());
        case 'post':
            $urls = ezoic_cdn_get_recache_urls_by_post($id);
            $results = ezoic_cdn_clear_urls($urls);
            return;
        default:
            return;
    }
}

add_action('after_rocket_clean_post', 'ezoic_cdn_rocket_clean_post_action', 2100, 3);
function ezoic_cdn_rocket_clean_post_action($post, $page_urls = [], $lang = '')
{
    if (!ezoic_cdn_is_enabled()) {
        return;
    }
    $urls = ezoic_cdn_get_recache_urls_by_post($post->ID, $post);
    $results = ezoic_cdn_clear_urls($urls);
    return;
}