<?php get_header(); ?>
<div class="shop-single">
    <h1><?php the_title(); ?></h1>
    <div class="product-image"><?php the_post_thumbnail(); ?></div>
    <div class="product-content"><?php the_content(); ?></div>
    <p>Price: £<?php echo get_post_meta(get_the_ID(), 'price', true); ?></p>
    <button class="add-to-cart" data-product-id="<?php echo get_the_ID(); ?>">Add to Cart</button>
</div>
<?php get_footer(); ?>