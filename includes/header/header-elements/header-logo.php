<?php
/**
 * Header "logo" element — wraps the parent template instead of copying it.
 *
 * The parent still renders everything; caw_render_logo_element() in
 * functions.php only fills in the alt text the parent leaves empty.
 */
defined( 'ABSPATH' ) or die();

caw_render_logo_element();
