<?php
namespace Zaver;

/** @var \WC_Order $order */
$payment = $order->get_meta('_zaver_payment');

if(!$payment) {
	wp_die('Invalid order');
}

do_action('zco_before_checkout', $order);
echo Plugin::gateway()->get_html_snippet($payment['token']);
do_action('zco_after_checkout', $order);
?>

<style>
	main .entry-title { display: none; }
	.zco-cancel-order { text-align: center; white-space: nowrap; }
</style>