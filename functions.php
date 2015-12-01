<?php
/**
 * WooCommerce 2.2 and lower support.
 */
if ( ! function_exists( 'wc_tax_enabled' ) ) {
	function wc_tax_enabled() {
		return get_option( 'woocommerce_calc_taxes' ) === 'yes';
	}
}

function image_to_base64( $image_url ) {
	$type   = pathinfo( $image_url, PATHINFO_EXTENSION );
	$data   = wp_remote_fopen( $image_url );
	$base64 = 'data:image/' . $type . ';base64,' . base64_encode( $data );

	return $base64;
}

function get_orders_from_previous_month() {
	$previous_month = date( 'm', strtotime( 'previous month' ) );

	$orders = get_posts( array(
		'numberposts'   => -1,
		'post_type'     => wc_get_order_types(),
		'post_status'   => array_keys( wc_get_order_statuses() ),
		'monthnum'      => $previous_month,
		'meta_key'      => '_customer_user',
		'orderby'       => array( 'meta_value', 'post_date' ),
		'order'         => 'ASC'
	) );

	return $orders;
}
