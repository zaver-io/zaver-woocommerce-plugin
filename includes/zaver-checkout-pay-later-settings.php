<?php
/**
 * Zaver Checkout Pay Later settings.
 *
 * @package ZCO/Includes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Settings for Zaver Checkout
 */
return apply_filters(
	'zaver_checkout_pay_later_settings',
	array(
		'enabled'       => array(
			'type'    => 'checkbox',
			'default' => 'yes',
			'title'   => __( 'Enable/Disable', 'zco' ),
			'label'   => __( 'Enable Zaver Checkout', 'zco' ),
		),
		'title'         => array(
			'type'        => 'text',
			'desc_tip'    => true,
			'title'       => __( 'Title', 'zco' ),
			'description' => __( 'This controls the title which the user sees during checkout.', 'zco' ),
			'default'     => __( 'Faktura', 'zco' ),
		),
		'primary_color' => array(
			'type'        => 'color',
			'desc_tip'    => true,
			'title'       => __( 'Primary color', 'zco' ),
			'description' => __( 'Some elements in the Zaver Checkout will get this color.', 'zco' ),
			'placeholder' => __( 'Default', 'zco' ),
		),
	)
);
