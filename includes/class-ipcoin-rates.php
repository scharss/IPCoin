<?php
/**
 * Tasas de cambio en tiempo real con caché (Yahoo, er-api, Google, manual).
 *
 * @package IPCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obtiene y cachea la tasa base -> destino.
 */
class IPCoin_Rates {

	/**
	 * Referencia al plugin.
	 *
	 * @var IPCoin
	 */
	private $plugin;

	/**
	 * Prefijo de transients.
	 *
	 * @var string
	 */
	const PREFIX = 'ipcoin_rate_';

	/**
	 * Opción con la última tasa.
	 *
	 * @var string
	 */
	const LAST_OPTION = 'ipcoin_last_rate';

	/**
	 * Claves de transients creadas.
	 *
	 * @var string
	 */
	const KEYS_OPTION = 'ipcoin_rate_keys';

	/**
	 * Caché en memoria por petición.
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Última fuente usada.
	 *
	 * @var string
	 */
	public $last_source = '';

	/**
	 * Constructor.
	 *
	 * @param IPCoin $plugin Plugin.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Devuelve la tasa (destino por 1 base).
	 *
	 * @param string $base   Base.
	 * @param string $target Destino.
	 * @param bool   $force  Ignorar caché.
	 * @return float|false
	 */
	public function get_rate( $base, $target, $force = false ) {
		$base   = strtoupper( (string) $base );
		$target = strtoupper( (string) $target );

		if ( '' === $base || '' === $target ) {
			return false;
		}
		if ( $base === $target ) {
			return 1.0;
		}

		$memo_key = $base . '_' . $target;
		if ( ! $force && isset( $this->memo[ $memo_key ] ) ) {
			return $this->memo[ $memo_key ];
		}

		$settings = $this->plugin->get_settings();

		// Tasa manual (prioridad absoluta).
		if ( '' !== $settings['manual_rate'] && is_numeric( $settings['manual_rate'] ) && (float) $settings['manual_rate'] > 0 ) {
			$this->last_source        = 'manual';
			$this->memo[ $memo_key ]  = (float) $settings['manual_rate'];
			return $this->memo[ $memo_key ];
		}

		$key = self::PREFIX . $memo_key;

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( false !== $cached && is_numeric( $cached ) && (float) $cached > 0 ) {
				$this->memo[ $memo_key ] = (float) $cached;
				return $this->memo[ $memo_key ];
			}
		}

		$rate = $this->fetch_rate( $base, $target, $settings['rate_source'] );

		if ( $rate && $rate > 0 ) {
			$ttl = max( 60, absint( $settings['cache_ttl'] ) * MINUTE_IN_SECONDS );
			set_transient( $key, $rate, $ttl );
			$this->register_key( $key );
			update_option(
				self::LAST_OPTION,
				array(
					'base'   => $base,
					'target' => $target,
					'rate'   => (float) $rate,
					'time'   => time(),
					'source' => $this->last_source,
				),
				false
			);
			$this->memo[ $memo_key ] = (float) $rate;
			return $this->memo[ $memo_key ];
		}

		// Última tasa conocida como respaldo.
		$last = get_option( self::LAST_OPTION );
		if ( is_array( $last ) && isset( $last['base'], $last['target'], $last['rate'] )
			&& strtoupper( (string) $last['base'] ) === $base
			&& strtoupper( (string) $last['target'] ) === $target
			&& (float) $last['rate'] > 0 ) {
			$this->last_source       = 'stale';
			$this->memo[ $memo_key ] = (float) $last['rate'];
			return $this->memo[ $memo_key ];
		}

