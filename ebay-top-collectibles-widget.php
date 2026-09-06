<?php
function get_ebay_category_id_from_slug($slug) {
    if (empty($slug) || !is_string($slug)) {
        return false;
    }

    $cache_key = 'ebay_cat_id_' . sanitize_key($slug);
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

    $response = wp_remote_get($url, [
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

    $response = wp_remote_post('https://api.ebay.com/ws/api.dll', [
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
function fetch_ebay_ranked_items($category_slug, $limit = 5) {

    $access_token = get_transient('ebay_oauth_token') ?: get_ebay_oauth_token();   
    $cache_key = 'ebay_ranked_' . sanitize_key($category_slug);
    $cached = get_transient($cache_key);
    if ($cached !== false && is_array($cached)) {
        return $cached;
    }    
    if (!$access_token) {
        error_log("eBay widget: No OAuth token available.");
        return false;
    }

    $category_id = get_ebay_category_id_from_slug($category_slug);
    if (!$category_id || !is_numeric($category_id)) {
        error_log("eBay widget: Invalid category ID for slug: $category_slug");
        return false;
    }
    $future_iso = gmdate('Y-m-d\TH:i:s\Z', time() + 300);
    $base_url = 'https://api.ebay.com/buy/browse/v1/item_summary/search';
    $query_params = [
        'category_ids' => $category_id,
        'limit' =>      200,         // more items = better client-side ranking pool
        'filter' => "buyingOptions:{AUCTION},bidCount:[1..],price:[3..],priceCurrency:USD,itemEndDate:[{$future_iso}..]",// optional price floor to avoid junk                  
        'sort'         => 'endingSoonest',  // supported value: closest ending first
    ];
    $url = add_query_arg($query_params, $base_url);

    $response = wp_remote_get($url, [
        'headers' => [
            'Authorization'          => 'Bearer ' . $access_token,
            'X-EBAY-C-ENDUSERCTX'    => 'contextualLocation=country=US',
            'Accept'                 => 'application/json',
        ],
        'timeout' => 12,
    ]);

    if (is_wp_error($response)) {
        error_log("eBay API error: " . $response->get_error_message());
        return false;
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        error_log("eBay API HTTP $status for category $category_slug");
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['itemSummaries']) || !is_array($body['itemSummaries'])) {
        return false;
    }

    $now = current_time('timestamp');
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
        return false;
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
    set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
    return $result;
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
function render_ebay_top_widget($atts) {

    $atts = shortcode_atts([
        'category' => '',
    ], $atts);

    $category_slug = !empty($atts['category']) ? $atts['category'] : '';

    if (empty($category_slug) && is_category()) {
        $category = get_queried_object();
        if (!$category || is_wp_error($category)) {
            return '<p>Invalid category.</p>';
        }
        $category_slug = $category->slug;
        $category_name = $category->name;
    } elseif (empty($category_slug)) {
        return '<p>This widget requires a category slug or must be used on a category page.</p>';
    } else {
        $category = get_category_by_slug($category_slug);
        $category_name = $category ? $category->name : ucwords(str_replace('-', ' ', $category_slug));
    }

    $ranked = fetch_ebay_ranked_items($category_slug);

    if (!$ranked || !is_array($ranked)) {
        return '<p>No items found for this category.</p>';
    }

    wp_enqueue_style('ebay-widget', plugins_url('ebay-widget.css', __FILE__), [], '1.0', 'all');

    $category_name_esc = esc_html($category_name);

    ob_start();
    ?>

    <div class="ebay-top-widget">

        <h2>Top eBay Auctions in <?php echo $category_name_esc; ?></h2>

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
