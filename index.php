<?php

/**
 * Plugin Name: GD Shop Plugin
 * Description: A lightweight eCommerce plugin using a custom post type and Stripe integration.
 * Version: 1.0.4
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
        add_action('init', [$this, 'register_shop_taxonomy'], 5); // Register taxonomy before post type
        add_action('init', [$this, 'register_shop_post_type']);
        add_action('add_meta_boxes', [$this, 'add_product_meta_boxes']);
        add_action('save_post_shop', [$this, 'save_product_meta']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_add_to_cart', [$this, 'add_to_cart']);
        add_action('wp_ajax_nopriv_add_to_cart', [$this, 'add_to_cart']);
        add_action('wp_ajax_update_cart_quantity', [$this, 'update_cart_quantity']);
        add_action('wp_ajax_nopriv_update_cart_quantity', [$this, 'update_cart_quantity']);
        add_action('wp_ajax_remove_from_cart', [$this, 'remove_from_cart']);
        add_action('wp_ajax_nopriv_remove_from_cart', [$this, 'remove_from_cart']);
        add_action('wp_ajax_get_cart_count', [$this, 'get_cart_count']);
        add_action('wp_ajax_nopriv_get_cart_count', [$this, 'get_cart_count']);
        add_action('template_redirect', [$this, 'handle_checkout']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('template_include', [$this, 'load_custom_templates']);
        add_filter('wp_nav_menu_items', [$this, 'add_cart_icon'], 10, 2);
    }

    /**
     * Register shop category taxonomy
     */
    public function register_shop_taxonomy()
    {
        $labels = array(
            'name'              => _x('Product Categories', 'taxonomy general name'),
            'singular_name'     => _x('Product Category', 'taxonomy singular name'),
            'search_items'      => __('Search Product Categories'),
            'all_items'         => __('All Product Categories'),
            'parent_item'       => __('Parent Product Category'),
            'parent_item_colon' => __('Parent Product Category:'),
            'edit_item'         => __('Edit Product Category'),
            'update_item'       => __('Update Product Category'),
            'add_new_item'      => __('Add New Product Category'),
            'new_item_name'     => __('New Product Category Name'),
            'menu_name'         => __('Product Categories'),
        );

        register_taxonomy('product_category', 'shop', array(
            'hierarchical'      => true,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => array('slug' => 'product-category'),
        ));
    }

    public function get_cart_count()
    {
        // Return the cart contents
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }

        wp_send_json_success($_SESSION['cart']);
    }

    public function start_session()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public function add_cart_icon($items, $args)
    {
        $cart_count = isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
        $cart_url = home_url('/?checkout=1');

        // Add cart count to JavaScript for Vue - simple one-liner
        echo '<script>window.cartCount = ' . $cart_count . ';</script>';

        $items .= '<li class="menu-item"><a href="' . $cart_url . '">🛒 Cart (' . $cart_count . ')</a></li>';
        return $items;
    }

    public function register_shop_post_type()
    {
        // Get archive setting
        $has_archive = get_option('shop_enable_archive', true) ? true : false;

        register_post_type('shop', [
            'labels' => [
                'name' => 'Shop',
                'singular_name' => 'Product'
            ],
            'public' => true,
            'has_archive' => $has_archive,
            'supports' => ['title', 'thumbnail', 'editor'], // Added 'editor' support for description
            'taxonomies' => ['product_category'], // Use our custom taxonomy
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
        $is_new = get_post_meta($post->ID, 'is_new', true);

        wp_nonce_field('save_product_meta', 'product_meta_nonce');

        echo "<p><label>Price (£):</label><input type='number' name='price' value='$price' step='0.01'></p>";
        echo "<p><label>Stock:</label><input type='number' name='stock' value='$stock'></p>";
        echo "<p><label>Sold Out:</label><input type='checkbox' name='sold_out' value='1' " . checked(1, $sold_out, false) . "></p>";
        echo "<p><label>New:</label><input type='checkbox' name='is_new' value='1' " . checked(1, $is_new, false) . "></p>";
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
        update_post_meta($post_id, 'is_new', isset($_POST['is_new']) ? 1 : 0);
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('shop-script', plugin_dir_url(__FILE__) . 'shop.js', ['jquery'], '1.0.4', true);

        // Add cart count to localized script data
        $cart_count = isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;

        wp_localize_script('shop-script', 'ShopAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'cartCount' => $cart_count
        ]);
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
        // Check if we should use the built-in template
        $use_builtin_template = get_option('shop_use_builtin_template', true);

        if ($use_builtin_template) {
            if (is_singular('shop')) {
                return plugin_dir_path(__FILE__) . 'templates/single-shop.php';
            }

            if (is_post_type_archive('shop')) {
                return plugin_dir_path(__FILE__) . 'templates/archive-shop.php';
            }
        }

        return $template;
    }

    public function register_admin_menu()
    {
        add_menu_page('Shop Settings', 'Shop Settings', 'manage_options', 'shop-settings', [$this, 'settings_page']);
    }

    public function register_settings()
    {
        // Stripe settings
        register_setting('shop_settings_group', 'stripe_publishable_key');
        register_setting('shop_settings_group', 'stripe_secret_key');

        // Template settings
        register_setting('shop_settings_group', 'shop_use_builtin_template');
        register_setting('shop_settings_group', 'shop_enable_archive');
    }

    public function settings_page()
    {
        // Get saved options
        $use_builtin_template = get_option('shop_use_builtin_template', true);
        $enable_archive = get_option('shop_enable_archive', true);
?>
        <div class="wrap">
            <h1>Shop Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('shop_settings_group'); ?>
                <?php do_settings_sections('shop_settings_group'); ?>

                <h2 class="title">Template Settings</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="shop_use_builtin_template">Use Built-in Shop Templates</label></th>
                        <td>
                            <input type="checkbox" name="shop_use_builtin_template" value="1" <?php checked(1, $use_builtin_template, true); ?>>
                            <p class="description">Check this box to use the plugin's built-in templates for shop pages. Uncheck to use your theme's templates.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="shop_enable_archive">Enable Shop Archive Page</label></th>
                        <td>
                            <input type="checkbox" name="shop_enable_archive" value="1" <?php checked(1, $enable_archive, true); ?>>
                            <p class="description">Check this box to enable the shop archive page. Uncheck to disable it.</p>
                            <p class="description"><strong>Note:</strong> After changing this option, you may need to <a href="<?php echo admin_url('options-permalink.php'); ?>">refresh your permalinks</a>.</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">Stripe Settings</h2>
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

// Add a file existence check to make template creation smoother
function check_shop_templates_exist()
{
    $template_dir = plugin_dir_path(__FILE__) . 'templates/';

    // Create template directory if it doesn't exist
    if (!file_exists($template_dir)) {
        mkdir($template_dir, 0755, true);
    }

    // Check for single product template
    $single_template = $template_dir . 'single-shop.php';
    if (!file_exists($single_template)) {
        $single_content = '<?php get_header(); ?>
<main class="container mx-auto py-8 px-4">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <div class="product-container">
            <h1 class="text-3xl font-bold mb-6"><?php the_title(); ?></h1>
            
            <div class="product-details flex flex-col md:flex-row gap-8">
                <div class="product-image md:w-1/2">
                    <?php if (has_post_thumbnail()) : ?>
                        <?php the_post_thumbnail(\'large\', [\'class\' => \'w-full h-auto\']); ?>
                    <?php endif; ?>
                </div>
                
                <div class="product-info md:w-1/2">
                    <?php 
                    $price = get_post_meta(get_the_ID(), \'price\', true);
                    $stock = get_post_meta(get_the_ID(), \'stock\', true);
                    $sold_out = get_post_meta(get_the_ID(), \'sold_out\', true);
                    $is_new = get_post_meta(get_the_ID(), \'is_new\', true);
                    ?>
                    
                    <?php if ($is_new) : ?>
                        <div class="new-badge inline-block bg-green-500 text-white px-2 py-1 text-sm mb-4 rounded">New</div>
                    <?php endif; ?>
                    
                    <div class="price text-2xl font-bold mb-4">£<?php echo number_format((float)$price, 2); ?></div>
                    
                    <div class="stock-status mb-4">
                        <?php if ($sold_out || $stock <= 0) : ?>
                            <span class="text-red-600 font-medium">Out of stock</span>
                        <?php else : ?>
                            <span class="text-green-600 font-medium">In stock (<?php echo $stock; ?> available)</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="product-description mb-6">
                        <?php the_content(); ?>
                    </div>
                    
                    <?php if (!$sold_out && $stock > 0) : ?>
                        <div class="add-to-cart">
                            <!-- Product card with Vue component -->
                            <productpage
                                image="<?php echo get_the_post_thumbnail_url(get_the_ID(), \'full\'); ?>"
                                title="<?php echo get_the_title(); ?>"
                                price="<?php echo $price; ?>"
                                stockLevel="<?php echo $stock; ?>"
                                :sold_out="<?php echo $sold_out ? \'true\' : \'false\'; ?>"
                                :productId="<?php echo get_the_ID(); ?>"
                                description="<?php echo htmlspecialchars(get_the_content()); ?>"
                            ></productpage>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endwhile; endif; ?>
</main>
<?php get_footer(); ?>';
        file_put_contents($single_template, $single_content);
    }

    // Check for archive template
    $archive_template = $template_dir . 'archive-shop.php';
    if (!file_exists($archive_template)) {
        $archive_content = '<?php get_header(); ?>
<main class="container mx-auto py-8 px-4">
    <h1 class="text-3xl font-bold mb-8">Shop</h1>
    
    <?php if (have_posts()) : ?>
        <div class="products-grid grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php while (have_posts()) : the_post(); 
                $price = get_post_meta(get_the_ID(), \'price\', true);
                $stock = get_post_meta(get_the_ID(), \'stock\', true);
                $sold_out = get_post_meta(get_the_ID(), \'sold_out\', true);
                $is_new = get_post_meta(get_the_ID(), \'is_new\', true);
                $category_terms = get_the_terms(get_the_ID(), \'product_category\');
                $category = $category_terms ? $category_terms[0]->name : \'\';
            ?>
                <div class="product-card">
                    <productcard
                        image="<?php echo get_the_post_thumbnail_url(get_the_ID(), \'full\'); ?>"
                        category="<?php echo $category; ?>"
                        title="<?php echo get_the_title(); ?>"
                        price="<?php echo $price; ?>"
                        stockLevel="<?php echo $stock; ?>"
                        :sold_out="<?php echo $sold_out ? \'true\' : \'false\'; ?>"
                        :productId="<?php echo get_the_ID(); ?>"
                    ></productcard>
                    
                    <?php if ($is_new) : ?>
                        <div class="new-badge absolute top-2 right-2 bg-green-500 text-white px-2 py-1 text-sm rounded">New</div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        </div>
        
        <div class="pagination mt-8">
            <?php echo paginate_links(); ?>
        </div>
    <?php else : ?>
        <p>No products found.</p>
    <?php endif; ?>
</main>
<?php get_footer(); ?>';
        file_put_contents($archive_template, $archive_content);
    }
}

// Check and create templates when plugin is activated
register_activation_hook(__FILE__, 'check_shop_templates_exist');

// Initialize the plugin
new CustomShopPlugin();
