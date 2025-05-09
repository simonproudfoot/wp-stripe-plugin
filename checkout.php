<?php
// checkout.php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load WordPress environment (4 levels up as confirmed)
require_once(dirname(__FILE__, 4) . '/wp-load.php');

// Get header first to ensure proper rendering of navigation
get_header();

// Create main container with proper spacing/padding to avoid navbar overlap
echo '<div class="shop-checkout">';
echo '<h1 class="font-heading text-3xl">Checkout</h1>';

// Get Stripe secret key
$stripe_secret_key = get_option('stripe_secret_key');
$stripe_publishable_key = get_option('stripe_publishable_key');

// Check if Stripe keys are set - error displayed in main container
if (empty($stripe_secret_key) || empty($stripe_publishable_key)) {
    echo '<div class="error-message">Stripe configuration is missing. Please contact the site administrator to set up Stripe keys in Shop Settings.</div>';
    echo '<a href="' . get_post_type_archive_link('shop') . '" class="back-to-shop">Back to Shop</a>';
    echo '</div>';
    get_footer();
    exit;
}

// Include Stripe PHP library with init.php (or autoload.php if you updated)
$stripe_init_path = plugin_dir_path(__FILE__) . 'stripe-php/init.php'; // Adjust if using a different version or autoload.php
if (file_exists($stripe_init_path)) {
    require_once $stripe_init_path;
} else {
    echo '<div class="error-message">Stripe PHP library init file not found. Please place the full stripe-php/ folder (including init.php) in the plugin directory.</div>';
    echo '<a href="' . get_post_type_archive_link('shop') . '" class="back-to-shop">Back to Shop</a>';
    echo '</div>';
    get_footer();
    exit;
}

\Stripe\Stripe::setApiKey($stripe_secret_key);

// Get cart items from session
$cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
$line_items = [];
$total = 0;

// Handle successful payment
if (isset($_GET['success']) && $_GET['success'] == 1 && !empty($cart)) {
    foreach ($cart as $product_id => $quantity) {
        $current_stock = intval(get_post_meta($product_id, 'stock', true));
        $new_stock = max(0, $current_stock - $quantity); // Prevent negative stock
        update_post_meta($product_id, 'stock', $new_stock);
        if ($new_stock == 0) {
            update_post_meta($product_id, 'sold_out', 1); // Mark as sold out if stock reaches 0
        }
    }
    unset($_SESSION['cart']); // Clear cart after updating stock
}

// Check stock before creating Stripe session
$inventory_errors = [];
if (!empty($cart)) {
    foreach ($cart as $product_id => $quantity) {
        $stock = intval(get_post_meta($product_id, 'stock', true));
        if ($stock < $quantity) {
            $inventory_errors[] = 'Insufficient stock for <strong>' . esc_html(get_the_title($product_id)) . '</strong>. Only ' . $stock . ' available.';
        } else {
            $price = floatval(get_post_meta($product_id, 'price', true));
            if (!$price) continue; // Skip if price is missing
            $product_title = get_the_title($product_id);
            $subtotal = $price * $quantity;
            $total += $subtotal;

            $line_items[] = [
                'price_data' => [
                    'currency' => 'gbp',
                    'product_data' => [
                        'name' => $product_title,
                    ],
                    'unit_amount' => $price * 100, // Stripe expects pence
                ],
                'quantity' => $quantity,
            ];
        }
    }
}

// Display inventory errors if any
if (!empty($inventory_errors)) {
    echo '<div class="error-message">';
    echo '<h3>Stock Issues</h3>';
    echo '<ul>';
    foreach ($inventory_errors as $error) {
        echo '<li>' . $error . '</li>';
    }
    echo '</ul>';
    echo '<p>Please adjust quantities or remove items to continue.</p>';

    // Add buttons for actions
    echo '<div class="action-buttons">';
    echo '<a href="' . get_post_type_archive_link('shop') . '" class="button">Continue Shopping</a>';
    echo '<button id="clear-cart" class="button remove">Clear Cart</button>';
    echo '</div>';

    // Add JavaScript for clearing the cart
    echo '<script>
        document.getElementById("clear-cart").addEventListener("click", function() {
            if (confirm("Are you sure you want to clear your cart?")) {
                var xhr = new XMLHttpRequest();
                xhr.open("POST", "' . admin_url('admin-ajax.php') . '", true);
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        window.location.href = "' . get_post_type_archive_link('shop') . '";
                    }
                };
                xhr.send("action=remove_from_cart&product_id=all");
            }
        });
    </script>';

    echo '</div>';
}

