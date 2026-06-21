<?php
/**
 * Template Name: Edd Checkout Template
 *
 * Child theme override of the mayosis checkout-template.php.
 * Adds a checkout page header and a wider container so the
 * CSS two-column layout has room to breathe.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
get_header();

$mayosis_breadcrumb_color = get_post_meta( $post->ID, 'mayosis_breadcrumb_color', true );
$mayosis_page_bg          = get_post_meta( $post->ID, 'mayosis_page_bg', true );
$mayosis_gradient         = get_post_meta( $post->ID, 'breadcrumb_gradient', true );
$mayosis_gradient_a       = get_post_meta( $post->ID, 'mayosis_gradient_a', true );
$mayosis_gradient_b       = get_post_meta( $post->ID, 'mayosis_gradient_b', true );
$breadcrumb_image         = get_post_meta( $post->ID, 'breadcrumb_image', true );
$custom_page_title        = get_post_meta( $post->ID, 'custom_page_title', true );

if ( is_home() ) {
    $breadcrumb_hide = get_post_meta( get_queried_object_id(), 'breadcrumb_hide', true );
    $sidebar_hide    = get_post_meta( get_queried_object_id(), 'page_sidebar', true );
} else {
    $breadcrumb_hide = get_post_meta( get_queried_object_id(), 'breadcrumb_hide', true );
    $sidebar_hide    = get_post_meta( get_the_ID(), 'page_sidebar', true );
}
?>

<div class="container-fluid" style="background:<?php echo esc_attr( $mayosis_page_bg ); ?>;">

    <?php while ( have_posts() ) : the_post(); ?>

        <?php if ( $breadcrumb_hide !== 'No' ) : ?>
            <?php if ( $mayosis_gradient === 'Yes' ) : ?>
                <div class="row page_breadcrumb" style="background:linear-gradient(45deg,<?php echo esc_attr( $mayosis_gradient_a ); ?>,<?php echo esc_attr( $mayosis_gradient_b ); ?>);">
            <?php else : ?>
                <div class="row page_breadcrumb" style="background-color:<?php echo esc_attr( $mayosis_breadcrumb_color ); ?>;<?php if ( $breadcrumb_image ) { echo 'background-image:url(' . esc_url( get_post_meta( get_the_ID(), 'breadcrumb_image', true ) ) . ');'; } ?>">
            <?php endif; ?>
                <div class="container">
                    <h2 class="page_title_single">
                        <?php echo $custom_page_title ? esc_html( $custom_page_title ) : get_the_title(); ?>
                    </h2>
                    <?php if ( function_exists( 'dm_breadcrumbs' ) ) dm_breadcrumbs(); ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ( $sidebar_hide === 'Show' ) : ?>
            <!-- Sidebar layout (unchanged from parent) -->
            <div class="container mayosis--checkout-page-stl">
                <div class="row">
                    <div class="col-md-8">
                        <?php the_content(); ?>
                    </div>
                    <div class="col-md-4">
                        <?php if ( is_active_sidebar( 'page-sidebar' ) ) : ?>
                            <?php dynamic_sidebar( 'page-sidebar' ); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php else : ?>
            <!-- Full-width checkout layout -->
            <div class="container mayosis--checkout-page-stl" style="background:<?php echo esc_attr( $mayosis_page_bg ); ?>;padding:30px 15px;">
                <div class="row">
                    <div class="col-md-12 col-lg-12 col-sm-12">
                        <?php the_content(); ?>
                    </div>
                </div>
            </div>

        <?php endif; ?>

    <?php endwhile; ?>

</div>

<?php get_footer(); ?>
