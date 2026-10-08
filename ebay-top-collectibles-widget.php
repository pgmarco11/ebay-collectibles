<?php
/** Reserve a listing fetch atomically; expired locks recover after interrupted requests. */
function tcs_ebay_acquire_lock($name, $seconds = 60) {
    $expires = (int) get_option($name, 0);
    if ($expires && $expires < time()) {
        delete_option($name);
    }
    return add_option($name, time() + $seconds, '', false);
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
    if (empty($slug) || !is_string($slug)) {
        return false;
    }

    $cache_key = 'ebay_cat_trail_v2_' . md5($slug);
    $cached_cat_id = get_transient($cache_key);
    if ($cached_cat_id !== false || get_transient($cache_key . '_empty')) {
        return $cached_cat_id;
    }

    $access_token = get_transient('ebay_oauth_token') ?: get_ebay_oauth_token();
    if (!$access_token) {
        set_transient($cache_key . '_empty', true, HOUR_IN_SECONDS);
        return false;
    }

    $query = rawurlencode(str_replace('-', ' ', $slug));
    $url = "https://api.ebay.com/commerce/taxonomy/v1/category_tree/0/get_category_suggestions?q=$query";

    $response = TCS_Ebay_API_Client::request('taxonomy', $url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $access_token,
            'Accept'        => 'application/json',
        ],
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        error_log('eBay Category API error: ' . $response->get_error_message());
        set_transient($cache_key . '_empty', true, HOUR_IN_SECONDS);
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $category_id = $body['categorySuggestions'][0]['category']['categoryId'] ?? false;

    set_transient($category_id ? $cache_key : $cache_key . '_empty', $category_id ?: true, 7 * DAY_IN_SECONDS);
    return $category_id;
}
function get_ebay_user_info($username) {
    if (empty($username) || !is_string($username)) {
        return ['feedbackPercent' => null, 'registrationDate' => null];
    }

    global $env_ebay;

    $cache_key = 'ebay_user_' . sanitize_key($username);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    $dev_id  = $env_ebay['EBAY_DEV_ID'] ?? '';
    $app_id  = $env_ebay['EBAY_PRODUCTION_APPID'] ?? '';
    $cert_id = $env_ebay['EBAY_CLIENT_SECRET'] ?? '';
    $token   = $env_ebay['EBAY_AUTH_TOKEN'] ?? '';

    if (empty($dev_id) || empty($app_id) || empty($cert_id) || empty($token)) {
        error_log('eBay User Info: Missing API credentials.');
        return ['feedbackPercent' => null, 'registrationDate' => null];
    }

    $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetUserRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <RequesterCredentials>
    <eBayAuthToken>{$token}</eBayAuthToken>
  </RequesterCredentials>
  <UserID>{$username}</UserID>
</GetUserRequest>
XML;

    $response = TCS_Ebay_API_Client::request('trading', 'https://api.ebay.com/ws/api.dll', [
        'method' => 'POST',
        'headers' => [
            'X-EBAY-API-CALL-NAME'   => 'GetUser',
            'X-EBAY-API-COMPATIBILITY-LEVEL' => '967',
            'X-EBAY-API-SITEID'      => '0',
            'X-EBAY-API-DEV-NAME'    => $dev_id,
            'X-EBAY-API-APP-NAME'    => $app_id,
            'X-EBAY-API-CERT-NAME'   => $cert_id,
            'Content-Type'           => 'text/xml',
        ],
        'body'    => $xml,
        'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
        error_log('eBay User API error: ' . $response->get_error_message());
        set_transient($cache_key, ['feedbackPercent' => null, 'registrationDate' => null], HOUR_IN_SECONDS);
        return ['feedbackPercent' => null, 'registrationDate' => null];
    }

    $body = wp_remote_retrieve_body($response);
    $xml_response = simplexml_load_string($body);

    if (!$xml_response || $xml_response->Ack != 'Success') {
        error_log('eBay User API: Invalid response or failure.');
        set_transient($cache_key, ['feedbackPercent' => null, 'registrationDate' => null], HOUR_IN_SECONDS);
        return ['feedbackPercent' => null, 'registrationDate' => null];
    }

    $info = [
        'feedbackPercent' => (string)$xml_response->User->PositiveFeedbackPercent,
        'registrationDate' => (string)$xml_response->User->RegistrationDate,
    ];

    set_transient($cache_key, $info, DAY_IN_SECONDS);
    return $info;
}
/**
 * Fetch ranked eBay auctions for tabs: bids, watched, hot.
 */
function fetch_ebay_ranked_items($category_slug, $limit = 10, $search_keywords = '') {

    $cache_key = 'ebay_ranked_trail_v7_' . md5(
        wp_json_encode([
            $category_slug,
            absint($limit),
            $search_keywords,
        ])
    );
    $cached = get_transient($cache_key);
    if ($cached !== false && is_array($cached)) {
        $cutoff = time() + 300;
    
        foreach (['hot', 'bids', 'ending'] as $tab) {
            $cached[$tab] = array_values(array_filter(
                $cached[$tab] ?? [],
                static function ($item) use ($cutoff) {
                    return (int) ($item['endTimeUnix'] ?? 0) > $cutoff;
                }
            ));
        }
    
        return !empty($cached['hot']) || !empty($cached['bids']) || !empty($cached['ending'])
            ? $cached
            : false;
    }

    $cache_empty = static function () use ($cache_key) {
        set_transient($cache_key, ['hot' => [], 'bids' => [], 'ending' => []], 30);
        return false;
    };
    $fetch_lock = 'tcs_ebay_fetch_' . md5($cache_key);
    if (!tcs_ebay_acquire_lock($fetch_lock)) {
        return false;
    }
    try {
        $access_token = get_transient('ebay_oauth_token') ?: get_ebay_oauth_token();
        if (!$access_token) {
            error_log("eBay widget: No OAuth token available.");
            return $cache_empty();
        }

        $future_iso = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
        $base_url = 'https://api.ebay.com/buy/browse/v1/item_summary/search';

        $query_params = [
            'limit'  => 200,
            'filter' => "buyingOptions:{AUCTION},bidCount:[1..],price:[3..],priceCurrency:USD,itemEndDate:[{$future_iso}..]",
            'sort'   => 'endingSoonest',
        ];

        // An empty category phrase means auctions across eBay categories.
        if ($category_slug !== '') {
            $category_id = get_ebay_category_id_from_slug($category_slug);

            if (!$category_id || !is_numeric($category_id)) {
                error_log(
                    "eBay widget: Invalid category ID for phrase: $category_slug"
                );
                return $cache_empty();
            }

            $query_params['category_ids'] = $category_id;
        }

        if ($search_keywords !== '') {
            $query_params['q'] = $search_keywords;
        }
        $url = add_query_arg($query_params, $base_url);

        $response = tcs_ebay_shortcode_browse_get($url, [
            'headers' => [
                'Authorization'          => 'Bearer ' . $access_token,
                'X-EBAY-C-ENDUSERCTX'    => 'contextualLocation=country=US',
                'Accept'                 => 'application/json',
            ],
            'timeout' => 12,
        ]);

        if (is_wp_error($response)) {
            error_log("eBay API error: " . $response->get_error_message());
            return $cache_empty();
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            error_log("eBay API HTTP $status for category $category_slug");
            return $cache_empty();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['itemSummaries']) || !is_array($body['itemSummaries'])) {
            return $cache_empty();
        }

        $now = time();
        $processed_items = [];

        foreach ($body['itemSummaries'] as $raw) {
            $end_time = isset($raw['itemEndDate']) ? strtotime($raw['itemEndDate']) : null;
            if (!$end_time || $end_time <= $now + 300) {  // ← add buffer: skip if ends in past or next 5 min
                continue;
            }
            $minutes_left = ($end_time - $now) / 60;
            if ($minutes_left < 5) {  // or 1, 10 — tune as you like
                continue;
            }

            $hours_left = max(0.1, ($end_time - $now) / 3600);
            // Optional: stricter — skip if less than 1 minute left (edge case lag)
            if ($hours_left < 0.0167) {  // ~1 minute
                continue;
            }

            $buying_options = $raw['buyingOptions'] ?? [];

            // Only keep if AUCTION is present (and optionally require no FIXED_PRICE for pure auctions)
            if (!in_array('AUCTION', $buying_options)) {
                continue;  // Skip pure BIN
            }
                   
            $bid_count = (int)($raw['bidCount'] ?? 0);
            if ($bid_count === 0 && !in_array('AUCTION', $buying_options)) {  // extra safety
                continue;
            }

            // Calculate time left for urgency
            // Inside the foreach ($body['itemSummaries'] as $raw)
            $end_time   = isset($raw['itemEndDate']) ? strtotime($raw['itemEndDate']) : null;
            $hours_left = $end_time ? max(0.1, ($end_time - $now) / 3600) : 9999; // avoid div-by-zero

            $urgency_boost = 0;
            if ($hours_left <= 48) {
                $urgency_boost = (48 - $hours_left) * 5;  // heavy weight: ending in 1 hour = +235 boost
            } elseif ($hours_left <= 120) {               // up to 5 days
                $urgency_boost = (120 - $hours_left) * 1.5;
            }

            $bid_boost     = $bid_count * 4;
            $price_value   = isset($raw['currentBidPrice']['value']) 
                ? (float)$raw['currentBidPrice']['value'] 
                : (float)($raw['price']['value'] ?? 0);
            $price_boost   = min(60, $price_value / 5);  // high bids add some signal
        
            $hot_score = $urgency_boost + $bid_boost + $price_boost;

            $processed_items[] = [
                'title'       => $raw['title'] ?? 'Untitled Item',
                'viewItemURL' => $raw['itemWebUrl'] ?? '#',
                'price'       => isset($raw['currentBidPrice']['value'])
                               ? (float)$raw['currentBidPrice']['value']
                               : (float)($raw['price']['value'] ?? 0),
                'imageURL'    => $raw['image']['imageUrl'] ?? '',
                'bidCount'    => $bid_count,
                'hours_left' => $hours_left,
                'hotScore'    => $hot_score,
                'endTime'     => $end_time ? date('c', $end_time) : null,
                'endTimeUnix' => $end_time ?: PHP_INT_MAX,
            ];
        }

        if (empty($processed_items)) {
            return $cache_empty();
        }

        // ────────────────────────────────────────────────
        // Create ranked lists for each tab
        // ────────────────────────────────────────────────

        // 1. Most Bids (primary: bidCount desc, tie-breaker: watchCount desc)
        $bids = $processed_items;
        usort($bids, function($a, $b) {
            if ($b['bidCount'] !== $a['bidCount']) {
                return $b['bidCount'] <=> $a['bidCount'];
            }
            return $b['hotScore'] <=> $a['hotScore'];  // tie-breaker
        });
        $bids = array_slice($bids, 0, $limit);
    
        // Hot: high urgency + bids (must have at least 1 bid to qualify as "hot")
        $hot = array_filter($processed_items, function($item) {
            return $item['bidCount'] > 0;
        });
        usort($hot, function($a, $b) {
            return $b['hotScore'] <=> $a['hotScore'];  // urgency dominant
        });
        $hot = array_slice($hot, 0, $limit);
    
        // Ending Soon: sort primarily by time left ascending (soonest first)
        $ending = $processed_items;
        usort($ending, function($a, $b) {
            $a_end = $a['endTimeUnix'] ?? PHP_INT_MAX;   // add this field below
            $b_end = $b['endTimeUnix'] ?? PHP_INT_MAX;
        
            if ($a_end !== $b_end) {
                return $a_end <=> $b_end;   // smaller timestamp = ends sooner
            }
        
            return $b['bidCount'] <=> $a['bidCount'];
        });
    
        $ending = array_slice($ending, 0, $limit);

        // Take top N for each
        $result = [
            'hot'     => array_slice($hot,     0, $limit),
            'bids'    => array_slice($bids,    0, $limit),
            'ending' => array_slice($ending, 0, $limit),
        ];

        // Cache for ~15 minutes
        set_transient($cache_key, $result, 5 * MINUTE_IN_SECONDS);
        return $result;
    } finally {
        delete_option($fetch_lock);
    }
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
    $candidates = tcs_top_items_buy_now_candidates($category_query, 5, $search_keywords);
    $items = array_map(static function ($item) {
        return [
            'title' => $item['title'],
            'itemWebUrl' => $item['viewItemURL'],
            'image' => ['imageUrl' => $item['imageURL']],
            'price' => ['value' => $item['price'], 'currency' => $item['currency']],
        ];
    }, $candidates);

    if (!$items) {
        return '<p>No Buy It Now items found for this category.</p>';
    }

    ob_start();
    ?>
    <div class="ebay-top-widget">
        <h2>
            Top eBay Buy It Now Items in
            <?php echo esc_html($category_name); ?>
        </h2>

        <div class="ebay-grid">
            <?php foreach (array_slice($items, 0, 5) as $item): ?>
                <div class="ebay-item">
                    <?php if (!empty($item['image']['imageUrl'])): ?>
                        <img
                            src="<?php echo esc_url($item['image']['imageUrl']); ?>"
                            alt="<?php echo esc_attr($item['title'] ?? ''); ?>"
                            class="ebay-item-image"
                            loading="lazy"
                        >
                    <?php endif; ?>

                    <div class="ebay-item-details">
                        <a
                            href="<?php echo esc_url($item['itemWebUrl'] ?? ''); ?>"
                            target="_blank"
                            rel="noopener"
                            class="ebay-item-title"
                        >
                            <?php
                            echo esc_html(wp_trim_words(
                                $item['title'] ?? '',
                                10
                            ));
                            ?>
                        </a>

                        <p class="ebay-item-price">
                            <?php
                            echo esc_html(
                                number_format(
                                    (float) ($item['price']['value'] ?? 0),
                                    2
                                ) . ' ' .
                                ($item['price']['currency'] ?? 'USD')
                            );
                            ?>
                        </p>

                        <p>Buy It Now</p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php

    return ob_get_clean();
}
function tcs_fetch_auction_parent_items(
    WP_Term $auction_category,
    int $limit = 5
) {
    $children = get_terms([
        'taxonomy'   => 'category',
        'parent'     => $auction_category->term_id,
        'hide_empty' => false,
    ]);

    if (is_wp_error($children) || !$children) {
        return false;
    }

    $pool = [];

    foreach ($children as $child) {
        $phrase = tcs_ebay_category_search_phrase($child);

        if ($phrase === '') {
            continue;
        }

        // Broad category lookup; no child-name keyword restriction.
        $ranked = fetch_ebay_ranked_items(
            $phrase,
            $limit,
            ''
        );

        if (!is_array($ranked)) {
            continue;
        }

        foreach (['hot', 'bids', 'ending'] as $tab) {
            foreach ($ranked[$tab] ?? [] as $item) {
                $url = $item['viewItemURL'] ?? '';

                if (
                    $url === '' ||
                    $url === '#' ||
                    (int) ($item['endTimeUnix'] ?? 0) <= time() + 300
                ) {
                    continue;
                }

                // Deduplicate items returned in multiple tabs.
                $pool[$url] = $item;
            }
        }
    }

    if (!$pool) {
        return false;
    }

    $items = array_values($pool);

    $hot = $items;
    usort($hot, static function ($a, $b) {
        return ($b['hotScore'] ?? 0) <=> ($a['hotScore'] ?? 0);
    });

    $bids = $items;
    usort($bids, static function ($a, $b) {
        return
            (($b['bidCount'] ?? 0) <=> ($a['bidCount'] ?? 0))
            ?: (($b['hotScore'] ?? 0) <=> ($a['hotScore'] ?? 0));
    });

    $ending = $items;
    usort($ending, static function ($a, $b) {
        return
            (($a['endTimeUnix'] ?? PHP_INT_MAX)
                <=> ($b['endTimeUnix'] ?? PHP_INT_MAX))
            ?: (($b['bidCount'] ?? 0) <=> ($a['bidCount'] ?? 0));
    });

    return [
        'hot'    => array_slice($hot, 0, $limit),
        'bids'   => array_slice($bids, 0, $limit),
        'ending' => array_slice($ending, 0, $limit),
    ];
}
function render_ebay_top_widget($atts) {
    if (tcs_ebay_is_editor_request()) {
        return '<p>eBay listings are displayed when viewing the page.</p>';
    }


    $atts = shortcode_atts([
        'category' => '',
        'type'     => 'auto',
    ], $atts, 'ebay_top_10_widget');
    
    $requested_type = strtolower(trim((string) $atts['type']));
    
    if (!in_array($requested_type, ['auto', 'auction', 'buynow'], true)) {
        return '<p>Invalid listing type. Use auction or buynow.</p>';
    }

    $category_slug = trim((string) $atts['category']);
    $category = null;

    if ($category_slug !== '') {
        $category = get_category_by_slug($category_slug);
    } elseif (is_category()) {
        $category = get_queried_object();
    } else {
        return '<p>This widget requires a category slug or must be used on a category page.</p>';
    }

    if ($category instanceof WP_Term && $category->taxonomy === 'category') {
        $category_name = $category->name;
        $category_query = tcs_ebay_category_search_phrase($category);
    } elseif ($category_slug !== '') {
        // Preserve support for an explicit category without a matching WP term.
        $category_query = trim(str_replace('-', ' ', $category_slug));
        $category_name = ucwords($category_query);
    } else {
        return '<p>Invalid category.</p>';
    }

    $is_auctions_root =
    $category instanceof WP_Term &&
    $category->taxonomy === 'category' &&
    $category->slug === 'auctions';

    if ($is_auctions_root) {
        $category_query = '';
    } elseif ($category_query === '') {
        return '<p>Invalid category.</p>';
    }

    $search_keywords = '';

    if (
        $category instanceof WP_Term &&
        $category->taxonomy === 'category' &&
        (int) $category->parent > 0 &&
        !$is_auctions_root
    ) {
        $search_keywords = html_entity_decode(
            $category->name,
            ENT_QUOTES,
            get_bloginfo('charset') ?: 'UTF-8'
        );
    }

    $is_collectibles_branch = false;

    if (
        $category instanceof WP_Term &&
        $category->taxonomy === 'category'
    ) {
        $ancestors = get_ancestors(
            $category->term_id,
            'category',
            'taxonomy'
        );

        $root_id = $ancestors
            ? (int) end($ancestors)
            : (int) $category->term_id;

        $root = get_term($root_id, 'category');

        $is_collectibles_branch =
            $root instanceof WP_Term &&
            $root->slug === 'collectibles';
    }

    $listing_type = $requested_type === 'auto'
    ? ($is_collectibles_branch ? 'buynow' : 'auction')
    : $requested_type;

    if ($listing_type === 'buynow') {
        /*
        * The Auctions grouping page has no product-category phrase.
        * Require a specific category for a Buy It Now override there.
        */
        if ($category_query === '') {
            return '<p>Please specify a product category for Buy It Now items.</p>';
        }

        return tcs_render_ebay_buy_it_now_widget(
            $category_query,
            $category_name,
            $search_keywords
        );
    }
        
    if ($is_auctions_root) {
        $ranked = tcs_fetch_auction_parent_items(
            $category,
            5
        );
    } else {
        $ranked = fetch_ebay_ranked_items(
            $category_query,
            5,
            $search_keywords
        );
    }

    if (!$ranked || !is_array($ranked)) {
        return '<p>No items found for this category.</p>';
    }


    $category_name_esc = esc_html($category_name);

    ob_start();
    ?>

    <div class="ebay-top-widget">

    <h2>
        <?php
        echo $is_auctions_root
            ? 'Top eBay Auctions'
            : 'Top eBay Auctions in ' . $category_name_esc;
        ?>
    </h2>

        <div class="ebay-tabs">
            <button class="ebay-tab active" data-tab="hot">🔥 Hot</button>
            <button class="ebay-tab" data-tab="bids">📈 Most Bids</button>
            <button class="ebay-tab" data-tab="ending">⏰ Ending Soon</button>  <!-- renamed -->
        </div>

        <?php foreach (['hot', 'bids', 'ending'] as $tab): ?>
            <div class="ebay-tab-content <?php echo $tab === 'hot' ? 'active' : ''; ?>" id="tab-<?php echo $tab; ?>">
                <div class="ebay-grid">

                    <?php foreach ($ranked[$tab] as $item): ?>

                        <div class="ebay-item">

                            <?php if (!empty($item['imageURL'])): ?>
                                <img src="<?php echo esc_url($item['imageURL']); ?>" 
                                     alt="<?php echo esc_attr(wp_trim_words($item['title'], 10)); ?>" 
                                     class="ebay-item-image" 
                                     loading="lazy">
                            <?php endif; ?>

                            <div class="ebay-item-details">

                                <a href="<?php echo esc_url($item['viewItemURL']); ?>" 
                                   target="_blank" 
                                   class="ebay-item-title">
                                    <?php echo esc_html(wp_trim_words($item['title'], 10)); ?>
                                </a>

                                <p class="ebay-item-price">
                                    $<?php echo number_format(floatval($item['price']), 2); ?>
                                </p>

                                <p>Bids: <?php echo esc_html($item['bidCount']); ?></p>
                                <p>Ends in: <?php echo esc_html( ebay_time_left( $item['endTime'] ?? null ) ); ?></p>

                            </div>
                        </div>

                    <?php endforeach; ?>

                </div>
            </div>
        <?php endforeach; ?>    

        <script>
        document.addEventListener('click', function(e) {

            const tab = e.target.closest('.ebay-tab');
            if (!tab) return;

            const widget = tab.closest('.ebay-top-widget');
            if (!widget) return;

            const targetId = 'tab-' + tab.dataset.tab;

            // Remove active from all tabs inside this widget
            widget.querySelectorAll('.ebay-tab').forEach(t => {
                t.classList.remove('active');
            });

            // Remove active from all contents inside this widget
            widget.querySelectorAll('.ebay-tab-content').forEach(c => {
                c.classList.remove('active');
            });

            // Activate clicked tab
            tab.classList.add('active');

            const target = widget.querySelector('#' + targetId);
            if (target) {
                target.classList.add('active');
            }

        });
        </script>
    </div>
    <?php

    return ob_get_clean();
}
add_shortcode('ebay_top_10_widget', 'render_ebay_top_widget');

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

