<?php
/**
 * Plugin Name:       IPCoin
 * Plugin URI:        https://byterock.xyz/
 * Description:       Muestra y cobra los precios según el país del cliente usando la geolocalización de WooCommerce. Colombia (configurable) usa la moneda base (COP); el resto del mundo usa USD (configurable), con conversión por tasa en tiempo real.
 * Version:           1.0.0
 * Author:            Scharss Azael
 * Author URI:        https://byterock.xyz/
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       ipcoin
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * WC tested up to:   9.8
 *
 * @package IPCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'IPCOIN_VERSION', '1.0.0' );
define( 'IPCOIN_FILE', __FILE__ );
define( 'IPCOIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'IPCOIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declara compatibilidad con HPOS y con los bloques de Carrito/Checkout.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Arranca el plugin cuando WooCommerce está disponible.
 */
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'ipcoin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'IPCoin requiere que WooCommerce esté instalado y activo.', 'ipcoin' ) .
						'</p></div>';
				}
			);
			return;
		}

		require_once IPCOIN_PATH . 'includes/class-ipcoin.php';
		IPCoin::instance();
	}
);
