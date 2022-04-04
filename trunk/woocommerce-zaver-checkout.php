<?php
/**
 * Plugin Name: Zaver Checkout for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/zaver-checkout-for-woocommerce/
 * Description: The official Zaver Checkout payment gateway for WooCommerce.
 * Version: 0.0.0-dev
 * Author: Zaver
 * Author URI: https://www.zaver.io/
 * Developer: Webbmaffian
 * Developer URI: https://www.webbmaffian.se/
 * Text Domain: zco
 * Domain Path: /languages
 *
 * WC requires at least: 6.0.0
 * WC tested up to: 6.2.1
 *
 * License: GNU General Public License v3.0
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Zaver;

class Plugin {
	const VERSION = '0.0.0-dev';
	const PATH = __DIR__;
	const PAYMENT_METHOD = 'zaver_checkout';

	static public function instance(): self {
		static $instance = null;

		if(is_null($instance)) {
			$instance = new self();
		}

		return $instance;
	}

	static public function gateway(): Checkout_Gateway {
		static $instance = null;

		if(is_null($instance)) {

			// If the class already is loaded, it's most likely through WooCommerce
			if(class_exists(__NAMESPACE__ . '\Checkout_Gateway', false)) {
				$gateways = WC()->payment_gateways()->payment_gateways();

				if(isset($gateways[self::PAYMENT_METHOD])) {
					$instance = $gateways[self::PAYMENT_METHOD];

					return $instance;
				}
			}

			// Don't bother with loading all the gateways otherwise
			$instance = new Checkout_Gateway();
		}

		return $instance;
	}

	static public function get_basename(bool $to_file = false): string {
		return plugin_basename($to_file ? __FILE__ : self::PATH);
	}

	private function __construct() {
		require(self::PATH . '/vendor/autoload.php');
		spl_autoload_register([$this, 'autoloader']);
		load_plugin_textdomain('zco', false, self::get_basename() . '/languages');
		add_filter('woocommerce_payment_gateways', [$this, 'register_gateway']);
		add_filter('plugin_action_links_' . self::get_basename(true), [$this, 'add_settings_link']);

		Hooks::instance();
	}

	private function autoloader(string $name): void {
		if(strncmp($name, __NAMESPACE__, strlen(__NAMESPACE__)) !== 0) return;
		
		$classname = trim(substr($name, strlen(__NAMESPACE__)), '\\');
		$filename = strtolower(str_replace('_', '-', $classname));
		$path = sprintf('%s/classes/%s.php', self::PATH, $filename);

		if(file_exists($path)) {
			require($path);
		}
	}

	public function register_gateway(array $gateways): array {
		$gateways[] = __NAMESPACE__ . '\Checkout_Gateway';

		return $gateways;
	}

	public function add_settings_link(array $links): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url(add_query_arg([
					'page' => 'wc-settings',
					'tab' => 'checkout',
					'section' => self::PAYMENT_METHOD
				], admin_url('admin.php'))),
				__('Settings', 'zco')
			)
		);
		
		return $links;
	}
}

Plugin::instance();