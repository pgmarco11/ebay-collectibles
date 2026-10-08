<?php
/** Shared transport and quota guards. Listing caches remain with their fetchers. */
class TCS_Ebay_API_Client {
    public static function acquire_lock($name, $seconds = 60) {
        $lock = get_option($name, false);
        if (is_array($lock) && ($lock['expires'] ?? 0) < time()) {
            delete_option($name);
        }
        $owner = wp_generate_uuid4();
        return add_option($name, ['owner' => $owner, 'expires' => time() + $seconds], '', false)
            ? $owner : false;
    }

    public static function release_lock($name, $owner) {
        $lock = get_option($name, false);
        if (is_array($lock) && ($lock['owner'] ?? '') === $owner) {
            delete_option($name);
        }
    }

    public static function request($api, $url, $args = []) {
        $paths = [
            'browse' => '/buy/browse/',
            'taxonomy' => '/commerce/taxonomy/',
            'trading' => '/ws/api.dll',
            'oauth' => '/identity/v1/oauth2/token',
        ];
        $host = wp_parse_url($url, PHP_URL_HOST);
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (!isset($paths[$api]) || $host !== 'api.ebay.com' ||
            wp_parse_url($url, PHP_URL_SCHEME) !== 'https' ||
            strpos($path, $paths[$api]) !== 0) {
            return new WP_Error('tcs_ebay_endpoint', 'Invalid eBay API endpoint.');
        }

        $cooldown = (int) get_option('tcs_ebay_api_cooldown_' . $api, 0);
        // Honor a cooldown recorded by the previous shortcode implementation.
        if ($api === 'browse') {
            $cooldown = max($cooldown, (int) get_option('tcs_ebay_browse_cooldown', 0));
        }
        if ($cooldown > time()) {
            return new WP_Error('tcs_ebay_backoff', 'eBay requests are temporarily paused. Try again later.', ['retry_after' => $cooldown - time()]);
        }

        $lock_name = 'tcs_ebay_budget_lock_' . $api;
        $owner = self::acquire_lock($lock_name, 15);
        if (!$owner) {
            return new WP_Error('tcs_ebay_busy', 'Another eBay request is being reserved. Try again shortly.');
        }
        try {
            $limits = apply_filters('tcs_ebay_api_budgets', [
                'browse' => 4000, 'trading' => 4000, 'taxonomy' => 4000, 'oauth' => 800,
            ]);
            $limit = max(0, (int) ($limits[$api] ?? 0));
            $hour = (int) floor(time() / HOUR_IN_SECONDS);
            $key = 'tcs_ebay_api_calls_' . $api;
            $buckets = get_option($key, []);
            $buckets = is_array($buckets) ? $buckets : [];
            // Conservatively include the entire oldest hour of the trailing day.
            foreach ($buckets as $bucket => $count) {
                if ((int) $bucket < $hour - 24) unset($buckets[$bucket]);
            }
            if ($api === 'browse') {
                // Count prior shortcode requests during migration; do not reset usage.
                $old = get_option('tcs_ebay_shortcode_browse_calls', []);
                foreach (is_array($old) ? $old : [] as $bucket => $count) {
                    if ((int) $bucket >= $hour - 24) {
                        $buckets[$bucket] = (int) ($buckets[$bucket] ?? 0) + (int) $count;
                    }
                }
                delete_option('tcs_ebay_shortcode_browse_calls');
            }
            if (array_sum($buckets) >= $limit) {
                update_option($key, $buckets, false);
                return new WP_Error('tcs_ebay_budget', 'The local eBay API budget has been reached. Try again later.');
            }
            $minute = (int) floor(time() / MINUTE_IN_SECONDS);
            $burst_key = 'tcs_ebay_api_burst_' . $api;
            $burst = get_option($burst_key, []);
            $burst = is_array($burst) ? $burst : [];
            foreach ($burst as $bucket => $count) {
                if ((int) $bucket < $minute - 1) unset($burst[$bucket]);
            }
            $burst_limit = $api === 'oauth' ? 10 : 120;
            if (array_sum($burst) >= $burst_limit) {
                update_option($key, $buckets, false);
                return new WP_Error('tcs_ebay_burst', 'Too many eBay requests in a short period. Try again shortly.');
            }
            $buckets[$hour] = (int) ($buckets[$hour] ?? 0) + 1;
            $burst[$minute] = (int) ($burst[$minute] ?? 0) + 1;
            update_option($key, $buckets, false);
            update_option($burst_key, $burst, false);
        } finally {
            self::release_lock($lock_name, $owner);
        }

        $args['timeout'] = min(30, max(1, (int) ($args['timeout'] ?? 15)));
        $args['redirection'] = 0;
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) return $response;

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $limited = $status === 429 || ($status === 403 && preg_match('/rate.?limit|call.?limit|quota/i', $body));
        if ($api === 'trading') {
            // Trading can report quota errors within an HTTP 200 XML response.
            $xml = self::parse_xml($body);
            if (!is_wp_error($xml)) {
                foreach ($xml->Errors as $error) {
                    $message = (string) $error->ShortMessage . ' ' . (string) $error->LongMessage;
                    if (preg_match('/call.?limit|rate.?limit|quota|exceeded.*(?:call|request)|(?:call|request).*exceeded/i', $message)) {
                        $limited = true;
                    }
                }
            }
        }
        if ($limited) {
            $retry = trim((string) wp_remote_retrieve_header($response, 'retry-after'));
            $date = $retry !== '' && !preg_match('/^\d+$/', $retry) ? strtotime($retry) : false;
            $wait = preg_match('/^\d+$/', $retry) ? max(60, (int) $retry)
                : ($date ? max(60, $date - time()) : HOUR_IN_SECONDS);
            update_option('tcs_ebay_api_cooldown_' . $api, time() + $wait, false);
            return new WP_Error('tcs_ebay_backoff', 'eBay has limited requests. Try again later.', ['retry_after' => $wait]);
        }
        if ($status >= 500) {
            update_option('tcs_ebay_api_cooldown_' . $api, time() + 60, false);
        }
        return $response;
    }

    public static function parse_xml($body) {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $xml ?: new WP_Error('tcs_ebay_xml', 'eBay returned an invalid XML response.');
    }

    public static function throttle($action, $limit = 12) {
        // Do not trust client-supplied forwarded IP headers.
        $identity = get_current_user_id() ? 'user:' . get_current_user_id()
            : 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $key = 'tcs_ebay_ajax_' . hash_hmac('sha256', $action . ':' . $identity, wp_salt('nonce'));
        $owner = self::acquire_lock($key . '_lock', 10);
        if (!$owner) return false;
        try {
            $count = (int) get_transient($key);
            if ($count >= $limit) return false;
            // Sliding inactivity window; repeated requests cannot reset the count downward.
            set_transient($key, $count + 1, MINUTE_IN_SECONDS);
            return true;
        } finally {
            self::release_lock($key . '_lock', $owner);
        }
    }
}
