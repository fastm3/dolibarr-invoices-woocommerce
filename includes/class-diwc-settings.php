<?php
/**
 * Page de réglages du plugin (WooCommerce > Factures Dolibarr).
 *
 * @package DIWC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Réglages du plugin : URL Dolibarr, clé API, modes de correspondance
 * et d'affichage, test de connexion.
 */
class DIWC_Settings {

	/**
	 * Nom de l'option WordPress.
	 */
	const OPTION = 'diwc_settings';

	/**
	 * Instance unique.
	 *
	 * @var DIWC_Settings|null
	 */
	private static $instance = null;

	/**
	 * Valeurs par défaut.
	 *
	 * @var array
	 */
	private $defaults = array(
		'url'                => '',
		'api_key'            => '',
		'ui_path'            => '',
		'match_mode'         => 'ref_client',
		'display_mode'       => 'proxy',
		'cache_ttl'          => 3600,
		'column_label'       => 'Facture',
		'product_extrafield' => 'id_du_produit_dans_woocommerce',
		'product_match_mode' => 'variation_then_parent',
		'block_unmapped_products' => 'yes',
	);

	/**
	 * Retourne l'instance unique.
	 *
	 * @return DIWC_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructeur privé (singleton).
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_diwc_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_diwc_test_download', array( $this, 'handle_test_download' ) );
		add_action( 'admin_post_diwc_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_diwc_test_extrafields', array( $this, 'handle_test_extrafields' ) );
	}

	/**
	 * Retourne une valeur de réglage (avec repli sur les valeurs par défaut).
	 *
	 * @param string $key Clé du réglage.
	 * @return mixed
	 */
	public function get( $key ) {
		$options = get_option( self::OPTION, array() );
		$options = wp_parse_args( is_array( $options ) ? $options : array(), $this->defaults );
		if ( array_key_exists( $key, $options ) ) {
			return $options[ $key ];
		}
		return isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : null;
	}

	/**
	 * Ajoute le sous-menu sous WooCommerce.
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Factures Dolibarr', 'diwc' ),
			__( 'Factures Dolibarr', 'diwc' ),
			'manage_woocommerce',
			'diwc-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enregistre l'option et ses champs.
	 */
	public function register_settings() {
		register_setting(
			'diwc_settings_group',
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);

		add_settings_section(
			'diwc_section_connection',
			__( 'Connexion à Dolibarr', 'diwc' ),
			array( $this, 'render_section_connection' ),
			'diwc-settings'
		);

		add_settings_field(
			'diwc_url',
			__( 'URL de Dolibarr', 'diwc' ),
			array( $this, 'field_url' ),
			'diwc-settings',
			'diwc_section_connection'
		);

		add_settings_field(
			'diwc_api_key',
			__( 'Clé API', 'diwc' ),
			array( $this, 'field_api_key' ),
			'diwc-settings',
			'diwc_section_connection'
		);

		add_settings_field(
			'diwc_ui_path',
			__( 'Chemin de l\'interface Dolibarr', 'diwc' ),
			array( $this, 'field_ui_path' ),
			'diwc-settings',
			'diwc_section_connection'
		);

		add_settings_section(
			'diwc_section_matching',
			__( 'Correspondance commande → facture', 'diwc' ),
			array( $this, 'render_section_matching' ),
			'diwc-settings'
		);

		add_settings_field(
			'diwc_match_mode',
			__( 'Méthode de correspondance', 'diwc' ),
			array( $this, 'field_match_mode' ),
			'diwc-settings',
			'diwc_section_matching'
		);

		add_settings_section(
			'diwc_section_display',
			__( 'Affichage côté client', 'diwc' ),
			array( $this, 'render_section_display' ),
			'diwc-settings'
		);

		add_settings_field(
			'diwc_display_mode',
			__( 'Mode d\'affichage des factures', 'diwc' ),
			array( $this, 'field_display_mode' ),
			'diwc-settings',
			'diwc_section_display'
		);

		add_settings_field(
			'diwc_column_label',
			__( 'Titre de la colonne', 'diwc' ),
			array( $this, 'field_column_label' ),
			'diwc-settings',
			'diwc_section_display'
		);

		add_settings_field(
			'diwc_cache_ttl',
			__( 'Durée du cache (secondes)', 'diwc' ),
			array( $this, 'field_cache_ttl' ),
			'diwc-settings',
			'diwc_section_display'
		);

		add_settings_section(
			'diwc_section_draft_order',
			__( 'Commande client Dolibarr (brouillon)', 'diwc' ),
			array( $this, 'render_section_draft_order' ),
			'diwc-settings'
		);

		add_settings_field(
			'diwc_product_extrafield',
			__( 'Extrafield produit lié à WooCommerce', 'diwc' ),
			array( $this, 'field_product_extrafield' ),
			'diwc-settings',
			'diwc_section_draft_order'
		);

		add_settings_field(
			'diwc_product_match_mode',
			__( 'Correspondance des articles', 'diwc' ),
			array( $this, 'field_product_match_mode' ),
			'diwc-settings',
			'diwc_section_draft_order'
		);

		add_settings_field(
			'diwc_block_unmapped_products',
			__( 'Article sans produit Dolibarr', 'diwc' ),
			array( $this, 'field_block_unmapped_products' ),
			'diwc-settings',
			'diwc_section_draft_order'
		);
	}

