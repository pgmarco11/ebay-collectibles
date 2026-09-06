<?php

function get_item_details($item_id, $auth_token) {
    $endpoint = "https://api.ebay.com/ws/api.dll";
    $xml_request = '<?xml version="1.0" encoding="utf-8"?>
        <GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
            <RequesterCredentials>
                <eBayAuthToken>' . htmlspecialchars($auth_token, ENT_XML1) . '</eBayAuthToken>
            </RequesterCredentials>
            <ItemID>' . esc_xml($item_id) . '</ItemID>
            <DetailLevel>ReturnAll</DetailLevel>
            <IncludeItemSpecifics>true</IncludeItemSpecifics>
        </GetItemRequest>';

    $response = wp_remote_post($endpoint, array(
        'headers' => array(
            'X-EBAY-API-COMPATIBILITY-LEVEL' => '967',
            'X-EBAY-API-CALL-NAME' => 'GetItem',
            'X-EBAY-API-SITEID' => '0',
            'Content-Type' => 'text/xml',
        ),
        'body' => $xml_request,
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log("GetItem API Error for Item $item_id: " . $error_message);
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);

    if ($xml && $xml->Ack == 'Success') {
        $end_time = isset($xml->Item->ListingDetails->EndTime) ? (string)$xml->Item->ListingDetails->EndTime : 'Not available';
        $description = isset($xml->Item->Description) ? (string)$xml->Item->Description : '';
        return [
            'endTime' => $end_time,
            'description' => $description,
        ];
    }

    error_log("GetItem failed for Item $item_id: Ack = " . ($xml->Ack ?? 'No Ack'));
    return false;
}
function fetch_ebay_auctions() {
    global $env_ebay;

    // Clear cache for testing (remove after confirming fix)clear
    // delete_transient('ebay_auctions_cache');

    // Check cache first
    $cached = get_transient('ebay_auctions_cache');
    if ($cached !== false && !empty($cached)) {
        return $cached;
    }

    $auth_token = $env_ebay['EBAY_AUTH_TOKEN'] ?? '';

    if (empty($auth_token)) {
        error_log("Error: Missing EBAY_AUTH_TOKEN in ebay.env");
        return ['error' => 'Missing EBAY_AUTH_TOKEN in ebay.env'];
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
        $error_message = $response->get_error_message();
        error_log("eBay Trading API Error: " . $error_message);
        return ['error' => "API request failed: $error_message"];
    }

    $body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);

    if (!$xml) {   
        return ['error' => 'Failed to parse XML response', 'response' => $body];
    }

    if ($xml->Ack != 'Success') {
        $error_details = $xml->Errors ? (string)$xml->Errors->LongMessage : 'Unknown error';
        if ((string)$xml->Errors->ErrorCode === '932') {        
            return ['error' => 'Token expired', 'details' => $error_details];
        }
        error_log("eBay API Failure: " . $body);
        return ['error' => 'API call failed', 'details' => $error_details, 'response' => $body];
    }

    $data = array('items' => []);

    if (isset($xml->ActiveList->ItemArray->Item)) {
        error_log("Found " . count($xml->ActiveList->ItemArray->Item) . " items in ActiveList");
        foreach ($xml->ActiveList->ItemArray->Item as $item) {
            $item_id = (string)$item->ItemID;
            $listing_type = (string)$item->ListingType;
            $title = (string)$item->Title;   

            // Debug ListingDetails
            if (isset($item->ListingDetails)) {
                $listing_details_xml = $item->ListingDetails->asXML();            
            } else {
                error_log("ListingDetails NOT found for ItemID $item_id");
            }
            // Debug EndTime
            $end_time = isset($item->ListingDetails->EndTime) ? (string)$item->ListingDetails->EndTime : 'Not available';
            $item_description = isset($item->ListingDetails->Description) ? (string)$item->ListingDetails->Description : '';
            error_log("EndTime for ItemID $item_id: $end_time");

            // Fetch EndTime via GetItem if missing and listing is auction-style
            if ($end_time === 'Not available' && stripos($listing_type, 'Auction') !== false || $listing_type === 'Chinese') {
                error_log("Attempting GetItem call for ItemID $item_id due to missing EndTime");
                $item_details = get_item_details($item_id, $auth_token);
                if ($item_details && $item_details['endTime'] !== 'Not available') {
                    $end_time = $item_details['endTime'];   
                    $item_description = $item_details['description'];              
                    error_log("Fetched EndTime via GetItem for ItemID $item_id: $end_time");
                } else {
                    error_log("Failed to fetch EndTime via GetItem for ItemID $item_id");
                }
            }

            // Debug additional fields
            $view_item_url = isset($item->ListingDetails->ViewItemURL) ? (string)$item->ListingDetails->ViewItemURL : 'Not available';
            $current_price = isset($item->SellingStatus->CurrentPrice) ? (float)$item->SellingStatus->CurrentPrice : 0;
            $buy_now_price = isset($item->BuyItNowPrice) ? (float)$item->BuyItNowPrice : null;     
            $bid_count = isset($item->SellingStatus->BidCount) ? (int)$item->SellingStatus->BidCount : 0;
            error_log('Additional fields for $itemfor ItemID' . $item_id . ': ' . esc_html($item)); 

            // Include only auction-style listings
            if (stripos($listing_type, 'Auction') !== false || $listing_type === 'Chinese') {
                $category_name = get_item_category($item->ItemID, $auth_token);
                if (!$category_name) {
                    $category_name = 'Auctions';
                }

                // Improved image URL extraction
                $image_url = '';
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
                    'itemId' => $item_id,
                    'title' => $title,
                    'viewItemURL' => $view_item_url,
                    'currentPrice' => $current_price,
                    'buyNowPrice' => $buy_now_price,
                    'bidCount' => $bid_count,
                    'imageURL' => $image_url,
                    'categoryName' => $category_name,
                    'endTime' => $end_time,
                    'description' => $item_description,
                );
            } else {
                error_log("Skipping ItemID $item_id due to ListingType: $listing_type");
            }
        }
    } else {
        error_log("No items found in ActiveList->ItemArray->Item");
    }

    set_transient('ebay_auctions_cache', $data, 12 * 3600);
    return $data;
}