// Create Stripe Checkout session if there are items and no errors
$checkout_session = null;
if (!empty($line_items) && empty($inventory_errors)) {
    try {
        $checkout_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => $line_items,
            'mode' => 'payment',
            'success_url' => home_url('/?checkout=1&success=1'),
            'cancel_url' => home_url('/?checkout=1'),
        ]);

        // Debugging - check if session ID is generated
        error_log('Stripe Session ID: ' . $checkout_session->id);
    } catch (Exception $e) {
        error_log('Stripe Error: ' . $e->getMessage());
        echo '<div class="error-message">Error creating Stripe checkout session: ' . esc_html($e->getMessage()) . '</div>';
        echo '<a href="' . get_post_type_archive_link('shop') . '" class="back-to-shop">Back to Shop</a>';
        echo '</div>';
        get_footer();
        exit;
    }
}

// Display cart contents
if (empty($cart)) {
    echo '<p>Your cart is empty.</p>';
    echo '<a href="' . get_post_type_archive_link('shop') . '" class="back-to-shop">Back to Shop</a>';
} else {
?>
    <table class="cart-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Price</th>
                <th>Quantity</th>
                <th>Subtotal</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cart as $product_id => $quantity): ?>
                <tr data-product-id="<?php echo esc_attr($product_id); ?>">
                    <td><?php echo esc_html(get_the_title($product_id)); ?></td>
                    <td>£<?php echo number_format(get_post_meta($product_id, 'price', true), 2); ?></td>
                    <td>
                        <input type="number" class="cart-quantity" min="1" value="<?php echo esc_attr($quantity); ?>" data-product-id="<?php echo esc_attr($product_id); ?>">
                    </td>
                    <td class="subtotal">£<?php echo number_format(get_post_meta($product_id, 'price', true) * $quantity, 2); ?></td>
                    <td>
                        <button class="remove-item" data-product-id="<?php echo esc_attr($product_id); ?>">Remove</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td colspan="3"><strong>Total</strong></td>
                <td class="cart-total"><strong>£<?php echo number_format($total, 2); ?></strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <p class="success-message">Payment successful! Thank you for your purchase.</p>
    <?php elseif (empty($inventory_errors) && $checkout_session): ?>
        <div class="checkout-actions">
            <script src="https://js.stripe.com/v3/"></script>
            <button id="checkout-button" class="checkout-button">Proceed to Payment</button>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var stripe = Stripe('<?php echo esc_js($stripe_publishable_key); ?>');
                var checkoutButton = document.getElementById('checkout-button');

                checkoutButton.addEventListener('click', function() {
                    // Show loading state
                    this.innerHTML = 'Redirecting to Stripe...';
                    this.disabled = true;
                    this.classList.add('loading');

                    // Redirect to Stripe Checkout
                    stripe.redirectToCheckout({
                        sessionId: '<?php echo $checkout_session->id; ?>'
                    }).then(function(result) {
                        if (result.error) {
                            alert(result.error.message);
                            checkoutButton.innerHTML = 'Proceed to Payment';
                            checkoutButton.disabled = false;
                            checkoutButton.classList.remove('loading');
                        }
                    });
                });
            });
        </script>
    <?php endif; ?>
<?php
}
echo '</div>'; // Close shop-checkout div
?>


<?php get_footer(); ?>


