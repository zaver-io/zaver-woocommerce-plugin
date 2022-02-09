<?php
namespace Zaver;

/** @var \WC_Order $order */
$payment = $order->get_meta('_zaver_payment');

if(!$payment) {
	wp_die('Invalid order');
}

echo Plugin::gateway()->api()->getHtmlSnippet($payment['token']);
?>

<style>
	main .entry-title { display: none; }
</style>