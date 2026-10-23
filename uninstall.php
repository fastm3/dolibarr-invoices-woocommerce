<?php
/**
 * Nettoyage à la désinstallation du plugin.
 *
 * @package DIWC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'diwc_settings' );
delete_option( 'diwc_log' );

// Tâches planifiées : elles survivraient sinon à la désinstallation.
wp_clear_scheduled_hook( 'diwc_sync_catalog_daily' );
wp_clear_scheduled_hook( 'diwc_sync_catalog' );

// Supprime les transients de correspondance commande → facture, le mapping
// produits (extrafield) et les marqueurs de synchronisation.
global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_diwc_invoice_%'
	    OR option_name LIKE '_transient_timeout_diwc_invoice_%'
	    OR option_name LIKE '_transient_diwc_thirdparty_%'
	    OR option_name LIKE '_transient_timeout_diwc_thirdparty_%'
	    OR option_name LIKE 'diwc_product_map_%'
	    OR option_name LIKE '_transient_diwc_efnosql_%'
	    OR option_name LIKE '_transient_timeout_diwc_efnosql_%'
	    OR option_name LIKE '_transient_diwc_sync_lock_%'
	    OR option_name LIKE '_transient_timeout_diwc_sync_lock_%'"
);
