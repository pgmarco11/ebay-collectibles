jQuery(function ($) {
    const $searchForm    = $('#ebay-title-search-form');
    const $searchInput   = $('#ebay-title-search');
    const $searchButton  = $('#search-ebay');
    const $searchMessage = $('#ebay-search-message');
    const $searchResults = $('#ebay-search-results');
    const $loadMore      = $('#ebay-load-more');
    const $itemForm      = $('#ebay-item-form');
    const $itemInput     = $('#ebay-item-id');
    const $itemDetails   = $('#item-details');

    function createResultCard(item) {
        const $card = $('<article>', {
            class: 'ebay-search-result'
        });
    
        const $imageWrapper = $('<div>', {
            class: 'ebay-search-result-image'
        });
    
        if (item.image_url) {
            $('<img>', {
                src: item.image_url,
                alt: '',
                loading: 'lazy',
                decoding: 'async'
            }).appendTo($imageWrapper);
        } else {
            $('<span>', {
                class: 'ebay-image-placeholder',
                text: 'No image'
            }).appendTo($imageWrapper);
        }
    
        const $content = $('<div>', {
            class: 'ebay-search-result-content'
        });
    
        $('<h3>', {
            text: item.title
        }).appendTo($content);
    
        const $priceRow = $('<div>', {
            class: 'ebay-result-price-row'
        });
    
        const isAuction = Array.isArray(item.buying_options) &&
            item.buying_options.includes('AUCTION');

        const displayPrice = isAuction
            ? item.current_bid
            : item.price;

        const displayCurrency = isAuction
            ? item.bid_currency
            : item.currency;

        if (displayPrice !== '' && displayPrice != null) {
            $('<span>', {
                text: isAuction ? 'Current bid:' : 'Price:'
            }).appendTo($priceRow);

            $('<strong>', {
                class: 'ebay-search-result-price',
                text: displayPrice
            }).appendTo($priceRow);
    
            $('<span>', {
                class: 'ebay-result-currency',
                text: displayCurrency
            }).appendTo($priceRow);
        }
    
        $content.append($priceRow);
    
        const $meta = $('<dl>', {
            class: 'ebay-search-result-meta'
        });
    
        function addMeta(label, value, className) {
            if (!value) {
                return;
            }
    
            const $group = $('<div>', {
                class: 'ebay-result-meta-item' +
                    (className ? ' ' + className : '')
            });
    
            $('<dt>', {
                text: label
            }).appendTo($group);
    
            $('<dd>', {
                text: value
            }).appendTo($group);
    
            $meta.append($group);
        }

        if (isAuction) {
            addMeta(
                'Bids',
                item.bid_count == null
                    ? 'Unavailable'
                    : String(item.bid_count)
            );
        
            if (
                item.current_bid === '' ||
                item.current_bid == null
            ) {
                addMeta('Current bid', 'Unavailable');
            }
        
            if (
                item.buying_options.includes('FIXED_PRICE') &&
                item.price
            ) {
                addMeta(
                    'Buy It Now',
                    item.price + ' ' + item.currency
                );
            }
        }
    
        addMeta('Condition', item.condition);
    
        if (item.shipping_cost !== '') {
            addMeta(
                'Shipping',
                Number(item.shipping_cost) === 0
                    ? 'Free'
                    : '$' + item.shipping_cost
            );
        }
    
        if (
            Array.isArray(item.buying_options) &&
            item.buying_options.length
        ) {
            const labels = item.buying_options.map(function(option) {
                switch (option) {
                    case 'FIXED_PRICE':
                        return 'Buy It Now';
    
                    case 'AUCTION':
                        return 'Auction';
    
                    case 'BEST_OFFER':
                        return 'Best Offer';
    
                    default:
                        return option
                            .toLowerCase()
                            .replaceAll('_', ' ')
                            .replace(/\b\w/g, function(letter) {
                                return letter.toUpperCase();
                            });
                }
            });
    
            addMeta('Type', labels.join(' \u00b7 '));
        }
    
        addMeta(
            'Item ID',
            item.item_id,
            'ebay-result-item-id'
        );
    
        $content.append($meta);
    
        const $actions = $('<div>', {
            class: 'ebay-search-result-actions'
        });
    
        $('<button>', {
            type: 'button',
            class: 'use-ebay-item',
            text: 'Use This Item',
            'data-item-id': item.item_id,
            'aria-label': 'Use Item ID ' +
                item.item_id +
                ' for ' +
                item.title
        }).appendTo($actions);
    
        if (item.item_url) {
            $('<a>', {
                class: 'view-ebay-item',
                href: item.item_url,
                target: '_blank',
                rel: 'noopener noreferrer',
                text: 'View on eBay',
                'aria-label': 'View ' +
                    item.title +
                    ' on eBay in a new tab'
            }).appendTo($actions);
        }
    
        $content.append($actions);
        $card.append($imageWrapper, $content);
    
        return $card;
    }

    let searchQuery = '';
    let nextOffset = null;
    let searchBusy = false;
    
    const seenItems = new Set();
    
    const categoryTerm = new URLSearchParams(
        window.location.search
    ).get('category_term') || '';
    
    function loadSearchPage(append) {
        if (
            searchBusy ||
            (append && nextOffset === null)
        ) {
            return;
        }
    
        const offset = append ? nextOffset : 0;
    
        searchBusy = true;
    
        $searchButton
            .prop('disabled', true)
            .text('Searching...');
    
        $searchInput.prop('disabled', true);
    
        $loadMore
            .prop('disabled', true)
            .text('Loading...');
    
        $searchResults.attr('aria-busy', 'true');
        $searchMessage.text('Searching eBay...');
    
        $.ajax({
            url: ebay_ajax_obj.ajax_url,
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'search_ebay_items',
                nonce: ebay_ajax_obj.nonce,
                query: searchQuery,
                category_term: categoryTerm,
                offset: offset
            }
        })
            .done(function (response) {
                if (
                    !response?.success ||
                    !Array.isArray(response.data?.items)
                ) {
                    $searchMessage.text(
                        response?.data?.message ||
                        'Unable to search eBay.'
                    );
    
                    return;
                }
    
                const data = response.data;
    
                nextOffset =
                    Number.isInteger(data.next_offset) &&
                    data.next_offset > offset &&
                    data.next_offset < 10000 &&
                    data.next_offset % 12 === 0
                        ? data.next_offset
                        : null;
    
                data.items.forEach(function (item) {
                    const id = String(item.item_id || '');
    
                    if (!id || seenItems.has(id)) {
                        return;
                    }
    
                    seenItems.add(id);
    
                    $searchResults.append(
                        createResultCard(item)
                    );
                });
    
                $searchMessage.text(
                    seenItems.size
                        ? seenItems.size + ' listings loaded.' +
                            (
                                nextOffset === null
                                    ? ' No more results.'
                                    : ''
                            )
                        : nextOffset !== null
                            ? 'No displayable listings on this page. Choose Load more.'
                            : 'No matching eBay listings were found.'
                );
            })
            .fail(function (xhr) {
                $searchMessage.text(
                    xhr.responseJSON?.data?.message ||
                    'The eBay search could not be completed.'
                );
            })
            .always(function () {
                searchBusy = false;
    
                $searchButton
                    .prop('disabled', false)
                    .text('Search eBay');
    
                $searchInput.prop('disabled', false);
    
                $searchResults.attr('aria-busy', 'false');
    
                $loadMore
                    .prop('hidden', nextOffset === null)
                    .prop('disabled', false)
                    .text('Load more');
            });
    }
    
    $searchForm.on('submit', function (event) {
        event.preventDefault();
    
        if (searchBusy) {
            return;
        }
    
        searchQuery = $.trim($searchInput.val());
        nextOffset = null;
    
        seenItems.clear();
        $searchResults.empty();
        $loadMore.prop('hidden', true);
    
        if (searchQuery.length < 3) {
            $searchMessage.text(
                'Enter at least three characters.'
            );
    
            return;
        }
    
        loadSearchPage(false);
    });
    
    $loadMore.on('click', function () {
        loadSearchPage(true);
    });

    $searchResults.on(
        'click',
        '.use-ebay-item',
        function () {
            const itemId = String(
                $(this).data('item-id') || ''
            );

            $itemInput
                .val(itemId)
                .trigger('change')
                .focus();

            document
                .querySelector('#ebay-item-form')
                ?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
        }
    );

    $itemForm.on('submit', function (event) {
        event.preventDefault();

        const itemId = $.trim($itemInput.val());

        if (!/^\d+$/.test(itemId)) {
            $itemDetails.html(
                '<p class="error">' +
                'Please enter a valid numeric item ID.' +
                '</p>'
            );
            return;
        }

        $('#check-item')
            .prop('disabled', true)
            .text('Checking...');

        $itemDetails.html(
            '<p>Loading item details...</p>'
        );

        $.get(
            ebay_ajax_obj.ajax_url,
            {
                action: 'get_ebay_item_details',
                item_id: itemId,
                nonce: ebay_ajax_obj.nonce
            }
        )
            .done(function (response) {
                $itemDetails.html(response);
                $(document).trigger('tcs:wishlist-refresh');
            })
            .fail(function () {
                $itemDetails.html(
                    '<p class="error">' +
                    'The item details could not be loaded.' +
                    '</p>'
                );
            })
            .always(function () {
                $('#check-item')
                    .prop('disabled', false)
                    .text('Check Item');
            });
    });
    // The existing item-ID flow takes precedence over a title search.
    const toolsParams = new URLSearchParams(window.location.search);
    const requestedItemId = (toolsParams.get('item_id') || '').trim();
    const requestedSearch = (toolsParams.get('search_query') || '').trim();

    if (!/^\d+$/.test(requestedItemId) && requestedSearch && $searchForm.length) {
        $searchInput.val(requestedSearch);
        $searchForm.trigger('submit');
    }
});

