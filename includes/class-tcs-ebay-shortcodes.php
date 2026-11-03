<?php
defined('ABSPATH') || exit;

/** Owns ebay shortcodes behavior; legacy function entry points delegate here. */
class TCS_Ebay_Shortcodes {
    private $listings;

    public function __construct(TCS_Ebay_Listing_Service $listings) {
        $this->listings = $listings;
    }

    private function render_loading(): string {
        return '<div class="tcs-ebay-loading" role="status">'
            . '<span class="tcs-ebay-loading-spinner" aria-hidden="true"></span>'
            . '<span class="screen-reader-text">'
            . 'Preparing eBay listings. Refresh this page shortly.'
            . '</span></div>';
    }

    public function render_buy_it_now(
        $category_query,
        $category_name,
        $search_keywords = ''
    ) {
        $GLOBALS['tcs_ebay_listings_pending'] = false;

        $candidates = tcs_top_items_buy_now_candidates($category_query, 5, $search_keywords);
        $items = array_map(static function ($item) {
            return [
                'title' => $item['title'],
                'itemWebUrl' => $item['viewItemURL'],
                'image' => ['imageUrl' => $item['imageURL']],
                'price' => ['value' => $item['price'], 'currency' => $item['currency']],
            ];
        }, $candidates);

        if (!$items) {
            if (!empty($GLOBALS['tcs_ebay_listings_pending'])) {
                return $this->render_loading();
            }

            return '<p>No Buy It Now items found for this category.</p>';
        }

        ob_start();
        ?>
    <div class="ebay-top-widget">
        <h2>
            Top eBay Buy It Now Items in
            <?php echo esc_html($category_name); ?>
        </h2>

        <div class="ebay-grid">
            <?php foreach (array_slice($items, 0, 5) as $item): ?>
                <div class="ebay-item">
                    <?php if (!empty($item['image']['imageUrl'])): ?>
                        <img
                            src="<?php echo esc_url($item['image']['imageUrl']); ?>"
                            alt="<?php echo esc_attr($item['title'] ?? ''); ?>"
                            class="ebay-item-image"
                            loading="lazy"
                        >
                    <?php endif; ?>

                    <div class="ebay-item-details">
                        <a
                            href="<?php echo esc_url($item['itemWebUrl'] ?? ''); ?>"
                            target="_blank"
                            rel="noopener"
                            class="ebay-item-title"
                        >
                            <?php
                            echo esc_html(wp_trim_words(
                                    $item['title'] ?? '',
                                    10
                                ));
                                ?>
                        </a>

                        <p class="ebay-item-price">
                            <?php
                            echo esc_html(
                                    number_format(
                                        (float) ($item['price']['value'] ?? 0),
                                        2
                                    ) . ' ' .
                                    ($item['price']['currency'] ?? 'USD')
                                );
                                ?>
                        </p>

                        <p>Buy It Now</p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php

        return ob_get_clean();
    }