<style>
    .shop-checkout {
        max-width: 900px;
        margin: 0;
        padding: 40px;

        margin-top: 200px;

        background-color: #fff;

        border-radius: 12px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
        z-index: 1000;
    }



    .text-3xl {
        font-size: 1.875rem;
        line-height: 2.25rem;
    }

    .shop-checkout h1 {
        margin-top: 0;
        margin-bottom: 30px;
        color: #222;
        border-bottom: 2px solid #f0f0f0;
        padding-bottom: 15px;

    }

    .error-message {
        background-color: #fff8f8;
        border-left: 4px solid #f44336;
        padding: 20px;
        margin-bottom: 25px;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
    }

    .error-message h3 {
        margin-top: 0;
        color: #d32f2f;
        font-size: 18px;
        margin-bottom: 10px;
    }

    .error-message ul {
        margin-bottom: 15px;
        padding-left: 20px;
    }

    .error-message li {
        margin-bottom: 8px;
        color: #555;
    }

    .cart-table {
        width: 100%;
        border-collapse: collapse;
        margin: 25px 0;
        border-radius: 4px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    }

    .cart-table th {
        background-color: #f8f9fa;
        padding: 12px 15px;
        text-align: left;
        font-weight: 600;
        color: #333;
        border: 1px solid #eaeaea;
    }

    .cart-table td {
        padding: 12px 15px;
        border: 1px solid #eaeaea;
        vertical-align: middle;
    }

    .cart-table tr:nth-child(even) {
        background-color: #fafafa;
    }

    .cart-table tr:last-child td {
        border-top: 2px solid #dee2e6;
        background-color: #f8f9fa;
        font-weight: bold;
    }

    .cart-quantity {
        width: 70px;
        padding: 8px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        text-align: center;
    }

    .action-buttons {
        display: flex;
        gap: 12px;
        margin-top: 20px;
        justify-content: center;
    }

    .button {
        display: inline-block;
        padding: 10px 18px;
        background-color: #007bff;
        color: white;
        text-decoration: none;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 500;
        transition: background-color 0.2s, transform 0.1s;
    }

    .button:hover {
        background-color: #0069d9;
        transform: translateY(-1px);
    }

    .button.remove {
        background-color: #dc3545;
    }

    .button.remove:hover {
        background-color: #c82333;
    }

    .remove-item {
        background-color: #ff4444;
        color: white;
        border: none;
        padding: 6px 12px;
        cursor: pointer;
        border-radius: 4px;
        transition: background-color 0.2s;
    }

    .remove-item:hover {
        background-color: #cc0000;
    }

    .back-to-shop {
        display: inline-block;
        margin-top: 20px;
        padding: 10px 18px;
        background-color: #6c757d;
        color: white;
        text-decoration: none;
        border-radius: 4px;
        transition: background-color 0.2s;
    }

    .back-to-shop:hover {
        background-color: #5a6268;
        color: white;
        text-decoration: none;
    }

    .checkout-actions {
        margin-top: 30px;
        text-align: center;
    }

    .checkout-button {
        background-color: #222;
        color: white;
        padding: 15px 30px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 16px;
        font-weight: 600;
        transition: background-color 0.2s, transform 0.1s;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }

    .checkout-button:hover {
        background-color: #000;
        transform: translateY(-1px);
        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
    }

    .checkout-button.loading {
        background-color: #444;
        cursor: not-allowed;
    }

    .success-message {
        color: #155724;
        font-weight: 500;
        padding: 20px;
        background-color: #d4edda;
        border-left: 4px solid #28a745;
        margin: 25px 0;
        border-radius: 4px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        text-align: center;
    }

    /* Responsive styles */
    @media (max-width: 768px) {
        .shop-checkout {
            padding: 20px;
            position: relative;
            top: 0;
            left: 0;
            transform: none;
            width: 100%;
            max-width: none;
            margin: 60px auto 30px;
            max-height: none;
        }

        .cart-table th:nth-child(2),
        .cart-table td:nth-child(2) {
            display: none;
            /* Hide price column on mobile */
        }

        .action-buttons {
            flex-direction: column;
            gap: 10px;
        }

        .button,
        .checkout-button {
            width: 100%;
            text-align: center;
        }
    }
</style>