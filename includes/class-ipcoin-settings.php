<?php
/**
 * Menú de administración y ajustes de IPCoin.
 *
 * @package IPCoin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla de configuración en la barra lateral de wp-admin.
 */
class IPCoin_Settings {

	/**
	 * Referencia al plugin.
	 *
	 * @var IPCoin
	 */
	private $plugin;

	/**
	 * Slug del menú.
	 *
	 * @var string
	 */
	const SLUG = 'ipcoin';

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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_ipcoin_refresh_rate', array( $this, 'handle_refresh' ) );
	}

	/**
	 * Añade el menú lateral.
	 */
	public function add_menu() {
		add_menu_page(
			'IPCoin',
			'IPCoin',
			'manage_options',
			self::SLUG,
			array( $this, 'render_page' ),
			'dashicons-admin-site-alt3',
			56.1
		);
	}

	/**
	 * Registra la opción.
	 */
	public function register_settings() {
		register_setting(
			'ipcoin_group',
			IPCoin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->plugin->defaults(),
			)
		);
	}

	/**
	 * Sanea la configuración.
	 *
	 * @param array $input Datos.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = $this->plugin->defaults();

		$out['enabled']       = ! empty( $input['enabled'] ) ? 1 : 0;
		$out['convert_shipping'] = ! empty( $input['convert_shipping'] ) ? 1 : 0;
		$out['convert_coupons']  = ! empty( $input['convert_coupons'] ) ? 1 : 0;
		$out['test_override']    = ! empty( $input['test_override'] ) ? 1 : 0;

		$countries = isset( $input['domestic_countries'] ) ? strtoupper( sanitize_text_field( $input['domestic_countries'] ) ) : 'CO';
		$parts     = array_filter( array_map( 'trim', explode( ',', $countries ) ) );
		$valid     = array();
		foreach ( $parts as $code ) {
			if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
				$valid[] = $code;
			}
		}
		$out['domestic_countries'] = ! empty( $valid ) ? implode( ',', array_unique( $valid ) ) : 'CO';

		$currencies = function_exists( 'get_woocommerce_currencies' ) ? array_keys( get_woocommerce_currencies() ) : array();

		$foreign = isset( $input['foreign_currency'] ) ? strtoupper( sanitize_text_field( $input['foreign_currency'] ) ) : 'USD';
		$out['foreign_currency'] = ( empty( $currencies ) || in_array( $foreign, $currencies, true ) ) ? $foreign : 'USD';

		$fallback = isset( $input['fallback_currency'] ) ? strtoupper( sanitize_text_field( $input['fallback_currency'] ) ) : 'USD';
		$out['fallback_currency'] = ( empty( $currencies ) || in_array( $fallback, $currencies, true ) ) ? $fallback : 'USD';

		$source = isset( $input['rate_source'] ) ? sanitize_key( $input['rate_source'] ) : 'auto';
		$out['rate_source'] = in_array( $source, array( 'auto', 'yahoo', 'er_api', 'google' ), true ) ? $source : 'auto';

		$out['cache_ttl'] = isset( $input['cache_ttl'] ) ? max( 1, absint( $input['cache_ttl'] ) ) : 720;

		$out['manual_rate'] = ( isset( $input['manual_rate'] ) && is_numeric( $input['manual_rate'] ) && (float) $input['manual_rate'] > 0 )
			? (float) $input['manual_rate']
			: '';

		$this->plugin->rates->clear_cache();

		return $out;
	}

	/**
	 * Refresca la tasa base -> moneda extranjera.
	 */
	public function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'ipcoin' ) );
		}
		check_admin_referer( 'ipcoin_refresh_rate' );

		$settings = $this->plugin->get_settings();
		$this->plugin->rates->clear_cache();

		$base = $this->plugin->base_currency();
		$this->plugin->rates->get_rate( $base, strtoupper( $settings['foreign_currency'] ), true );
		if ( strtoupper( $settings['fallback_currency'] ) !== strtoupper( $settings['foreign_currency'] ) ) {
			$this->plugin->rates->get_rate( $base, strtoupper( $settings['fallback_currency'] ), true );
		}

		wp_safe_redirect( add_query_arg( 'refreshed', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Renderiza la pantalla.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = $this->plugin->get_settings();
		$currencies = function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : array();
		$base       = $this->plugin->base_currency();
		$last       = get_option( IPCoin_Rates::LAST_OPTION );

		// Detección del visitante actual (admin): país por IP y moneda resultante.
		$detected_country = '';
		if ( class_exists( 'WC_Geolocation' ) ) {
			$geo = WC_Geolocation::geolocate_ip( '', true, true );
			if ( ! empty( $geo['country'] ) ) {
				$detected_country = strtoupper( $geo['country'] );
			}
		}
		$detected_currency = $this->plugin->geo->currency_for_country( $detected_country );
		?>
		<div class="wrap">
			<h1>IPCoin</h1>

			<?php if ( isset( $_GET['refreshed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Tasa actualizada.', 'ipcoin' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: base currency. */
					esc_html__( 'Moneda base de la tienda: %s. Los clientes del país doméstico ven y pagan en esta moneda; el resto del mundo usa la moneda extranjera, con conversión por tasa en tiempo real.', 'ipcoin' ),
					'<strong>' . esc_html( $base ) . '</strong>'
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'ipcoin_group' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Activar IPCoin', 'ipcoin' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="ipcoin_settings[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
									<?php esc_html_e( 'Aplicar la moneda por país y convertir los precios.', 'ipcoin' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="ipcoin_domestic"><?php esc_html_e( 'Países domésticos', 'ipcoin' ); ?></label></th>
							<td>
								<input type="text" id="ipcoin_domestic" name="ipcoin_settings[domestic_countries]" value="<?php echo esc_attr( $settings['domestic_countries'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Códigos ISO-2 separados por coma. Ej.: CO. Estos países usan la moneda base.', 'ipcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="ipcoin_foreign"><?php esc_html_e( 'Moneda extranjera', 'ipcoin' ); ?></label></th>
							<td>
								<select id="ipcoin_foreign" name="ipcoin_settings[foreign_currency]">
									<?php foreach ( $currencies as $code => $name ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( strtoupper( $settings['foreign_currency'] ), strtoupper( $code ) ); ?>>
											<?php echo esc_html( $code . ' — ' . $name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Moneda para los visitantes fuera de los países domésticos (ej. USD).', 'ipcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="ipcoin_fallback"><?php esc_html_e( 'Moneda si no se detecta el país', 'ipcoin' ); ?></label></th>
							<td>
								<select id="ipcoin_fallback" name="ipcoin_settings[fallback_currency]">
									<?php foreach ( $currencies as $code => $name ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( strtoupper( $settings['fallback_currency'] ), strtoupper( $code ) ); ?>>
											<?php echo esc_html( $code . ' — ' . $name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Se usa cuando la geolocalización no puede determinar el país (VPN, IP no válida).', 'ipcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="ipcoin_source"><?php esc_html_e( 'Fuente de la tasa', 'ipcoin' ); ?></label></th>
							<td>
								<select id="ipcoin_source" name="ipcoin_settings[rate_source]">
									<option value="auto" <?php selected( $settings['rate_source'], 'auto' ); ?>><?php esc_html_e( 'Automática (Yahoo → er-api → Google)', 'ipcoin' ); ?></option>
									<option value="yahoo" <?php selected( $settings['rate_source'], 'yahoo' ); ?>><?php esc_html_e( 'Solo Yahoo Finance', 'ipcoin' ); ?></option>
									<option value="er_api" <?php selected( $settings['rate_source'], 'er_api' ); ?>><?php esc_html_e( 'Solo open.er-api.com', 'ipcoin' ); ?></option>
									<option value="google" <?php selected( $settings['rate_source'], 'google' ); ?>><?php esc_html_e( 'Solo Google Finance (scraping)', 'ipcoin' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="ipcoin_ttl"><?php esc_html_e( 'Caché (minutos)', 'ipcoin' ); ?></label></th>
							<td>
								<input type="number" min="1" step="1" id="ipcoin_ttl" name="ipcoin_settings[cache_ttl]" value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( '720 = 12 horas (dos actualizaciones al día).', 'ipcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="ipcoin_manual"><?php esc_html_e( 'Tasa manual (opcional)', 'ipcoin' ); ?></label></th>
							<td>
								<input type="text" inputmode="decimal" id="ipcoin_manual" name="ipcoin_settings[manual_rate]" value="<?php echo esc_attr( $settings['manual_rate'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Unidades de la moneda extranjera por 1 unidad de la moneda base. Si se indica, se ignora la API.', 'ipcoin' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Conversión', 'ipcoin' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="ipcoin_settings[convert_shipping]" value="1" <?php checked( ! empty( $settings['convert_shipping'] ) ); ?> />
									<?php esc_html_e( 'Convertir los costos de envío.', 'ipcoin' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="ipcoin_settings[convert_coupons]" value="1" <?php checked( ! empty( $settings['convert_coupons'] ) ); ?> />
									<?php esc_html_e( 'Convertir los cupones de descuento fijo.', 'ipcoin' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Pruebas', 'ipcoin' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="ipcoin_settings[test_override]" value="1" <?php checked( ! empty( $settings['test_override'] ) ); ?> />
									<?php esc_html_e( 'Permitir simular país en la portada con ?ipcoin_country=XX (solo administradores).', 'ipcoin' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Guardar cambios', 'ipcoin' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Estado', 'ipcoin' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: country, 2: currency. */
					esc_html__( 'Tu IP actual: %1$s → moneda resultante: %2$s', 'ipcoin' ),
					'<strong>' . esc_html( $detected_country ? $detected_country : '—' ) . '</strong>',
					'<strong>' . esc_html( $detected_currency ) . '</strong>'
				);
				?>
			</p>

			<?php if ( is_array( $last ) && isset( $last['rate'] ) ) : ?>
				<p>
					<?php
					printf(
						'1 %s = %s %s · %s · %s',
						esc_html( $last['base'] ),
						esc_html( number_format_i18n( (float) $last['rate'], 6 ) ),
						esc_html( $last['target'] ),
						esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] ) ),
						esc_html( $last['source'] )
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'Aún no hay una tasa registrada.', 'ipcoin' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ipcoin_refresh_rate" />
				<?php wp_nonce_field( 'ipcoin_refresh_rate' ); ?>
				<?php submit_button( __( 'Actualizar tasa ahora', 'ipcoin' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