    public function render_top_widget($atts, bool $poll = false) {

        $GLOBALS['tcs_ebay_listings_pending'] = false;

        if (!$poll && tcs_ebay_is_editor_request()) {
            return '<p>eBay listings are displayed when viewing the page.</p>';
        }

        $atts = shortcode_atts([
            'category' => '',
            'type'     => 'auto',
        ], $atts, 'ebay_top_5_widget');

        $requested_type = strtolower(trim((string) $atts['type']));

        if (!in_array($requested_type, ['auto', 'auction', 'buynow'], true)) {
            return '<p>Invalid listing type. Use auction or buynow.</p>';
        }

        $category_slug = trim((string) $atts['category']);
        $category = null;

        if ($category_slug !== '') {
            $category = get_category_by_slug($category_slug);
        } elseif (is_category()) {
            $category = get_queried_object();
        } else {
            return '<p>This widget requires a category slug or must be used on a category page.</p>';
        }

        if ($category instanceof WP_Term && $category->taxonomy === 'category') {
            $category_name = $category->name;
            $category_query = tcs_ebay_category_search_phrase($category);
        } elseif ($category_slug !== '') {
            // Preserve support for an explicit category without a matching WP term.
            $category_query = trim(str_replace('-', ' ', $category_slug));
            $category_name = ucwords($category_query);
        } else {
            return '<p>Invalid category.</p>';
        }

        $is_auctions_root =
        $category instanceof WP_Term &&
        $category->taxonomy === 'category' &&
        $category->slug === 'auctions';

        if ($is_auctions_root) {
            $category_query = '';
        } elseif ($category_query === '') {
            return '<p>Invalid category.</p>';
        }

        $search_keywords = '';

        if (
            $category instanceof WP_Term &&
            $category->taxonomy === 'category' &&
            (int) $category->parent > 0 &&
            !$is_auctions_root
        ) {
            $search_keywords = trim(html_entity_decode(
                $category->name,
                ENT_QUOTES,
                get_bloginfo('charset') ?: 'UTF-8'
            ));
        }

        $is_collectibles_branch = false;

        if (
            $category instanceof WP_Term &&
            $category->taxonomy === 'category'
        ) {
            $ancestors = get_ancestors(
                $category->term_id,
                'category',
                'taxonomy'
            );

            $root_id = $ancestors
                ? (int) end($ancestors)
                : (int) $category->term_id;

            $root = get_term($root_id, 'category');

            $is_collectibles_branch =
                $root instanceof WP_Term &&
                $root->slug === 'collectibles';
        }

        $listing_type = $requested_type === 'auto'
        ? ($is_collectibles_branch ? 'buynow' : 'auction')
        : $requested_type;

        if ($listing_type === 'buynow') {
            /*
            * The Auctions grouping page has no product-category phrase.
            * Require a specific category for a Buy It Now override there.
            */
            if ($category_query === '') {
                return '<p>Please specify a product category for Buy It Now items.</p>';
            }

            return $this->render_buy_it_now(
                $category_query,
                $category_name,
                $search_keywords
            );
        }

        if ($is_auctions_root) {
            $ranked = $this->listings->auction_parent_items(
                $category,
                5
            );
        } else {
            $ranked = fetch_ebay_ranked_items(
                $category_query,
                5,
                $search_keywords
            );
        }

        if (!$ranked || !is_array($ranked)) {

            if (!empty($GLOBALS['tcs_ebay_listings_pending'])) {
                if ($poll) {
                    return '';
                }

                $slug = $category instanceof WP_Term
                    ? $category->slug
                    : sanitize_text_field($category_slug);

                return '<div class="tcs-ebay-loading" role="status"'
                    . ' data-tcs-ebay-auction-pending'
                    . ' data-category="' . esc_attr($slug) . '"'
                    . ' data-url="' . esc_url(admin_url('admin-ajax.php')) . '"'
                    . ' data-nonce="' . esc_attr(wp_create_nonce(
                        'tcs_ebay_auction_widget_' . $slug
                    )) . '">'
                    . '<span class="tcs-ebay-loading-spinner" aria-hidden="true"></span>'
                    . '<span class="screen-reader-text">Loading eBay auctions</span>'
                    . '</div>';
            }

            return '<p>No items found for this category.</p>';
        }


        $category_name_esc = esc_html($category_name);

        ob_start();
        ?>

    <div class="ebay-top-widget">

    <h2>
        <?php
        echo $is_auctions_root
                ? 'Top eBay Auctions'
                : 'Top eBay Auctions in ' . $category_name_esc;
            ?>
    </h2>

        <div
            class="ebay-tabs"
            role="group"
            aria-label="Auction ranking"
        >
            <button
                type="button"
                class="ebay-tab active"
                data-tab="hot"
                aria-pressed="true"
            >🔥 Hot</button>
            <button
                type="button"
                class="ebay-tab"
                data-tab="bids"
                aria-pressed="false"
            >📈 Most Bids</button>
            <button
                type="button"
                class="ebay-tab"
                data-tab="ending"
                aria-pressed="false"
            >⏰ Ending Soon</button>
        </div>

        <?php foreach (['hot', 'bids', 'ending'] as $tab): ?>
            <div
                class="ebay-tab-content <?php echo $tab === 'hot' ? 'active' : ''; ?>"
                data-tab-panel="<?php echo esc_attr($tab); ?>"
                <?php echo $tab !== 'hot' ? 'hidden' : ''; ?>
            >
                <div class="ebay-grid">

                    <?php foreach ($ranked[$tab] as $item): ?>

                        <div class="ebay-item">

                            <?php if (!empty($item['imageURL'])): ?>
                                <img src="<?php echo esc_url($item['imageURL']); ?>"
                                     alt="<?php echo esc_attr(wp_trim_words($item['title'], 10)); ?>"
                                     class="ebay-item-image"
                                     loading="lazy">
                            <?php endif; ?>

                            <div class="ebay-item-details">

                                <a href="<?php echo esc_url($item['viewItemURL']); ?>"
                                   target="_blank"
                                   class="ebay-item-title">
                                    <?php echo esc_html(wp_trim_words($item['title'], 10)); ?>
                                </a>

                                <p class="ebay-item-price">
                                    $<?php echo number_format(floatval($item['price']), 2); ?>
                                </p>

                                <p>Bids: <?php echo esc_html($item['bidCount']); ?></p>
                                <p>Ends in: <?php echo esc_html( ebay_time_left( $item['endTime'] ?? null ) ); ?></p>

                            </div>
                        </div>

                    <?php endforeach; ?>

                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php

        return ob_get_clean();
    }

