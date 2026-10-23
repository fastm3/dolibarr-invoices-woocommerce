<?php
/**
 * Plugin Name:       Factures Dolibarr pour WooCommerce
 * Plugin URI:        https://github.com/fastm3/dolibarr-invoices-woocommerce
 * Description:       Relie WooCommerce à Dolibarr : factures dans le compte client (liste des commandes et page commande), facture PDF en pièce jointe des e-mails, commande client brouillon créée depuis la fiche commande (articles reliés par extrafield), comparaison et mise à jour des coordonnées du client (tiers et contacts) et de l'adresse de livraison. Nécessite l'API REST de Dolibarr activée.
 * Version:           1.23.1
 * Author:            fastm3
 * Author URI:        https://github.com/fastm3
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       diwc
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 6.0
 * WC tested up to:   9.6
 *
 * @package DIWC
 */

defined( 'ABSPATH' ) || exit;

define( 'DIWC_VERSION', '1.23.1' );
define( 'DIWC_FILE', __FILE__ );
define( 'DIWC_DIR', plugin_dir_path( __FILE__ ) );
define( 'DIWC_URL', plugin_dir_url( __FILE__ ) );

require_once DIWC_DIR . 'includes/class-diwc-log.php';
require_once DIWC_DIR . 'includes/class-diwc-api.php';
require_once DIWC_DIR . 'includes/class-diwc-settings.php';
require_once DIWC_DIR . 'includes/class-diwc-my-account.php';
require_once DIWC_DIR . 'includes/class-diwc-order-create.php';
require_once DIWC_DIR . 'includes/class-diwc-thirdparty.php';

/**
 * Déclare la compatibilité avec les tables de commandes haute performance (HPOS)
 * et le cache de commandes de WooCommerce.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'orders_cache', __FILE__, true );
		}
	}
);

/**
 * Démarre le plugin une fois WooCommerce chargé.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		DIWC_Settings::instance();
		DIWC_API::instance();
		DIWC_My_Account::instance();
		DIWC_Order_Create::instance();
		DIWC_Thirdparty::instance();
	}
);

/**
 * À la désactivation : supprime les tâches planifiées du plugin
 * (synchronisation quotidienne et resynchronisations ponctuelles).
 */
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'diwc_sync_catalog_daily' );
		wp_clear_scheduled_hook( 'diwc_sync_catalog' );
	}
);
