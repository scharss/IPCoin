<?php
/**
 * Cambio real de moneda y conversión de precios, envío y cupones.
 *
 * @package IPCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aplica la moneda del visitante y convierte los importes para que el carrito,
 * el pedido y la pasarela operen en esa moneda.
 */
class IPCoin_Currency {

	/**
	 * Referencia al plugin.
	 *
	 * @var IPCoin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param IPCoin $plugin Plugin.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registra los hooks.
	 */
	public function init() {
		add_filter( 'woocommerce_currency', array( $this, 'filter_currency' ), 100 );

		$price_filters = array(
			'woocommerce_product_get_price',
			'woocommerce_product_get_regular_price',
			'woocommerce_product_get_sale_price',
			'woocommerce_product_variation_get_price',
			'woocommerce_product_variation_get_regular_price',
			'woocommerce_product_variation_get_sale_price',
		);
		foreach ( $price_filters as $filter ) {
			add_filter( $filter, array( $this, 'convert_price' ), 100, 2 );
		}

		add_filter( 'woocommerce_package_rates', array( $this, 'convert_shipping' ), 100, 2 );
		add_filter( 'woocommerce_coupon_get_amount', array( $this, 'convert_coupon_amount' ), 100, 2 );
	}

	/**
	 * ¿Estamos en una página de administración (no AJAX ni REST)?
	 *
	 * @return bool
	 */
	private function is_admin_context() {
		if ( ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return true;
	}

	/**
	 * Indica si hay que convertir en este contexto.
	 *
	 * @return bool
	 */
	public function should_convert() {
		if ( ! $this->plugin->get_setting( 'enabled' ) ) {
			return false;
		}
		if ( $this->is_admin_context() ) {
			return false;
		}

		$base   = $this->plugin->base_currency();
		$target = $this->plugin->visitor_currency();

		if ( '' === $target || $base === $target ) {
			return false;
		}

		$rate = $this->plugin->get_rate();
		if ( ! $rate || $rate <= 0 ) {
			return false;
		}

		/**
		 * Permite desactivar la conversión en un contexto concreto.
		 *
		 * @param bool $should Convertir o no.
		 */
		return (bool) apply_filters( 'ipcoin_should_convert', true );
	}

	/**
	 * Cambia la moneda de WooCommerce por la del visitante.
	 *
	 * @param string $currency Moneda actual.
	 * @return string
	 */
	public function filter_currency( $currency ) {
		if ( ! $this->should_convert() ) {
			return $currency;
		}
		return $this->plugin->visitor_currency();
	}

	/**
	 * Convierte el precio de un producto.
	 *
	 * @param mixed $price   Precio.
	 * @param mixed $product Producto.
	 * @return mixed
	 */
	public function convert_price( $price, $product = null ) {
		if ( ! $this->should_convert() ) {
			return $price;
		}
		return $this->convert_amount( $price );
	}

	/**
	 * Convierte un importe de la moneda base a la del visitante.
	 *
	 * @param mixed $amount Importe.
	 * @return mixed
	 */
	private function convert_amount( $amount ) {
		if ( null === $amount || '' === $amount || ! is_numeric( $amount ) ) {
			return $amount;
		}
		$rate = $this->plugin->get_rate();
		if ( ! $rate || $rate <= 0 ) {
			return $amount;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return round( (float) $amount * (float) $rate, $decimals );
	}

	/**
	 * Convierte los costos de envío.
	 *
	 * @param array $rates   Tarifas.
	 * @param array $package Paquete.
	 * @return array
	 */
	public function convert_shipping( $rates, $package ) {
		if ( ! $this->should_convert() || ! $this->plugin->get_setting( 'convert_shipping' ) ) {
			return $rates;
		}
		$rate = $this->plugin->get_rate();
		if ( ! $rate ) {
			return $rates;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;

		foreach ( $rates as $key => $rate_obj ) {
			if ( isset( $rate_obj->cost ) && is_numeric( $rate_obj->cost ) ) {
				$rates[ $key ]->cost = round( (float) $rate_obj->cost * (float) $rate, $decimals );
			}
			if ( ! empty( $rate_obj->taxes ) && is_array( $rate_obj->taxes ) ) {
				foreach ( $rate_obj->taxes as $tax_id => $tax ) {
					$rates[ $key ]->taxes[ $tax_id ] = round( (float) $tax * (float) $rate, $decimals );
				}
			}
		}
		return $rates;
	}

	/**
	 * Convierte el importe de cupones de descuento fijo (no porcentuales).
	 *
	 * @param mixed     $amount Importe.
	 * @param WC_Coupon $coupon Cupón.
	 * @return mixed
	 */
	public function convert_coupon_amount( $amount, $coupon = null ) {
		if ( ! $this->should_convert() || ! $this->plugin->get_setting( 'convert_coupons' ) ) {
			return $amount;
		}
		if ( ! class_exists( 'WC_Coupon' ) || ! ( $coupon instanceof WC_Coupon ) ) {
			return $amount;
		}
		$type = $coupon->get_discount_type();
		if ( ! in_array( $type, array( 'fixed_cart', 'fixed_product' ), true ) ) {
			return $amount;
		}
		$rate = $this->plugin->get_rate();
		if ( ! $rate ) {
			return $amount;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return round( (float) $amount * (float) $rate, $decimals );
	}
}
