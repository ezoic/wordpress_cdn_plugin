<?php
/**
 * Ezoic CDN Manager Plugin
 *
 * @package ezoic-cdn-manager
 * @version 1.2.1
 * @author Ezoic
 * @copyright 2020 Ezoic Inc
 * @license GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: Ezoic CDN Manager
 * Plugin URI: https://www.ezoic.com/site-speed/
 * Description: Automatically instructs the Ezoic CDN to purge changed pages from its cache whenever a post or page is updated.
 * Version: 1.2.1
 * Requires at least: 5.2
 * Requires PHP: 7.0
 * Author: Ezoic Inc
 * Author URI: https://www.ezoic.com/
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

$ezoic_cdn_already_purged = array();
$ezoic_cdn_keys_purged    = array();

/**
 * Helper function to determine if auto-purging of the Ezoic CDN is enabled or not.
 *
 * Note if there is not an API key stored, this is always false.
 *
 * @see ezoic_cdn_api_key()
 * @since 1.0.0
 * @param boolean $refresh Set to true if you want to re-fetch the option instead of using static variable.
 * @return boolean
 */
function ezoic_cdn_is_enabled( $refresh = false ) {
	static $cdn_enabled = null;
	if ( ! ezoic_cdn_api_key() ) {
		return false;
	}
	if ( is_null( $cdn_enabled ) || $refresh ) {
		$cdn_enabled = ( get_option( 'ezoic_cdn_enabled', 'off' ) === 'on' );
	}
	return $cdn_enabled;
}

/**
 * Helper Function to determine if we are always purging the home page when purging anything.
 *
 * @since 1.1.2
 * @param boolean $refresh Set to true if you want to re-fetch the option instead of using static variable.
 * @return boolean
 */
function ezoic_cdn_always_purge_home( $refresh = false ) {
	static $always_home = null;
	if ( ! ezoic_cdn_is_enabled() ) {
		return false;
	}
	if ( is_null( $always_home ) || $refresh ) {
		$always_home = ( get_option( 'ezoic_cdn_always_home', 'off' ) === 'on' );
	}
	return boolval( $always_home );
}

/**
 * When purging for any other reason, submit a separate purge of the home page
 *
 * @since 1.1.2
 * @return boolean|array|WP_Error Returns false if not set to auto-purge home page, otherwise returns the response from doing separate purge.
 */
function ezoic_cdn_purge_home() {
	if ( ! ezoic_cdn_always_purge_home() ) {
		return false;
	}

	$urls = array(
		get_site_url(),
		get_home_url(),
		get_post_type_archive_link( 'post' ),
	);

	$urls = array_unique( $urls );
	return ezoic_cdn_clear_urls( $urls );
}

/**
 * Helper function to determine if verbose mode is on.
 *
 * @since 1.1.2
 * @param boolean $refresh Set to true if you want to re-fetch the option instead of using the static variable.
 * @return boolean
 */
function ezoic_cdn_verbose_mode( $refresh = false ) {
	static $verbose_mode = null;
	if ( ! ezoic_cdn_is_enabled() ) {
		return false;
	}
	if ( is_null( $verbose_mode ) || $refresh ) {
		$verbose_mode = ( get_option( 'ezoic_cdn_verbose_mode', 'off' ) === 'on' );
	}
	return boolval( $verbose_mode );
}

/**
 * Helper function to get the Ezoic Domain from the WordPress Options
 *
 * @since 1.1.1
 * @param boolean $default Set to true if you want to generate the domain from WordPress Site URL.
 * @return string Domain Name as defined in Ezoic
 */
function ezoic_cdn_get_domain( $default = false ) {
	static $cdn_domain = null;

	if ( is_null( $cdn_domain ) && ! $default ) {
		$cdn_domain = get_option( 'ezoic_cdn_domain' );
	}
	if ( ! $cdn_domain || $default ) {
		$cdn_domain = wp_parse_url( get_site_url(), PHP_URL_HOST );
		$cdn_domain = preg_replace( '@^www\.@msi', '', $cdn_domain );
	}

	return $cdn_domain;
}

/**
 * Helper Function to retrieve the API Key from WordPress Options
 *
 * @since 1.0.0
 * @param boolean $refresh Set to true if you want to force re-fetching of the option rather than use static version.
 * @return string API Key
 */
function ezoic_cdn_api_key( $refresh = false ) {
	static $api_key = null;
	if ( is_null( $api_key ) || $refresh ) {
		$api_key = get_option( 'ezoic_cdn_api_key' );
	}
	return $api_key;
}

