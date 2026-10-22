<?php
/**
 * Orquestador principal de IPCoin.
 *
 * @package IPCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase principal (singleton).
 */
final class IPCoin {

	/**
	 * Opción de configuración.
	 *
	 * @var string
	 */
	const OPTION = 'ipcoin_settings';

	/**
	 * Instancia única.
	 *
	 * @var IPCoin|null
	 */
	private static $instance = null;

	/**
	 * Geolocalización / moneda por visitante.
	 *
	 * @var IPCoin_Geo
	 */
	public $geo;

	/**
	 * Tasas de cambio.
	 *
	 * @var IPCoin_Rates
	 */
	public $rates;

	/**
	 * Conversión de moneda y precios.
	 *
	 * @var IPCoin_Currency
	 */
	public $currency;

	/**
	 * Ajustes de administración.
	 *
	 * @var IPCoin_Settings
	 */
	public $settings;

	/**
	 * Devuelve la instancia única.
	 *
	 * @return IPCoin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor privado.
	 */
	private function __construct() {
		require_once IPCOIN_PATH . 'includes/class-ipcoin-geo.php';
		require_once IPCOIN_PATH . 'includes/class-ipcoin-rates.php';
		require_once IPCOIN_PATH . 'includes/class-ipcoin-currency.php';
		require_once IPCOIN_PATH . 'includes/class-ipcoin-settings.php';

		$this->geo      = new IPCoin_Geo( $this );
		$this->rates    = new IPCoin_Rates( $this );
		$this->currency = new IPCoin_Currency( $this );
		$this->settings = new IPCoin_Settings( $this );

		$this->geo->init();
		$this->currency->init();
		$this->settings->init();

		add_filter( 'plugin_action_links_' . plugin_basename( IPCOIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Enlace "Ajustes" en la lista de plugins.
	 *
	 * @param array $links Enlaces.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=ipcoin' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ajustes', 'ipcoin' ) . '</a>' );
		return $links;
	}

	/**
	 * Valores por defecto.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'enabled'            => 1,
			'domestic_countries' => 'CO',
			'foreign_currency'   => 'USD',
			'fallback_currency'  => 'USD',
			'rate_source'        => 'auto',
			'cache_ttl'          => 720,
			'manual_rate'        => '',
			'convert_shipping'   => 1,
			'convert_coupons'    => 1,
			'test_override'      => 1,
		);
	}

	/**
	 * Configuración completa (defaults + guardada).
	 *
	 * @return array
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, $this->defaults() );
	}

	/**
	 * Un ajuste.
	 *
	 * @param string $key     Clave.
	 * @param mixed  $default Defecto.
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = $this->get_settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Moneda base real de la tienda (sin filtrar).
	 *
	 * @return string
	 */
	public function base_currency() {
		return strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
	}

	/**
	 * Moneda resuelta para el visitante actual.
	 *
	 * @return string
	 */
	public function visitor_currency() {
		return strtoupper( (string) $this->geo->currency() );
	}

	/**
	 * Tasa base -> moneda del visitante.
	 *
	 * @return float|false
	 */
	public function get_rate() {
		$base   = $this->base_currency();
		$target = $this->visitor_currency();
		if ( $base === $target ) {
			return 1.0;
		}
		return $this->rates->get_rate( $base, $target );
	}
}
