<?php
// ebay-selling-fetcher.php
function fetch_ebay_buy_it_now_collectibles() {
    global $env_ebay;

    // Check cache first
    $cached = get_transient('ebay_buy_it_now_cache');
    if ($cached !== false && !empty($cached)) {
        error_log("Returning cached eBay Buy It Now data: " . json_encode($cached, JSON_PRETTY_PRINT));
        return $cached;
    }

    $auth_token = $env_ebay['EBAY_AUTH_TOKEN'];

    if (empty($auth_token)) {
        error_log("Error: Missing EBAY_AUTH_TOKEN in ebay.env");
        return false;
    }

    $endpoint = "https://api.ebay.com/ws/api.dll";
    $xml_request = '<?xml version="1.0" encoding="utf-8"?>
        <GetMyeBaySellingRequest xmlns="urn:ebay:apis:eBLBaseComponents">
            <RequesterCredentials>
                <eBayAuthToken>' . htmlspecialchars($auth_token, ENT_XML1) . '</eBayAuthToken>
            </RequesterCredentials>
            <ActiveList>
                <Sort>TimeLeft</Sort>
                <Pagination>
                    <EntriesPerPage>100</EntriesPerPage>
                    <PageNumber>1</PageNumber>
                </Pagination>
            </ActiveList>
            <DetailLevel>ReturnAll</DetailLevel>
            <IncludeItemSpecifics>true</IncludeItemSpecifics>
        </GetMyeBaySellingRequest>';

    $response = wp_remote_post($endpoint, array(
        'headers' => array(
            'X-EBAY-API-COMPATIBILITY-LEVEL' => '967',
            'X-EBAY-API-CALL-NAME' => 'GetMyeBaySelling',
            'X-EBAY-API-SITEID' => '0',
            'Content-Type' => 'text/xml',
        ),
        'body' => $xml_request,
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        error_log("eBay Buy It Now API Error: " . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($body);

    if (!$xml || $xml->Ack != 'Success') {
        if ($xml && (string)$xml->Errors->ErrorCode === '932') {
            error_log("eBay API Failure: Token expired - " . $body);
            return 'token_expired';
        }
        error_log("eBay API Failure: " . $body);
        return false;
    }

    $data = array('items' => []);

    if (isset($xml->ActiveList->ItemArray->Item)) {
        foreach ($xml->ActiveList->ItemArray->Item as $item) {

            $item_id = (string)$item->ItemID;
    
            if ((string)$item->ListingType === 'FixedPriceItem') {
                $category_name = get_item_category($item->ItemID, $auth_token); 
                if (!$category_name) {
                    $category_name = 'Collectibles';    
                }
    
                // Improved image URL extraction
                $image_url = '';
                $item_description = isset($item->ListingDetails->Description) ? (string)$item->ListingDetails->Description : '';

                 // Fetch description via GetItem if missing
                if ($item_description === '') {            
                    $item_details = get_item_details($item_id, $auth_token);
                    if ($item_details !== 'Not available') {                   
                        $item_description = $item_details['description'];                
                    } else {
                        error_log("Failed to fetch description via GetItem for ItemID $item_id");
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

    set_transient('ebay_buy_it_now_cache', $data, 12 * 3600);
    return $data;
}


function create_buy_it_now_posts() { 

    // Clear cache to ensure fresh data
    delete_transient('ebay_buy_it_now_cache');

    $items = fetch_ebay_buy_it_now_collectibles();

        if ($items === false) {
            error_log("Failed to fetch Buy It Now items; check previous logs for details");
            echo '<div class="error"><p>Failed to fetch Buy It Now items. Check logs or try again later.</p></div>';
            return;
        } elseif ($items === 'token_expired') {
            echo '<div class="error"><p>Your eBay authentication token has expired. Please generate a new one in the eBay Developer Portal and update ebay.env.</p></div>';
            return;
        }

        if (empty($items['items'])) {
            error_log("No active Buy It Now items found in eBay response");
            echo '<div class="notice notice-warning"><p>No active Buy It Now items found.</p></div>';
            return;
        }

        // Ensure parent category "Collectibles" exists        
        $parent_cat = get_term_by('name', 'Collectibles', 'category');
        if (!$parent_cat) {
            $created = wp_insert_term('Collectibles', 'category', ['slug' => 'collectibles']);
            if (is_wp_error($created)) {
                error_log("Error creating parent 'Collectibles' category: " . $created->get_error_message());
                echo '<div class="notice notice-error"><p>Failed to create Collectibles category.</p></div>';
                return;
            }
            $parent_cat_id = $created['term_id'];
        } else {
            $parent_cat_id = $parent_cat->term_id;
        }

        $trashed_count = 0;
        $updated_count = 0;
        $created_count = 0;
 
 
        foreach ($items['items'] as $item) {    
            
            // Normalize into consistent array
            $subcategory_name = trim($item['categoryName']);
            $item_subcategories = array_filter(explode(' > ', str_replace(':', ' > ', $subcategory_name)));
            $item_subcategories = array_map('trim', $item_subcategories);
            
            $root = isset($item_subcategories[0]) ? $item_subcategories[0] : '';          
            
            if ($root === 'Collectibles') {                
                array_shift($item_subcategories);
            } elseif ($root === 'Sports Mem, Cards & Fan Shop') {                
                array_shift($item_subcategories);
                array_unshift($item_subcategories, 'Sports Mem, Cards & Fan Shop');
            } elseif ($root === 'Movies & TV') {                
                array_shift($item_subcategories);
                array_unshift($item_subcategories, 'Movies/DVD');
            } else {                
                array_unshift($item_subcategories, 'Other');
            }
            
            if (empty($item_subcategories)) {
                $item_subcategories = ['Other'];
            }            

            $category_ids = [$parent_cat_id]; 
            $current_parent_id = $parent_cat_id;    


            foreach ($item_subcategories as $index => $subcategory) {
                $subcat_name = trim($subcategory);
                $subcat_slug = sanitize_title($subcat_name);
            
                // Get all child terms under the current parent
                $child_terms = get_terms([
                    'taxonomy'   => 'category',
                    'hide_empty' => false,
                    'parent'     => $current_parent_id,
                ]);
            
                $matched_term_id = null;            
                foreach ($child_terms as $term) {
                    if (sanitize_title($term->name) === $subcat_slug) {
                        $matched_term_id = $term->term_id;
                        break;
                    }
                }
            
                if ($matched_term_id) {
                    $subcat_id = $matched_term_id; // Use existing term
                } else {
                    // Create new term only if it doesn't already exist under the current parent
                    $subcat = wp_insert_term(
                        $subcat_name,
                        'category',
                        [
                            'slug'   => $subcat_slug,
                            'parent' => $current_parent_id,
                        ]
                    );
            
                    if (is_wp_error($subcat)) {
                        error_log("Failed to create subcategory '$subcat_name' with slug '$subcat_slug' under parent ID $current_parent_id: " . $subcat->get_error_message());
                        echo '<div class="notice notice-error"><p>Failed to create subcategory: ' . esc_html($subcat->get_error_message()) . '</p></div>';
                        continue;
                    }
            
                    $subcat_id = $subcat['term_id'];
                }
            
                // Add to category list and move one level deeper
                $category_ids[] = $subcat_id;
                $current_parent_id = $subcat_id;
            }       

            $post_data = array(
                'post_title'   => $item['title'],
                'post_content' => $item['description'] . '<br>',
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_category' => $category_ids,
                'meta_input'   => array(
                    'ebay_item_id' => $item['itemId'],
                    'ebay_url'     => $item['viewItemURL'],
                    'ebay_image_url' => $item['imageURL'],
                    'ebay_price'   => $item['currentPrice'],
                    'is_buy_it_now' => true,
                ),
            );

        // Check for existing post
        $existing = get_posts([
            'meta_key'   => 'ebay_item_id',
            'meta_value' => $item['itemId'],
            'post_type'  => 'post',
            'numberposts' => 1,
        ]);

        if (!empty($existing)) {
            // Update existing post
            $post_data['ID'] = $existing[0]->ID;
            $post_id = wp_update_post($post_data, true);
            if (is_wp_error($post_id)) {
                error_log("Failed to update post for eBay item {$item['itemId']}: " . $post_id->get_error_message());
                echo '<div class="notice notice-error"><p>Failed to update post for item ' . esc_html($item['itemId']) . '</p></div>';
            } else {
                error_log("Updated post ID {$post_id} for eBay Buy It Now item {$item['itemId']}");
                $updated_count++;
                // Update featured image only if none exists or URL has changed
                if (!empty($item['imageURL'])) {
                    $existing_image_url = get_post_meta($post_id, 'ebay_image_url', true);
                    if (!has_post_thumbnail($post_id) || $existing_image_url !== $item['imageURL']) {
                        $attach_id = set_featured_image_from_url($post_id, $item['imageURL']);
                        if ($attach_id) {
                            error_log("Updated featured image for post ID {$post_id} from {$item['imageURL']}");
                        } else {
                            error_log("Failed to set featured image for post ID {$post_id} from {$item['imageURL']}");
                        }
                    } else {
                        error_log("Skipped image update for post ID {$post_id}: Image already exists and URL unchanged");
                    }
                }
            }
        } else {
            // Create new post
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                error_log("Failed to create post for eBay item {$item['itemId']}: " . $post_id->get_error_message());
                echo '<div class="notice notice-error"><p>Failed to create post for item ' . esc_html($item['itemId']) . '</p></div>';
            } else {
                error_log("Created post ID {$post_id} for eBay Buy It Now item {$item['itemId']}");
                $created_count++;
                if (!empty($item['imageURL'])) {
                    $attach_id = set_featured_image_from_url($post_id, $item['imageURL']);
                    if ($attach_id) {
                        error_log("Set featured image for post ID {$post_id} from {$item['imageURL']}");
                    } else {
                        error_log("Failed to set featured image for post ID {$post_id} from {$item['imageURL']}");
                    }
                }
            }
        }
    }

    // Clean up outdated posts
    $current_item_ids = array_column($items['items'], 'itemId');
    $existing_buy_now_posts = get_posts([
        'post_type'  => 'post',
        'numberposts' => -1,
        'meta_query' => [
            [
                'key'     => 'is_buy_it_now',
                'value'   => true,
                'compare' => '=',
            ],
        ],
    ]);

    foreach ($existing_buy_now_posts as $existing_post) {
        $existing_item_id = get_post_meta($existing_post->ID, 'ebay_item_id', true);
        if (!in_array($existing_item_id, $current_item_ids)) {
            wp_trash_post($existing_post->ID);
            error_log("Trashed outdated Buy It Now post ID {$existing_post->ID} (eBay ID: $existing_item_id)");
            $trashed_count++;
        }
    }

    // Display results
    $message = sprintf(
        'Buy It Now refresh completed: %d posts created, %d posts updated, %d posts trashed.',
        $created_count,
        $updated_count,
        $trashed_count
    );

    echo '<div class="updated"><p>' . esc_html($message) . '</p></div>';
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
                        <h2><a href="<?php echo esc_url($url); ?>" target="_blank"><?php echo get_the_title(); ?></a></h2>
                       
                    </div>
                    <div class="item-details d-flex justify-content-evenly mt-3">
                        <div class="justify-content-center">
                            <p><strong> Price: $<?php echo esc_html($price); ?></strong></p>             
                        </div> 
                    </div>
    
                    <a href="<?= $url ?>" class="btn btn-secondary" target="_blank">Buy Now</a>
 
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


