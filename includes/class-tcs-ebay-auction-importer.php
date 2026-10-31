<?php
defined('ABSPATH') || exit;

/** Owns ebay auction importer behavior; legacy function entry points delegate here. */
class TCS_Ebay_Auction_Importer {
    public function synchronize($auctions) {

            // Delete expired auction posts
            // Get the "Auctions" category

            $parent_cat = get_term_by('name', 'Auctions', 'category');
            if (!$parent_cat) {
                error_log("Auctions category not found for deletion filtering");
                // Optionally create the category to prevent issues
                $created = wp_insert_term('Auctions', 'category', ['slug' => 'auctions']);
                if (is_wp_error($created)) {
                    error_log("Error creating parent 'Auctions' category: " . $created->get_error_message());
                    return $created;
                }
                $parent_cat_id = $created['term_id'];
            } else {
                $parent_cat_id = $parent_cat->term_id;
            }

            if (!is_array($auctions) || isset($auctions['error'])) {
                return new WP_Error(
                    'tcs_auction_import_failed',
                    is_array($auctions)
                        ? ($auctions['error'] ?? 'Auction data is invalid.')
                        : 'Auction data is invalid.'
                );
            }

            if (empty($auctions['items'])) {
                echo '<div class="notice notice-warning"><p>' .
                    'No active auctions found. Existing posts were preserved.' .
                    '</p></div>';

                return true;
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
                    return $collectibles; // Stop if we can't create the category
                }
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
                    array_unshift(
                        $item_subcategories,
                        'Sports Cards & Memorabilia'
                    );
            } elseif ($subcat === 'Movies & TV') {
                    array_shift($item_subcategories);
                    array_unshift(
                        $item_subcategories,
                        'Movies/DVD'
                    );

            } elseif (
                    $subcat === 'Music' &&
                    isset($item_subcategories[1]) &&
                    strcasecmp(
                        $item_subcategories[1],
                        'Vinyl Records'
                    ) === 0
            ) {
                    // Remove "Music" but retain "Vinyl Records".
                    array_shift($item_subcategories);

            } elseif ($subcat === 'Toys & Hobbies') {
                    array_shift($item_subcategories);
                    array_unshift(
                        $item_subcategories,
                        'Toys & Hobbies'
                    );
            } elseif (
                    $subcat === 'Collectibles & Other Auctions' ||
                    stripos($subcat, 'Collectibles') !== false
             ) {
                    array_shift($item_subcategories);
                    array_unshift(
                        $item_subcategories,
                        'Collectibles'
                    );
            }

            // Check for existing category by name
            $category_ids = [$parent_cat_id];
            $current_parent_id = $parent_cat_id;

                foreach ($item_subcategories as $index => $subcategory) {
                    $subcat_name = trim($subcategory);
                    // Use the dedicated auction slug for Collectibles.
                    $subcat_slug = $subcat_name === 'Collectibles'
                        ? 'collectibles-auctions'
                        : sanitize_title($subcat_name);

                    // Get all child terms under the current parent
                    $child_terms = get_terms([
                        'taxonomy'   => 'category',
                        'hide_empty' => false,
                        'parent'     => $current_parent_id,
                    ]);

                $matched_term_id = null;
                foreach ($child_terms as $term) {
                    if (sanitize_title($term->name) === $subcat_slug || (
                                $subcat_name === 'Collectibles' &&
                                $term->slug === 'collectibles-auctions'
                        ))
                        {
                            $matched_term_id = $term->term_id;
                            break;
                        }
                    }

                    if ($matched_term_id) {

                        $subcat_id = $matched_term_id; // Use existing term\

                    } else {

                        $subcat = wp_insert_term(
                            $subcat_name,
                            'category',
                            [
                                'slug'   => $subcat_slug,
                                'parent' => $current_parent_id,
                            ]
                        );

                        if (is_wp_error($subcat)) {
                            error_log(
                                "Failed to create subcategory '$subcat_name' " .
                                "with slug '$subcat_slug' under parent ID " .
                                "$current_parent_id: " .
                                $subcat->get_error_message()
                            );

                            echo '<div class="notice notice-error"><p>' .
                                'Failed to create subcategory: ' .
                                esc_html($subcat->get_error_message()) .
                                '</p></div>';

                            return $subcat;
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
                        return $result;
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
                                $current_image_url = get_post_meta(
                                    $result,
                                    '_ebay_featured_image_url',
                                    true
                                );
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
                        return $post_id;
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
                        'include_children' => true, // Include all Auctions subcategories.
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
        return true;
    }

}