function get_item_category($item_id, $auth_token) {
    $endpoint = "https://api.ebay.com/ws/api.dll";
    $xml_request = '<?xml version="1.0" encoding="utf-8"?>
        <GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">
            <RequesterCredentials>
                <eBayAuthToken>' . htmlspecialchars($auth_token, ENT_XML1) . '</eBayAuthToken>
            </RequesterCredentials>
            <ItemID>' . esc_xml($item_id) . '</ItemID>
            <DetailLevel>ReturnAll</DetailLevel>
            <IncludeItemSpecifics>true</IncludeItemSpecifics>
        </GetItemRequest>';

    $response = wp_remote_post($endpoint, array(
        'headers' => array(
            'X-EBAY-API-COMPATIBILITY-LEVEL' => '967',
            'X-EBAY-API-CALL-NAME' => 'GetItem',
            'X-EBAY-API-SITEID' => '0',
            'Content-Type' => 'text/xml',
        ),
        'body' => $xml_request,
        'timeout' => 15,
    ));

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        error_log("GetItem API Error for Item $item_id: " . $error_message);
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($body);

    if ($xml && $xml->Ack == 'Success' && isset($xml->Item->PrimaryCategory->CategoryName)) {
        return (string)$xml->Item->PrimaryCategory->CategoryName;
    }
    return false;
}

function set_featured_image_from_url($post_id, $image_url) {
    if (empty($image_url)) {
        error_log("No image URL provided for post ID $post_id");
        return false;
    }

    $parsed_url = parse_url($image_url);
    $base_url = preg_replace('/\/s-l\d+\.(jpg|png|jpeg|webp)$/i', '', $parsed_url['path']);
    $image_url = 'https://i.ebayimg.com' . $base_url . '/s-l300.jpg';
    
    $image_data = @file_get_contents($image_url);
    if ($image_data === false) {
        error_log("Failed to download image from URL: $image_url for post ID $post_id");
        return false;
    }
    
    $post = get_post($post_id);
    $sanitized_title = sanitize_title($post->post_title);
    $filename = $sanitized_title . '-' . $post_id . '.jpg';
    
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['path'] . '/' . $filename;
    
    file_put_contents($file_path, $image_data);
    
    $filetype = wp_check_filetype($filename, null);
    $attachment = array(
        'guid'           => $upload_dir['url'] . '/' . $filename,
        'post_mime_type' => $filetype['type'],
        'post_title'     => sanitize_file_name($filename),
        'post_content'   => '',
        'post_status'    => 'inherit',
    );
    
    $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);
    if ($attach_id === 0) {
        error_log("Failed to insert attachment for image: $image_url for post ID $post_id");
        return false;
    }
    
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
    wp_update_attachment_metadata($attach_id, $attach_data);
    
    set_post_thumbnail($post_id, $attach_id);
    return $attach_id;
}

