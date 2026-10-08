<?php 

add_shortcode('ebay_item_form', 'render_ebay_item_form');

function render_ebay_item_form() {
    ob_start();
    ?>
    <section id="ebay-item-container" aria-labelledby="ebay-tool-heading">
        <header class="ebay-tool-header">
            <p class="ebay-tool-eyebrow">CollectibleSpot Marketplace Tool</p>
            <h1 id="ebay-tool-heading">Find an eBay Item</h1>
            <p class="ebay-tool-intro">
                Search active eBay listings, choose the correct item, and inspect
                its complete listing details.
            </p>
        </header>

        <div class="ebay-tool-panel ebay-search-section">
            <div class="ebay-panel-heading">
                <span class="ebay-step-number" aria-hidden="true">1</span>

                <div>
                    <h2>Search eBay</h2>
                    <p>Enter an item title, model number, or identifying keywords.</p>
                </div>
            </div>

            <form id="ebay-title-search-form">
                <label for="ebay-title-search">
                    Item title or keywords
                </label>

                <div class="ebay-search-controls">
                    <input
                        type="search"
                        id="ebay-title-search"
                        name="search_query"
                        placeholder="Example: 1988 Tiger Electronics Mega Man 2"
                        minlength="3"
                        autocomplete="off"
                        enterkeyhint="search"
                        required
                    >

                    <button type="submit" id="search-ebay">
                        Search eBay
                    </button>
                </div>
            </form>

            <div
                id="ebay-search-message"
                role="status"
                aria-live="polite"
                aria-atomic="true"
            ></div>

            <div id="ebay-search-results"></div>
        </div>

        <div class="ebay-tool-divider">
            <span>or enter an ID directly</span>
        </div>

        <div class="ebay-tool-panel ebay-check-section">
            <div class="ebay-panel-heading">
                <span class="ebay-step-number" aria-hidden="true">2</span>

                <div>
                    <h2>Check Item ID</h2>
                    <p>Select a search result above or enter a numeric eBay Item ID.</p>
                </div>
            </div>

            <form id="ebay-item-form">
                <label class="screen-reader-text" for="ebay-item-id">
                    eBay Item ID
                </label>

                <input
                    type="text"
                    id="ebay-item-id"
                    name="item_id"
                    inputmode="numeric"
                    pattern="[0-9]+"
                    placeholder="Enter eBay Item ID"
                    required
                >

                <button type="submit" id="check-item">
                    Check Item
                </button>
            </form>
        </div>

        <div
            id="item-details"
            aria-live="polite"
        ></div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            const itemId = params.get('item_id');

            if (!itemId || !/^\d+$/.test(itemId)) {
                return;
            }

            const input = document.querySelector('#ebay-item-id');
            const form  = document.querySelector('#ebay-item-form');

            if (input && form) {
                input.value = itemId;

                setTimeout(function() {
                    form.requestSubmit();
                }, 300);
            }
        });
    </script>
    <?php

    return ob_get_clean();
}

add_action(
    'wp_ajax_search_ebay_items',
    'handle_search_ebay_items'
);

add_action(
    'wp_ajax_nopriv_search_ebay_items',
    'handle_search_ebay_items'
);

/**
 * Extract the numeric legacy listing ID returned inside a Browse API item ID.
 *
 * Browse IDs normally look like:
 * v1|123456789012|0
 */
function ebay_get_legacy_item_id(array $item) {
    $browse_item_id = isset($item['itemId'])
        ? (string) $item['itemId']
        : '';

    if (
        preg_match(
            '/^v1\|(\d+)\|/',
            $browse_item_id,
            $matches
        )
    ) {
        return $matches[1];
    }

    /*
     * Fallback for any response that provides a numeric ID directly.
     */
    if (ctype_digit($browse_item_id)) {
        return $browse_item_id;
    }

    /*
     * Final fallback: extract the ID from the public item URL.
     */
    $item_url = isset($item['itemWebUrl'])
        ? (string) $item['itemWebUrl']
        : '';

    if (
        preg_match(
            '~/(?:itm|p)/[^/?#]*/?(\d{9,})~',
            $item_url,
            $matches
        )
    ) {
        return $matches[1];
    }

    return '';
}

