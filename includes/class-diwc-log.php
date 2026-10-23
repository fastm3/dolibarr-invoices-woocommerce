<?php
/**
 * Journal interne du plugin : écrit dans le log PHP ET dans une option
 * WordPress, visible sur la page de réglages (WooCommerce → Factures Dolibarr).
 *
 * @package DIWC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Journal interne du plugin.
 */
class DIWC_Log {

	/**
	 * Nom de l'option WordPress.
	 */
	const OPTION = 'diwc_log';

	/**
	 * Nombre maximal d'entrées conservées.
	 */
	const MAX = 50;

	/**
	 * Ajoute une entrée au journal (et au log PHP).
	 *
	 * @param string $message Message à journaliser.
	 */
	public static function add( $message ) {
		// Log PHP uniquement en débogage : en production, le journal affiché sur
		// la page de réglages suffit (et évite d'écrire dans les logs serveur).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'DIWC : ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}

		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			't' => current_time( 'mysql' ),
			'm' => (string) $message,
		);

		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}

		// autoload = false : le journal ne doit pas être chargé à chaque requête.
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Retourne les entrées du journal.
	 *
	 * @return array
	 */
	public static function get() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Vide le journal.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