function create_auction_posts() {

        // Delete expired auction posts
        // Get the "Auctions" category

        $parent_cat = get_term_by('name', 'Auctions', 'category');
        if (!$parent_cat) {
            error_log("Auctions category not found for deletion filtering");
            // Optionally create the category to prevent issues
            $created = wp_insert_term('Auctions', 'category', ['slug' => 'auctions']);
            if (is_wp_error($created)) {
                error_log("Error creating parent 'Auctions' category: " . $created->get_error_message());
                return;
            }
            $parent_cat_id = $created['term_id'];
        } else {
            $parent_cat_id = $parent_cat->term_id;
        }

        // Delete expired auction posts under "Auctions" category
        $expired_posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => 'ebay_end_date',
                    'compare' => 'EXISTS',
                ]
            ],
            'tax_query' => [
                [
                    'taxonomy' => 'category',
                    'field' => 'term_id',
                    'terms' => $parent_cat_id,
                    'include_children' => true, // Include subcategories like Collectibles, Other Auctions
                ]
            ]
        ]);

        foreach ($expired_posts as $post) {
            $end_date_str = get_post_meta($post->ID, 'ebay_end_date', true);
            if ($end_date_str) {
                $end_date = strtotime($end_date_str);
                if ($end_date && time() > $end_date) {
                    // Delete attachments
                    $attachments = get_attached_media('', $post->ID);
                    foreach ($attachments as $attachment) {
                        wp_delete_attachment($attachment->ID, true);
                    }
                    // Delete post
                    wp_delete_post($post->ID, true);
                    error_log("Deleted expired auction post ID {$post->ID} with end date $end_date_str");
                }
            }
        }
        $auctions = fetch_ebay_auctions();

        if (is_array($auctions) && isset($auctions['error'])) {
            echo '<div class="error"><p>' . esc_html($auctions['error']) . '</p>';
            if (isset($auctions['details'])) {
                echo '<p>Details: ' . esc_html($auctions['details']) . '</p>';
            }
            if (isset($auctions['response'])) {
                echo '<pre>';
                print_r($auctions['response']);
                echo '</pre>';
            }
            echo '</div>';
            return;
        }

        if (empty($auctions['items'])) {
            echo '<div class="notice notice-warning"><p>No active auctions found.</p><pre>';
            print_r($auctions);
            echo '</pre></div>';
            return;
        }

        // Get all existing auction posts under "Auctions" category
        $existing_posts = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'meta_query' => [
                [
                    'key' => 'ebay_item_id',
                    'compare' => 'EXISTS',
                ]
            ],
            'tax_query' => [
                [
                    'taxonomy' => 'category',
                    'field' => 'term_id',
                    'terms' => $parent_cat_id,
                    'include_children' => true,
                ]
            ]
        ]);

        $existing_item_ids = [];
        foreach ($existing_posts as $post) {
            $item_id = get_post_meta($post->ID, 'ebay_item_id', true);
            if ($item_id) {
                $existing_item_ids[$item_id] = $post->ID;
            }
        }

        // Current eBay item IDs
        $current_item_ids = array_column($auctions['items'], 'itemId');

        // Delete posts for auctions no longer active under "Auctions" category
        foreach ($existing_item_ids as $item_id => $post_id) {
            if (!in_array($item_id, $current_item_ids)) {
                $attachments = get_attached_media('', $post_id);
                foreach ($attachments as $attachment) {
                    wp_delete_attachment($attachment->ID, true);
                }
                wp_delete_post($post_id, true);
                error_log("Deleted post ID $post_id for inactive eBay item $item_id");
            }
        }
    
        // Define parent category
        if (!$parent_cat) {            
            $parent_cat = get_term_by('name', 'Auctions', 'category');
            $parent_cat_id = $parent_cat->term_id;
        }

        // Ensure "Collectibles" category with slug "collectibles-auctions" exists under Auctions
        $collectibles_cat = get_term_by('slug', 'collectibles-auctions', 'category');
        if (!$collectibles_cat) {
            $collectibles = wp_insert_term(
                'Collectibles',
                'category',
                [
                    'slug'   => 'collectibles-auctions',
                    'parent' => $parent_cat_id,
                ]
            );

            if (is_wp_error($collectibles)) {
                error_log("Error creating 'Collectibles' category: " . $collectibles->get_error_message());
                return; // Stop if we can't create the category
            }
            $collectibles_cat_id = $collectibles['term_id'];
        } else {
            $collectibles_cat_id = $collectibles_cat->term_id;
        }

        // Ensure "Other Auctions" category with slug "other-auctions" exists under Auctions
        $other_cat = get_term_by('slug', 'other-auctions', 'category');
        if (!$other_cat) {
            $other = wp_insert_term(
                'Other Auctions',
                'category',
                [
                    'slug'   => 'other-auctions',
                    'parent' => $parent_cat_id,
                ]
            );

            if (is_wp_error($other)) {
                error_log("Error creating 'Other Auctions' category: " . $other->get_error_message());
                return; // Stop if we can't create the category
            }
            $other_cat_id = $other['term_id'];
        } else {
            $other_cat_id = $other_cat->term_id;
        }

        $created_posts = [];
        $updated_posts = [];

        foreach ($auctions['items'] as $item) {
            $end_time = isset($item['endTime']) ? $item['endTime'] : 'Not available';
            $ebay_bid_count = isset($item['bidCount']) ? $item['bidCount'] : 0;    

            // Normalize category name
            $subcategory_name = trim($item['categoryName']);
            $item_subcategories = array_filter(explode(' > ', str_replace(':', ' > ', $subcategory_name)));
            $item_subcategories = array_map('trim', $item_subcategories);

            $subcat = isset($item_subcategories[0]) ? $item_subcategories[0] : '';

            // Adjust category mapping using array_shift and array_unshift
            if ($subcat === 'Sports Mem, Cards & Fan Shop') {
                array_shift($item_subcategories);
                array_unshift($item_subcategories, 'Sports Mem, Cards & Fan Shop');
            } elseif ($subcat === 'Movies & TV') {
                array_shift($item_subcategories);
                array_unshift($item_subcategories, 'Movies/DVD');
            } elseif ($subcat === 'Collectibles & Other Auctions' || stripos($subcat, 'Collectibles') !== false) {             
                array_shift($item_subcategories);
                array_unshift($item_subcategories, 'Collectibles');
            } else {
                // Non-collectibles go to "Other Auctions"
                array_shift($item_subcategories);
                array_unshift($item_subcategories, 'Other Auctions');
            }

            if (empty($item_subcategories)) {
                $item_subcategories = ['Other Auctions'];
            }

            // Check for existing category by name
            $category_ids = [$parent_cat_id];
            $current_parent_id = $parent_cat_id;

            foreach ($item_subcategories as $index => $subcategory) {
                $subcat_name = trim($subcategory);
                // Use specific slugs for Collectibles and Other Auctions
                $subcat_slug = $subcat_name === 'Collectibles' ? 'collectibles-auctions' : 
               ($subcat_name === 'Other Auctions' ? 'other-auctions' : 
               sanitize_title($subcat_name));

                // Get all child terms under the current parent
                $child_terms = get_terms([
                    'taxonomy'   => 'category',
                    'hide_empty' => false,
                    'parent'     => $current_parent_id,
                ]);

                $matched_term_id = null;
                foreach ($child_terms as $term) {
                    if (sanitize_title($term->name) === $subcat_slug || 
                        ($subcat_name === 'Collectibles' && $term->slug === 'collectibles-auctions') ||
                        ($subcat_name === 'Other Auctions' && $term->slug === 'other-auctions')) {
                        $matched_term_id = $term->term_id;
                        break;
                    }
                }

                if ($matched_term_id) {
                    $subcat_id = $matched_term_id; // Use existing term
                } else {                    
                    if ($subcat_name === 'Collectibles' && $current_parent_id === $other_cat_id) {
                        $subcat_id = $collectibles_cat_id; // Use the top-level Collectibles category
                    } else {
                        // Create new term under the current parent
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
                    'ebay_buy_now_price' => $item['buyNowPrice'],
                    'ebay_bid_count' => $ebay_bid_count,
                    'ebay_end_date' => $end_time,
                ),
            );

        // Check for existing post
        if (isset($existing_item_ids[$item['itemId']])) {
            $post_data['ID'] = $existing_item_ids[$item['itemId']];
            $result = wp_update_post($post_data, true);
                if (is_wp_error($result)) {
                    error_log("Failed to update post for ItemID {$item['itemId']}: " . $result->get_error_message());
                } else {
                    // Only set featured image if it doesn't exist or has changed
                    if (!empty($item['imageURL'])) {
                        $current_thumbnail_id = get_post_thumbnail_id($result);
                        $update_image = false;

                        if (!$current_thumbnail_id) {
                            $update_image = true; // No featured image, set it
                            error_log("No featured image for post ID $result, setting new image");
                        } else {
                            // Compare current image URL with eBay image URL
                            $current_image_url = get_post_meta($result, 'ebay_image_url', true);
                            if ($current_image_url !== $item['imageURL']) {
                                $update_image = true; // Image URL has changed, update it
                                error_log("Image URL changed for post ID $result, updating from $current_image_url to {$item['imageURL']}");
                            }
                        }

                        if ($update_image) {
                            $attachment_id = set_featured_image_from_url($result, $item['imageURL']);
                            if (is_wp_error($attachment_id)) {
                                error_log("Failed to set featured image for post ID $result: " . $attachment_id->get_error_message());
                            } else {
                                error_log("Set featured image for post ID $result, attachment ID: $attachment_id");
                            }
                        } else {
                            error_log("Skipped setting featured image for post ID $result, image unchanged");
                        }
                    }
                    $updated_posts[] = [
                        'id' => $result,
                        'itemId' => $item['itemId'],
                        'title' => $item['title'],
                        'image' => !empty($item['imageURL']) ? ($update_image ? 'Updated' : 'Unchanged') : 'Not Set',
                        'ebay_bid_count' => $ebay_bid_count,
                        'endTime' => $end_time,
                    ];
                    error_log("Updated post ID $result for ItemID {$item['itemId']}");
                }
        } else {
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                    error_log("Failed to create post for ItemID {$item['itemId']}: " . $post_id->get_error_message());
            } else {
                // Set featured image for new posts
                if (!empty($item['imageURL'])) {
                        $attachment_id = set_featured_image_from_url($post_id, $item['imageURL']);
                        if (is_wp_error($attachment_id)) {
                            error_log("Failed to set featured image for post ID $post_id: " . $attachment_id->get_error_message());
                        } else {
                            error_log("Set featured image for post ID $post_id, attachment ID: $attachment_id");
                        }
                }
                $created_posts[] = [
                        'id' => $post_id,
                        'itemId' => $item['itemId'],
                        'title' => $item['title'],
                        'image' => !empty($item['imageURL']) ? 'Set' : 'Not Set',
                        'ebay_bid_count' => $ebay_bid_count,
                        'endTime' => $end_time,
                ];
                error_log("Created post ID $post_id for ItemID {$item['itemId']}");
            }
        }
    }
}
add_action('ebay_update_posts', 'create_auction_posts');

