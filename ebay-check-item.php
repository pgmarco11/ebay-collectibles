<?php 

add_shortcode('ebay_item_form', 'render_ebay_item_form');

function render_ebay_item_form() {
    ob_start();
    ?>
    <div id="ebay-item-container">
        <form id="ebay-item-form">
            <input type="text" name="item_id" placeholder="eBay Item ID" required>    
            <button type="button" id="check-item">Check Item</button>
        </form>
        <div id="item-details"></div> 
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            const itemId = params.get('item_id');

            if (itemId) {
                const input = document.querySelector('#ebay-item-form input[name="item_id"]');
                const button = document.querySelector('#check-item');

                if (input && button) {
                    input.value = itemId;

                    // Give the DOM time to process the input, then simulate a click
                    setTimeout(() => {
                        button.click();
                    }, 300); // adjust delay if needed
                }
            }         
        });
    </script>
    <?php return ob_get_clean();
}

// Handle AJAX request
add_action('wp_ajax_get_ebay_item_details', 'handle_get_ebay_item_details');
add_action('wp_ajax_nopriv_get_ebay_item_details', 'handle_get_ebay_item_details');

function handle_get_ebay_item_details() {
    $item_id = sanitize_text_field($_GET['item_id']);
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
    global $env_ebay;

    $devID     = $env_ebay['EBAY_DEV_ID'];
    $appID     = $env_ebay['EBAY_PRODUCTION_APPID'];
    $certID    = $env_ebay['EBAY_CLIENT_SECRET'];
    $userToken = $env_ebay['EBAY_AUTH_TOKEN']; // eBay user token (if using Auth'n'Auth)
    $endpoint  = 'https://api.ebay.com/ws/api.dll'; 

    $xml_request = '<?xml version="1.0" encoding="utf-8"?>
    <GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
      <RequesterCredentials>
        <eBayAuthToken>' . $userToken . '</eBayAuthToken>
      </RequesterCredentials>
      <ItemID>' . $item_id . '</ItemID>
      <DetailLevel>ReturnAll</DetailLevel>
      <IncludeItemSpecifics>true</IncludeItemSpecifics>
    </GetItemRequest>';

    $headers = [
        'X-EBAY-API-CALL-NAME'      => 'GetItem',
        'X-EBAY-API-SITEID'         => '0',
        'X-EBAY-API-COMPATIBILITY-LEVEL' => '967',
        'X-EBAY-API-DEV-NAME'       => $devID,
        'X-EBAY-API-APP-NAME'       => $appID,
        'X-EBAY-API-CERT-NAME'      => $certID,
        'Content-Type'              => 'text/xml'
    ];

    $response = wp_remote_post($endpoint, [
        'headers' => $headers,
        'body'    => $xml_request
    ]);

    if (is_wp_error($response)) {
        return ['error' => $response->get_error_message()];
    }

    $body = wp_remote_retrieve_body($response);
    $xml  = simplexml_load_string($body);
    $itemList = $xml->Item->ItemSpecifics->NameValueList;

    if ($xml->Ack != 'Success') {
        return ['error' => (string) $xml->Errors->LongMessage];
    }

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


