<?php

/**
 * Plugin Name: GD Shop Plugin
 * Description: A lightweight eCommerce plugin using a custom post type and Stripe integration.
 * Version: 1.0.2
 * Author: Greenwich Design
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CustomShopPlugin
{
    public function __construct()
    {
        // Start session early on init
        add_action('init', [$this, 'start_session'], 1);
        add_action('init', [$this, 'register_shop_post_type']);
        add_action('add_meta_boxes', [$this, 'add_product_meta_boxes']);
        add_action('save_post_shop', [$this, 'save_product_meta']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_add_to_cart', [$this, 'add_to_cart']);
        add_action('wp_ajax_nopriv_add_to_cart', [$this, 'add_to_cart']);
        add_action('wp_ajax_update_cart_quantity', [$this, 'update_cart_quantity']); // New
        add_action('wp_ajax_nopriv_update_cart_quantity', [$this, 'update_cart_quantity']); // New
        add_action('wp_ajax_remove_from_cart', [$this, 'remove_from_cart']); // New
        add_action('wp_ajax_nopriv_remove_from_cart', [$this, 'remove_from_cart']); // New
        add_action('template_redirect', [$this, 'handle_checkout']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('template_include', [$this, 'load_custom_templates']);
        add_filter('wp_nav_menu_items', [$this, 'add_cart_icon'], 10, 2);
    }

    public function start_session()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function register_shop_post_type()
    {
        register_post_type('shop', [
            'labels' => [
                'name' => 'Shop',
                'singular_name' => 'Product'
            ],
            'public' => true,
            'has_archive' => true,
            'supports' => ['title', 'editor', 'thumbnail'],
            'menu_icon' => 'dashicons-cart'
        ]);
    }

    public function add_product_meta_boxes()
    {
        add_meta_box('shop_details', 'Product Details', [$this, 'render_meta_boxes'], 'shop', 'side');
    }

    public function render_meta_boxes($post)
    {
        $price = get_post_meta($post->ID, 'price', true);
        $stock = get_post_meta($post->ID, 'stock', true);
        $sold_out = get_post_meta($post->ID, 'sold_out', true);
        wp_nonce_field('save_product_meta', 'product_meta_nonce');
        echo "<p><label>Price (£):</label><input type='number' name='price' value='$price' step='0.01'></p>";
        echo "<p><label>Stock:</label><input type='number' name='stock' value='$stock'></p>";
        echo "<p><label>Sold Out:</label><input type='checkbox' name='sold_out' value='1' " . checked(1, $sold_out, false) . "></p>";
    }
    public function update_cart_quantity()
    {
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        if ($quantity < 1) $quantity = 1; // Minimum quantity
        if (isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id] = $quantity;
        }
        wp_send_json_success($_SESSION['cart']);
    }

    public function remove_from_cart()
    {
        $product_id = intval($_POST['product_id']);
        if (isset($_SESSION['cart'][$product_id])) {
            unset($_SESSION['cart'][$product_id]);
        }
        wp_send_json_success($_SESSION['cart']);
    }
    public function save_product_meta($post_id)
    {
        if (!isset($_POST['product_meta_nonce']) || !wp_verify_nonce($_POST['product_meta_nonce'], 'save_product_meta')) return;
        update_post_meta($post_id, 'price', sanitize_text_field($_POST['price']));
        update_post_meta($post_id, 'stock', sanitize_text_field($_POST['stock']));
        update_post_meta($post_id, 'sold_out', isset($_POST['sold_out']) ? 1 : 0);
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('shop-script', plugin_dir_url(__FILE__) . 'shop.js', ['jquery'], '1.0.2', true);
        wp_localize_script('shop-script', 'ShopAjax', ['ajaxurl' => admin_url('admin-ajax.php')]);
    }

    public function add_to_cart()
    {
        $product_id = intval($_POST['product_id']);
        if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
        $_SESSION['cart'][$product_id] = ($_SESSION['cart'][$product_id] ?? 0) + 1;
        wp_send_json_success($_SESSION['cart']);
    }

    public function handle_checkout()
    {
        if (isset($_GET['checkout'])) {
            require_once 'checkout.php';
            exit;
        }
    }

    public function load_custom_templates($template)
    {
        if (is_singular('shop')) {
            return plugin_dir_path(__FILE__) . 'templates/single-shop.php';
        }
        return $template;
    }

    public function add_cart_icon($items, $args)
    {
        $cart_count = isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
        $cart_url = home_url('/?checkout=1');
        $items .= '<li class="menu-item"><a href="' . $cart_url . '">🛒 Cart (' . $cart_count . ')</a></li>';
        return $items;
    }

    public function register_admin_menu()
    {
        add_menu_page('Shop Settings', 'Shop Settings', 'manage_options', 'shop-settings', [$this, 'settings_page']);
    }

    public function register_settings()
    {
        register_setting('shop_settings_group', 'stripe_publishable_key');
        register_setting('shop_settings_group', 'stripe_secret_key');
    }

    public function settings_page()
    {
?>
        <div class="wrap">
            <h1>Shop Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('shop_settings_group'); ?>
                <?php do_settings_sections('shop_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="stripe_publishable_key">Stripe Publishable Key</label></th>
                        <td><input type="text" name="stripe_publishable_key" value="<?php echo esc_attr(get_option('stripe_publishable_key')); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="stripe_secret_key">Stripe Secret Key</label></th>
                        <td><input type="text" name="stripe_secret_key" value="<?php echo esc_attr(get_option('stripe_secret_key')); ?>" class="regular-text"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
<?php
    }
}

new CustomShopPlugin();
