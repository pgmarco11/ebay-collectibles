<?php
/** One GetItem response supplies category, description and the item-details form. */
function tcs_get_cached_ebay_item_xml($item_id) {
    global $env_ebay;
    $item_id = (string) $item_id;
    if (!preg_match('/^\d{9,20}$/', $item_id)) {
        return new WP_Error('tcs_ebay_item_id', 'Enter a valid numeric eBay item ID.');
    }
    $token = (string) ($env_ebay['EBAY_AUTH_TOKEN'] ?? '');
    if ($token === '') return new WP_Error('tcs_ebay_auth', 'The eBay seller token is missing.');
    $key = 'tcs_ebay_getitem_v1_' . md5($item_id . ':' . $token);
    $cached = get_transient($key);
    if (is_array($cached) && isset($cached['error'])) {
        return new WP_Error('tcs_ebay_item_cached_error', $cached['error']);
    }
    if (is_string($cached)) return TCS_Ebay_API_Client::parse_xml($cached);
    $lock = $key . '_lock';
    $owner = TCS_Ebay_API_Client::acquire_lock($lock);
    if (!$owner) return new WP_Error('tcs_ebay_item_busy', 'This eBay item is already being fetched. Try again shortly.');
    try {
        $cached = get_transient($key);
        if (is_string($cached)) return TCS_Ebay_API_Client::parse_xml($cached);
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
            . '<RequesterCredentials><eBayAuthToken>' . esc_xml($token) . '</eBayAuthToken></RequesterCredentials>'
            . '<ItemID>' . esc_xml($item_id) . '</ItemID><DetailLevel>ReturnAll</DetailLevel>'
            . '<IncludeItemSpecifics>true</IncludeItemSpecifics></GetItemRequest>';
        $response = TCS_Ebay_API_Client::request('trading', 'https://api.ebay.com/ws/api.dll', [
            'method' => 'POST',
            'headers' => ['X-EBAY-API-COMPATIBILITY-LEVEL' => '967', 'X-EBAY-API-CALL-NAME' => 'GetItem', 'X-EBAY-API-SITEID' => '0', 'Content-Type' => 'text/xml'],
            'body' => $body, 'timeout' => 15,
        ]);
        if (is_wp_error($response)) {
            set_transient($key, ['error' => $response->get_error_message()], MINUTE_IN_SECONDS);
            return $response;
        }
        $raw = wp_remote_retrieve_body($response);
        $xml = TCS_Ebay_API_Client::parse_xml($raw);
        if (wp_remote_retrieve_response_code($response) !== 200 || is_wp_error($xml) ||
            !in_array((string) $xml->Ack, ['Success', 'Warning'], true) || !isset($xml->Item)) {
            $error = new WP_Error('tcs_ebay_item_failed', 'eBay could not return this item. Try again later.');
            set_transient($key, ['error' => $error->get_error_message()], MINUTE_IN_SECONDS);
            return $error;
        }
        $auction = in_array((string) $xml->Item->ListingType, ['Chinese', 'Auction'], true);
        set_transient($key, $raw, ($auction ? 5 : 15) * MINUTE_IN_SECONDS);
        return $xml;
    } finally {
        TCS_Ebay_API_Client::release_lock($lock, $owner);
    }
}

