jQuery(document).ready(function($) {
    $('#check-item').on('click', function() {
        let item_id = $('input[name="item_id"]').val();
        if (!item_id) {
            $('#item-details').html('<p class="error">Please enter an item ID</p>');
            return;
        }
        $.get(ebay_ajax_obj.ajax_url, {
            action: "get_ebay_item_details",
            item_id: item_id
        }, function(response) {
            $('#item-details').html(response);
        });
    });
});
