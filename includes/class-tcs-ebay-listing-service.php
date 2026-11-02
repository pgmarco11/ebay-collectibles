<?php
defined('ABSPATH') || exit;

/** Owns ebay listing service behavior; legacy function entry points delegate here. */
class TCS_Ebay_Listing_Service {
    public function user_info($username) {
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

    public function ranked_items($category_slug, $limit = 10, $search_keywords = '') {

        $cache_key = 'ebay_ranked_trail_v9_' . md5(
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

        $cache_empty = static function ($ttl = 30) use ($cache_key) {
            // A confirmed successful search with no matches.
            if ($ttl === 5 * MINUTE_IN_SECONDS) {
                $empty = [
                    'hot' => [],
                    'bids' => [],
                    'ending' => [],
                ];

                set_transient($cache_key, $empty, $ttl);

                return $empty;
            }

            return new WP_Error(
                'tcs_ebay_refresh_failed',
                'Auction listings could not be refreshed.'
            );
        };
        $fetch_lock = 'tcs_ebay_fetch_v2_' . md5($cache_key);
        $owner = TCS_Ebay_API_Client::acquire_lock($fetch_lock);

        if (!$owner) {
            return new WP_Error(
                'tcs_ebay_refresh_busy',
                'Another auction refresh is running.'
            );
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

            // Resolve against the shared tree; retain unmatched WP terms.
            if ($category_slug !== '') {
                $resolved = tcs_ebay_resolve_search($category_slug, $search_keywords);
                if (is_wp_error($resolved)) {
                    error_log('eBay category lookup: ' . $resolved->get_error_message());
                    return $resolved;
                }
                if (!empty($resolved['id'])) {
                    $query_params['category_ids'] = $resolved['id'];
                }
                $search_keywords = $resolved['keywords'];
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
                error_log(
                    'eBay API error: ' . $response->get_error_message()
                );
            
                return $response;
            }

            $status = wp_remote_retrieve_response_code($response);
            if ($status !== 200) {
                error_log("eBay API HTTP $status for category $category_slug");
                return $cache_empty();
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            // Retry invalid responses and API errors after 30 seconds.
            if (!is_array($body) || !empty($body['errors'])) {
                return $cache_empty();
            }

            // A successful search with zero matches stays cached for 5 minutes.
            if (
                isset($body['total'])
                && is_numeric($body['total'])
                && (float) $body['total'] === 0.0
            ) {
                return $cache_empty(5 * MINUTE_IN_SECONDS);
            }

            // Missing or malformed item data gets a short retry.
            if (
                empty($body['itemSummaries'])
                || !is_array($body['itemSummaries'])
            ) {
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
            TCS_Ebay_API_Client::release_lock($fetch_lock, $owner);
        }
    }

    public function auction_parent_items(
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

    public function buy_now_candidates(
        $phrase,
        $limit,
        $search_keywords = ''
    ) {
        $phrase = trim((string) $phrase);
        $search_keywords = trim((string) $search_keywords);

        if ($phrase === '') {
            return [];
        }

        $limit = max(1, min(200, absint($limit)));
        $fetch_limit = min(200, max(20, $limit * 2));

        $cache_key = 'tcs_top_bin_v5_' . md5(
            wp_json_encode([
                $phrase,
                $fetch_limit,
                $search_keywords,
            ])
        );

        $active_items = static function ($items) use ($limit) {
            $cutoff = time() + 300;

            $items = array_filter(
                $items,
                static function ($item) use ($cutoff) {
                    if (!is_array($item)) {
                        return false;
                    }

                    $end = (int) ($item['endTimeUnix'] ?? 0);

                    return !$end || $end > $cutoff;
                }
            );

            return array_slice(
                array_values($items),
                0,
                $limit
            );
        };

        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $active_items($cached);
        }

        $cache_empty = static function ($ttl = MINUTE_IN_SECONDS) use ($cache_key) {
            // A confirmed successful search with no matches.
            if ($ttl === 15 * MINUTE_IN_SECONDS) {
                set_transient($cache_key, [], $ttl);

                return [];
            }

            return new WP_Error(
                'tcs_ebay_refresh_failed',
                'Buy It Now listings could not be refreshed.'
            );
        };

        $fetch_lock = 'tcs_ebay_fetch_v2_' . md5($cache_key);
        $owner = TCS_Ebay_API_Client::acquire_lock($fetch_lock);

        if (!$owner) {
            return new WP_Error(
                'tcs_ebay_refresh_busy',
                'Another Buy It Now refresh is running.'
            );
        }
        try {
            // Another request may have populated the cache
            // between our initial check and acquiring the lock.
            $cached = get_transient($cache_key);

            if (is_array($cached)) {
                return $active_items($cached);
            }

            $resolved = tcs_ebay_resolve_search($phrase, $search_keywords);
            if (is_wp_error($resolved)) {
                error_log('eBay category lookup: ' . $resolved->get_error_message());
                return $resolved;
            }
            $category_id = $resolved['id'];
            $search_keywords = $resolved['keywords'];
            $token = get_transient('ebay_oauth_token') ?: get_ebay_oauth_token();
            if (!$token) {
                return $cache_empty();
            }
            $params = [
                'limit' => $fetch_limit,
                'filter' => 'buyingOptions:{FIXED_PRICE},price:[3..],priceCurrency:USD',
            ];
            if (!empty($category_id)) {
                $params['category_ids'] = $category_id;
            }

            if ($search_keywords !== '') {
                $params['q'] = $search_keywords;
            }

            $response = tcs_ebay_shortcode_browse_get(
                add_query_arg(
                    $params,
                    'https://api.ebay.com/buy/browse/v1/item_summary/search'
                ),
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_US',
                        'Accept' => 'application/json',
                    ],
                    'timeout' => 12,
                ]
            );

            if (is_wp_error($response)) {
                return $response;
            }
            
            if (wp_remote_retrieve_response_code($response) !== 200) {
                return $cache_empty();
            }

            $body = json_decode(
                wp_remote_retrieve_body($response),
                true
            );

            if (
                json_last_error() !== JSON_ERROR_NONE ||
                !is_array($body) ||
                !empty($body['errors'])
            ) {
                return $cache_empty();
            }

            // Cache a confirmed successful search with no matches for 15 minutes.
            if (
                isset($body['total'])
                && is_numeric($body['total'])
                && (float) $body['total'] === 0.0
            ) {
                return $cache_empty(15 * MINUTE_IN_SECONDS);
            }

            // Retry missing or malformed item data after one minute.
            $summaries = $body['itemSummaries'] ?? null;

            if (!is_array($summaries) || empty($summaries)) {
                return $cache_empty();
            }

            $items = [];
            $cutoff = time() + 300;

            foreach ($summaries as $raw) {
                if (!is_array($raw)) {
                    continue;
                }

                $options = $raw['buyingOptions'] ?? [];

                if (
                    !is_array($options) ||
                    !in_array('FIXED_PRICE', $options, true) ||
                    in_array('AUCTION', $options, true)
                ) {
                    continue;
                }

                $url = $raw['itemWebUrl'] ?? '';

                if (!is_string($url) || $url === '') {
                    continue;
                }

                $end = null;
                $end_date = $raw['itemEndDate'] ?? '';

                if ($end_date !== '') {
                    if (!is_string($end_date)) {
                        continue;
                    }

                    $end = strtotime($end_date);

                    if (!$end || $end <= $cutoff) {
                        continue;
                    }
                }

                $items[] = [
                    'title' => $raw['title'] ?? 'Untitled item',
                    'viewItemURL' => $url,
                    'imageURL' => $raw['image']['imageUrl'] ?? '',
                    'price' => (float) (
                        $raw['price']['value'] ?? 0
                    ),
                    'currency' => $raw['price']['currency'] ?? 'USD',
                    'endTimeUnix' => $end,
                ];
            }

            set_transient(
                $cache_key,
                $items,
                15 * MINUTE_IN_SECONDS
            );

            return $active_items($items);
        } finally {
            TCS_Ebay_API_Client::release_lock(
                $fetch_lock,
                $owner
            );
        }
    }

}