function tcs_validate_ebay_selling_pages($pages) {
    $expected_total = null;
    $seen = [];

    if (!is_array($pages) || !$pages) {
        return new WP_Error(
            'tcs_inventory_empty_response',
            'No inventory pages were received.'
        );
    }

    foreach ($pages as $xml) {
        // Fail safely on warnings until specific harmless warnings
        // have been reviewed and explicitly allowed.
        if ((string) $xml->Ack !== 'Success') {
            return new WP_Error(
                'tcs_inventory_warning',
                'eBay reported an inventory warning or error.'
            );
        }

        $pagination = $xml->ActiveList->PaginationResult;

        if (
            !isset($pagination->TotalNumberOfEntries) ||
            !isset($pagination->TotalNumberOfPages)
        ) {
            return new WP_Error(
                'tcs_inventory_pagination',
                'Inventory pagination information is missing.'
            );
        }

        $total = (int) $pagination->TotalNumberOfEntries;
        $page_count = max(
            1,
            (int) $pagination->TotalNumberOfPages
        );

        if (
            $total < 0 ||
            $page_count !== count($pages) ||
            ($expected_total !== null && $total !== $expected_total)
        ) {
            return new WP_Error(
                'tcs_inventory_changed',
                'Inventory changed or pages are missing. Retry later.'
            );
        }

        $expected_total = $total;

        if (!isset($xml->ActiveList->ItemArray)) {
            continue;
        }

        foreach ($xml->ActiveList->ItemArray->Item as $item) {
            $id = (string) $item->ItemID;

            if (
                !preg_match('/^\d{9,20}$/', $id) ||
                isset($seen[$id])
            ) {
                return new WP_Error(
                    'tcs_inventory_duplicate',
                    'Inventory contains an invalid or duplicate item.'
                );
            }

            $seen[$id] = true;
        }
    }

    if (count($seen) !== $expected_total) {
        return new WP_Error(
            'tcs_inventory_incomplete',
            'Inventory is incomplete. Existing posts were preserved.'
        );
    }

    return true;
}

/** Fetch every seller-inventory page before imports change or remove any posts. */
function tcs_get_cached_ebay_selling_pages() {
    global $env_ebay;
    $token = (string) ($env_ebay['EBAY_AUTH_TOKEN'] ?? '');
    if ($token === '') return new WP_Error('tcs_ebay_auth', 'The eBay seller token is missing.');
    $key = 'tcs_ebay_selling_pages_v2_' . md5($token);
    $cached = get_transient($key);
    if (is_array($cached) && isset($cached['error'])) {
        return new WP_Error('tcs_ebay_inventory_failed', $cached['error']);
    }
    if (is_array($cached)) {
        $pages = [];
    
        foreach ($cached as $raw) {
            $xml = TCS_Ebay_API_Client::parse_xml($raw);
    
            if (is_wp_error($xml)) {
                set_transient(
                    $key,
                    ['error' => $xml->get_error_message()],
                    MINUTE_IN_SECONDS
                );
    
                return $xml;
            }
    
            $pages[] = $xml;
        }
    
        $validation = tcs_validate_ebay_selling_pages($pages);
    
        if (is_wp_error($validation)) {
            set_transient(
                $key,
                ['error' => $validation->get_error_message()],
                MINUTE_IN_SECONDS
            );
    
            return $validation;
        }
    
        return $pages;
    }
    $lock = $key . '_lock';
    $owner = TCS_Ebay_API_Client::acquire_lock($lock, 1800);
    if (!$owner) return new WP_Error('tcs_ebay_inventory_busy', 'Seller inventory is already being fetched. Try again shortly.');
    try {
        $pages = []; $raw_pages = []; $total_pages = 1;
        for ($page = 1; $page <= $total_pages; $page++) {
            $body = '<?xml version="1.0" encoding="utf-8"?>'
                . '<GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">'
                . '<RequesterCredentials><eBayAuthToken>' . esc_xml($token) . '</eBayAuthToken></RequesterCredentials>'
                . '<ActiveList><Include>true</Include><Sort>TimeLeft</Sort><Pagination>'
                . '<EntriesPerPage>100</EntriesPerPage><PageNumber>' . $page . '</PageNumber>'
                . '</Pagination></ActiveList><DetailLevel>ReturnAll</DetailLevel></GetMyeBaySellingRequest>';
            $response = TCS_Ebay_API_Client::request('trading', 'https://api.ebay.com/ws/api.dll', [
                'method' => 'POST', 'body' => $body, 'timeout' => 15,
                'headers' => ['X-EBAY-API-COMPATIBILITY-LEVEL' => '967', 'X-EBAY-API-CALL-NAME' => 'GetMyeBaySelling', 'X-EBAY-API-SITEID' => '0', 'Content-Type' => 'text/xml'],
            ]);
            if (is_wp_error($response)) {
                set_transient($key, ['error' => $response->get_error_message()], MINUTE_IN_SECONDS);
                return $response;
            }
            $raw = wp_remote_retrieve_body($response);
            $xml = TCS_Ebay_API_Client::parse_xml($raw);
            if (wp_remote_retrieve_response_code($response) !== 200 || is_wp_error($xml) ||
                !in_array((string) $xml->Ack, ['Success', 'Warning'], true)) {
                $error = new WP_Error('tcs_ebay_inventory_failed', 'eBay inventory retrieval failed. Existing posts were preserved.');
                set_transient($key, ['error' => $error->get_error_message()], MINUTE_IN_SECONDS);
                return $error;
            }
            if (!isset($xml->ActiveList->PaginationResult->TotalNumberOfPages)) {
                $error = new WP_Error('tcs_ebay_inventory_incomplete', 'eBay returned incomplete inventory pagination. Existing posts were preserved.');
                set_transient($key, ['error' => $error->get_error_message()], MINUTE_IN_SECONDS);
                return $error;
            }
            $reported = max(1, (int) $xml->ActiveList->PaginationResult->TotalNumberOfPages);
            if ($page > 1 && $reported !== $total_pages) {
                return new WP_Error('tcs_ebay_inventory_changed', 'Seller inventory changed during pagination. Retry the import.');
            }
            $total_pages = $reported;
            if ($total_pages > 100) {
                return new WP_Error('tcs_ebay_inventory_large', 'This inventory requires a batched import. Existing posts were preserved.');
            }
            $pages[] = $xml; $raw_pages[] = $raw;
        }
        $validation = tcs_validate_ebay_selling_pages($pages);

        if (is_wp_error($validation)) {
            set_transient(
                $key,
                ['error' => $validation->get_error_message()],
                MINUTE_IN_SECONDS
            );

            return $validation;
        }

        set_transient($key, $raw_pages, MINUTE_IN_SECONDS);

        return $pages;
    } finally {
        TCS_Ebay_API_Client::release_lock($lock, $owner);
    }
}