/**
 * Implementation of post_updated action
 *
 * When a post is modified, clear Ezoic CDN cache for the post URL and all related archive pages (both before and after the change)
 *
 * @since 1.0.0
 * @param int     $post_id ID of the Post that has been modified.
 * @param WP_Post $old_post The WordPress Post object of the post before modification.
 * @param WP_Post $new_post The WordPress Post object of the post after modification.
 * @see ezoic_cdn_clear_urls()
 * @return void
 */
function ezoic_cdn_post_updated( $post_id, WP_Post $old_post, WP_Post $new_post ) {
	if ( ! ezoic_cdn_is_enabled() ) {
		return;
	}
	if ( wp_is_post_revision( $new_post ) ) {
		return;
	}

	// If the post wasn't published before and isn't published now, there is no need to purge anything.
	if ( 'publish' !== $old_post->post_status && 'publish' !== $new_post->post_status ) {
		return;
	}

	$urls = ezoic_cdn_get_recache_urls_by_post( $post_id, $old_post );
	$urls = array_merge( $urls, ezoic_cdn_get_recache_urls_by_post( $post_id, $new_post ) );
	$urls = array_unique( $urls );

	ezoic_cdn_clear_urls( $urls );

	$keys = ezoic_cdn_get_surrogate_keys_by_post( $post_id, $old_post );
	$keys = array_merge( $keys, ezoic_cdn_get_surrogate_keys_by_post( $post_id, $new_post ) );
	$keys = array_unique( $keys );

	ezoic_cdn_clear_surrogate_keys( $keys, ezoic_cdn_get_domain() );
}
add_action( 'post_updated', 'ezoic_cdn_post_updated', 100, 3 );

/**
 * Implementation of save_post action, like the updated one but for new posts.
 *
 * @since 1.1.3
 * @param int     $post_id  ID of the post created or updated.
 * @param WP_Post $post     The WP_Post object.
 * @param boolean $update   true if this is an update of an existing post.
 * @return void
 */