function handle_search_ebay_items() {
    check_ajax_referer(
        'ebay_nonce',
        'nonce'
    );

    $query = isset($_GET['query'])
        ? sanitize_text_field(wp_unslash($_GET['query']))
        : '';

    if (mb_strlen($query) < 3) {
        wp_send_json_error(
            [
                'message' => 'Enter at least three characters.',
            ],
            400
        );
    }

    if (!TCS_Ebay_API_Client::throttle('search', 12)) {
        wp_send_json_error(['message' => 'Too many searches. Wait a minute before trying again.'], 429);
    }
    $cache_key = 'tcs_ebay_title_search_v1_' . md5($query);
    $cached = get_transient($cache_key);
    if (is_array($cached)) {
        $response = $cached;
    } else {
        $access_token = get_transient('ebay_oauth_token')
            ?: get_ebay_oauth_token();

        if (!$access_token) {
            wp_send_json_error(
                [
                    'message' => 'Unable to obtain an eBay access token.',
                ],
                500
            );
        }

        $url = add_query_arg(
            [
                'q'     => $query,
                'limit' => 12,
            ],
            'https://api.ebay.com/buy/browse/v1/item_summary/search'
        );

        $response = TCS_Ebay_API_Client::request(
            'browse',
            $url,
            [
                'headers' => [
                    'Authorization'                  => 'Bearer ' . $access_token,
                    'X-EBAY-C-MARKETPLACE-ID'       => 'EBAY_US',
                    'X-EBAY-C-ENDUSERCTX'           => 'contextualLocation=country=US',
                    'Accept'                        => 'application/json',
                ],
                'timeout' => 12,
            ]
        );

    }

    if (is_wp_error($response)) {
        error_log(
            'eBay title search failed: ' .
            $response->get_error_message()
        );

        wp_send_json_error(
            [
                'message' => 'The eBay search request failed.',
            ],
            500
        );
    }

    $status = wp_remote_retrieve_response_code($response);
    $body   = json_decode(
        wp_remote_retrieve_body($response),
        true
    );

    if ($status !== 200) {
        $message = $body['errors'][0]['message']
            ?? 'eBay returned an unsuccessful response.';

        error_log(
            sprintf(
                'eBay title search HTTP %d: %s',
                $status,
                $message
            )
        );

        wp_send_json_error(
            [
                'message' => $message,
            ],
            $status
        );
    }

    if (!is_array($cached)) {
        set_transient($cache_key, [
            'response' => ['code' => 200, 'message' => 'OK'],
            'body' => wp_json_encode($body), 'headers' => [], 'cookies' => [],
        ], 5 * MINUTE_IN_SECONDS);
    }

    $results = [];

    foreach ($body['itemSummaries'] ?? [] as $item) {
        $legacy_item_id = ebay_get_legacy_item_id($item);

        /*
         * The existing GetItem request requires the numeric legacy ID.
         */
        if (!$legacy_item_id) {
            continue;
        }

        $shipping_cost = '';

        if (isset($item['shippingOptions'][0]['shippingCost']['value'])) {
            $shipping_cost = (string)
                $item['shippingOptions'][0]['shippingCost']['value'];
        }

        $results[] = [
            'item_id'       => $legacy_item_id,
            'title'         => sanitize_text_field(
                $item['title'] ?? 'Untitled item'
            ),
            'image_url'     => esc_url_raw(
                $item['image']['imageUrl'] ?? ''
            ),
            'item_url'      => esc_url_raw(
                $item['itemWebUrl'] ?? ''
            ),
            'price'         => sanitize_text_field(
                $item['price']['value'] ?? ''
            ),
            'currency'      => sanitize_text_field(
                $item['price']['currency'] ?? 'USD'
            ),
            'condition'     => sanitize_text_field(
                $item['condition'] ?? ''
            ),
            'shipping_cost' => sanitize_text_field(
                $shipping_cost
            ),
            'buying_options' => array_values(
                array_map(
                    'sanitize_text_field',
                    $item['buyingOptions'] ?? []
                )
            ),
        ];
    }

    wp_send_json_success(
        [
            'items' => $results,
        ]
    );
}

