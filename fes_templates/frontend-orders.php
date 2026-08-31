<?php
global $orders;
do_action( 'fes_before_frontend_orders' );
if ( is_singular( 'download' ) ) {
			$author = new WP_User( $post->post_author );
		} else {
			$author = fes_get_vendor();
		}

		if ( ! $author ) {
			$author = get_current_user_id();
		}
?>
    <h3 class="mayofes-headers" id="mayofes-orders-page-title"><?php _e( 'Orders', 'mayosis' ); ?></h3>

    <div class="mayofes--table--order" id="mayofes-order-list">

        <div class="mayofes-order--flex">
            <?php
            if ( ! empty( $orders ) && count( $orders ) > 0 && is_array( $orders ) ) {
                foreach ( $orders as $order ) :
                    if ( ! is_object( $order ) ) {
                        continue;
                    }
        
                    ?>
                    <div class="mayofes--order--flex--item">
                         <div class = "mayofes-order-flex-data">
                        
                             <?php echo EDD_FES()->dashboard->order_list_customer( $order->ID ); ?></div>
                        <div class = "mayofes-order-flex-data"><?php echo EDD_FES()->dashboard->order_list_title( $order->ID ); ?></div>
                        
                        <div class = "mayofes-order-flex-data mayofes-flex-order-auto"><?php echo EDD_FES()->dashboard->order_list_total( $order->ID ); ?></div>
                    
                        
                        <div class = "mayofes-order-flex-data"><?php echo EDD_FES()->dashboard->order_list_date( $order->ID ); ?></div>
                        
                        <div class = "mayofes-order-flex-data"><?php echo EDD_FES()->dashboard->order_list_status( $order->ID ); ?></div>
                        
                        <div class = "mayofes-order-flex-data"><?php EDD_FES()->dashboard->order_list_actions( $order->ID ); ?></div>
                        
                        <?php
                        /*
                         * Child-theme override of mayosis/fes_templates/frontend-orders.php.
                         * The ONLY change is this block; everything else is the parent's
                         * markup verbatim. Re-copy from the parent if the theme updates.
                         *
                         * WHY: plugins hook 'fes-order-table-column-value' assuming FES
                         * core's TABLE markup, so they echo a <td>. edd-message does exactly
                         * that (edd-message/includes/integrations/fes/frontend.php:56) for
                         * its "Send message" button. Mayosis rewrote this list as flex DIVs,
                         * so a <td> here has no table ancestor — the HTML parser cannot place
                         * it and hoists the content out of the row, which is why the buttons
                         * appeared detached on their own lines with blank bands beside them.
                         *
                         * Retag the injected cells as the theme's own flex cell. If nothing
                         * hooks the action the buffer is empty and this is a no-op; if a
                         * plugin already emits divs, the pattern doesn't match and its markup
                         * passes through untouched.
                         */
                        ob_start();
                        do_action( 'fes-order-table-column-value', $order );
                        $caw_injected = ob_get_clean();

                        if ( '' !== trim( (string) $caw_injected ) ) {
                            $caw_injected = preg_replace_callback(
                                '#<td\b([^>]*)>#i',
                                function ( $m ) {
                                    $attrs = $m[1];
                                    $class = 'mayofes-order-flex-data';
                                    if ( preg_match( '#\sclass\s*=\s*("|\')(.*?)\1#i', $attrs, $c ) ) {
                                        $attrs = str_replace( $c[0], '', $attrs );
                                        $class .= ' ' . $c[2];
                                    }
                                    return '<div class="' . esc_attr( $class ) . '"' . $attrs . '>';
                                },
                                $caw_injected
                            );
                            $caw_injected = preg_replace( '#</td>#i', '</div>', $caw_injected );
                        }

                        echo $caw_injected;
                        ?>
                    </div>
                    <?php
                    do_action( 'fes_frontend_order_rows' );
                endforeach;
            } else {
                echo '<div>' . __( 'No orders found','mayosis' ) . '</div>';
            }
            ?>
        </div>
    </div>
<?php EDD_FES()->dashboard->order_list_pagination();

do_action( 'fes_after_frontend_orders' );