function ezoic_cdn_save_post( $post_id, WP_Post $post, $update = false ) {
	if ( ! ezoic_cdn_is_enabled() ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	// If this is an update to an existing post, this will be handled by the post_updated action instead.
	if ( $update ) {
		return;
	}
	// No need to purge anything if the new post isn't published.
	if ( 'publish' !== $post->post_status ) {
		return;
	}

	$urls = ezoic_cdn_get_recache_urls_by_post( $post_id, $post );
	ezoic_cdn_clear_urls( $urls );

	$keys = ezoic_cdn_get_surrogate_keys_by_post( $post_id, $post );
	ezoic_cdn_clear_surrogate_keys( $keys, ezoic_cdn_get_domain() );
}
add_action( 'save_post', 'ezoic_cdn_save_post', 100, 3 );

/**
 * Implementation of after_delete_post action
 *
 * When a post is deleted, clear Ezoic CDN cache for the post URL, and all related archive pages
 *
 * @since 1.0.0
 * @param int     $post_id ID of the deleted post.
 * @param WP_Post $old_post WordPress Post object as it was before deletion.
 * @see ezoic_cdn_clear_urls()
 * @return void
 */
function ezoic_cdn_post_deleted( $post_id, WP_Post $old_post ) {
	if ( ! ezoic_cdn_is_enabled() ) {
		return;
	}
	if ( wp_is_post_revision( $old_post ) ) {
		return;
	}

	if ( 'publish' !== $old_post->post_status ) {
		return;
	}

	$urls = ezoic_cdn_get_recache_urls_by_post( $post_id, $old_post );
	ezoic_cdn_clear_urls( $urls );

	$keys = ezoic_cdn_get_surrogate_keys_by_post( $post_id, $old_post );
	ezoic_cdn_clear_surrogate_keys( $keys, ezoic_cdn_get_domain() );
}
add_action( 'after_delete_post', 'ezoic_cdn_post_deleted', 100, 2 );

/**
 * Add an admin notice for verbose mode
 *
 * @since 1.1.2
 * @param string $label    Label for the notice.
 * @param mixed  $results  The verbose output.
 * @param mixed  $params   Any parameters relevant to the submission.
 * @param string $class    Notice Class.
 * @return void
 */
function ezoic_cdn_add_notice( $label, $results, $params = null, $class = 'info' ) {
	static $notices = array();

	$raw = null;

	if ( ! $notices ) {
		$notices = get_transient( 'ezoic_cdn_admin_notice' );
	}

	if ( is_array( $results ) && ! empty( $results['response'] ) && ! empty( $results['body'] ) ) {
		$raw = $results;

		$results         = $raw['response'];
		$results['body'] = $raw['body'];
	}

	$notices[] = array(
		'label'   => $label,
		'results' => $results,
		'params'  => $params,
		'class'   => $class,
		'raw'     => $raw,
	);

	set_transient( 'ezoic_cdn_admin_notice', $notices, 600 );
}

/**
 * Verbose Mode output notices
 *
 * @since 1.1.2
 * @return void
 */
function ezoic_cdn_display_admin_notices() {
	if ( ! ezoic_cdn_verbose_mode() ) {
		return;
	}
	$notices = get_transient( 'ezoic_cdn_admin_notice' );
	if ( ! $notices ) {
		return;
	}

	foreach ( $notices as $key => $notice ) {
		?>
		<div class="notice notice-<?php echo esc_attr( $notice['class'] ); ?> is-dismissable">
			<p><strong>Ezoic CDN Notice <?php echo esc_attr( $key ); ?>: <?php echo esc_attr( $notice['label'] ); ?></strong></p>
			<?php
			echo '<pre>Input: ';
			print_r( $notice['params'] );
			echo "\nResult: ";
			print_r( $notice['results'] );
			echo '</pre>';
			echo '<!-- Raw Results: ';
			print_r( $notice['raw'] );
			echo '-->';
			?>
		</div>
		<?php
	}

	delete_transient( 'ezoic_cdn_admin_notice' );
}

add_action( 'admin_notices', 'ezoic_cdn_display_admin_notices' );

/**
 * Uses Ezoic CDN API to purge cache for a single URL
 *
 * @since 1.0.0
 * @param string $url URL to purge from Ezoic CDN Cache.
 * @return array|WP_Error wp_remote_post() response array
 */
function ezoic_cdn_clear_url( $url = null ) {
	global $ezoic_cdn_already_purged;

	if ( in_array( $url, $ezoic_cdn_already_purged, true ) ) {
		return;
	}

	$api_url = 'https://api-gateway.ezoic.com/gateway/cdnservices/clearcache?developerKey=' . ezoic_cdn_api_key();

	$verbose = ezoic_cdn_verbose_mode();

	$args = array(
		'timeout'     => 45,
		'blocking'    => $verbose,
		'httpversion' => '1.1',
		'headers'     => array( 'Content-Type' => 'application/json' ),
		'body'        => wp_json_encode( array( 'url' => $url ) ),
	);

	$results = wp_remote_post( $api_url, $args );

	if ( $verbose ) {
		ezoic_cdn_add_notice( 'Single URL', $results, $url );
	}

	$ezoic_cdn_already_purged[] = $url;

	ezoic_cdn_purge_home();

	return $results;
}

/**
 * Our own action to use with scheduling cache clears.
 *
 * @since 1.1.3
 * @param array $urls List of URLs to purge from Ezoic Cache.
 * @return void
 */
function ezoic_cdn_scheduled_clear_action( $urls = array() ) {
	ezoic_cdn_clear_urls( $urls, true );
}
add_action( 'ezoic_cdn_scheduled_clear', 'ezoic_cdn_scheduled_clear_action', 1, 1 );

/**
 * Uses Ezoic CDN API to purge cache for an array of URLs
 *
 * @since 1.0.0
 * @since 1.1.3 Once a removal has been submitted, submit another one 1 minute later.
 * @param array $urls      List of URLs to purge from Ezoic Cache.
 * @param bool  $scheduled True if this is a scheduled run of this removal request.
 * @return array|WP_Error wp_remote_post() response array
 */
function ezoic_cdn_clear_urls( $urls = array(), $scheduled = false ) {
	global $ezoic_cdn_already_purged;

	$urls = array_unique( array_diff( $urls, $ezoic_cdn_already_purged ) );

	if ( ! $urls ) {
		return;
	}

	$api_url = 'https://api-gateway.ezoic.com/gateway/cdnservices/bulkclearcache?developerKey=' . ezoic_cdn_api_key();

	$verbose = ezoic_cdn_verbose_mode();

	$args = array(
		'timeout'     => 45,
		'blocking'    => $verbose,
		'httpversion' => '1.1',
		'headers'     => array( 'Content-Type' => 'application/json' ),
		'body'        => wp_json_encode( array( 'urls' => array_values( $urls ) ) ),
	);

	$results = wp_remote_post( $api_url, $args );

	$ezoic_cdn_already_purged = array_merge( $ezoic_cdn_already_purged, $urls );

	if ( $verbose ) {
		$label = ( $scheduled ) ? 'Scheduled Purge' : 'Bulk Purge';
		ezoic_cdn_add_notice( $label, $results, $urls );
	}

	return $results;
}

/**
 * Purge pages from Ezoic CDN by Surrogate Keys
 *
 * @since 1.2.0
 * @param array  $keys   Array of Surrogate Keys to purge from Ezoic CDN cache.
 * @param string $domain Domain Name to purge for.
 * @return array|WP_Error wp_remote_post() response array
 */
function ezoic_cdn_clear_surrogate_keys( $keys = array(), $domain = null ) {
	global $ezoic_cdn_keys_purged;

	if ( ! $domain ) {
		$domain = ezoic_cdn_get_domain();
	}

	$keys = array_unique( array_diff( $keys, $ezoic_cdn_keys_purged ) );

	if ( ! $keys ) {
		return;
	}

	$api_url = 'https://api-gateway.ezoic.com/gateway/cdnservices/clearbysurrogatekeys?developerKey=' . ezoic_cdn_api_key();

	$verbose = ezoic_cdn_verbose_mode();

	$args = array(
		'timeout'     => 45,
		'blocking'    => $verbose,
		'httpversion' => '1.1',
		'headers'     => array( 'Content-Type' => 'application/json' ),
		'body'        => wp_json_encode(
			array(
				'keys'   => implode( ',', $keys ),
				'domain' => $domain,
			)
		),
	);

	$results = wp_remote_post( $api_url, $args );

	$ezoic_cdn_keys_purged = array_merge( $ezoic_cdn_keys_purged, $keys );

	if ( $verbose ) {
		ezoic_cdn_add_notice( 'Surrogate Key Purge', $results, $keys );
	}

	return $results;
}

/**
 * Uses Ezoic CDN API to purge cache for an entire domain
 *
 * @since 1.0.0
 * @param string $domain Domain Name to purge Ezoic Cache for.
 * @return array|WP_Error wp_remote_post() response array
 */
function ezoic_cdn_purge( $domain = null ) {
	$api_url = 'https://api-gateway.ezoic.com/gateway/cdnservices/purgecache?developerKey=' . ezoic_cdn_api_key();

	$verbose = ezoic_cdn_verbose_mode();

	$args = array(
		'timeout'     => 45,
		'blocking'    => $verbose,
		'httpversion' => '1.1',
		'headers'     => array( 'Content-Type' => 'application/json' ),
		'body'        => wp_json_encode( array( 'domain' => $domain ) ),
	);

	$results = wp_remote_post( $api_url, $args );

	if ( $verbose ) {
		ezoic_cdn_add_notice( 'Purge', $results, array( 'domain' => $domain ) );
	}

	return $results;
}

/**
 * Determines list of SurrogateKeys related to a post that should be recached when the post is updated
 *
 * @since 1.2.0
 * @param int     $post_id ID of the Post.
 * @param WP_Post $post WordPress post object (found with get_post if omitted).
 * @return array $keys Array of Surrogate Keys to be recached for a given post
 */
function ezoic_cdn_get_surrogate_keys_by_post( $post_id, WP_Post $post = null ) {
	if ( ! $post ) {
		$post = get_post( $post_id );
	}

	$keys = array();

	$keys[] = "single-{$post_id}";

	$categories = get_the_terms( $post, 'category' );
	if ( $categories ) {
		foreach ( $categories as $category ) {
			$keys[] = "category-{$category->term_id}";
			$keys[] = "category-{$category->slug}";
		}
	}

	$tags = get_the_terms( $post, 'post_tag' );
	if ( $tags ) {
		foreach ( $tags as $tag ) {
			$keys[] = "tag-{$tag->term_id}";
			$keys[] = "tag-{$tag->slug}";
		}
	}

	$taxonomies = get_object_taxonomies( $post, 'names' );
	if ( $taxonomies ) {
		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, array( 'category', 'post_tag', 'author' ), true ) ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy );
			if ( $terms ) {
				foreach ( $terms as $term ) {
					$keys[] = "tax-{$taxonomy}-{$term->term_id}";
					$keys[] = "tax-{$taxonomy}-{$term->slug}";
				}
			}
		}
	}

	$keys[] = 'author-' . get_the_author_meta( 'user_nicename', $post->post_author );

	if ( function_exists( 'coauthors' ) ) {
		$authors = get_coauthors( $post_id );
		if ( $authors ) {
			foreach ( $authors as $author ) {
				$keys[] = "author-{$author->user_nicename}";
			}
		}
	}

	if ( ezoic_cdn_always_purge_home() ) {
		$keys[] = 'front';
		$keys[] = 'home';
	}

	if ( 'post' !== $post->post_type ) {
		return array_unique( $keys );
	}

	$date   = strtotime( $post->post_date );
	$keys[] = 'date-' . gmdate( 'Y', $date );
	$keys[] = 'date-' . gmdate( 'Ym', $date );
	$keys[] = 'date-' . gmdate( 'Ymd', $date );

	return array_unique( $keys );
}

