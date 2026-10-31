<?php

require_once __DIR__ . '/includes/class-tcs-ebay-buy-now-importer.php';

function tcs_ebay_buy_now_importer(): TCS_Ebay_Buy_Now_Importer {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Ebay_Buy_Now_Importer();
    }
    return $instance;
}

// ebay-selling-fetcher.php
function fetch_ebay_buy_it_now_collectibles() {
    global $env_ebay;

    // Check cache first
    $cached = get_transient('ebay_buy_it_now_cache_v3');
    if ($cached !== false && !empty($cached)) {
        error_log("Returning cached eBay Buy It Now data: " . json_encode($cached, JSON_PRETTY_PRINT));
        return $cached;
    }

    $auth_token = $env_ebay['EBAY_AUTH_TOKEN'] ?? '';

    if (empty($auth_token)) {
        error_log("Error: Missing EBAY_AUTH_TOKEN in ebay.env");
        return false;
    }

    $pages = tcs_get_cached_ebay_selling_pages();
    if (is_wp_error($pages)) return ['error' => $pages->get_error_message()];
    $xml = $pages[0];

    $data = array('items' => []);

    if (tcs_ebay_selling_items($pages)) {
        foreach (tcs_ebay_selling_items($pages) as $item) {

            $item_id = (string)$item->ItemID;
    
            if ((string)$item->ListingType === 'FixedPriceItem') {
                $category_name = get_item_category($item->ItemID, $auth_token); 
                if (!$category_name) {
                    return ['error' => 'Could not fetch a Buy It Now item category. Existing posts were preserved.'];    
                }
    
                // Improved image URL extraction
                $image_url = '';
                $item_description = isset($item->ListingDetails->Description) ? (string)$item->ListingDetails->Description : '';

                 // Fetch description via GetItem if missing
                if ($item_description === '') {            
                    $item_details = get_item_details($item_id, $auth_token);
                    if (is_array($item_details)) {                   
                        $item_description = $item_details['description'];                
                    } else {
                        return ['error' => 'Could not fetch complete Buy It Now item details. Existing posts were preserved.'];
                    }
                }
                
                if (isset($item->PictureDetails)) {
                    if (isset($item->PictureDetails->PictureURL)) {
                        $pics = $item->PictureDetails->PictureURL;
                        if (is_array($pics) || count($pics) > 1) {
                            $image_url = (string) $pics[count($pics) - 1];
                        } else {
                            $image_url = (string) $pics;
                        }
                    } elseif (isset($item->PictureDetails->GalleryURL)) {
                        $image_url = (string) $item->PictureDetails->GalleryURL;
                    }
                }
    
                $data['items'][] = array(
                    'itemId' => (string)$item->ItemID,
                    'title' => (string)$item->Title,
                    'viewItemURL' => (string)$item->ListingDetails->ViewItemURL,
                    'currentPrice' => (float)$item->SellingStatus->CurrentPrice,
                    'imageURL' => $image_url,
                    'categoryName' => $category_name, 
                    'description' => $item_description,                   
                );
            }
        }
    }

    set_transient('ebay_buy_it_now_cache_v3', $data, 15 * MINUTE_IN_SECONDS);
    return $data;
}


function create_buy_it_now_posts($force = false) {
    return tcs_run_ebay_import('buynow', $force);
}

function create_buy_it_now_posts_unlocked($items) {
    return tcs_ebay_buy_now_importer()->synchronize($items);
}
add_action('ebay_update_buy_it_now_posts', 'create_buy_it_now_posts');

// Schedule weekly refresh
function schedule_ebay_buy_it_now_refresh() {
    if (!wp_next_scheduled('ebay_update_buy_it_now_posts')) {
        wp_schedule_event(time(), 'weekly', 'ebay_update_buy_it_now_posts');
    }
}
add_action('wp', 'schedule_ebay_buy_it_now_refresh');

// New Buy It Now Shortcode
function ebay_buy_it_now_shortcode($atts) {
    $atts = shortcode_atts(array(
        'posts_per_page' => 5,
        'order' => 'DESC', // Default sort
    ), $atts, 'ebay_buy_it_now');

    $args = array(
        'post_type'      => 'post',
        'meta_key'       => 'ebay_item_id',
        'orderby'        => 'date',
        'order'          => $atts['order'],
        'posts_per_page' => intval($atts['posts_per_page']),
        'meta_query'     => array(
            array(
                'key'     => 'is_buy_it_now',
                'value'   => true,
                'compare' => '=',
            ),
        ),
    );

    $query = new WP_Query($args);

    ob_start();

    if ($query->have_posts()) {
        $post_count = $query->post_count;
        ?>
        <div class="ebay-buy-it-now owl-carousel" data-post-count="<?php echo esc_attr($post_count); ?>">
            <?php
            while ($query->have_posts()) {
                $query->the_post();
                $url = get_post_meta(get_the_ID(), 'ebay_url', true);
                $price = get_post_meta(get_the_ID(), 'ebay_price', true);
                $ebay_image_url = get_post_meta(get_the_ID(), 'ebay_image_url', true);

                ?>
                <div class="item">
                    <div class="item-header">
                    <?php if (has_post_thumbnail()) {
                            the_post_thumbnail('full', ['class' => 'img-fluid']);
                        } elseif (!empty($ebay_image_url)) {
                            echo '<img src="' . esc_url($ebay_image_url) . '" alt="' . esc_attr(get_the_title()) . '" class="img-fluid">';  
                        } ?>
                        <h2><a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo esc_html(get_the_title()); ?></a></h2>
                       
                    </div>
                    <div class="item-details d-flex justify-content-evenly mt-3">
                        <div class="justify-content-center">
                            <p><strong> Price: </strong>$<?php echo esc_html($price); ?></p>             
                        </div> 
                    </div>
    
                    <a  href="<?php echo esc_url($url); ?>" class="btn btn-secondary" target="_blank">Buy Now</a>
 
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }
    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode('ebay_buy_it_now', 'ebay_buy_it_now_shortcode');



