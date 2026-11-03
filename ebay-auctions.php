<?php

require_once __DIR__ . '/includes/class-tcs-ebay-auction-importer.php';

function tcs_ebay_auction_importer(): TCS_Ebay_Auction_Importer {
    static $instance;
    if (!$instance) {
        $instance = new TCS_Ebay_Auction_Importer();
    }
    return $instance;
}


function get_item_details($item_id, $auth_token = '') {
    $xml = tcs_get_cached_ebay_item_xml($item_id);
    if (is_wp_error($xml)) return false;
    return [
        'endTime' => (string) $xml->Item->ListingDetails->EndTime ?: 'Not available',
        'description' => (string) $xml->Item->Description,
    ];
}
function fetch_ebay_auctions($force = false) {
    global $env_ebay;

    // Clear cache for testing (remove after confirming fix)clear
    // delete_transient('ebay_auctions_cache');

    // Check cache first
    $cached = get_transient('ebay_auctions_cache_v3');
    if ($cached !== false && !empty($cached)) {
        return $cached;
    }

    $auth_token = $env_ebay['EBAY_AUTH_TOKEN'] ?? '';

    if (empty($auth_token)) {
        error_log("Error: Missing EBAY_AUTH_TOKEN in ebay.env");
        return ['error' => 'Missing EBAY_AUTH_TOKEN in ebay.env'];
    }

    $pages = tcs_get_cached_ebay_selling_pages($force);
    
    if (is_wp_error($pages)) return ['error' => $pages->get_error_message()];
    $xml = $pages[0];

    $data = array('items' => []);

    if (tcs_ebay_selling_items($pages)) {
        error_log("Found " . count($xml->ActiveList->ItemArray->Item) . " items in ActiveList");
        foreach (tcs_ebay_selling_items($pages) as $item) {
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
            $is_auction = stripos($listing_type, 'Auction') !== false ||
            $listing_type === 'Chinese';
        
            if (
                $is_auction &&
                (
                    $end_time === 'Not available' ||
                    trim($item_description) === ''
                )
            ) {
                $details = get_item_details($item_id, $auth_token);
            
                if (!is_array($details)) {
                    return [
                        'error' =>
                            'Could not fetch auction details. Existing posts were preserved.',
                    ];
                }
            
                if ($end_time === 'Not available') {
                    $end_time = $details['endTime'];
            
                    if ($end_time === 'Not available') {
                        return [
                            'error' =>
                                'Auction end time is missing. Existing posts were preserved.',
                        ];
                    }
                }
            
                if (trim($item_description) === '') {
                    $item_description = $details['description'];
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
                    return ['error' => 'Could not fetch an auction item category. Existing posts were preserved.'];
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

    set_transient('ebay_auctions_cache_v3', $data, 5 * MINUTE_IN_SECONDS);
    return $data;
}

function get_item_category($item_id, $auth_token = '') {
    $xml = tcs_get_cached_ebay_item_xml($item_id);
    if (is_wp_error($xml)) return false;
    return (string) $xml->Item->PrimaryCategory->CategoryName ?: false;
}

/**
 * Download an eBay image, attach it to a post, and set it as featured.
 *
 * Tries multiple eBay image sizes because a particular size may occasionally
 * be unavailable even though another variant exists.
 *
 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
 */
function set_featured_image_from_url($post_id, $image_url) {
    $post_id   = absint($post_id);
    $image_url = esc_url_raw(html_entity_decode(trim($image_url)));

    if (!$post_id || empty($image_url)) {
        return new WP_Error(
            'invalid_ebay_image',
            'A valid post ID and image URL are required.'
        );
    }

    $host = strtolower((string) wp_parse_url($image_url, PHP_URL_HOST));

    if (
        $host !== 'i.ebayimg.com' &&
        !str_ends_with($host, '.ebayimg.com')
    ) {
        return new WP_Error(
            'invalid_ebay_image_host',
            'The supplied image is not hosted by eBay.'
        );
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $clean_url  = strtok($image_url, '?');
    $candidates = [$image_url];

    /*
     * Convert URLs such as:
     * /s-l140.jpg
     *
     * Into several possible eBay sizes.
     */
    if (
        preg_match(
            '~^(.*)/s-l\d+\.(jpe?g|png|webp)$~i',
            $clean_url,
            $matches
        )
    ) {
        $base      = $matches[1];
        $extension = strtolower($matches[2]);

        $candidates = [
            "{$base}/s-l1600.{$extension}",
            "{$base}/s-l500.{$extension}",
            $image_url,
            "{$base}/s-l300.{$extension}",
            "{$base}/s-l140.{$extension}",
        ];
    }

    $candidates = array_values(array_unique($candidates));
    $errors     = [];

    foreach ($candidates as $candidate_url) {
        $attachment_id = media_sideload_image(
            $candidate_url,
            $post_id,
            get_the_title($post_id),
            'id'
        );

        if (is_wp_error($attachment_id)) {
            $errors[] = sprintf(
                '%s: %s',
                $candidate_url,
                $attachment_id->get_error_message()
            );

            error_log(
                sprintf(
                    'eBay image download failed for post ID %d using %s: %s',
                    $post_id,
                    $candidate_url,
                    $attachment_id->get_error_message()
                )
            );

            continue;
        }

        $featured_image_set = set_post_thumbnail(
            $post_id,
            $attachment_id
        );

        if (!$featured_image_set) {
            wp_delete_attachment($attachment_id, true);

            $errors[] = sprintf(
                '%s: downloaded but could not be set as featured image',
                $candidate_url
            );

            continue;
        }

        update_post_meta(
            $attachment_id,
            '_ebay_source_image_url',
            $image_url
        );

        update_post_meta(
            $post_id,
            '_ebay_featured_image_url',
            $image_url
        );

        error_log(
            sprintf(
                'eBay featured image set for post ID %d. Attachment ID: %d. Downloaded from: %s',
                $post_id,
                $attachment_id,
                $candidate_url
            )
        );

        return $attachment_id;
    }

    return new WP_Error(
        'ebay_image_download_failed',
        'All eBay image variants failed: ' . implode(' | ', $errors)
    );
}

function create_auction_posts($force = false) {
    return tcs_run_ebay_import('auction', $force);
}

function create_auction_posts_unlocked($auctions) {
    return tcs_ebay_auction_importer()->synchronize($auctions);
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
        'meta_query' => [
            'relation' => 'AND',
            [
                'key'     => 'is_buy_it_now',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => 'ebay_end_date',
                'value'   => gmdate('Y-m-d\TH:i:s\Z'),
                'compare' => '>',
                'type'    => 'CHAR',
            ],
        ],
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
                    margin: 30,
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