/**
 * Determines list of URLs related to a post that should be recached when the post is updated
 *
 * @since 1.0.0
 * @since 1.1.0 Added support for custom taxonomies and author archives.
 * @param int     $post_id ID of the Post.
 * @param WP_Post $post WordPress post object (found with get_post if omitted).
 * @return array $urls Array of URLs to be recached for a given post
 */
function ezoic_cdn_get_recache_urls_by_post( $post_id, WP_Post $post = null ) {
	if ( ! $post ) {
		$post = get_post( $post_id );
	}

	$urls = array();

	$urls[] = get_permalink( $post );
	if ( 'page' !== $post->post_type ) {
		$urls[] = get_post_type_archive_link( $post->post_type );
	}

	$categories = get_the_terms( $post, 'category' );
	if ( $categories ) {
		foreach ( $categories as $category ) {
			$urls[] = get_term_link( $category );
			$urls[] = get_category_feed_link( $category->term_id, 'atom' );
			$urls[] = get_category_feed_link( $category->term_id, 'rss2' );
		}
	}

	$tags = get_the_terms( $post, 'post_tag' );
	if ( $tags ) {
		foreach ( $tags as $tag ) {
			$urls[] = get_term_link( $tag );
			$urls[] = get_tag_feed_link( $tag->term_id, 'atom' );
			$urls[] = get_tag_feed_link( $tag->term_id, 'rss2' );
		}
	}

	$taxonomies = get_object_taxonomies( $post, 'names' );
	if ( $taxonomies ) {
		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, array( 'category', 'post_tag', 'author' ), true ) ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy );
			if ( $terms ) {
				foreach ( $terms as $term ) {
					$urls[] = get_term_link( $term, $taxonomy );
					$urls[] = get_term_feed_link( $term->term_id, $taxonomy, 'atom' );
					$urls[] = get_term_feed_link( $term->term_id, $taxonomy, 'rss2' );
				}
			}
		}
	}

	$urls[] = get_author_posts_url( $post->post_author );
	$urls[] = get_author_feed_link( $post->post_author, 'atom' );
	$urls[] = get_author_feed_link( $post->post_author, 'rss2' );

	if ( function_exists( 'coauthors' ) ) {
		$authors = get_coauthors( $post_id );
		if ( $authors ) {
			foreach ( $authors as $author ) {
				$urls[] = get_author_posts_url( $author->ID, $author->user_nicename );
				$urls[] = get_author_feed_link( $author->ID, 'atom' );
				$urls[] = get_author_feed_link( $author->ID, 'rss2' );
			}
		}
	}

	if ( comments_open( $post ) ) {
		$urls[] = get_bloginfo( 'comments_atom_url' );
		$urls[] = get_bloginfo( 'comments_rss2_url' );
		$urls[] = get_post_comments_feed_link( $post_id, 'atom' );
		$urls[] = get_post_comments_feed_link( $post_id, 'rss2' );
	}

	if ( ezoic_cdn_always_purge_home() ) {
		$urls[] = get_site_url( null, '/' );
		$urls[] = get_home_url( null, '/' );
	}

	if ( 'post' !== $post->post_type ) {
		return $urls;
	}

	$urls[] = get_bloginfo( 'atom_url' );
	$urls[] = get_bloginfo( 'rss_url' );
	$urls[] = get_bloginfo( 'rss2_url' );
	$urls[] = get_bloginfo( 'rdf_url' );

	$date   = strtotime( $post->post_date );
	$urls[] = get_year_link( gmdate( 'Y', $date ) );
	$urls[] = get_month_link( gmdate( 'Y', $date ), gmdate( 'm', $date ) );
	$urls[] = get_day_link( gmdate( 'Y', $date ), gmdate( 'm', $date ), gmdate( 'j', $date ) );

	return $urls;
}

