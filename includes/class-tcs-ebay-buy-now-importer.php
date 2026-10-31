<?php
defined('ABSPATH') || exit;

/** Owns ebay buy now importer behavior; legacy function entry points delegate here. */
class TCS_Ebay_Buy_Now_Importer {
    public function synchronize($items) {

            if (!is_array($items) || isset($items['error'])) {
                return new WP_Error(
                    'tcs_bin_import_failed',
                    is_array($items)
                        ? ($items['error'] ?? 'Buy It Now data is invalid.')
                        : 'Buy It Now data is invalid.'
                );
            }

            if (empty($items['items'])) {
                echo '<div class="notice notice-warning"><p>' .
                    'No active Buy It Now items found. Existing posts were preserved.' .
                    '</p></div>';

                return true;
            }

            /*
            * Ensure we use the TOP-LEVEL Collectibles category.
            *
            * Do not look it up by name because Auctions also contains
            * a child category named "Collectibles".
            *
            * Main collectibles:
            * /collectibles/
            *
            * Auction collectibles:
            * /auctions/collectibles-auctions/
            */
            $parent_cat = get_term_by(
                'slug',
                'collectibles',
                'category'
            );

            if (
                !$parent_cat ||
                is_wp_error($parent_cat)
            ) {

                $created = wp_insert_term(
                    'Collectibles',
                    'category',
                    [
                        'slug'   => 'collectibles',
                        'parent' => 0,
                    ]
                );

                if (is_wp_error($created)) {

                    error_log(
                        "Error creating top-level Collectibles category: " .
                        $created->get_error_message()
                    );

                    echo '<div class="notice notice-error"><p>' .
                        'Failed to locate or create the top-level ' .
                        'Collectibles category.' .
                        '</p></div>';

                    return $created;
                }

                $parent_cat_id = (int) $created['term_id'];

            } else {

                $parent_cat_id =
                    (int) $parent_cat->term_id;

                /*
                * Defensive check.
                *
                * The main Collectibles category must always be top-level.
                */
                if ((int) $parent_cat->parent !== 0) {

                    error_log(
                        sprintf(
                            'Invalid Collectibles parent category: ' .
                            'term ID %d has parent %d.',
                            $parent_cat_id,
                            (int) $parent_cat->parent
                        )
                    );

                    echo '<div class="notice notice-error"><p>' .
                        'The main Collectibles category is not top-level.' .
                        '</p></div>';

                    return new WP_Error(
                        'tcs_invalid_collectibles_parent',
                        'The main Collectibles category must be top-level.'
                    );
                }
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
                    // Collectibles is already the WordPress parent.
                    array_shift($item_subcategories);
                } elseif ($root === 'Sports Mem, Cards & Fan Shop') {
                    array_shift($item_subcategories);
                    array_unshift(
                        $item_subcategories,
                        'Sports Cards & Memorabilia'
                    );
                } elseif ($root === 'Movies & TV') {
                    array_shift($item_subcategories);
                    array_unshift(
                        $item_subcategories,
                        'Movies/DVD'
                    );
                } elseif (
                    $root === 'Music' &&
                    isset($item_subcategories[1]) &&
                    strcasecmp(
                        $item_subcategories[1],
                        'Vinyl Records'
                    ) === 0
                ) {
                    // Remove "Music" but retain "Vinyl Records".
                    array_shift($item_subcategories);
                } elseif ($root === 'Toys & Hobbies') {
                    array_shift($item_subcategories);
                    array_unshift(
                        $item_subcategories,
                        'Toys & Hobbies'
                    );
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
                    return $post_id;
                } else {
                    error_log("Updated post ID {$post_id} for eBay Buy It Now item {$item['itemId']}");
                    $updated_count++;
                    // Update featured image only if none exists or URL has changed
                    if (!empty($item['imageURL'])) {
                        $existing_image_url = get_post_meta(
                            $post_id,
                            '_ebay_featured_image_url',
                            true
                        );
                        if (!has_post_thumbnail($post_id) || $existing_image_url !== $item['imageURL']) {

                            $attach_id = set_featured_image_from_url(
                                $post_id,
                                $item['imageURL']
                            );

                            if (is_wp_error($attach_id)) {
                                error_log(
                                    "Failed to set featured image for post ID {$post_id} " .
                                    "from {$item['imageURL']}: " .
                                    $attach_id->get_error_message()
                                );
                            } else {
                                error_log(
                                    "Updated featured image for post ID {$post_id} " .
                                    "using attachment ID {$attach_id}"
                                );
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
                    return $post_id;
                } else {
                    error_log("Created post ID {$post_id} for eBay Buy It Now item {$item['itemId']}");
                    $created_count++;
                    if (!empty($item['imageURL'])) {

                        $attach_id = set_featured_image_from_url(
                            $post_id,
                            $item['imageURL']
                        );

                        if (is_wp_error($attach_id)) {
                            error_log(
                                "Failed to set featured image for post ID {$post_id} " .
                                "from {$item['imageURL']}: " .
                                $attach_id->get_error_message()
                            );
                        } else {
                            error_log(
                                "Set featured image for post ID {$post_id} " .
                                "using attachment ID {$attach_id}"
                            );
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
        return true;
    }

}
