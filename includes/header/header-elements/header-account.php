<?php
/**
 * Header "account" element — wraps the parent template instead of copying it.
 *
 * The parent file still does all the rendering (so theme updates flow through);
 * caw_render_account_element() in functions.php moves its auth popup out of the
 * header and into the footer. See the comment there.
 */
defined( 'ABSPATH' ) or die();

caw_render_account_element();