	/**
	 * Texte d'introduction de la section connexion.
	 */
	public function render_section_connection() {
		echo '<p>' . esc_html__( 'Activez le module « Web services REST » dans Dolibarr, créez une clé API avec accès en lecture aux factures, puis renseignez ci-dessous.', 'diwc' ) . '</p>';
	}

	/**
	 * Texte d'introduction de la section correspondance.
	 */
	public function render_section_matching() {
		echo '<p>' . esc_html__( 'Le plugin recherche la facture d\'une commande par le champ « Réf. client » (ref_client) de la facture Dolibarr, qui doit contenir le numéro de commande WooCommerce. En mode manuel, vous saisissez l\'ID de la facture sur chaque commande.', 'diwc' ) . '</p>';
	}

	/**
	 * Texte d'introduction de la section affichage.
	 */
	public function render_section_display() {
		echo '<p>' . esc_html__( 'Mode « proxy » : le PDF transite par votre site WordPress, Dolibarr reste invisible pour le client (recommandé). Mode « auto » : lien public Dolibarr si disponible, sinon proxy. Mode « public » : lien direct sur votre Dolibarr (à éviter si vous ne voulez pas exposer Dolibarr).', 'diwc' ) . '</p>';
	}

	/**
	 * Champ : URL de Dolibarr.
	 */
	public function field_url() {
		$value = (string) $this->get( 'url' );
		echo '<input type="url" class="regular-text" name="diwc_settings[url]" value="' . esc_attr( $value ) . '" placeholder="https://dolibarr.exemple.fr" />';
		echo '<p class="description">' . esc_html__( 'Exemple : https://dolibarr.exemple.fr (sans slash final).', 'diwc' ) . '</p>';
	}

	/**
	 * Champ : clé API.
	 */
	public function field_api_key() {
		$value = (string) $this->get( 'api_key' );
		echo '<input type="password" class="regular-text" name="diwc_settings[api_key]" value="' . esc_attr( $value ) . '" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'Clé générée dans Dolibarr : menu « API » puis « Créer une clé ». Donnez-lui au minimum le droit de lecture des factures.', 'diwc' ) . '</p>';
	}

	/**
	 * Champ : chemin de l'interface Dolibarr (lien « Ouvrir dans Dolibarr »).
	 */
	public function field_ui_path() {
		$value = (string) $this->get( 'ui_path' );
		echo '<input type="text" class="regular-text" name="diwc_settings[ui_path]" value="' . esc_attr( $value ) . '" placeholder="/compta" />';
		echo '<p class="description">' . esc_html__( 'Sous-dossier de l\'interface Dolibarr, si différent de l\'API. Exemple : /compta si vos fiches sont à https://votre-dolibarr.fr/compta/facture/card.php. Laissez vide si l\'interface est à la racine.', 'diwc' ) . '</p>';
	}

