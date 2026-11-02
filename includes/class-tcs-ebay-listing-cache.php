<?php
defined('ABSPATH') || exit;

class TCS_Ebay_Listing_Cache {
    private static function key($type, $phrase, $limit, $keywords) {
        return 'tcs_listing_bg_v1_' . md5(wp_json_encode([
            $type,
            $phrase,
            $limit,
            $keywords,
        ]));
    }

    public static function read($type, $phrase, $limit, $keywords = '') {
        $phrase = trim((string) $phrase);
        $keywords = trim((string) $keywords);
        $limit = max(1, min(200, absint($limit)));

        $key = self::key($type, $phrase, $limit, $keywords);
        $record = get_transient($key);

        if (!is_array($record)) {
            $record = get_option($key, []);
        }
        
        $record = is_array($record) ? $record : [];
        $now = time();

        $cooldown = max(
            (int) get_option('tcs_ebay_api_cooldown_browse', 0),
            (int) get_option('tcs_ebay_browse_cooldown', 0)
        );
        
        if (
            (int) ($record['refresh_after'] ?? 0) <= $now &&
            $cooldown <= $now
        ) {
            $args = [$type, $phrase, $limit, $keywords];

            $owner = TCS_Ebay_API_Client::acquire_lock(
                $key . '_queue',
                10
            );

            if ($owner) {
                try {
                    if (!wp_next_scheduled('tcs_ebay_refresh_listings', $args)) {
                        wp_schedule_single_event(
                            $now,
                            'tcs_ebay_refresh_listings',
                            $args
                        );
                    }
                } finally {
                    TCS_Ebay_API_Client::release_lock(
                        $key . '_queue',
                        $owner
                    );
                }
            }
        }

        if (
            !isset($record['data']) ||
            !is_array($record['data']) ||
            (int) ($record['usable_until'] ?? 0) <= $now
        ) {
            $GLOBALS['tcs_ebay_listings_pending'] = true;
        
            return $type === 'auction' ? false : [];
        }

        $active = static function ($items) use ($type, $now) {
            return array_values(array_filter(
                is_array($items) ? $items : [],
                static function ($item) use ($type, $now) {
                    if (!is_array($item)) {
                        return false;
                    }

                    $end = (int) ($item['endTimeUnix'] ?? 0);

                    return $type === 'auction'
                        ? $end > $now + 300
                        : (!$end || $end > $now + 300);
                }
            ));
        };

        if ($type === 'auction') {
            $data = [];

            foreach (['hot', 'bids', 'ending'] as $tab) {
                $data[$tab] = $active($record['data'][$tab] ?? []);
            }

            return !empty($data['hot'])
                || !empty($data['bids'])
                || !empty($data['ending'])
                    ? $data
                    : false;
        }

        return $active($record['data'] ?? []);
    }

    public static function refresh($type, $phrase, $limit, $keywords) {
        if (!in_array($type, ['auction', 'buynow'], true)) {
            return;
        }

        $key = self::key($type, $phrase, $limit, $keywords);

        $owner = TCS_Ebay_API_Client::acquire_lock(
            $key . '_worker',
            180
        );

        if (!$owner) {
            return;
        }

        try {
            $record = get_transient($key);

            if (!is_array($record)) {
                $record = get_option($key, []);
            }
            
            $record = is_array($record) ? $record : [];

            if ((int) ($record['refresh_after'] ?? 0) > time()) {
                return;
            }

            $now = time();

            $cooldown = max(
                (int) get_option('tcs_ebay_api_cooldown_browse', 0),
                (int) get_option('tcs_ebay_browse_cooldown', 0)
            );
            
            if ($cooldown > $now) {
                return;
            }
            
            // Reuse an existing fetcher cache instead of deleting it.

            $data = $type === 'auction'
                ? tcs_fetch_ranked_items_now($phrase, $limit, $keywords)
                : tcs_fetch_buy_now_candidates_now($phrase, $limit, $keywords);

            $now = time();

            if (is_wp_error($data) || !is_array($data)) {
                $error_data = is_wp_error($data)
                    ? $data->get_error_data()
                    : [];

                $retry = is_array($error_data)
                    ? (int) ($error_data['retry_after'] ?? 300)
                    : 300;

                $record['refresh_after'] = max(
                    $now + max(60, $retry),
                    (int) get_option('tcs_ebay_api_cooldown_browse', 0),
                    (int) get_option('tcs_ebay_browse_cooldown', 0)
                );

                // Failures never extend the age of saved listings.
            } else {
                $fresh = $type === 'auction'
                    ? 5 * MINUTE_IN_SECONDS
                    : 15 * MINUTE_IN_SECONDS;
                
                $max_age = $type === 'auction'
                    ? 15 * MINUTE_IN_SECONDS
                    : HOUR_IN_SECONDS;

                $record = [
                    'data' => $data,
                    'refresh_after' => $now + $fresh,
                    'usable_until' => $now + $max_age,
                ];
            }

            set_transient(
                $key,
                $record,
                max(
                    60,
                    (int) ($record['usable_until'] ?? 0) - $now,
                    (int) $record['refresh_after'] - $now
                )
            );

            // Preserve the last completed results when the transient expires.
            update_option($key, $record, false);

        } finally {
            TCS_Ebay_API_Client::release_lock(
                $key . '_worker',
                $owner
            );
        }
    }
}

add_action(
    'tcs_ebay_refresh_listings',
    ['TCS_Ebay_Listing_Cache', 'refresh'],
    10,
    4
);

function fetch_ebay_ranked_items(
    $category_slug,
    $limit = 10,
    $search_keywords = ''
) {
    return TCS_Ebay_Listing_Cache::read(
        'auction',
        $category_slug,
        $limit,
        $search_keywords
    );
}

function tcs_top_items_buy_now_candidates(
    $phrase,
    $limit,
    $search_keywords = ''
) {
    return TCS_Ebay_Listing_Cache::read(
        'buynow',
        $phrase,
        $limit,
        $search_keywords
    );
}

// Remove the old self-refreshing category jobs once.
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    $version_key = 'tcs_ebay_demand_cache_migration_v1';

    if (get_option($version_key)) {
        return;
    }

    $result = wp_unschedule_hook(
        'tcs_ebay_refresh_listings',
        true
    );

    if (is_wp_error($result) || $result === false) {
        error_log(
            'eBay listing queue cleanup failed; it will retry.'
        );
        return;
    }

    update_option($version_key, 1, false);
});