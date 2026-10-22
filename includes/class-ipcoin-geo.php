<?php
/**
 * Geolocalización del visitante y resolución de la moneda.
 *
 * @package IPCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detecta el país con la API de WooCommerce y persiste la moneda elegida en la
 * sesión y en una cookie para que sea consistente entre páginas, AJAX y el
 * Store API.
 */
class IPCoin_Geo {

	/**
	 * Referencia al plugin.
	 *
	 * @var IPCoin
	 */
	private $plugin;

	/**
	 * País resuelto.
	 *
	 * @var string
	 */
	private $country = '';

	/**
	 * Moneda resuelta.
	 *
	 * @var string
	 */
	private $currency = '';

	/**
	 * Si ya se resolvió.
	 *
	 * @var bool
	 */
	private $resolved = false;

	/**
	 * Constructor.
	 *
	 * @param IPCoin $plugin Plugin.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks.
	 */
	public function init() {
		add_action( 'init', array( $this, 'resolve' ), 20 );
		add_action( 'wp', array( $this, 'resolve' ), 1 );
	}

	/**
	 * Resuelve país y moneda una sola vez por petición.
	 */
	public function resolve() {
		if ( $this->resolved ) {
			return;
		}
		$this->resolved = true;

		// En el admin (páginas) no se convierte: se usa la moneda base.
		if ( $this->is_admin_context() ) {
			$this->country  = '';
			$this->currency = $this->plugin->base_currency();
			return;
		}

		// Override de prueba para administradores: ?ipcoin_country=US
		if ( $this->plugin->get_setting( 'test_override' ) && isset( $_GET['ipcoin_country'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
				$code = strtoupper( sanitize_text_field( wp_unslash( $_GET['ipcoin_country'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
					$this->country  = $code;
					$this->currency = $this->currency_for_country( $code );
					$this->persist( $code, $this->currency );
					return;
				}
			}
		}

		$stored_currency = $this->get_stored_currency();
		if ( '' !== $stored_currency ) {
			$this->country  = $this->get_stored_country();
			$this->currency = $stored_currency;
			return;
		}

		$country        = $this->geolocate();
		$this->country  = $country;
		$this->currency = $this->currency_for_country( $country );
		$this->persist( $country, $this->currency );
	}

	/**
	 * País detectado (puede ser '').
	 *
	 * @return string
	 */
	public function country() {
		$this->resolve();
		return $this->country;
	}

	/**
	 * Moneda resuelta para el visitante.
	 *
	 * @return string
	 */
	public function currency() {
		$this->resolve();
		return $this->currency ? $this->currency : $this->plugin->base_currency();
	}

	/**
	 * Moneda correspondiente a un país.
	 *
	 * @param string $country Código ISO-2.
	 * @return string
	 */
	public function currency_for_country( $country ) {
		$country  = strtoupper( (string) $country );
		$domestic = array_filter(
			array_map(
				'trim',
				explode( ',', strtoupper( (string) $this->plugin->get_setting( 'domestic_countries', 'CO' ) ) )
			)
		);

		if ( '' === $country ) {
			$fallback = strtoupper( (string) $this->plugin->get_setting( 'fallback_currency', 'USD' ) );
			return $fallback ? $fallback : $this->plugin->base_currency();
		}

		if ( in_array( $country, $domestic, true ) ) {
			return $this->plugin->base_currency();
		}

		$foreign = strtoupper( (string) $this->plugin->get_setting( 'foreign_currency', 'USD' ) );
		return $foreign ? $foreign : 'USD';
	}

	/**
	 * Detecta el país con WooCommerce.
	 *
	 * @return string
	 */
	private function geolocate() {
		if ( ! class_exists( 'WC_Geolocation' ) ) {
			return '';
		}
		$geo     = WC_Geolocation::geolocate_ip( '', true, true );
		$country = isset( $geo['country'] ) ? strtoupper( (string) $geo['country'] ) : '';
		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
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
	 * Moneda guardada en sesión o cookie.
	 *
	 * @return string
	 */
	private function get_stored_currency() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$value = WC()->session->get( 'ipcoin_currency' );
			if ( $value && preg_match( '/^[A-Z]{3}$/', strtoupper( (string) $value ) ) ) {
				return strtoupper( (string) $value );
			}
		}
		if ( isset( $_COOKIE['ipcoin_currency'] ) ) {
			$value = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['ipcoin_currency'] ) ) );
			if ( preg_match( '/^[A-Z]{3}$/', $value ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * País guardado en sesión o cookie.
	 *
	 * @return string
	 */
	private function get_stored_country() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$value = WC()->session->get( 'ipcoin_country' );
			if ( $value && preg_match( '/^[A-Z]{2}$/', strtoupper( (string) $value ) ) ) {
				return strtoupper( (string) $value );
			}
		}
		if ( isset( $_COOKIE['ipcoin_country'] ) ) {
			$value = strtoupper( sanitize_text_field( wp_unslash( $_COOKIE['ipcoin_country'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $value ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Persiste país y moneda en la sesión y la cookie (24h).
	 *
	 * @param string $country  País.
	 * @param string $currency Moneda.
	 */
	private function persist( $country, $currency ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			if ( '' !== $country ) {
				WC()->session->set( 'ipcoin_country', $country );
			}
			WC()->session->set( 'ipcoin_currency', $currency );
		}

		if ( function_exists( 'wc_setcookie' ) ) {
			if ( '' !== $country ) {
				wc_setcookie( 'ipcoin_country', $country, time() + DAY_IN_SECONDS );
			}
			wc_setcookie( 'ipcoin_currency', $currency, time() + DAY_IN_SECONDS );
		}
	}
}