/**
 * Implementation of admin_menu action
 *
 * Creates link to CDN Settings Page in WordPress Admin
 *
 * @since 1.0.0
 * @return void
 */
function ezoic_cdn_admin_menu() {
	add_options_page( 'Ezoic CDN', 'Ezoic CDN', 'manage_options', 'ezoic_cdn', 'ezoic_cdn_admin_page' );
}
add_action( 'admin_menu', 'ezoic_cdn_admin_menu' );

/**
 * Implementation of admin_init action
 *
 * Defines settings for the Ezoic CDN Manager plugin
 *
 * @since 1.0.0
 * @return void
 */
function ezoic_cdn_admin_init() {
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

	add_settings_field(
		'ezoic_cdn_always_home',
		'Purge Home',
		'ezoic_cdn_always_home_field',
		'ezoic_cdn',
		'ezoic_cdn_settings_section'
	);

	add_settings_field(
		'ezoic_cdn_verbose_mode',
		'Verbose Mode',
		'ezoic_cdn_verbose_field',
		'ezoic_cdn',
		'ezoic_cdn_settings_section'
	);

	register_setting( 'ezoic_cdn', 'ezoic_cdn_api_key' );
	register_setting( 'ezoic_cdn', 'ezoic_cdn_domain' );
	register_setting( 'ezoic_cdn', 'ezoic_cdn_enabled' );
	register_setting( 'ezoic_cdn', 'ezoic_cdn_always_home' );
	register_setting( 'ezoic_cdn', 'ezoic_cdn_verbose_mode' );
}
add_action( 'admin_init', 'ezoic_cdn_admin_init' );