/*
 * Fetch Buy It Now candidates for one category.
 */
function tcs_top_items_buy_now_candidates(
    $phrase,
    $limit,
    $search_keywords = ''
) {
    $limit = max(1, min(200, absint($limit)));
    $fetch_limit = min(200, max(20, $limit * 2));
    $cache_key = 'tcs_top_bin_v3_' . md5(wp_json_encode([
        $phrase,
        $fetch_limit,
        $search_keywords,
    ]));

    $active_items = static function ($items) use ($limit) {
        $cutoff = time() + 300;
        $items = array_filter($items, static function ($item) use ($cutoff) {
            $end = (int) ($item['endTimeUnix'] ?? 0);
            return !$end || $end > $cutoff;
        });
        return array_slice(array_values($items), 0, $limit);
    };

    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        return $active_items($cached);
    }

    $cache_empty = static function () use ($cache_key) {
        set_transient($cache_key, [], 2 * MINUTE_IN_SECONDS);
        return [];
    };

    $fetch_lock = 'tcs_ebay_fetch_' . md5($cache_key);
    if (!tcs_ebay_acquire_lock($fetch_lock)) {
        return [];
    }
    try {
        $category_id = get_ebay_category_id_from_slug($phrase);
        $token = get_transient('ebay_oauth_token') ?: get_ebay_oauth_token();
        if (!$category_id || !$token) {
            return $cache_empty();
        }

        $params = [
            'category_ids' => $category_id,
            'limit' => $fetch_limit,
            'filter' => 'buyingOptions:{FIXED_PRICE},price:[3..],priceCurrency:USD',
        ];
        if ($search_keywords !== '') {
            $params['q'] = $search_keywords;
        }

        $response = tcs_ebay_shortcode_browse_get(
            add_query_arg($params, 'https://api.ebay.com/buy/browse/v1/item_summary/search'),
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_US',
                    'Accept' => 'application/json',
                ],
                'timeout' => 12,
            ]
        );

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return $cache_empty();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return $cache_empty();
        }

        $items = [];
        foreach ($body['itemSummaries'] ?? [] as $raw) {
            $options = $raw['buyingOptions'] ?? [];
            if (!in_array('FIXED_PRICE', $options, true) || in_array('AUCTION', $options, true)) {
                continue;
            }

            $end = !empty($raw['itemEndDate']) ? strtotime($raw['itemEndDate']) : null;
            if (!empty($raw['itemEndDate']) && (!$end || $end <= time() + 300)) {
                continue;
            }

            $items[] = [
                'title' => $raw['title'] ?? 'Untitled item',
                'viewItemURL' => $raw['itemWebUrl'] ?? '',
                'imageURL' => $raw['image']['imageUrl'] ?? '',
                'price' => (float) ($raw['price']['value'] ?? 0),
                'currency' => $raw['price']['currency'] ?? 'USD',
                'endTimeUnix' => $end,
            ];
        }

        set_transient($cache_key, $items, ($items ? 15 : 2) * MINUTE_IN_SECONDS);
        return $active_items($items);
    } finally {
        delete_option($fetch_lock);
    }
}

