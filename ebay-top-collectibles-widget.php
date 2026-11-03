<?php

require_once __DIR__ . '/includes/class-tcs-ebay-listing-service.php';
require_once __DIR__ . '/includes/class-tcs-ebay-shortcodes.php';
require_once __DIR__ . '/includes/class-tcs-ebay-listing-cache.php';
require_once __DIR__ . '/includes/ebay-category-tree.php';

function tcs_ebay_listing_service(): TCS_Ebay_Listing_Service {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Ebay_Listing_Service();
    }
    return $instance;
}

function tcs_ebay_shortcodes(): TCS_Ebay_Shortcodes {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Ebay_Shortcodes(tcs_ebay_listing_service());
    }
    return $instance;
}

/** Shared Browse request budget/backoff for the eBay shortcodes. */
function tcs_ebay_shortcode_browse_get($url, $args) {
    return TCS_Ebay_API_Client::request('browse', $url, $args);
}

/** Editor rendering should not trigger external listing requests. */
function tcs_ebay_is_editor_request() {
    return is_admin() || (
        defined('REST_REQUEST') && REST_REQUEST &&
        isset($_REQUEST['context']) && $_REQUEST['context'] === 'edit'
    );
}

/**
 * Build an eBay search phrase from a WordPress category trail.
 */
function tcs_ebay_category_search_phrase($category) {
    if (!$category instanceof WP_Term || $category->taxonomy !== 'category') {
        return '';
    }

    $term_ids = array_reverse(
        get_ancestors($category->term_id, 'category', 'taxonomy')
    );
    $term_ids[] = $category->term_id;

    $names = [];

    foreach ($term_ids as $term_id) {
        $term = get_term($term_id, 'category');

        if (!$term instanceof WP_Term) {
            continue;
        }

        // Auctions is a grouping category, not an eBay search term.
        if ($term->slug === 'auctions') {
            continue;
        }

        $names[] = html_entity_decode(
            $term->name,
            ENT_QUOTES,
            get_bloginfo('charset') ?: 'UTF-8'
        );
    }

    return trim(implode(' ', $names));
}

function get_ebay_category_id_from_slug($slug) {
    if (!is_string($slug) || trim($slug) === '') {
        return false;
    }
    $resolved = tcs_ebay_resolve_search($slug);
    return is_wp_error($resolved) ? false : ($resolved['id'] ?: false);
}
function get_ebay_user_info($username) {
    return tcs_ebay_listing_service()->user_info($username);
}
/**
 * Fetch ranked eBay auctions for tabs: bids, watched, hot.
 */
function tcs_fetch_ranked_items_now($category_slug, $limit = 10, $search_keywords = '') {
    return tcs_ebay_listing_service()->ranked_items($category_slug, $limit, $search_keywords);
}
/**
 * Format human-readable time left until auction ends
 *
 * @param string|null $endTime ISO 8601 datetime string (e.g. '2026-03-01T20:33:46.000Z')
 * @return string Formatted string like "2d 14h", "5h 30m", "45 minutes", "Ended", or "N/A"
 */
function ebay_time_left( $endTime ) {
    if ( empty( $endTime ) ) {
        return 'N/A';
    }

    try {
        $end = new DateTime( $endTime );
        $now = new DateTime();
        
        if ( $end <= $now ) {
            return 'Ended';
        }

        $interval = $now->diff( $end );

        if ( $interval->days > 0 ) {
            return $interval->days . 'd ' . $interval->h . 'h';
        } elseif ( $interval->h > 0 ) {
            return $interval->h . 'h ' . $interval->i . 'm';
        } else {
            return $interval->i . ' minutes';
        }
    } catch ( Exception $e ) {
        // In case of invalid date format
        return 'N/A';
    }
}
function tcs_render_ebay_buy_it_now_widget(
    $category_query,
    $category_name,
    $search_keywords = ''
) {
    return tcs_ebay_shortcodes()->render_buy_it_now($category_query, $category_name, $search_keywords);
}
function tcs_fetch_auction_parent_items(
    WP_Term $auction_category,
    int $limit = 5
) {
    return tcs_ebay_listing_service()->auction_parent_items($auction_category, $limit);
}
function render_ebay_top_widget($atts) {
    return tcs_ebay_shortcodes()->render_top_widget($atts);
}
add_shortcode('ebay_top_5_widget', 'render_ebay_top_widget');

function tcs_poll_ebay_auction_widget(): void {
    $category = isset($_POST['category']) && is_string($_POST['category'])
        ? sanitize_text_field(wp_unslash($_POST['category']))
        : '';

    if (
        $category === '' ||
        !check_ajax_referer(
            'tcs_ebay_auction_widget_' . $category,
            'nonce',
            false
        )
    ) {
        wp_send_json_error(null, 403);
    }

    add_filter('tcs_ebay_queue_listing_refresh', '__return_false');

    $html = tcs_ebay_shortcodes()->render_top_widget([
        'category' => $category,
        'type' => 'auction',
    ], true);

    wp_send_json_success([
        'pending' => $html === '',
        'html' => $html,
    ]);
}