/**
 * Empty Callback for WordPress Settings
 *
 * @since 1.0.0
 * @return void
 */
function ezoic_cdn_settings_section_callback() {}

/**
 * Settings Page for Ezoic CDN Manager plugin
 *
 * @since 1.0.0
 * @return void
 */
function ezoic_cdn_admin_page() {
	require_once __DIR__ . '/ezoic-cdn-admin.php';
}

/**
 * WordPress Settings Field for defining the Ezoic API Key
 *
 * @since 1.0.0
 * @return void
 */
function ezoic_cdn_api_key_field() {
	?>
	<input type="text" name="ezoic_cdn_api_key" value="<?php echo( esc_attr( ezoic_cdn_api_key() ) ); ?>" />
	<?php
}

/**
 * WordPress Settings Field for defining the domain to purge cache for
 *
 * @since 1.1.1
 * @return void
 */
function ezoic_cdn_domain_field() {
	?>
	<input type="text" name="ezoic_cdn_domain" value="<?php echo( esc_attr( ezoic_cdn_get_domain() ) ); ?>" /> <em>Main domain only, must match domain in ezoic, no subdomains.</em>
	<?php
}

/**
 * WordPress Settings Field for enabling/disabling auto-purge
 *
 * @since 1.0.0
 * @return void
 */
function ezoic_cdn_enabled_field() {
	$value = ezoic_cdn_is_enabled( true );

	?>
	<input type="radio" id="ezoic_cdn_enabled_on" name="ezoic_cdn_enabled" value="on"
	<?php
	if ( $value ) {
		echo( 'checked="checked"' );
	}
	?>
	/>
	<label for="ezoic_cdn_enabled_on">Enabled</label>

	<input type="radio" id="ezoic_cdn_enabled_off" name="ezoic_cdn_enabled" value="off"
	<?php
	if ( ! $value ) {
		echo( 'checked="checked"' );
	}
	?>
	/>
	<label for="ezoic_cdn_enabled_off">Disabled</label>
	<?php
}

/**
 * WordPress Settings Field for enabling/disabling verbose mode
 *
 * @since 1.1.2
 * @return void
 */
function ezoic_cdn_always_home_field() {
	$checked = ezoic_cdn_always_purge_home( true );
	?>
	<input type="radio" id="ezoic_cdn_always_home_on" name="ezoic_cdn_always_home" value="on"
	<?php
	if ( $checked ) {
		echo( 'checked="checked"' );
	}
	?>
	/>
	<label for="ezoic_cdn_always_home_on">Enabled</label>

	<input type="radio" id="ezoic_cdn_always_home_off" name="ezoic_cdn_always_home" value="off"
	<?php
	if ( ! $checked ) {
		echo( 'checked="checked"' );
	}
	?>
	/>
	<label for="ezoic_cdn_always_home_off">Disabled</label> <em>Will purge the home page whenever purging for any post.</em>
	<?php
}

/**
 * WordPress Settings Field for enabling/disabling verbose mode
 *
 * @since 1.1.2
 * @return void
 */
function ezoic_cdn_verbose_field() {
	$checked = ezoic_cdn_verbose_mode( true );
	?>
	<input type="radio" id="ezoic_cdn_verbose_on" name="ezoic_cdn_verbose_mode" value="on"
	<?php
	if ( $checked ) {
		echo( 'checked="checked"' );
	}
	?>
	/>
	<label for="ezoic_cdn_verbose_on">Enabled</label>

	<input type="radio" id="ezoic_cdn_verbose_off" name="ezoic_cdn_verbose_mode" value="off"
	<?php
	if ( ! $checked ) {
		echo( 'checked="checked"' );
	}
	?>
	/>
	<label for="ezoic_cdn_verbose_off">Disabled</label> <em>Outputs debug messages whenever submitting purge, <span style="color: red;font-weight: bold;">will slow down editing, leave disabled unless you need it</span>.</em>
	<?php
}

// When W3TC is instructed to purge cache for entire site, also purge cache from Ezoic CDN.
add_action( 'w3tc_flush_posts', 'ezoic_cdn_cachehook_purge_posts_action', 2100 );
add_action( 'w3tc_flush_all', 'ezoic_cdn_cachehook_purge_posts_action', 2100 );
// Also hook into WP Super Cache's wp_cache_cleared action.
add_action( 'wp_cache_cleared', 'ezoic_cdn_cachehook_purge_posts_action', 2100 );