/**
 * Page/post shortcode:
 * [ebay_top_items type="auction" limit="10"]
 */
function tcs_render_ebay_top_items_shortcode($atts) {
    if (tcs_ebay_is_editor_request()) {
        return '<p>eBay listings are displayed when viewing the page.</p>';
    }

    $atts = shortcode_atts([
        'type'  => 'auction',
        'limit' => 10,
    ], $atts, 'ebay_top_items');

    $type = strtolower(trim((string) $atts['type']));

    if (!in_array($type, ['auction', 'buynow'], true)) {
        return '<p>Invalid type. Use auction or buynow.</p>';
    }

    $requested_limit = filter_var(
        $atts['limit'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 200]]
    );

    if ($requested_limit === false) {
        return '<p>Limit must be a whole number between 1 and 200.</p>';
    }

    $section_limit = min(5, $requested_limit);

    $groups = tcs_top_items_categories($type, get_the_ID());

    if (!$groups) {
        return '<p>No suitable WordPress categories were found.</p>';
    }

    $sections = [];
    $cutoff = time() + 300;

    foreach ($groups as $group) {
        $children = get_terms([
            'taxonomy'   => 'category',
            'parent'     => $group->term_id,
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
    
        if (is_wp_error($children)) {
            continue;
        }
    
        /*
         * Children get individual listing sections.
         * A group without children displays its own listings.
         */
        $listing_categories = $children ?: [$group];
    
        foreach ($listing_categories as $listing_category) {
            $pool = [];
    
            foreach (
                tcs_top_items_group_categories($listing_category)
                as $category
            ) {
            $phrase = tcs_ebay_category_search_phrase($category);

            if ($phrase === '') {
                continue;
            }

            // Keep each descendant's search relevant to its name.
            $keywords = html_entity_decode(
                $category->name,
                ENT_QUOTES,
                get_bloginfo('charset') ?: 'UTF-8'
            );

            if ($type === 'auction') {
                $ranked = fetch_ebay_ranked_items(
                    $phrase,
                    max(10, $section_limit),
                    $keywords
                );

                if (!is_array($ranked)) {
                    continue;
                }

                $candidates = array_merge(
                    $ranked['hot'] ?? [],
                    $ranked['bids'] ?? [],
                    $ranked['ending'] ?? []
                );
            } else {
                $candidates = tcs_top_items_buy_now_candidates(
                    $phrase,
                    max(10, $section_limit),
                    $keywords
                );
            }

            foreach ($candidates as $item) {
                $url = $item['viewItemURL'] ?? '';
                $end = (int) ($item['endTimeUnix'] ?? 0);

                if ($url === '' || $url === '#') {
                    continue;
                }

                if (
                    ($type === 'auction' && $end <= $cutoff) ||
                    ($type === 'buynow' && $end && $end <= $cutoff)
                ) {
                    continue;
                }

                $pool[$url] = $item;
            }
        }

        // Hide sections with no qualifying listings.
        if (!$pool) {
            continue;
        }

        $items = array_values($pool);

        if ($type === 'auction') {
            $hot = $items;
            usort($hot, static function ($a, $b) {
                return ($b['hotScore'] ?? 0)
                    <=> ($a['hotScore'] ?? 0);
            });

            $bids = $items;
            usort($bids, static function ($a, $b) {
                return
                    (($b['bidCount'] ?? 0) <=> ($a['bidCount'] ?? 0))
                    ?: (($b['hotScore'] ?? 0) <=> ($a['hotScore'] ?? 0));
            });

            $ending = $items;
            usort($ending, static function ($a, $b) {
                return
                    (($a['endTimeUnix'] ?? PHP_INT_MAX)
                        <=> ($b['endTimeUnix'] ?? PHP_INT_MAX))
                    ?: (($b['bidCount'] ?? 0) <=> ($a['bidCount'] ?? 0));
            });

            $panels = [
                'hot'    => array_slice($hot, 0, $section_limit),
                'bids'   => array_slice($bids, 0, $section_limit),
                'ending' => array_slice($ending, 0, $section_limit),
            ];
        } else {
            $panels = [
                'buynow' => array_slice($items, 0, $section_limit),
            ];
        }

        $sections[] = [
            'group_id'   => $group->term_id,
            'group_name' => $group->name,
            'has_children' => !empty($children),
            'name'       => $listing_category->name,
            'panels'     => $panels,
        ];
        }
    }

    if (!$sections) {
        return '<p>No matching eBay items are available right now.</p>';
    }

    $labels = [
        'hot'    => 'Trending',
        'bids'   => 'Most bids',
        'ending' => 'Ending soon',
        'buynow' => 'Buy It Now',
    ];

    $instance_id = wp_unique_id('tcs-ebay-page-');


    ob_start();
    ?>
    <div
        id="<?php echo esc_attr($instance_id); ?>"
        class="tcs-ebay-page"
    >
    <?php
            $previous_group_id = null;

            foreach ($sections as $index => $section):
                $section_id = $instance_id . '-section-' . $index;
                $first_key = $type === 'auction' ? 'hot' : 'buynow';

                if ($previous_group_id !== $section['group_id']):
                    $previous_group_id = $section['group_id'];
            ?>
                <h2 class="tcs-ebay-group-heading">
                    <?php echo esc_html($section['group_name']); ?>
                </h2>

            <?php
                endif;
            ?>
            <section
                class="tcs-ebay-section"
                aria-labelledby="<?php echo esc_attr($section_id . '-heading'); ?>"
            >
                <header class="tcs-ebay-section-header"> 
                    <span class="tcs-ebay-type">
                        <?php echo $type === 'auction' ? 'Auctions' : 'Buy It Now'; ?>
                    </span>   
                    <?php if ($section['has_children']): ?>
                        <h3 id="<?php echo esc_attr($section_id . '-heading'); ?>">
                            <?php echo esc_html($section['name']); ?>
                        </h3>
                    <?php else: ?>
                        <span
                            id="<?php echo esc_attr($section_id . '-heading'); ?>"
                            class="tcs-ebay-sr-only"
                        >
                            <?php echo esc_html($section['name'] . ' listings'); ?>
                        </span>
                    <?php endif; ?>

                </header>

                <?php if ($type === 'auction'): ?>
                    <div
                        class="tcs-ebay-tabs"
                        role="tablist"
                        aria-label="<?php
                            echo esc_attr($section['name'] . ' auction rankings');
                        ?>"
                    >
                        <?php foreach ($section['panels'] as $key => $items):
                            $active = $key === $first_key;
                        ?>
                            <button
                                type="button"
                                class="tcs-ebay-tab"
                                role="tab"
                                id="<?php echo esc_attr($section_id . '-tab-' . $key); ?>"
                                aria-controls="<?php echo esc_attr($section_id . '-panel-' . $key); ?>"
                                aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
                                tabindex="<?php echo $active ? '0' : '-1'; ?>"
                            >
                                <?php echo esc_html($labels[$key]); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php foreach ($section['panels'] as $key => $items): ?>
                    <div
                        class="tcs-ebay-panel"
                        id="<?php echo esc_attr($section_id . '-panel-' . $key); ?>"
                        <?php if ($type === 'auction'): ?>
                            role="tabpanel"
                            aria-labelledby="<?php echo esc_attr($section_id . '-tab-' . $key); ?>"
                            tabindex="0"
                        <?php endif; ?>
                        <?php echo $key !== $first_key ? 'hidden' : ''; ?>
                    >
                        <ul class="tcs-ebay-cards">
                            <?php foreach ($items as $item): ?>
                                <li class="tcs-ebay-card">
                                    <a
                                        class="tcs-ebay-image-link"
                                        href="<?php echo esc_url($item['viewItemURL']); ?>"
                                        target="_blank"
                                        rel="noopener"
                                        tabindex="-1"
                                        aria-hidden="true"
                                    >
                                        <?php if (!empty($item['imageURL'])): ?>
                                            <img
                                                src="<?php echo esc_url($item['imageURL']); ?>"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            >
                                        <?php else: ?>
                                            <span class="tcs-ebay-no-image">
                                                No image available
                                            </span>
                                        <?php endif; ?>
                                    </a>

                                    <div class="tcs-ebay-card-body">
                                        <h3 class="tcs-ebay-card-title">
                                            <a
                                                href="<?php echo esc_url($item['viewItemURL']); ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                <?php echo esc_html($item['title'] ?? 'Untitled item'); ?>
                                                <span class="tcs-ebay-sr-only">
                                                    (opens in a new tab)
                                                </span>
                                            </a>
                                        </h3>

                                        <p class="tcs-ebay-price">
                                            <?php
                                            echo esc_html(
                                                number_format(
                                                    (float) ($item['price'] ?? 0),
                                                    2
                                                ) . ' ' .
                                                ($item['currency'] ?? 'USD')
                                            );
                                            ?>
                                        </p>

                                        <?php if ($type === 'auction'): ?>
                                            <dl class="tcs-ebay-meta">
                                                <div>
                                                    <dt>Bids</dt>
                                                    <dd><?php
                                                        echo esc_html($item['bidCount'] ?? 0);
                                                    ?></dd>
                                                </div>
                                                <div>
                                                    <dt>Ends in</dt>
                                                    <dd><?php
                                                        echo esc_html(ebay_time_left(
                                                            gmdate(
                                                                'Y-m-d\TH:i:s\Z',
                                                                (int) $item['endTimeUnix']
                                                            )
                                                        ));
                                                    ?></dd>
                                                </div>
                                            </dl>
                                        <?php else: ?>
                                            <p class="tcs-ebay-buy-label">
                                                Buy It Now
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <?php if ($type === 'auction'): ?>
        <script>
        (() => {
            const root = document.getElementById(
                <?php echo wp_json_encode($instance_id); ?>
            );

            if (!root) return;

            function activate(tab) {
                const section = tab.closest('.tcs-ebay-section');

                section.querySelectorAll('[role="tab"]').forEach(button => {
                    const active = button === tab;
                    button.setAttribute('aria-selected', String(active));
                    button.tabIndex = active ? 0 : -1;
                });

                section.querySelectorAll('[role="tabpanel"]').forEach(panel => {
                    panel.hidden = panel.id !== tab.getAttribute('aria-controls');
                });
            }

            root.addEventListener('click', event => {
                const tab = event.target.closest('.tcs-ebay-tab');

                if (!tab || !root.contains(tab)) return;

                activate(tab);
            });

            root.addEventListener('keydown', event => {
                const tab = event.target.closest('.tcs-ebay-tab');

                if (!tab || !root.contains(tab)) return;

                const tabs = Array.from(
                    tab.closest('[role="tablist"]').querySelectorAll('[role="tab"]')
                );

                let index = tabs.indexOf(tab);

                if (event.key === 'ArrowRight') {
                    index = (index + 1) % tabs.length;
                } else if (event.key === 'ArrowLeft') {
                    index = (index - 1 + tabs.length) % tabs.length;
                } else if (event.key === 'Home') {
                    index = 0;
                } else if (event.key === 'End') {
                    index = tabs.length - 1;
                } else {
                    return;
                }

                event.preventDefault();
                activate(tabs[index]);
                tabs[index].focus();
            });
        })();
        </script>
    <?php endif; ?>
    <?php

    return ob_get_clean();
}

add_shortcode(
    'ebay_top_items',
    'tcs_render_ebay_top_items_shortcode'
);