add_action(
    'wp_ajax_tcs_poll_ebay_auction_widget',
    'tcs_poll_ebay_auction_widget'
);

add_action(
    'wp_ajax_nopriv_tcs_poll_ebay_auction_widget',
    'tcs_poll_ebay_auction_widget'
);

/*
 * Get category groups for the page/post shortcode.
 *
 * Auctions: direct children of Auctions and Collectibles.
 * Buy It Now: direct children of Collectibles.
 */
function tcs_top_items_categories($type, $post_id) {
    $root_slugs = $type === 'auction'
        ? ['auctions', 'collectibles']
        : ['collectibles'];

    $groups = [];

    foreach ($root_slugs as $slug) {
        $root = get_term_by('slug', $slug, 'category');

        if (!$root instanceof WP_Term) {
            continue;
        }

        $children = get_terms([
            'taxonomy'   => 'category',
            'parent'     => $root->term_id,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($children)) {
            continue;
        }

        foreach ($children as $child) {
            $groups[$child->term_id] = $child;
        }

        if (!$children && $type === 'buynow') {
            $groups[$root->term_id] = $root;
        }
    }

    // Auction fallback when neither tree provides category groups.
    if (!$groups && $type === 'auction' && $post_id) {
        $assigned = wp_get_post_terms($post_id, 'category');

        if (!is_wp_error($assigned)) {
            foreach ($assigned as $term) {
                if (in_array(
                    $term->slug,
                    ['auctions', 'collectibles'],
                    true
                )) {
                    continue;
                }

                $groups[$term->term_id] = $term;
            }
        }
    }

    return array_values($groups);
}

/*
 * Descendants supply a group's listings.
 * Use the group itself if it has no descendants.
 */
/*
 * Return only categories without children.
 * Parent categories are never searched for listings.
 */
function tcs_top_items_group_categories(WP_Term $group) {
    $children = get_terms([
        'taxonomy'   => 'category',
        'parent'     => $group->term_id,
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ]);

    if (is_wp_error($children)) {
        return [];
    }

    if (!$children) {
        return [$group];
    }

    $leaves = [];

    foreach ($children as $child) {
        foreach (tcs_top_items_group_categories($child) as $leaf) {
            $leaves[$leaf->term_id] = $leaf;
        }
    }

    return array_values($leaves);
}

/**
 * Fetch Buy It Now candidates for one category.
 *
 * Successful results are cached for 15 minutes.
 * Empty results and failures are cached for 2 minutes.
 */
function tcs_fetch_buy_now_candidates_now(
    $phrase,
    $limit,
    $search_keywords = ''
) {
    return tcs_ebay_listing_service()->buy_now_candidates($phrase, $limit, $search_keywords);
}

/**
 * Page/post shortcode:
 * [ebay_top_items type="auction" limit="10"]
 */
/** Build a Tools link locally from the listing's public URL. */
function tcs_top_items_tools_url(array $item) {
    $url = isset($item['viewItemURL']) && is_string($item['viewItemURL'])
        ? wp_parse_url($item['viewItemURL']) : false;
    if (!is_array($url) || !preg_match('/(?:^|\.)ebay\.[a-z.]+\z/', strtolower($url['host'] ?? ''))) {
        return '';
    }
    if (!preg_match('~^/itm/(?:[^/]+/)?([0-9]{9,20})/?$~', $url['path'] ?? '', $matches)) {
        return '';
    }
    return add_query_arg('item_id', $matches[1], home_url('/tools/'));
}

function tcs_render_ebay_top_items_shortcode($atts) {
    return tcs_ebay_shortcodes()->render_top_items($atts);
}

add_shortcode(
    'ebay_top_items',
    'tcs_render_ebay_top_items_shortcode'
);

function tcs_poll_ebay_top_items(): void {
    $post_id = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    $limit = filter_var($_POST['limit'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 5],
    ]);

    if (
        !$post_id ||
        !$limit ||
        !check_ajax_referer(
            'tcs_ebay_top_items_' . $post_id . '_' . $limit,
            'nonce',
            false
        ) ||
        get_post_status($post_id) !== 'publish' ||
        post_password_required($post_id)
    ) {
        wp_send_json_error(null, 403);
    }

    add_filter('tcs_ebay_queue_listing_refresh', '__return_false');

    $html = tcs_ebay_shortcodes()->render_top_items([
        'type' => 'auction',
        'limit' => $limit,
    ], $post_id);

    wp_send_json_success([
        'pending' => $html === '',
        'html' => $html,
    ]);
}

add_action('wp_ajax_tcs_poll_ebay_top_items', 'tcs_poll_ebay_top_items');
add_action('wp_ajax_nopriv_tcs_poll_ebay_top_items', 'tcs_poll_ebay_top_items');