	/**
	 * Champ : méthode de correspondance.
	 */
	public function field_match_mode() {
		$value = (string) $this->get( 'match_mode' );
		?>
		<select name="diwc_settings[match_mode]">
			<option value="ref_client" <?php selected( $value, 'ref_client' ); ?>><?php esc_html_e( 'Réf. client (ref_client) = numéro de commande', 'diwc' ); ?></option>
			<option value="manual" <?php selected( $value, 'manual' ); ?>><?php esc_html_e( 'ID de facture saisi manuellement sur chaque commande', 'diwc' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'En mode manuel, un champ « ID facture Dolibarr » apparaît sur la page de modification des commandes.', 'diwc' ); ?></p>
		<?php
	}

	/**
	 * Champ : mode d'affichage.
	 */
	public function field_display_mode() {
		$value = (string) $this->get( 'display_mode' );
		?>
		<select name="diwc_settings[display_mode]">
			<option value="proxy" <?php selected( $value, 'proxy' ); ?>><?php esc_html_e( 'Proxy : téléchargement via l\'API — Dolibarr invisible pour le client (recommandé)', 'diwc' ); ?></option>
			<option value="auto" <?php selected( $value, 'auto' ); ?>><?php esc_html_e( 'Auto : lien public si possible, sinon proxy', 'diwc' ); ?></option>
			<option value="public" <?php selected( $value, 'public' ); ?>><?php esc_html_e( 'Lien public Dolibarr uniquement', 'diwc' ); ?></option>
		</select>
		<p class="description">
			<?php
			echo esc_html__(
				'Seul le mode « proxy » garantit que le client ne voit jamais l\'URL ni la clé de votre Dolibarr : le PDF est récupéré côté serveur par le plugin et affiché via votre site. Les modes « auto » et « public » ouvrent une page de votre Dolibarr chez le client (lien public sécurisé).',
				'diwc'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Champ : titre de la colonne.
	 */
	public function field_column_label() {
		$value = (string) $this->get( 'column_label' );
		echo '<input type="text" class="regular-text" name="diwc_settings[column_label]" value="' . esc_attr( $value ) . '" />';
	}

	/**
	 * Champ : durée du cache.
	 */
	public function field_cache_ttl() {
		$value = (int) $this->get( 'cache_ttl' );
		echo '<input type="number" min="300" max="86400" step="60" name="diwc_settings[cache_ttl]" value="' . esc_attr( (string) $value ) . '" />';
		echo '<p class="description">' . esc_html__( 'Durée pendant laquelle le résultat de la correspondance est mémorisé. Réduisez si les factures apparaissent avec retard après paiement.', 'diwc' ) . '</p>';
	}

	/**
	 * Texte d'introduction de la section commande client (brouillon).
	 */
	public function render_section_draft_order() {
		echo '<p>' . esc_html__( 'Le bouton « Créer la commande brouillon Dolibarr » (fiche commande admin) crée une commande client au statut brouillon dans Dolibarr, avec les lignes de la commande WooCommerce. Chaque article est relié à son produit Dolibarr via un extrafield.', 'diwc' ) . '</p>';
	}

	/**
	 * Champ : extrafield produit lié à WooCommerce.
	 */
	public function field_product_extrafield() {
		$value = (string) $this->get( 'product_extrafield' );
		echo '<input type="text" class="regular-text" name="diwc_settings[product_extrafield]" value="' . esc_attr( $value ) . '" placeholder="id_du_produit_dans_woocommerce" />';
		echo '<p class="description">' . esc_html__( 'Nom technique de l\'extrafield produit Dolibarr contenant l\'ID du produit WooCommerce. Un article dont le produit n\'est pas retrouvé fait échouer la création (voir le réglage suivant) ; les frais de port sont toujours ajoutés en ligne libre.', 'diwc' ) . '</p>';
	}

	/**
	 * Champ : stratégie de correspondance des articles (déclinaison / parent).
	 */
	public function field_product_match_mode() {
		$value = (string) $this->get( 'product_match_mode' );
		?>
		<select name="diwc_settings[product_match_mode]">
			<option value="variation_then_parent" <?php selected( $value, 'variation_then_parent' ); ?>><?php esc_html_e( 'ID de la déclinaison, puis ID du produit parent (défaut)', 'diwc' ); ?></option>
			<option value="variation" <?php selected( $value, 'variation' ); ?>><?php esc_html_e( 'ID de la déclinaison uniquement (aucun repli sur le parent)', 'diwc' ); ?></option>
			<option value="parent" <?php selected( $value, 'parent' ); ?>><?php esc_html_e( 'ID du produit parent uniquement', 'diwc' ); ?></option>
		</select>
		<p class="description">
			<?php
			echo esc_html__(
				'Choisissez selon ce que contient l\'extrafield : l\'ID de la DÉCLINAISON WooCommerce, celui du produit PARENT, ou les deux. Le mode « déclinaison uniquement » évite qu\'un article soit relié à un autre produit Dolibarr via le parent ; l\'aperçu affiché sur la fiche commande montre la correspondance obtenue avant toute création.',
				'diwc'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Champ : comportement lorsqu'un article n'a pas de produit Dolibarr.
	 */
	public function field_block_unmapped_products() {
		$value = (string) $this->get( 'block_unmapped_products' );
		?>
		<select name="diwc_settings[block_unmapped_products]">
			<option value="yes" <?php selected( $value, 'yes' ); ?>><?php esc_html_e( 'Refuser la création de la commande brouillon (recommandé)', 'diwc' ); ?></option>
			<option value="no" <?php selected( $value, 'no' ); ?>><?php esc_html_e( 'Créer quand même, en ajoutant une ligne libre', 'diwc' ); ?></option>
		</select>
		<p class="description">
			<?php
			echo esc_html__(
				'Avec « refuser », aucune commande Dolibarr n\'est créée tant qu\'un article de la commande WooCommerce n\'a pas de produit Dolibarr correspondant : le message indique le nom de l\'article avec sa déclinaison (attributs) et l\'ID Woo recherché. C\'est la garantie qu\'aucun document comptable ne parte avec un article générique. Avec « créer quand même », l\'article est ajouté en ligne libre, sans lien vers un produit Dolibarr — à réserver aux cas exceptionnels (le détail est écrit dans le Journal).',
				'diwc'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Nettoie les valeurs soumises.
	 *
	 * @param mixed $input Valeurs brutes.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$output = array();

		$output['url']          = isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ) ) : '';
		$output['api_key']      = isset( $input['api_key'] ) ? sanitize_text_field( (string) $input['api_key'] ) : '';
		$output['ui_path']      = isset( $input['ui_path'] ) ? sanitize_text_field( trim( (string) $input['ui_path'] ) ) : '';
		$output['match_mode']   = ( isset( $input['match_mode'] ) && in_array( $input['match_mode'], array( 'ref_client', 'manual' ), true ) ) ? $input['match_mode'] : 'ref_client';
		$output['display_mode'] = ( isset( $input['display_mode'] ) && in_array( $input['display_mode'], array( 'proxy', 'auto', 'public' ), true ) ) ? $input['display_mode'] : 'proxy';
		$output['cache_ttl']    = isset( $input['cache_ttl'] ) ? min( 86400, max( 300, (int) $input['cache_ttl'] ) ) : 3600;
		$output['column_label'] = isset( $input['column_label'] ) ? sanitize_text_field( $input['column_label'] ) : __( 'Facture', 'diwc' );
		$extrafield             = isset( $input['product_extrafield'] ) ? sanitize_text_field( trim( (string) $input['product_extrafield'] ) ) : '';
		$output['product_extrafield'] = ( '' !== $extrafield && preg_match( '/^[a-zA-Z0-9_]+$/', $extrafield ) ) ? $extrafield : 'id_du_produit_dans_woocommerce';
		$output['product_match_mode'] = ( isset( $input['product_match_mode'] ) && in_array( $input['product_match_mode'], array( 'variation_then_parent', 'variation', 'parent' ), true ) ) ? $input['product_match_mode'] : 'variation_then_parent';
		$output['block_unmapped_products'] = ( isset( $input['block_unmapped_products'] ) && 'no' === $input['block_unmapped_products'] ) ? 'no' : 'yes';

		return $output;
	}

	/**
	 * Affiche l'état du mapping produits (extrafield) et le bouton de
	 * synchronisation du catalogue (en arrière-plan via WP-Cron).
	 */
	private function render_catalog_sync() {
		$field = (string) $this->get( 'product_extrafield' );
		$data  = DIWC_API::instance()->get_product_map( $field );
		?>
		<h2><?php esc_html_e( 'Catalogue produits Dolibarr', 'diwc' ); ?></h2>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s : nom de l'extrafield. */
					__( 'Le bouton « Créer la commande brouillon Dolibarr » relie chaque article au produit Dolibarr via l\'extrafield « %s ». Si votre version de Dolibarr ne permet pas de filtrer la liste des produits par extrafield, le plugin utilise un mapping du catalogue construit en arrière-plan : lancez la synchronisation ci-dessous (WP-Cron requis), puis rechargez cette page pour vérifier l\'état.', 'diwc' ),
					$field
				)
			);
			?>
		</p>
		<?php
		if ( is_array( $data ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1 : nombre de produits mappés, 2 : date de dernière synchro, 3 : mode. */
					__( 'État du mapping : %1$d produit(s) mappé(s) — dernière synchronisation : %2$s (mode : %3$s).', 'diwc' ),
					count( $data['map'] ),
					date_i18n( get_option( 'date_format' ) . ' H:i', (int) $data['at'] ),
					'inline' === $data['mode'] ? 'liste' : 'produit par produit'
				)
			) . '</p>';

			// Une synchronisation en échec ne remplace plus le mapping valide :
			// on affiche l'état réel plutôt que de laisser croire à une synchro saine.
			if ( ! empty( $data['error'] ) ) {
				echo '<p style="color:#b32d2e;"><strong>' . esc_html(
					sprintf(
						/* translators: 1 : message d'erreur, 2 : date de la tentative. */
						__( 'Dernière synchronisation en échec (%2$s) — le mapping précédent a été conservé. Erreur : %1$s', 'diwc' ),
						(string) $data['error'],
						isset( $data['attempt'] ) ? date_i18n( get_option( 'date_format' ) . ' H:i', (int) $data['attempt'] ) : '?'
					)
				) . '</strong></p>';
			}
		} else {
			echo '<p>' . esc_html__( 'État du mapping : jamais synchronisé.', 'diwc' ) . '</p>';
		}

		$next = wp_next_scheduled( 'diwc_sync_catalog_daily' );
		echo '<p>' . esc_html(
			$next
				? sprintf(
					/* translators: %s : date de la prochaine synchronisation automatique. */
					__( 'Mise à jour automatique : une fois par jour — prochaine exécution le %s (et resynchronisation immédiate si un article n\'est pas retrouvé lors d\'une création).', 'diwc' ),
					date_i18n( get_option( 'date_format' ) . ' H:i', (int) $next )
				)
				: __( 'Mise à jour automatique : une fois par jour (planification effective à la prochaine visite du site) — et resynchronisation immédiate si un article n\'est pas retrouvé lors d\'une création.', 'diwc' )
		) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="diwc_sync_catalog" />
			<?php wp_nonce_field( 'diwc_sync_catalog' ); ?>
			<?php submit_button( __( 'Synchroniser le catalogue (arrière-plan)', 'diwc' ), 'secondary', 'submit', false ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<input type="hidden" name="action" value="diwc_test_extrafields" />
			<?php wp_nonce_field( 'diwc_test_extrafields' ); ?>
			<input type="number" name="product_id" min="1" placeholder="<?php esc_attr_e( 'ID produit Dolibarr', 'diwc' ); ?>" style="width:120px;" />
			<button type="submit" class="button"><?php esc_html_e( 'Diagnostiquer les extrafields d\'un produit', 'diwc' ); ?></button>
		</form>
		<p class="description">
			<?php
			echo esc_html__(
				'Collez l\'ID d\'un produit Dolibarr qui possède l\'extrafield : le plugin lit /products/{id}, affiche les array_options et vérifie qu\'il sait retrouver ce produit par l\'extrafield (résumé à l\'écran, détails dans le Journal).',
				'diwc'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Affiche la page de réglages.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$this->maybe_show_notice();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Factures Dolibarr pour WooCommerce', 'diwc' ); ?></h1>
			<p>
				<?php
				echo esc_html__(
					'Ce plugin affiche, dans le compte client WooCommerce, les factures Dolibarr liées aux commandes. Il suppose que vos commandes créent des factures dans Dolibarr (connecteur WooCommerce officiel, ou création manuelle).',
					'diwc'
				);
				?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'diwc_settings_group' ); ?>
				<?php do_settings_sections( 'diwc-settings' ); ?>
				<?php submit_button(); ?>
			</form>

			<?php $this->render_catalog_sync(); ?>

			<hr />

			<h2><?php esc_html_e( 'Tests de diagnostic', 'diwc' ); ?></h2>
			<p>
				<?php
				echo esc_html__(
					'En cas d\'erreur 502 à l\'ouverture d\'une facture, lancez le test de téléchargement : il vérifie chaque étape (accès API, PDF présent dans Dolibarr, téléchargement réel).',
					'diwc'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="diwc_test_connection" />
				<?php wp_nonce_field( 'diwc_test_connection' ); ?>
				<?php submit_button( __( 'Tester la connexion à Dolibarr', 'diwc' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="diwc_test_download" />
				<?php wp_nonce_field( 'diwc_test_download' ); ?>
				<?php submit_button( __( 'Tester le téléchargement d\'un PDF', 'diwc' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Journal', 'diwc' ); ?></h2>
			<?php $this->render_log(); ?>
		</div>
		<?php
	}

	/**
	 * Affiche le journal interne du plugin.
	 */
	private function render_log() {
		$entries = DIWC_Log::get();

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'Aucune entrée pour l\'instant. Les échecs de téléchargement et les tentatives y apparaîtront.', 'diwc' ) . '</p>';
		} else {
			echo '<div style="background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:10px 12px;max-height:300px;overflow:auto;">';
			echo '<pre style="margin:0;font-size:12px;white-space:pre-wrap;word-break:break-all;">';
			$lines = array();
			foreach ( array_reverse( $entries ) as $entry ) {
				$lines[] = sprintf( '[%s] %s', isset( $entry['t'] ) ? $entry['t'] : '', isset( $entry['m'] ) ? $entry['m'] : '' );
			}
			echo esc_html( implode( "\n", $lines ) );
			echo '</pre></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px;">
			<input type="hidden" name="action" value="diwc_clear_log" />
			<?php wp_nonce_field( 'diwc_clear_log' ); ?>
			<?php submit_button( __( 'Vider le journal', 'diwc' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Vide le journal (action admin-post).
	 */
	public function handle_clear_log() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_clear_log' );
		DIWC_Log::clear();
		wp_safe_redirect( add_query_arg( 'page', 'diwc-settings', admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Affiche le résultat du test de connexion passé par query arg.
	 */
	private function maybe_show_notice() {
		if ( ! isset( $_GET['diwc_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$ok  = '1' === $_GET['diwc_test']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['diwc_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['diwc_msg'] ) ) : '';
		$msg = $msg ? $msg : ( $ok ? __( 'Connexion OK.', 'diwc' ) : __( 'Échec de la connexion.', 'diwc' ) );

		echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Gère le test de connexion (action admin-post).
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_test_connection' );

		$result = DIWC_API::instance()->test_connection();
		$ok     = ! is_wp_error( $result );
		$msg    = $ok ? __( 'Connexion OK : l\'API Dolibarr répond.', 'diwc' ) : $result->get_error_message();

		$url = add_query_arg(
			array(
				'page'      => 'diwc-settings',
				'diwc_test' => $ok ? '1' : '0',
				'diwc_msg'  => rawurlencode( $msg ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Gère le test de téléchargement (action admin-post).
	 */
	public function handle_test_download() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_test_download' );

		$result = DIWC_API::instance()->test_download();

		if ( is_wp_error( $result ) ) {
			$msg = __( 'Échec du téléchargement : ', 'diwc' ) . $result->get_error_message();
		} else {
			$msg = sprintf(
				/* translators: 1 : référence facture, 2 : octets, 3 : type MIME. */
				__( 'PDF récupéré : facture %1$s, %2$s octets, type « %3$s ». Le téléchargement via le site fonctionne.', 'diwc' ),
				$result['ref'],
				number_format_i18n( (int) $result['bytes'] ),
				$result['content_type']
			);
		}

		$url = add_query_arg(
			array(
				'page'      => 'diwc-settings',
				'diwc_test' => is_wp_error( $result ) ? '0' : '1',
				'diwc_msg'  => rawurlencode( $msg ),
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Gère le diagnostic des extrafields d'un produit (action admin-post) :
	 * interroge /products/{id} et /products/{id}/extrafields, affiche un
	 * résumé et journalise la réponse brute (pour ajuster la lecture des
	 * extrafields côté plugin).
	 */
	public function handle_test_extrafields() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_test_extrafields' );

		$pid = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $pid <= 0 ) {
			$msg = __( 'ID produit invalide.', 'diwc' );
		} else {
			$api    = DIWC_API::instance();
			$single = $api->request( 'GET', '/products/' . $pid );

			$lines = array();

			if ( is_wp_error( $single ) ) {
				$lines[] = 'GET /products/' . $pid . ' → ' . $single->get_error_message();
			} elseif ( is_array( $single ) ) {
				$top   = implode( ', ', array_slice( array_keys( $single ), 0, 15 ) );
				$opts  = ( isset( $single['array_options'] ) && is_array( $single['array_options'] ) ) ? $single['array_options'] : null;
				$lines[] = 'GET /products/' . $pid . ' → OK (clés : ' . $top . ')';
				$lines[] = ( null === $opts ) ? 'array_options : ABSENT' : 'array_options : ' . wp_json_encode( $opts );

				// Vérifie que le plugin sait bien retrouver ce produit par l'extrafield.
				$field = (string) DIWC_Settings::instance()->get( 'product_extrafield' );
				$val   = null;
				if ( is_array( $opts ) ) {
					foreach ( array( $field, 'custom_' . $field, 'options_' . $field ) as $key ) {
						if ( array_key_exists( $key, $opts ) ) {
							$val = $opts[ $key ];
							break;
						}
					}
				}

				if ( null === $val ) {
					$lines[] = 'Mapping plugin : extrafield « ' . $field . ' » absent des array_options ❌';
				} else {
					$hit     = $api->search_product_by_extrafield( $field, $val );
					$lines[] = $hit
						? 'Mapping plugin : ' . $field . '=' . $val . ' → produit Dolibarr ' . $hit['ref'] . ' (id ' . $hit['id'] . ') ✅'
						: 'Mapping plugin : ' . $field . '=' . $val . ' → NON RETROUVÉ ❌ (synchronisez le catalogue)';
				}
			} else {
				$lines[] = 'GET /products/' . $pid . ' → réponse inattendue.';
			}

			$detail = implode( ' | ', $lines );
			DIWC_Log::add( 'Diagnostic extrafields du produit ' . $pid . ' : ' . $detail );

			$short = ( function_exists( 'mb_substr' ) ? mb_substr( $detail, 0, 500 ) : substr( $detail, 0, 500 ) );
			$msg   = __( 'Diagnostic extrafields envoyé au Journal. Résumé : ', 'diwc' ) . $short;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'diwc-settings',
					'diwc_test' => '1',
					'diwc_msg'  => rawurlencode( $msg ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
