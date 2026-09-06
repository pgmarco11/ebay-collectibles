jQuery(function ($) {
    const $searchForm    = $('#ebay-title-search-form');
    const $searchInput   = $('#ebay-title-search');
    const $searchButton  = $('#search-ebay');
    const $searchMessage = $('#ebay-search-message');
    const $searchResults = $('#ebay-search-results');
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
    
        if (item.price) {
            $('<strong>', {
                class: 'ebay-search-result-price',
                text: '$' + item.price
            }).appendTo($priceRow);
    
            $('<span>', {
                class: 'ebay-result-currency',
                text: item.currency
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
    
            addMeta('Type', labels.join(' · '));
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

    $searchForm.on('submit', function (event) {
        event.preventDefault();

        const query = $.trim($searchInput.val());

        if (query.length < 3) {
            $searchMessage.text(
                'Enter at least three characters.'
            );
            $searchResults.empty();
            return;
        }

        $searchButton
            .prop('disabled', true)
            .text('Searching…');

        $searchMessage.text('Searching eBay…');
        $searchResults.empty();

        $.ajax({
            url: ebay_ajax_obj.ajax_url,
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'search_ebay_items',
                nonce: ebay_ajax_obj.nonce,
                query: query
            }
        })
            .done(function (response) {
                if (!response.success) {
                    $searchMessage.text(
                        response.data?.message ||
                        'Unable to search eBay.'
                    );
                    return;
                }

                const items = response.data.items || [];

                if (!items.length) {
                    $searchMessage.text(
                        'No matching eBay listings were found.'
                    );
                    return;
                }

                $searchMessage.text(
                    items.length + ' matching listings found.'
                );

                items.forEach(function (item) {
                    $searchResults.append(
                        createResultCard(item)
                    );
                });
            })
            .fail(function (xhr) {
                const message =
                    xhr.responseJSON?.data?.message ||
                    'The eBay search could not be completed.';

                $searchMessage.text(message);
            })
            .always(function () {
                $searchButton
                    .prop('disabled', false)
                    .text('Search eBay');
            });
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
            .text('Checking…');

        $itemDetails.html(
            '<p>Loading item details…</p>'
        );

        $.get(
            ebay_ajax_obj.ajax_url,
            {
                action: 'get_ebay_item_details',
                item_id: itemId
            }
        )
            .done(function (response) {
                $itemDetails.html(response);
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
});