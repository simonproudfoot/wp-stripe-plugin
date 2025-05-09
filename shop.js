// shop.js
jQuery(document).ready(function ($) {
    // Add to cart (existing)
    $('.add-to-cart').on('click', function () {
        var productId = $(this).data('product-id');
        $.ajax({
            url: ShopAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'add_to_cart',
                product_id: productId
            },
            success: function (response) {
                if (response.success) {
                    alert('Added to cart!');
                    var cartCount = Object.values(response.data).reduce((a, b) => a + b, 0);
                    $('.cart-count').text(cartCount);
                } else {
                    alert('Failed to add to cart.');
                }
            },
            error: function (xhr, status, error) {
                alert('An error occurred: ' + error);
            }
        });
    });

    // Update quantity
    $('.cart-quantity').on('change', function () {
        var productId = $(this).data('product-id');
        var quantity = parseInt($(this).val());
        var price = parseFloat($(this).closest('tr').find('td:eq(1)').text().replace('£', ''));
        $.ajax({
            url: ShopAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'update_cart_quantity',
                product_id: productId,
                quantity: quantity
            },
            success: function (response) {
                if (response.success) {
                    var subtotal = (price * quantity).toFixed(2);
                    $(`tr[data-product-id="${productId}"] .subtotal`).text('£' + subtotal);
                    updateCartTotal(response.data);
                    var cartCount = Object.values(response.data).reduce((a, b) => a + b, 0);
                    $('.menu-item a:contains("Cart")').text('🛒 Cart (' + cartCount + ')');
                }
            },
            error: function (xhr, status, error) {
                alert('Error updating quantity: ' + error);
            }
        });
    });

    // Remove item
    $('.remove-item').on('click', function () {
        var productId = $(this).data('product-id');
        $.ajax({
            url: ShopAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'remove_from_cart',
                product_id: productId
            },
            success: function (response) {
                if (response.success) {
                    $(`tr[data-product-id="${productId}"]`).remove();
                    updateCartTotal(response.data);
                    var cartCount = Object.values(response.data).reduce((a, b) => a + b, 0);
                    $('.menu-item a:contains("Cart")').text('🛒 Cart (' + cartCount + ')');
                    if (Object.keys(response.data).length === 0) {
                        $('.shop-checkout').html('<p>Your cart is empty.</p><a href="' + window.location.origin + '/shop">Back to Shop</a>');
                    }
                }
            },
            error: function (xhr, status, error) {
                alert('Error removing item: ' + error);
            }
        });
    });

    // Function to update cart total
    function updateCartTotal(cart) {
        var total = 0;
        $('.cart-table tbody tr').each(function () {
            var price = parseFloat($(this).find('td:eq(1)').text().replace('£', ''));
            var quantity = parseInt($(this).find('.cart-quantity').val());
            total += price * quantity;
        });
        $('.cart-total').text('£' + total.toFixed(2));
    }
});