		return false;
	}

	/**
	 * Consulta las fuentes configuradas.
	 *
	 * @param string $base   Base.
	 * @param string $target Destino.
	 * @param string $source auto|yahoo|er_api|google.
	 * @return float|false
	 */
	private function fetch_rate( $base, $target, $source ) {
		$sources = ( 'auto' === $source ) ? array( 'yahoo', 'er_api', 'google' ) : array( $source );

		foreach ( $sources as $candidate ) {
			if ( 'yahoo' === $candidate ) {
				$rate = $this->fetch_yahoo( $base, $target );
				if ( $rate ) {
					$this->last_source = 'yahoo';
					return $rate;
				}
			}
			if ( 'er_api' === $candidate ) {
				$rate = $this->fetch_er_api( $base, $target );
				if ( $rate ) {
					$this->last_source = 'er_api';
					return $rate;
				}
			}
			if ( 'google' === $candidate ) {
				$rate = $this->fetch_google( $base, $target );
				if ( $rate ) {
					$this->last_source = 'google';
					return $rate;
				}
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Yahoo Finance
	 * ------------------------------------------------------------------ */

	/**
	 * Tasa desde Yahoo (target por 1 base).
	 *
	 * @param string $base   Base.
	 * @param string $target Destino.
	 * @return float|false
	 */
	private function fetch_yahoo( $base, $target ) {
		$direct = $this->yahoo_price( $base . $target );
		if ( $direct && $direct >= 0.01 ) {
			return $direct;
		}
		$inverse = $this->yahoo_price( $target . $base );
		if ( $inverse && $inverse > 0 ) {
			$rate = 1 / $inverse;
			if ( $rate > 0 ) {
				return $rate;
			}
		}
		return $direct ? $direct : false;
	}

	/**
	 * Precio de un símbolo en Yahoo.
	 *
	 * @param string $symbol Símbolo.
	 * @return float|false
	 */
	private function yahoo_price( $symbol ) {
		$url  = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode( strtoupper( $symbol ) . '=X' ) . '?interval=1d&range=1d';
		$json = $this->http_json( $url, array( 'User-Agent' => 'Mozilla/5.0 (compatible; IPCoin/1.0)' ) );

		if ( is_array( $json ) && isset( $json['chart']['result'][0]['meta']['regularMarketPrice'] ) ) {
			$price = (float) $json['chart']['result'][0]['meta']['regularMarketPrice'];
			if ( $price > 0 ) {
				return $price;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * open.er-api.com
	 * ------------------------------------------------------------------ */

	/**
	 * Tasa desde open.er-api.com.
	 *
	 * @param string $base   Base.
	 * @param string $target Destino.
	 * @return float|false
	 */
	private function fetch_er_api( $base, $target ) {
		$url  = 'https://open.er-api.com/v6/latest/' . rawurlencode( strtoupper( $base ) );
		$json = $this->http_json( $url );

		if ( is_array( $json ) && isset( $json['rates'][ strtoupper( $target ) ] ) ) {
			$rate = (float) $json['rates'][ strtoupper( $target ) ];
			if ( $rate > 0 ) {
				return $rate;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Google Finance (scraping, último respaldo)
	 * ------------------------------------------------------------------ */

	/**
	 * Tasa desde Google Finance (scraping).
	 *
	 * @param string $base   Base.
	 * @param string $target Destino.
	 * @return float|false
	 */
	private function fetch_google( $base, $target ) {
		$direct = $this->google_price( $base, $target );
		if ( $direct && $direct >= 0.01 ) {
			return $direct;
		}
		$inverse = $this->google_price( $target, $base );
		if ( $inverse && $inverse > 0 ) {
			$rate = 1 / $inverse;
			if ( $rate > 0 ) {
				return $rate;
			}
		}
		return $direct ? $direct : false;
	}

	/**
	 * Precio de un par en Google Finance.
	 *
	 * @param string $from Base.
	 * @param string $to   Destino.
	 * @return float|false
	 */
	private function google_price( $from, $to ) {
		$url  = 'https://www.google.com/finance/quote/' . rawurlencode( strtoupper( $from ) . '-' . strtoupper( $to ) );
		$body = $this->http_get(
			$url,
			array(
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
				'Accept-Language' => 'en-US,en;q=0.9',
			)
		);

		if ( ! $body ) {
			return false;
		}

		if ( preg_match( '/data-last-price="([0-9.]+)"/', $body, $matches ) ) {
			$price = (float) $matches[1];
			if ( $price > 0 ) {
				return $price;
			}
		}

		if ( preg_match( '/class="YMlKec fxKbKc"[^>]*>\s*([0-9.,]+)\s*</', $body, $matches ) ) {
			$price = (float) str_replace( ',', '', $matches[1] );
			if ( $price > 0 ) {
				return $price;
			}
		}

		return false;
	}

	/* ---------------------------------------------------------------------
	 * HTTP
	 * ------------------------------------------------------------------ */

	/**
	 * GET y decodifica JSON.
	 *
	 * @param string $url     URL.
	 * @param array  $headers Cabeceras.
	 * @return array|null
	 */
	private function http_json( $url, $headers = array() ) {
		$body = $this->http_get( $url, array_merge( array( 'Accept' => 'application/json' ), $headers ) );
		if ( ! $body ) {
			return null;
		}
		$json = json_decode( $body, true );
		return is_array( $json ) ? $json : null;
	}

	/**
	 * GET y devuelve el cuerpo.
	 *
	 * @param string $url     URL.
	 * @param array  $headers Cabeceras.
	 * @return string|false
	 */
	private function http_get( $url, $headers = array() ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Guarda la clave de un transient creado.
	 *
	 * @param string $key Clave.
	 */
	private function register_key( $key ) {
		$keys = get_option( self::KEYS_OPTION, array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::KEYS_OPTION, $keys, false );
		}
	}

	/**
	 * Elimina todas las tasas cacheadas.
	 */
	public function clear_cache() {
		$keys = get_option( self::KEYS_OPTION, array() );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}
		update_option( self::KEYS_OPTION, array(), false );
		$this->memo = array();
	}
}
