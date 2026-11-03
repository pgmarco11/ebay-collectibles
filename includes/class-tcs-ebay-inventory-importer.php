<?php
defined('ABSPATH') || exit;

/** Owns ebay inventory importer behavior; legacy function entry points delegate here. */
class TCS_Ebay_Inventory_Importer {
    public function run($type, $force = false) {
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
            
            $items = $type === 'auction'
                ? fetch_ebay_auctions($force)
                : fetch_ebay_buy_it_now_collectibles($force);

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

}