/**
 * Implementation of all of the following actions: w3tc_flush_posts, w3tc_flush_all, and wp_cache_cleared
 *
 * Completely purges Ezoic CDN cache for domain when these caches are purged
 *
 * @since 1.1.1
 * @since 1.1.2 auto-purge home when configured
 * @return void
 */
function ezoic_cdn_cachehook_purge_posts_action() {
	if ( ! ezoic_cdn_is_enabled() ) {
		return;
	}

	ezoic_cdn_purge( ezoic_cdn_get_domain() );
}

// When W3TC is instructed to purge cache for a post, also purge cache from Ezoic CDN.
add_action( 'w3tc_flush_post', 'ezoic_cdn_cachehook_purge_post_action', 2100, 1 );

/**
 * Implementation of w3tc_flush_post action
 *
 * Purges Ezoic CDN Cache when a post is flushed by the W3TC plugin
 *
 * @since 1.1.1
 * @since 1.1.2 auto-purge home when configured
 * @param int $post_id ID of the Post.
 * @return void
 */
function ezoic_cdn_cachehook_purge_post_action( $post_id = null ) {
	if ( ! ezoic_cdn_is_enabled() || ! $post_id ) {
		return;
	}
	$urls = ezoic_cdn_get_recache_urls_by_post( $post_id );
	ezoic_cdn_clear_urls( $urls );

	$keys = ezoic_cdn_get_surrogate_keys_by_post( $post_id );
	ezoic_cdn_clear_surrogate_keys( $keys, ezoic_cdn_get_domain() );

	return true;
}

// WP-Rocket Purge Cache Hook.
add_action( 'rocket_purge_cache', 'ezoic_cdn_rocket_purge_action', 2100, 4 );

/**
 * Implementation of rocket_purge_cache action
 *
 * When WP-Rocket purges cache for various page types, also purge the corresponding URLs from the Ezoic CDN
 *
 * @since 1.1.1
 * @since 1.1.2 Added support for WP-Rockets 'term' and 'url' based purges as well
 * @param string $type     Type of cache clearance: 'all', 'post', 'term', 'user', 'url'.
 * @param int    $id       The post ID, term ID, or user ID being cleared. 0 when $type is not 'post', 'term', or 'user'.
 * @param string $taxonomy The taxonomy the term being cleared belong to. '' when $type is not 'term'.
 * @param string $url      The URL being cleared. '' when $type is not 'url'.
 * @return void
 */
function ezoic_cdn_rocket_purge_action( $type = 'all', $id = 0, $taxonomy = '', $url = '' ) {
	if ( ! ezoic_cdn_is_enabled() ) {
		return;
	}
	switch ( $type ) {
		case 'all':
			return ezoic_cdn_purge( ezoic_cdn_get_domain() );
		case 'post':
			$urls = ezoic_cdn_get_recache_urls_by_post( $id );
			ezoic_cdn_clear_urls( $urls );

			$keys = ezoic_cdn_get_surrogate_keys_by_post( $id );
			ezoic_cdn_clear_surrogate_keys( $keys, ezoic_cdn_get_domain() );

			return;
		case 'term':
			$urls   = array();
			$urls[] = get_term_link( $id, $taxonomy );
			$urls[] = get_term_feed_link( $id, $taxonomy, 'atom' );
			$urls[] = get_term_feed_link( $id, $taxonomy, 'rss2' );
			ezoic_cdn_clear_urls( $urls );

			$term = get_term( $id, $taxonomy );

			if ( 'category' === $taxonomy ) {
				$keys[] = "category-{$id}";
				$keys[] = "category-{$term->slug}";
			} elseif ( 'post_tag' === $taxonomy ) {
				$keys[] = "tag-{$id}";
				$keys[] = "tag-{$term->slug}";
			} else {
				$keys[] = "tax-{$taxonomy}-{$id}";
				$keys[] = "tax-{$taxonomy}-{$term->slug}";
			}
			ezoic_cdn_clear_surrogate_keys( $keys, ezoic_cdn_get_domain() );

			return;
		case 'url':
			$urls = array( $url );
			ezoic_cdn_clear_urls( $urls );
			return;
	}
}

add_action( 'after_rocket_clean_post', 'ezoic_cdn_rocket_clean_post_action', 2100, 3 );

/**
 * Implementation of after_rocket_clean_post action
 *
 * When WP Rocket plugin purges local cache for a post, also clear appropriate urls from the Ezoic CDN.
 *
 * @since 1.1.1
 * @since 1.1.2 Added support for purging all the urls passed in $purge_urls as well
 * @param WP_Post $post       The post object.
 * @param array   $purge_urls URLs cache files to remove.
 * @param string  $lang       The post language.
 * @return void
 */
