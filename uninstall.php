<?php
/**
 * Limpieza al desinstalar IPCoin.
 *
 * @package IPCoin
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ipcoin_settings' );
delete_option( 'ipcoin_last_rate' );

$ipcoin_keys = get_option( 'ipcoin_rate_keys', array() );
if ( is_array( $ipcoin_keys ) ) {
	foreach ( $ipcoin_keys as $ipcoin_key ) {
		delete_transient( $ipcoin_key );
	}
}
delete_option( 'ipcoin_rate_keys' );