// Schedule the cron job if not already scheduled
function schedule_ebay_auction_refresh() {
    if (!wp_next_scheduled('ebay_update_posts')) {
        wp_schedule_event(time(), 'weekly', 'ebay_update_posts');
    }
}
add_action('wp', 'schedule_ebay_auction_refresh');

// Function to generate the eBay auction carousel
function ebay_auction_carousel_shortcode($atts) {
    $atts = shortcode_atts(array(
        'posts_per_page' => 5,
        'order' => 'ASC', 
    ), $atts, 'ebay_auction_carousel');

    $args = array(
        'post_type'      => 'post',
        'meta_key'       => 'ebay_item_id',
        'order'            => $atts['order'],
        'orderby'           => 'date',
        'posts_per_page' => intval($atts['posts_per_page']),
        'meta_query'     => array(
            array(
                'key'     => 'is_buy_it_now',
                'compare' => 'NOT EXISTS', // Only auctions, not Buy It Now
            ),
        ),
    );

    $query = new WP_Query($args);

    ob_start();

    if ($query->have_posts()) { 
        
        $post_count = $query->post_count;

        ?>
        <div class="ebay-carousel owl-carousel" data-post-count="<?php echo esc_attr($post_count); ?>">
            <?php
            while ($query->have_posts()) {
                $query->the_post();
                $url = get_post_meta(get_the_ID(), 'ebay_url', true);
                $price = get_post_meta(get_the_ID(), 'ebay_price', true);
                $buy_now_price = get_post_meta(get_the_ID(), 'ebay_buy_now_price', true);
                $bid_count = get_post_meta(get_the_ID(), 'ebay_bid_count', true);
                $date_string = get_post_meta(get_the_ID(), 'ebay_end_date', true);
                $ebay_image_url = get_post_meta(get_the_ID(), 'ebay_image_url', true);
               
                $date = new DateTime($date_string, new DateTimeZone('UTC')); 
                $date->setTimezone(new DateTimeZone('America/New_York'));

                $end_time = $date->format('g:i A T');
                $end_date = $date->format('Y-m-d');                


                ?>
                <div class="item">
                    <div class="item-header">       
                        <?php 
                        if (has_post_thumbnail()) {                        
                             ?><a href="<?php echo the_permalink(); ?>"> <?php the_post_thumbnail('full'); ?></a> <?php
                        } ?>
                        <h2>
                            <a href="<?php echo the_permalink(); ?>"><?php echo get_the_title(); ?></a>
                        </h2>
                    </div>                 
                    <div class="item-details d-flex justify-content-evenly mt-3">

                        <div class="col-6">

                            <p>Bids: <?php echo esc_html($bid_count); ?></p>
                            <p>Ends: <br><?php echo esc_html($end_date ? $end_date : 'Not available') . '<br>' . esc_html($end_time ? $end_time : ''); ?></p>
                        </div>
                        <br>

                        <div class="col-6">
                            <p>Current Price: $<?php echo esc_html($price); ?></p>
                            <?php if ($buy_now_price) : ?>
                                <p>Buy It Now: $<?php echo esc_html($buy_now_price); ?></p>
                            <?php endif; ?>
                        </div> 

                    </div>

                    <div class="btn-group" role="group">
                    <a href="<?= $url ?>/#bidBtn_btn" class="btn btn-primary" target="_blank">Bid Now</a>
                    <a href="<?= the_permalink(); ?>" class="btn btn-secondary">View Details</a>
                    </div>

                </div>
                <?php
            }
            ?>
        </div>
        <?php
    } else {
        echo do_shortcode('[ebay_buy_it_now posts_per_page="6"]');
    }
    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode('ebay_auction_carousel', 'ebay_auction_carousel_shortcode');

// Update the Owl Carousel initialization
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_script('owl-carousel', 'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/owl.carousel.min.js', array('jquery'), '2.3.4', true);
    wp_enqueue_style('owl-carousel-css', 'https://cdnjs.cloudflare.com/ajax/libs/OwlCarousel2/2.3.4/assets/owl.carousel.min.css', array(), '2.3.4');
    $inline_script = '
        jQuery(document).ready(function($) {
            $(".ebay-carousel, .ebay-buy-it-now").each(function() {
                const $carousel = $(this);
                const postCount = parseInt($carousel.data("post-count")) || 1;                

                $carousel.owlCarousel({
                    loop: postCount > 1,
                    autoplay: postCount > 1,
                    margin: 10,
                    nav: true,
                    navText: [
                        "<span>&#x2039;</span>", 
                        "<span>&#x203A;</span>"
                    ],
                    responsive: {
                        0: { items: 1 },
                        768: { items: Math.min(2, postCount) },
                        1024: { items: Math.min(3, postCount) }
                    }
                });
            });
        });
    ';
    wp_add_inline_script('owl-carousel', $inline_script);
});