function ezoic_cdn_rocket_clean_post_action( $post, $purge_urls = array(), $lang = '' ) {
	if ( ! ezoic_cdn_is_enabled() ) {
		return;
	}
	$urls = ezoic_cdn_get_recache_urls_by_post( $post->ID, $post );
	$urls = array_merge( $urls, $purge_urls );
	$urls = array_unique( $urls );
	ezoic_cdn_clear_urls( $urls );

	$keys = ezoic_cdn_get_surrogate_keys_by_post( $post->ID, $post );
	ezoic_cdn_clear_surrogate_keys( $keys, ezoic_cdn_get_domain() );
}

/**
 * Set Cache Headers for Ezoic CDN
 *
 * Sets Cache-Control and Last-Modified headers as well as Surrogate Keys headers used for recaching whole archives including pagination.
 *
 * @since 1.2.0
 * @return void
 */
function ezoic_cdn_add_headers() {
	if ( ! ezoic_cdn_is_enabled() ) {
		return;
	}
	global $wp_query;

	$object         = get_queried_object();
	$surrogate_keys = array();
	$last_modified  = time();

	$browser_max_age = 60 * 60; // Browser Cache pages 1 hour.
	$server_max_age  = 86400 * 365 * 3; // Server Cache pages 3 years.

	if ( is_singular() ) {
		$surrogate_keys[] = 'single';
		$surrogate_keys[] = 'single-' . get_post_type();
		$surrogate_keys[] = 'single-' . get_the_ID();

		$last_modified = strtotime( $object->post_modified );
	} elseif ( is_archive() ) {
		$surrogate_keys[] = 'archive';
		if ( is_category() ) {
			$surrogate_keys[] = 'category';

			$surrogate_keys[] = 'category-' . $object->slug;
			$surrogate_keys[] = 'category-' . $object->term_id;
		} elseif ( is_tag() ) {
			$surrogate_keys[] = 'tag';
			$surrogate_keys[] = 'tag-' . $object->slug;
			$surrogate_keys[] = 'tag-' . $object->term_id;
		} elseif ( is_tax() ) {
			$surrogate_keys[] = 'tax';
			$surrogate_keys[] = "tax-{$object->taxonomy}";
			$surrogate_keys[] = "tax-{$object->taxonomy}-{$object->slug}";
			$surrogate_keys[] = "tax-{$object->taxonomy}-{$object->term_id}";
		} elseif ( is_date() ) {
			$surrogate_keys[] = 'date';
			if ( is_day() ) {
				$surrogate_keys[] = 'date-day';
				$surrogate_keys[] = "date-{$wp_query->query_vars['year']}{$wp_query->query_vars['monthnum']}{$wp_query->query_vars['day']}";
			} elseif ( is_month() ) {
				$surrogate_keys[] = 'date-month';
				$surrogate_keys[] = "date-{$wp_query->query_vars['year']}{$wp_query->query_vars['monthnum']}";
			} elseif ( is_year() ) {
				$surrogate_keys[] = 'date-year';
				$surrogate_keys[] = "date-{$wp_query->query_vars['year']}";
			}
		} elseif ( is_author() ) {
			$surrogate_keys[] = 'author';
			$surrogate_keys[] = "author-{$object->user_nicename}";
		} elseif ( is_post_type_archive() ) {
			$surrogate_keys[] = 'type-' . get_post_type();
		}

		$paged = get_query_var( 'pagenum' ) ? get_query_var( 'pagenum' ) : false;
		if ( ! $paged && get_query_var( 'paged' ) ) {
			$paged = get_query_var( 'paged' );
		}
		if ( $paged ) {
			$surrogate_keys[] = 'paged';
			$surrogate_keys[] = "paged-{$paged}";
		}
	}

	if ( is_front_page() ) {
		$surrogate_keys[] = 'front';
		$browser_max_age  = 600;   // Home page likely changes frequently, browser cache only 10 minutes.
		$server_max_age   = 86400; // Home page likely changes frequently, server cache only 1 day.
	}
	if ( is_home() ) {
		$surrogate_keys[] = 'home';
		$browser_max_age  = 600;
		$server_max_age   = 86400;
	}

	if ( is_user_logged_in() ) {
		header( 'Cache-Control: max-age=0, no-store', true );
		header_remove( 'Expires' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s \G\M\T', $last_modified ), true );
	} else {
		header( "Cache-Control: max-age={$browser_max_age}, s-maxage={$server_max_age}, public", true );
		header_remove( 'Expires' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s \G\M\T', $last_modified ), true );
	}
	if ( $surrogate_keys ) {
		header( 'Surrogate-Key: ' . implode( ' ', $surrogate_keys ), true );
	}
}
add_action( 'template_redirect', 'ezoic_cdn_add_headers' );
