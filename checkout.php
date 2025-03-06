<?php
// checkout.php

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load WordPress environment (4 levels up as confirmed)
require_once(dirname(__FILE__, 4) . '/wp-load.php');

// Get Stripe secret key
$stripe_secret_key = get_option('stripe_secret_key');
$stripe_publishable_key = get_option('stripe_publishable_key');

// Check if Stripe keys are set
if (empty($stripe_secret_key) || empty($stripe_publishable_key)) {
    get_header();
    echo '<div class="shop-checkout"><h1>Checkout Error</h1><p>Stripe configuration is missing. Please contact the site administrator to set up Stripe keys in Shop Settings.</p></div>';
    get_footer();
    exit;
}

// Include Stripe PHP library with init.php (or autoload.php if you updated)
$stripe_init_path = plugin_dir_path(__FILE__) . 'stripe-php/init.php'; // Adjust if using a different version or autoload.php
if (file_exists($stripe_init_path)) {
    require_once $stripe_init_path;
} else {
    get_header();
    echo '<div class="shop-checkout"><h1>Checkout Error</h1><p>Stripe PHP library init file not found. Please place the full stripe-php/ folder (including init.php) in the plugin directory.</p></div>';
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
if (!empty($cart)) {
    foreach ($cart as $product_id => $quantity) {
        $stock = intval(get_post_meta($product_id, 'stock', true));
        if ($stock < $quantity) {
            get_header();
            echo '<div class="shop-checkout"><h1>Checkout Error</h1><p>Insufficient stock for ' . esc_html(get_the_title($product_id)) . '. Only ' . $stock . ' available.</p></div>';
            get_footer();
            exit;
        }

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

// Create Stripe Checkout session if there are items
if (!empty($line_items)) {
    try {
        $checkout_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => $line_items,
            'mode' => 'payment',
            'success_url' => home_url('/?checkout=1&success=1'),
            'cancel_url' => home_url('/?checkout=1'),
        ]);
    } catch (Exception $e) {
        get_header();
        echo '<div class="shop-checkout"><h1>Checkout Error</h1><p>Error creating Stripe checkout session: ' . esc_html($e->getMessage()) . '</p></div>';
        get_footer();
        exit;
    }
}

get_header();
?>

<div class="shop-checkout">
    <h1>Checkout</h1>
    <?php if (empty($cart)): ?>
        <p>Your cart is empty.</p>
        <a href="<?php echo home_url('/shop'); ?>">Back to Shop</a>
    <?php else: ?>
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
        <?php else: ?>
            <script src="https://js.stripe.com/v3/"></script>
            <button id="checkout-button">Proceed to Payment</button>
            <script>
                var stripe = Stripe('<?php echo esc_js($stripe_publishable_key); ?>');
                var checkoutButton = document.getElementById('checkout-button');
                checkoutButton.addEventListener('click', function() {
                    stripe.redirectToCheckout({
                        sessionId: '<?php echo $checkout_session->id; ?>'
                    }).then(function(result) {
                        if (result.error) {
                            alert(result.error.message);
                        }
                    });
                });
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
    .shop-checkout {
        max-width: 800px;
        margin: 20px auto;
        padding: 20px;
    }

    .cart-table {
        width: 100%;
        border-collapse: collapse;
    }

    .cart-table th,
    .cart-table td {
        padding: 10px;
        border: 1px solid #ddd;
        text-align: left;
    }

    .cart-quantity {
        width: 60px;
    }

    .remove-item {
        background-color: #ff4444;
        color: white;
        border: none;
        padding: 5px 10px;
        cursor: pointer;
    }

    .remove-item:hover {
        background-color: #cc0000;
    }

    #checkout-button {
        background-color: #007bff;
        color: white;
        padding: 10px 20px;
        border: none;
        cursor: pointer;
        margin-top: 20px;
    }

    #checkout-button:hover {
        background-color: #0056b3;
    }

    .success-message {
        color: green;
        font-weight: bold;
    }
</style>

<?php get_footer(); ?>