function tcs_ebay_selling_items($pages) {
    $items = [];
    foreach ($pages as $page) {
        if (!isset($page->ActiveList->ItemArray)) continue;
        foreach ($page->ActiveList->ItemArray->Item as $item) {
            $items[(string) $item->ItemID] = $item;
        }
    }
    return array_values($items);
}

/** Shared import lock; aborted fetches cannot reach inventory-deletion logic. */
function tcs_run_ebay_import($type, $force = false) {
    $lock = 'tcs_ebay_inventory_import_v1';
    $owner = TCS_Ebay_API_Client::acquire_lock($lock, 3600);
    if (!$owner) {
        $error = new WP_Error('tcs_ebay_import_busy', 'An eBay import is already running. Try again after it finishes.');
        echo '<div class="notice notice-warning"><p>' . esc_html($error->get_error_message()) . '</p></div>';
        return $error;
    }
    try {
        $cache = $type === 'auction' ? 'ebay_auctions_cache_v3' : 'ebay_buy_it_now_cache_v3';
        if ($force) delete_transient($cache);
        $items = $type === 'auction' ? fetch_ebay_auctions() : fetch_ebay_buy_it_now_collectibles();
        if (!is_array($items) || isset($items['error']) || !array_key_exists('items', $items)) {
            $message = is_array($items) ? ($items['error'] ?? 'eBay inventory retrieval failed.') : 'eBay inventory retrieval failed.';
            $error = new WP_Error('tcs_ebay_import_failed', $message);
            echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            return $error;
        }
        // Do not reconcile an empty response by deleting all existing posts.
        if (!$items['items']) {
            echo '<div class="notice notice-warning"><p>No active listings of this type were found. Existing posts were preserved.</p></div>';
            return true;
        }
        $result = $type === 'auction'
            ? create_auction_posts_unlocked($items)
            : create_buy_it_now_posts_unlocked($items);
        
        if (is_wp_error($result)) {
            echo '<div class="notice notice-error"><p>' .
                esc_html($result->get_error_message()) .
                '</p></div>';
        
            return $result;
        }
        
        if ($result !== true) {
            $error = new WP_Error(
                'tcs_import_no_result',
                'The import did not finish successfully. ' .
                'Some changes may already have been applied.'
            );
        
            echo '<div class="notice notice-error"><p>' .
                esc_html($error->get_error_message()) .
                '</p></div>';
        
            return $error;
        }
        
        return true;
    } finally {
        TCS_Ebay_API_Client::release_lock($lock, $owner);
    }
}