// Handle AJAX request
add_action('wp_ajax_get_ebay_item_details', 'handle_get_ebay_item_details');
add_action('wp_ajax_nopriv_get_ebay_item_details', 'handle_get_ebay_item_details');

function handle_get_ebay_item_details() {
    check_ajax_referer('ebay_nonce', 'nonce');
    if (!TCS_Ebay_API_Client::throttle('details', 12)) {
        status_header(429);
        echo '<p class="error">Too many item requests. Wait a minute before trying again.</p>';
        wp_die();
    }
    $item_id = isset($_GET['item_id']) ? sanitize_text_field(wp_unslash($_GET['item_id'])) : '';
    if (!preg_match('/^\d{9,20}$/', $item_id)) {
        status_header(400);
        echo '<p class="error">Enter a valid numeric eBay item ID.</p>';
        wp_die();
    }
    $item_info = get_ebay_item_info($item_id);

    $ebay_item_id = get_post_meta(get_the_ID(), 'ebay_item_id', true);    

    if (isset($item_info['error'])) {
        echo '<p class="error">Error: ' . esc_html($item_info['error']) . '</p>';
        wp_die();
    }    

    $output = '<div class="ebay-item-card">';
    $output .= '<div class="ebay-item-header">';
    $output .= '<h2>' . esc_html($item_info['title']) . '</h2>';
    $output .= '</div>';
    
    $output .= '<div class="ebay-item-body">';
    $output .= '<div class="ebay-item-image">';
    $output .= '<img src="' . esc_url($item_info['pictureURL']) . '" alt="' . esc_attr($item_info['title']) . '">';
    $output .= '</div>';    
    $output .= '<div class="ebay-item-meta">';
    $output .= '<ul class="item-meta-list">';

    if ($item_info['ListingType'] !== 'FixedPriceItem'):
        $output .= '<li><strong>Listing Type:</strong> Auction</li>';
        $output .= '<li><strong>Bids:</strong> ' . esc_html($item_info['bidCount']) . '</li>';
        $output .= '<li><strong>Current Bid:</strong> ' . esc_html($item_info['currentPrice']) . ' USD</li>';
        $output .= '<li><strong>Minimum Bid:</strong> ' . esc_html($item_info['minimumBid']) . ' USD</li>';
    else:
        $output .= '<li><strong>Listing Type:</strong> Buy Now</li>';
        $output .= '<li><strong>Current Price:</strong> ' . esc_html($item_info['currentPrice']) . ' USD</li>';
    endif;

    $output .= '<li><strong>Time Left:</strong> ' . esc_html(format_time_remaining($item_info['endTime'])) . '</li>';

    $output .= '<li><strong>Condition:</strong> ' . esc_html($item_info['conditionDisplayName']) . '<p>' . esc_html($item_info['conditionDescription']) . '</p></li>';
    $output .= '<li><strong>Seller:</strong> ' . esc_html($item_info['sellerUsername']) . '</li>';
    $output .= '<li><strong>Feedback:</strong> ' . esc_html($item_info['sellerFeedback']) . '% since ' . esc_html($item_info['sellerJoinYear']) . '</li>';
    $output .= '<li><strong>Shipping:</strong> ' . esc_html($item_info['shippingType']) . '</li>';
    $output .= '<li class="pt-2"><strong>eBay Link:</strong> <a href="' . esc_url("https://www.ebay.com/itm/" . $item_id) . '" target="_blank" rel="noopener">' . esc_html("https://www.ebay.com/itm/" . $item_id) . '</a></li>';
    $output .= '</ul>';
    $output .= '</div>'; // .ebay-item-meta
    $output .= '</div>'; // .ebay-item-body
    
    if (!empty($item_info['itemSpecifics'])) {
        $allowed_keys = [
            'Issue Number', 'Grade', 'Vintage', 'Features', 'Signed', 'Artist/Writer', 'Cover Artist',
            'Universe',  'Publisher', 'Inscribed', 'Certification',
            'Intended Audience', 'Publication Year', 'Type', 'Era'
        ];
    
        $output .= '<div class="ebay-item-specifics">';
        $output .= '<h3>Item Specifics</h3><ul>';
        foreach ($item_info['itemSpecifics'] as $name => $value) {
            if (in_array($name, $allowed_keys)) {
                $output .= '<li><strong>' . esc_html($name) . ':</strong> ' . esc_html($value) . '</li>';
            }
        }
        $output .= '</ul></div>';
    }
    
    $output .= '<div class="ebay-item-footer">';
    $output .= '<a href="https://www.ebay.com/sch/i.html?_nkw=' . urlencode($item_info['title']) . '&_sop=13" target="_blank" class="view-similar">View similar items</a>';
   
    if(is_user_logged_in()):
        
        $user_wishlist = get_user_meta(get_current_user_id(), 'user_wishlist', true);
        $in_wishlist = false;
        
        if (is_array($user_wishlist)) {
            foreach ($user_wishlist as $wish_item) {
                if (isset($wish_item['item_id']) && $wish_item['item_id'] == $item_id) {
                    $in_wishlist = true;
                    break;
                }
            }
        }
        
        $wishlist_class = $in_wishlist ? 'add-to-wishlist in-wishlist' : 'add-to-wishlist';
        
        $output .= '<button class="' . esc_attr($wishlist_class) . '" 
            data-type="post"
            data-item-id="' . esc_attr($item_id) . '" 
            data-title="' . esc_attr($item_info['title']) . '"
            data-ebay-id="' . esc_attr($ebay_item_id) . '"
            data-item-url="https://www.ebay.com/itm/' . esc_attr($item_id)  . '"
            data-image-url="' . esc_url($item_info['pictureURL']) . '"
            >' . ($in_wishlist ? 'In Wishlist' : 'Add to Wishlist') . '</button>';

    endif;

    $output .= '</div>';
    
    $output .= '</div>'; // .ebay-item-card

    echo $output;
    wp_die();
}
//Helper Functions - Fetch eBay item info - Format time
function get_ebay_item_info($item_id) {
    $xml = tcs_get_cached_ebay_item_xml($item_id);
    if (is_wp_error($xml)) return ['error' => $xml->get_error_message()];
    $itemList = $xml->Item->ItemSpecifics->NameValueList;

     $item_specifics = [];
   
     if (isset($itemList)) {
        
        foreach ($itemList as $nvl) {
            $name = (string) $nvl->Name;
            $value = (string) $nvl->Value;          
            $item_specifics[$name] = $value;
        }  
     }

    return [
        'title'                => (string) $xml->Item->Title,
        'currentPrice'         => (string) $xml->Item->SellingStatus->CurrentPrice,
        'minimumBid'           => (string) $xml->Item->SellingStatus->MinimumToBid,
        'endTime'              => (string) $xml->Item->ListingDetails->EndTime,
        'bidCount'             => (string) $xml->Item->SellingStatus->BidCount,
        'sellerUsername'       => (string) $xml->Item->Seller->UserID,
        'sellerFeedback'       => (string) $xml->Item->Seller->PositiveFeedbackPercent,
        'sellerJoinYear'       => date('Y', strtotime((string) $xml->Item->Seller->RegistrationDate)),
        'shippingType'         => (string) $xml->Item->ShippingDetails->ShippingType,
        'pictureURL'           => (string) $xml->Item->PictureDetails->PictureURL[0],
        'conditionDisplayName' => (string) $xml->Item->ConditionDisplayName,
        'conditionDescription' => (string) $xml->Item->ConditionDescription,
        'ListingType'          => (string) $xml->Item->ListingType,
        'itemSpecifics'        => $item_specifics
    ];
}
function format_time_remaining($end_time) {
    $diff = strtotime($end_time) - time();
    if ($diff <= 0) return 'Ended';
    $hours = floor($diff / 3600);
    $minutes = floor(($diff % 3600) / 60);
    return "{$hours}h {$minutes}m";
}