    public function render_top_items($atts, ?int $poll_post_id = null) {

        $GLOBALS['tcs_ebay_listings_pending'] = false;

        if ($poll_post_id === null && tcs_ebay_is_editor_request()) {
            return '<p>eBay listings are displayed when viewing the page.</p>';
        }

        $atts = shortcode_atts([
            'type'  => 'auction',
            'limit' => 5,
        ], $atts, 'ebay_top_items');

        $type = strtolower(trim((string) $atts['type']));

        if (!in_array($type, ['auction', 'buynow'], true)) {
            return '<p>Invalid type. Use auction or buynow.</p>';
        }

        $requested_limit = filter_var(
            $atts['limit'],
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 200]]
        );

        if ($requested_limit === false) {
            return '<p>Limit must be a whole number between 1 and 200.</p>';
        }

        $section_limit = min(5, $requested_limit);

        $post_id = $poll_post_id ?? (int) get_the_ID();
        $groups = tcs_top_items_categories($type, $post_id);

        if (!$groups) {
            return '<p>No suitable WordPress categories were found.</p>';
        }

        $sections = [];
        $cutoff = time() + 300;

        foreach ($groups as $group) {
            $children = get_terms([
                'taxonomy'   => 'category',
                'parent'     => $group->term_id,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ]);

            if (is_wp_error($children)) {
                continue;
            }

            /*
             * Children get individual listing sections.
             * A group without children displays its own listings.
             */
            $listing_categories = $children ?: [$group];

            foreach ($listing_categories as $listing_category) {
                $pool = [];

                foreach (
                    tcs_top_items_group_categories($listing_category)
                    as $category
                ) {
                $phrase = tcs_ebay_category_search_phrase($category);

                if ($phrase === '') {
                    continue;
                }

                // Keep each descendant's search relevant to its name.
                $keywords = html_entity_decode(
                    $category->name,
                    ENT_QUOTES,
                    get_bloginfo('charset') ?: 'UTF-8'
                );

                if ($type === 'auction') {
                    $ranked = fetch_ebay_ranked_items(
                        $phrase,
                        $section_limit,
                        $keywords
                    );

                    if (!is_array($ranked)) {
                        continue;
                    }

                    $candidates = array_merge(
                        $ranked['hot'] ?? [],
                        $ranked['bids'] ?? [],
                        $ranked['ending'] ?? []
                    );
                } else {
                    $candidates = tcs_top_items_buy_now_candidates(
                        $phrase,
                        max(10, $section_limit),
                        $keywords
                    );
                }

                foreach ($candidates as $item) {
                    $url = $item['viewItemURL'] ?? '';
                    $end = (int) ($item['endTimeUnix'] ?? 0);

                    if ($url === '' || $url === '#') {
                        continue;
                    }

                    if (
                        ($type === 'auction' && $end <= $cutoff) ||
                        ($type === 'buynow' && $end && $end <= $cutoff)
                    ) {
                        continue;
                    }

                    $pool[$url] = $item;
                }
            }

            // Hide sections with no qualifying listings.
            if (!$pool) {
                continue;
            }

            $items = array_values($pool);

            if ($type === 'auction') {
                $hot = $items;
                usort($hot, static function ($a, $b) {
                    return ($b['hotScore'] ?? 0)
                        <=> ($a['hotScore'] ?? 0);
                });

                $bids = $items;
                usort($bids, static function ($a, $b) {
                    return
                        (($b['bidCount'] ?? 0) <=> ($a['bidCount'] ?? 0))
                        ?: (($b['hotScore'] ?? 0) <=> ($a['hotScore'] ?? 0));
                });

                $ending = $items;
                usort($ending, static function ($a, $b) {
                    return
                        (($a['endTimeUnix'] ?? PHP_INT_MAX)
                            <=> ($b['endTimeUnix'] ?? PHP_INT_MAX))
                        ?: (($b['bidCount'] ?? 0) <=> ($a['bidCount'] ?? 0));
                });

                $panels = [
                    'hot'    => array_slice($hot, 0, $section_limit),
                    'bids'   => array_slice($bids, 0, $section_limit),
                    'ending' => array_slice($ending, 0, $section_limit),
                ];
            } else {
                $panels = [
                    'buynow' => array_slice($items, 0, $section_limit),
                ];
            }

            $sections[] = [
                'group_id'   => $group->term_id,
                'group_name' => $group->name,
                'has_children' => !empty($children),
                'name'       => $listing_category->name,
                'panels'     => $panels,
            ];
            }
        }

        if (!$sections) {

            if (!empty($GLOBALS['tcs_ebay_listings_pending'])) {
                if ($poll_post_id !== null) {
                    return '';
                }
            
                if ($post_id > 0) {
                    $nonce = wp_create_nonce(
                        'tcs_ebay_top_items_' . $post_id . '_' . $section_limit . '_' . $type
                    );

                    return '<div class="tcs-ebay-loading" role="status"'
                        . ' data-tcs-ebay-top-items-pending'
                        . ' data-post-id="' . esc_attr($post_id) . '"'
                        . ' data-limit="' . esc_attr($section_limit) . '"'
                        . ' data-type="' . esc_attr($type) . '"'
                        . ' data-url="' . esc_url(admin_url('admin-ajax.php')) . '"'
                        . ' data-nonce="' . esc_attr($nonce) . '">'
                        . '<span class="tcs-ebay-loading-spinner" aria-hidden="true"></span>'
                        . '<span class="screen-reader-text">Loading eBay auctions</span>'
                        . '</div>';
                }
            
                return '<p role="status" class="text-white">eBay listings are being prepared. Please refresh this page shortly.</p>';
            }

            return '<p class="text-white">No matching eBay items are available right now.</p>';
        }

        $labels = [
            'hot'    => 'Trending',
            'bids'   => 'Most bids',
            'ending' => 'Ending soon',
            'buynow' => 'Buy It Now',
        ];

        $instance_id = $poll_post_id === null
            ? wp_unique_id('tcs-ebay-page-')
            : 'tcs-ebay-page-' . wp_generate_uuid4();


        ob_start();
        ?>
    <div
        id="<?php echo esc_attr($instance_id); ?>"
        class="tcs-ebay-page"
    >
    <?php
            $previous_group_id = null;

                foreach ($sections as $index => $section):
                    $section_id = $instance_id . '-section-' . $index;
                    $first_key = $type === 'auction' ? 'hot' : 'buynow';

                    if ($previous_group_id !== $section['group_id']):
                        $previous_group_id = $section['group_id'];
                ?>
                <h2 class="tcs-ebay-group-heading">
                    <?php echo esc_html($section['group_name']); ?>
                </h2>

            <?php
                endif;
                ?>
            <section
                class="tcs-ebay-section"
                aria-labelledby="<?php echo esc_attr($section_id . '-heading'); ?>"
            >
                <header class="tcs-ebay-section-header">
                    <span class="tcs-ebay-type">
                        <?php echo $type === 'auction' ? 'Auctions' : 'Buy It Now'; ?>
                    </span>
                    <?php if ($section['has_children']): ?>
                        <h3 id="<?php echo esc_attr($section_id . '-heading'); ?>">
                            <?php echo esc_html($section['name']); ?>
                        </h3>
                    <?php else: ?>
                        <span
                            id="<?php echo esc_attr($section_id . '-heading'); ?>"
                            class="tcs-ebay-sr-only"
                        >
                            <?php echo esc_html($section['name'] . ' listings'); ?>
                        </span>
                    <?php endif; ?>

                </header>

                <?php if ($type === 'auction'): ?>
                    <div
                        class="tcs-ebay-tabs"
                        role="tablist"
                        aria-label="<?php
                            echo esc_attr($section['name'] . ' auction rankings');
                            ?>"
                    >
                        <?php foreach ($section['panels'] as $key => $items):
                                $active = $key === $first_key;
                            ?>
                            <button
                                type="button"
                                class="tcs-ebay-tab"
                                role="tab"
                                id="<?php echo esc_attr($section_id . '-tab-' . $key); ?>"
                                aria-controls="<?php echo esc_attr($section_id . '-panel-' . $key); ?>"
                                aria-selected="<?php echo $active ? 'true' : 'false'; ?>"
                                tabindex="<?php echo $active ? '0' : '-1'; ?>"
                            >
                                <?php echo esc_html($labels[$key]); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php foreach ($section['panels'] as $key => $items): ?>
                    <div
                        class="tcs-ebay-panel"
                        id="<?php echo esc_attr($section_id . '-panel-' . $key); ?>"
                        <?php if ($type === 'auction'): ?>
                            role="tabpanel"
                            aria-labelledby="<?php echo esc_attr($section_id . '-tab-' . $key); ?>"
                            tabindex="0"
                        <?php endif; ?>
                        <?php echo $key !== $first_key ? 'hidden' : ''; ?>
                    >
                        <ul class="tcs-ebay-cards">
                            <?php foreach ($items as $item): ?>
                                <li class="tcs-ebay-card">
                                    <a
                                        class="tcs-ebay-image-link"
                                        href="<?php echo esc_url($item['viewItemURL']); ?>"
                                        target="_blank"
                                        rel="noopener"
                                        tabindex="-1"
                                        aria-hidden="true"
                                    >
                                        <?php if (!empty($item['imageURL'])): ?>
                                            <img
                                                src="<?php echo esc_url($item['imageURL']); ?>"
                                                alt=""
                                                loading="lazy"
                                                decoding="async"
                                            >
                                        <?php else: ?>
                                            <span class="tcs-ebay-no-image">
                                                No image available
                                            </span>
                                        <?php endif; ?>
                                    </a>

                                    <div class="tcs-ebay-card-body">
                                        <h3 class="tcs-ebay-card-title">
                                            <a
                                                href="<?php echo esc_url($item['viewItemURL']); ?>"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                <?php echo esc_html($item['title'] ?? 'Untitled item'); ?>
                                                <span class="tcs-ebay-sr-only">
                                                    (opens in a new tab)
                                                </span>
                                            </a>
                                        </h3>

                                        <p class="tcs-ebay-price">
                                            <?php
                                            echo esc_html(
                                                    number_format(
                                                        (float) ($item['price'] ?? 0),
                                                        2
                                                    ) . ' ' .
                                                    ($item['currency'] ?? 'USD')
                                                );
                                                ?>
                                        </p>

                                        <?php if ($type === 'auction'): ?>
                                            <dl class="tcs-ebay-meta">
                                                <div>
                                                    <dt>Bids</dt>
                                                    <dd><?php
                                                        echo esc_html($item['bidCount'] ?? 0);
                                                        ?></dd>
                                                </div>
                                                <div>
                                                    <dt>Ends in</dt>
                                                    <dd><?php
                                                        echo esc_html(ebay_time_left(
                                                                gmdate(
                                                                    'Y-m-d\TH:i:s\Z',
                                                                    (int) $item['endTimeUnix']
                                                                )
                                                            ));
                                                        ?></dd>
                                                </div>
                                            </dl>
                                        <?php else: ?>
                                            <p class="tcs-ebay-buy-label">
                                                Buy It Now
                                            </p>
                                        <?php endif; ?>

                                        <?php $tools_url = tcs_top_items_tools_url($item); ?>
                                        <?php if ($tools_url !== ''): ?>
                                            <a
                                                class="btn btn-secondary tcs-ebay-tools-link"
                                                href="<?php echo esc_url($tools_url); ?>"
                                                aria-label="<?php echo esc_attr('Check item on Tools: ' . ($item['title'] ?? 'eBay listing')); ?>"
                                            >Check Item on Tools</a>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </div>
    <?php

        return ob_get_clean();
    }

}
