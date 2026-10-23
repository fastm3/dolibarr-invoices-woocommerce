<?php
/**
 * Intégration « Mon compte » WooCommerce : colonne Facture, bouton sur la
 * page de commande, champ admin sur la commande, téléchargement du PDF.
 *
 * @package DIWC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Affichage des factures Dolibarr dans le compte client WooCommerce.
 */
class DIWC_My_Account {

	/**
	 * Meta stockant l'ID de facture Dolibarr saisi manuellement (mode manuel).
	 */
	const META_INVOICE_ID = '_diwc_dolibarr_invoice_id';

	/**
	 * Instance unique.
	 *
	 * @var DIWC_My_Account|null
	 */
	private static $instance = null;

	/**
	 * Garde anti-récursion lors de la sauvegarde du champ admin.
	 *
	 * @var bool
	 */
	private static $saving = false;

	/**
	 * Fichiers temporaires attachés aux e-mails (nettoyés après envoi).
	 *
	 * @var array
	 */
	private $email_attachments = array();

	/**
	 * Retourne l'instance unique.
	 *
	 * @return DIWC_My_Account
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
		// Compte client : colonne dans la liste des commandes.
		add_filter( 'woocommerce_account_orders_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_my_account_my_orders_column_diwc-invoice', array( $this, 'render_column' ) );

		// Page de commande (Mon compte > Commande).
		add_action( 'woocommerce_view_order', array( $this, 'render_order_panel' ) );

		// Téléchargement / visualisation du PDF (proxy).
		add_action( 'admin_post_diwc_download_invoice', array( $this, 'handle_download' ) );

		// Champ admin « ID facture Dolibarr » sur la commande (mode manuel).
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_admin_field' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_admin_field' ), 10, 2 );
		add_action( 'woocommerce_after_save_order', array( $this, 'save_admin_field' ), 10, 2 );

		// Actualisation forcée de la recherche (admin).
		add_action( 'admin_init', array( $this, 'maybe_force_refresh' ) );

		// Recherche du compte client Dolibarr par e-mail (admin, sans commande).
		add_filter( 'manage_users_columns', array( $this, 'add_users_column' ) );
		add_action( 'manage_users_custom_column', array( $this, 'render_users_column' ), 10, 3 );
		add_action( 'show_user_profile', array( $this, 'render_user_profile_box' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_profile_box' ) );

		// Envoi de la facture par e-mail : bouton admin + pièce jointe à
		// l'e-mail « commande terminée ».
		add_action( 'admin_post_diwc_send_invoice', array( $this, 'handle_send_invoice' ) );
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach_invoice_to_completed_email' ), 10, 3 );
		add_action( 'woocommerce_email_sent', array( $this, 'cleanup_email_attachment' ) );
		add_action( 'shutdown', array( $this, 'cleanup_email_attachment' ) );

		// Styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Ajoute la colonne « Facture » à la liste des commandes du compte client.
	 *
	 * @param array $columns Colonnes existantes.
	 * @return array
	 */
	public function add_column( $columns ) {
		$label = (string) DIWC_Settings::instance()->get( 'column_label' );

		$out = array();
		foreach ( $columns as $key => $value ) {
			$out[ $key ] = $value;
			if ( 'order-total' === $key ) {
				$out['diwc-invoice'] = $label;
			}
		}
		if ( ! isset( $out['diwc-invoice'] ) ) {
			$out['diwc-invoice'] = $label;
		}
		return $out;
	}

	/**
	 * Affiche le bouton facture dans la colonne du tableau des commandes.
	 *
	 * @param WC_Order $order Commande.
	 */
	public function render_column( $order ) {
		echo $this->invoice_button( $order ); // phpcs:ignore WordPress.Security.EscapeOutput -- HTML construit et échappé dans invoice_button().
	}

	/**
	 * Affiche un bloc « Facture » sur la page de commande.
	 *
	 * @param int $order_id ID de la commande.
	 */
	public function render_order_panel( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( isset( $_GET['diwc_dlerr'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<p class="woocommerce-error">' . esc_html__( 'La facture est temporairement indisponible. Merci de réessayer plus tard.', 'diwc' ) . '</p>';
		}

		$invoice = $this->find_invoice( $order );
		if ( null === $invoice ) {
			return;
		}

		$button = $this->invoice_button( $order );
		if ( '' === $button ) {
			return;
		}

		$ref   = isset( $invoice['ref'] ) ? (string) $invoice['ref'] : '';
		$date  = isset( $invoice['date'] ) ? (int) $invoice['date'] : 0;
		$total = isset( $invoice['total_ttc'] ) ? (float) $invoice['total_ttc'] : 0.0;

		echo '<section class="diwc-invoice-panel">';
		echo '<h2>' . esc_html__( 'Facture', 'diwc' ) . '</h2>';
		echo '<ul class="diwc-invoice-meta">';
		if ( '' !== $ref ) {
			echo '<li><strong>' . esc_html__( 'Référence :', 'diwc' ) . '</strong> ' . esc_html( $ref ) . '</li>';
		}
		if ( $date > 0 ) {
			echo '<li><strong>' . esc_html__( 'Date :', 'diwc' ) . '</strong> ' . esc_html( date_i18n( get_option( 'date_format' ), $date ) ) . '</li>';
		}
		if ( $total > 0 ) {
			echo '<li><strong>' . esc_html__( 'Montant TTC :', 'diwc' ) . '</strong> ' . wp_kses_post( wc_price( $total ) ) . '</li>';
		}
		echo '</ul>';
		echo $button; // phpcs:ignore WordPress.Security.EscapeOutput -- HTML construit et échappé dans invoice_button().
		echo '</section>';
	}

	/**
	 * Construit le bouton « Voir la facture » (lien public Dolibarr ou proxy API).
	 *
	 * @param WC_Order $order Commande.
	 * @return string HTML du bouton, ou chaîne vide.
	 */
	private function invoice_button( $order ) {
		if ( ! $order ) {
			return '';
		}

		$invoice = $this->find_invoice( $order );
		if ( null === $invoice ) {
			return '';
		}

		$mode  = (string) DIWC_Settings::instance()->get( 'display_mode' );
		$hashp = isset( $invoice['hashp'] ) ? (string) $invoice['hashp'] : '';

		$url = '';

		if ( 'proxy' === $mode || ( 'auto' === $mode && '' === $hashp ) ) {
			$url = $this->build_download_url( (int) $order->get_id(), (int) $invoice['id'] );
		} elseif ( 'public' === $mode || 'auto' === $mode ) {
			if ( '' !== $hashp ) {
				$url = $this->card_url( (int) $invoice['id'], $hashp );
			}
		}

		if ( '' === $url ) {
			return '';
		}

		$label = __( 'Voir la facture', 'diwc' );

		return '<a class="diwc-invoice-button button" href="' . esc_url( $url ) . '" target="_blank" rel="noopener nofollow">'
			. esc_html( $label )
			. '</a>';
	}

	/**
	 * Trouve la facture Dolibarr associée à une commande, avec cache transient.
	 *
	 * @param WC_Order $order Commande.
	 * @return array|null Facture (id, ref, date, total_ttc, hashp...), ou null.
	 */
	private function find_invoice( $order ) {
		$order_id  = (int) $order->get_id();
		$cache_key = 'diwc_invoice_' . $order_id;

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['id'] ) ) ? $cached : null;
		}

		$invoice = null;

		// 1) Mode manuel : ID saisi sur la commande.
		if ( 'manual' === (string) DIWC_Settings::instance()->get( 'match_mode' ) ) {
			$manual = (int) $order->get_meta( self::META_INVOICE_ID );
			if ( $manual > 0 ) {
				$invoice = DIWC_API::instance()->get_invoice( $manual );
			}
		}

		// 2) Par défaut : ref_client = numéro de commande.
		if ( null === $invoice ) {
			$ref  = $order->get_order_number();
			$list = DIWC_API::instance()->search_invoices_by_ref_client( $ref );
			if ( ! empty( $list ) ) {
				$invoice = $list[0];
			}
		}

		$ttl = max( 300, (int) DIWC_Settings::instance()->get( 'cache_ttl' ) );

		if ( null !== $invoice ) {
			set_transient( $cache_key, $invoice, $ttl );
		} else {
			// Cache négatif : évite de solliciter l'API à chaque affichage
			// tant que la facture n'existe pas encore.
			set_transient( $cache_key, array( 'id' => 0 ), $ttl );
		}

		return $invoice;
	}

	/**
	 * Champ « ID facture Dolibarr » sur la page admin de la commande.
	 *
	 * @param WC_Order $order Commande.
	 */
	public function render_admin_field( $order ) {
		// Notification du résultat d'envoi de facture (bouton admin).
		if ( isset( $_GET['diwc_sent'] ) && isset( $_GET['diwc_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sent = '1' === $_GET['diwc_sent'];
			$msg  = sanitize_text_field( wp_unslash( $_GET['diwc_msg'] ) );
			echo '<div class="notice notice-' . ( $sent ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Lien vers la fiche client Dolibarr — toujours affiché en haut de la
		// fiche commande (via la facture liée, ou par e-mail du client).
		$thirdparty = $this->thirdparty_for_order( $order );
		if ( null !== $thirdparty && ! empty( $thirdparty['id'] ) ) {
			echo '<p class="form-field form-field-wide diwc-admin-thirdparty">';
			echo '<strong>' . esc_html__( 'Compte client Dolibarr :', 'diwc' ) . '</strong> ';
			if ( '' !== (string) $thirdparty['name'] ) {
				echo esc_html( (string) $thirdparty['name'] ) . ' — ';
			}
			echo '<a href="' . esc_url( $this->thirdparty_card_url( (int) $thirdparty['id'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ouvrir la fiche client', 'diwc' ) . '</a>';
			echo '</p>';
		}

		$mode = (string) DIWC_Settings::instance()->get( 'match_mode' );

		if ( 'manual' === $mode ) {
			$value = (int) $order->get_meta( self::META_INVOICE_ID );
			?>
			<p class="form-field form-field-wide diwc-admin-field">
				<label for="diwc_invoice_id"><?php esc_html_e( 'ID facture Dolibarr', 'diwc' ); ?></label>
				<input type="number" id="diwc_invoice_id" name="diwc_invoice_id" value="<?php echo esc_attr( (string) $value ); ?>" min="1" />
				<span class="description"><?php esc_html_e( 'ID de la facture Dolibarr liée à cette commande (visible dans l\'URL de la facture : /facture/card.php?facid=ID).', 'diwc' ); ?></span>
			</p>
			<?php
		} else {
			?>
			<p class="form-field form-field-wide">
				<strong><?php esc_html_e( 'Facture Dolibarr', 'diwc' ); ?></strong> —
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s : numéro de commande WooCommerce. */
						__( 'correspondance automatique par « Réf. client » (numéro de commande : %s).', 'diwc' ),
						$order->get_order_number()
					)
				);
				?>
			</p>
			<?php
		}

		$invoice = $this->find_invoice( $order );
		if ( null !== $invoice ) {
			$this->render_admin_invoice_panel( $order, $invoice );
		} else {
			$this->render_admin_no_invoice( $order );
		}
	}

	/**
	 * Encart admin « Facture Dolibarr liée » : infos de la facture + boutons
	 * (PDF via le site, et ouverture dans Dolibarr pour vérification).
	 *
	 * @param WC_Order $order   Commande.
	 * @param array    $invoice Facture Dolibarr trouvée.
	 */
	private function render_admin_invoice_panel( $order, $invoice ) {
		$order_id = (int) $order->get_id();
		$inv_id   = (int) $invoice['id'];
		$ref      = isset( $invoice['ref'] ) ? (string) $invoice['ref'] : '';
		$date     = isset( $invoice['date'] ) ? (int) $invoice['date'] : 0;
		$total    = isset( $invoice['total_ttc'] ) ? (float) $invoice['total_ttc'] : 0.0;

		$dl_url   = $this->build_download_url( $order_id, $inv_id );
		$card_url = $this->card_url( $inv_id );

		$last_err = get_transient( 'diwc_dl_err_' . $order_id );
		if ( false !== $last_err && '' !== (string) $last_err ) {
			echo '<p class="diwc-dl-error">' . esc_html( sprintf( __( 'Dernière erreur de téléchargement : %s', 'diwc' ), (string) $last_err ) ) . '</p>';
		}

		echo '<div class="diwc-admin-invoice">';
		echo '<p><strong>' . esc_html__( 'Facture Dolibarr liée', 'diwc' ) . '</strong> — ' . esc_html( $ref );
		if ( $date > 0 ) {
			echo ' · ' . esc_html( date_i18n( get_option( 'date_format' ), $date ) );
		}
		if ( $total > 0 ) {
			echo ' · ' . wp_kses_post( wc_price( $total ) );
		}
		echo '</p>';
		$send_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'diwc_send_invoice',
					'order_id'   => $order_id,
					'invoice_id' => $inv_id,
				),
				admin_url( 'admin-post.php' )
			),
			'diwc_send_' . $order_id . '_' . $inv_id
		);

		echo '<p>';
		echo '<a class="button" href="' . esc_url( $dl_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Voir la facture (PDF via le site)', 'diwc' ) . '</a> ';
		echo '<a class="button button-secondary" href="' . esc_url( $card_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ouvrir dans Dolibarr', 'diwc' ) . '</a> ';
		echo '<a class="button button-secondary" href="' . esc_url( $send_url ) . '">' . esc_html__( 'Envoyer la facture par e-mail', 'diwc' ) . '</a>';
		echo '</p>';
		echo '<p class="diwc-admin-invoice-url"><code>' . esc_html( $card_url ) . '</code></p>';
		echo '</div>';
	}

	/**
	 * Encart admin quand aucune facture n'a été trouvée : diagnostic + bouton
	 * « Actualiser la recherche » (pour contourner le cache négatif).
	 *
	 * @param WC_Order $order Commande.
	 */
	private function render_admin_no_invoice( $order ) {
		$order_id = (int) $order->get_id();
		$url      = (string) DIWC_Settings::instance()->get( 'url' );
		$api_key  = (string) DIWC_Settings::instance()->get( 'api_key' );

		$refresh_url = wp_nonce_url(
			add_query_arg(
				array(
					'diwc_refresh' => '1',
					'order_id'     => $order_id,
				),
				remove_query_arg( array( 'diwc_refresh', '_wpnonce' ) )
			),
			'diwc_refresh_' . $order_id
		);

		echo '<div class="diwc-admin-invoice diwc-admin-missing">';

		if ( '' === $url || '' === $api_key ) {
			echo '<p>' . esc_html__( 'API Dolibarr non configurée : renseignez l\'URL et la clé API dans WooCommerce → Factures Dolibarr.', 'diwc' ) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s : numéro de commande WooCommerce. */
					__( 'Aucune facture Dolibarr trouvée pour le numéro de commande « %s » (recherche par réf. client).', 'diwc' ),
					$order->get_order_number()
				)
			) . '</p>';
			echo '<ul>';
			echo '<li>' . esc_html__( 'La facture doit avoir le statut Validée ou Payée (brouillon et annulée exclus).', 'diwc' ) . '</li>';
			echo '<li>' . esc_html__( 'Le champ « Réf. client » de la facture doit être strictement identique au numéro de commande (pas d\'espace, même casse).', 'diwc' ) . '</li>';
			echo '<li>' . esc_html__( 'Vérifiez la connexion API avec « Tester la connexion à Dolibarr » dans les réglages.', 'diwc' ) . '</li>';
			echo '</ul>';
		}

		// Si aucun compte n'a été trouvé (le lien du haut n'est pas affiché),
		// on explique pourquoi, pour aider au diagnostic.
		$thirdparty = $this->thirdparty_for_order( $order );
		if ( null === $thirdparty ) {
			$email = $order->get_billing_email();
			if ( $email && is_email( $email ) ) {
				echo '<p style="color:#8a6d3b;margin-bottom:8px;">' . esc_html(
					sprintf(
						/* translators: %s : e-mail du client. */
						__( 'Aucun compte client Dolibarr trouvé pour %s (e-mail). Vérifiez l\'e-mail du tiers dans Dolibarr et les droits de la clé API (détails dans le Journal).', 'diwc' ),
						$email
					)
				) . '</p>';
			}
		}

		echo '<p><a class="button button-secondary" href="' . esc_url( $refresh_url ) . '">' . esc_html__( 'Actualiser la recherche', 'diwc' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * URL de la fiche facture dans l'interface Dolibarr.
	 * Prend en compte le sous-dossier éventuel de l'interface (réglage
	 * « Chemin de l'interface »), distinct de celui de l'API.
	 *
	 * @param int    $invoice_id ID de la facture.
	 * @param string $hashp      Hash de partage (lien public), facultatif.
	 * @return string
	 */
	private function card_url( $invoice_id, $hashp = '' ) {
		$base = untrailingslashit( (string) DIWC_Settings::instance()->get( 'url' ) );
		$ui   = trim( (string) DIWC_Settings::instance()->get( 'ui_path' ), '/' );

		$path = ( '' !== $ui ? '/' . $ui : '' ) . '/facture/card.php?facid=' . (int) $invoice_id;
		if ( '' !== $hashp ) {
			$path .= '&hashp=' . rawurlencode( $hashp );
		}

		return $base . $path;
	}

	/**
	 * URL de la fiche du tiers (compte client) dans l'interface Dolibarr.
	 * NB : contrairement aux fiches factures, la fiche tiers est servie à la
	 * racine sur certaines installations (pas de sous-dossier d'interface).
	 *
	 * @param int $socid ID du tiers Dolibarr.
	 * @return string
	 */
	private function thirdparty_card_url( $socid ) {
		$base = untrailingslashit( (string) DIWC_Settings::instance()->get( 'url' ) );

		return $base . '/societe/card.php?socid=' . (int) $socid;
	}

	/**
	 * Retrouve le tiers Dolibarr (compte client) d'une commande : d'abord via
	 * la facture liée (socid), sinon par l'e-mail du client WooCommerce.
	 * Résultat mis en cache (transient).
	 *
	 * @param WC_Order $order Commande.
	 * @return array|null array( 'id', 'name', 'source' ) ou null.
	 */
	private function thirdparty_for_order( $order ) {
		$order_id  = (int) $order->get_id();
		$cache_key = 'diwc_thirdparty_' . $order_id;

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['id'] ) ) ? $cached : null;
		}

		$thirdparty = null;

		// 1) Via la facture liée.
		$invoice = $this->find_invoice( $order );
		if ( null !== $invoice ) {
			$socid = isset( $invoice['socid'] ) ? (int) $invoice['socid'] : 0;
			$name  = '';
			if ( isset( $invoice['thirdparty'] ) && is_array( $invoice['thirdparty'] ) && isset( $invoice['thirdparty']['name'] ) ) {
				$name = (string) $invoice['thirdparty']['name'];
			}
			if ( $socid > 0 ) {
				$thirdparty = array(
					'id'     => $socid,
					'name'   => $name,
					'source' => 'invoice',
				);
			}
		}

		// 2) Par e-mail du client WooCommerce.
		if ( null === $thirdparty ) {
			$email = $order->get_billing_email();
			if ( $email && is_email( $email ) ) {
				$tp = DIWC_API::instance()->search_thirdparty_by_email( $email );
				if ( $tp ) {
					$thirdparty = array(
						'id'     => (int) $tp['id'],
						'name'   => isset( $tp['name'] ) ? (string) $tp['name'] : '',
						'source' => 'email',
					);
				}
			}
		}

		$ttl = max( 300, (int) DIWC_Settings::instance()->get( 'cache_ttl' ) );
		if ( null !== $thirdparty ) {
			set_transient( $cache_key, $thirdparty, $ttl );
		} else {
			set_transient( $cache_key, array( 'id' => 0 ), $ttl );
		}

		return $thirdparty;
	}

	/**
	 * Recherche le tiers Dolibarr correspondant à un e-mail, avec cache
	 * (utilisé pour les clients sans commande : liste Utilisateurs, fiche
	 * utilisateur, etc.).
	 *
	 * @param string $email Adresse e-mail à chercher.
	 * @return array|null array( 'id', 'name' ) ou null.
	 */
	private function thirdparty_by_email_cached( $email ) {
		if ( ! $email || ! is_email( $email ) ) {
			return null;
		}

		$cache_key = 'diwc_thirdparty_email_' . md5( strtolower( $email ) );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return ( is_array( $cached ) && ! empty( $cached['id'] ) ) ? $cached : null;
		}

		$result = null;
		$tp     = DIWC_API::instance()->search_thirdparty_by_email( $email );
		if ( $tp ) {
			$result = array(
				'id'   => (int) $tp['id'],
				'name' => isset( $tp['name'] ) ? (string) $tp['name'] : '',
			);
		}

		$ttl = max( 300, (int) DIWC_Settings::instance()->get( 'cache_ttl' ) );
		set_transient( $cache_key, null !== $result ? $result : array( 'id' => 0 ), $ttl );

		return $result;
	}

	/**
	 * Ajoute la colonne « Compte Dolibarr » à la liste Utilisateurs (admin).
	 *
	 * @param array $columns Colonnes existantes.
	 * @return array
	 */
	public function add_users_column( $columns ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $columns;
		}
		$columns['diwc_thirdparty'] = __( 'Compte Dolibarr', 'diwc' );
		return $columns;
	}

	/**
	 * Affiche le lien fiche client dans la liste Utilisateurs.
	 *
	 * @param string $value      Valeur existante.
	 * @param string $column_name Nom de la colonne.
	 * @param int    $user_id    ID de l'utilisateur.
	 * @return string
	 */
	public function render_users_column( $value, $column_name, $user_id ) {
		if ( 'diwc_thirdparty' !== $column_name || ! current_user_can( 'manage_woocommerce' ) ) {
			return $value;
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return '—';
		}

		$tp = $this->thirdparty_by_email_cached( $user->user_email );
		if ( null === $tp ) {
			return '—';
		}

		return '<a href="' . esc_url( $this->thirdparty_card_url( $tp['id'] ) ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Fiche client', 'diwc' )
			. '</a>';
	}

	/**
	 * Encart « Compte client Dolibarr » sur la fiche utilisateur (admin).
	 *
	 * @param WP_User $user Utilisateur.
	 */
	public function render_user_profile_box( $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$email = isset( $user->user_email ) ? (string) $user->user_email : '';
		?>
		<h2><?php esc_html_e( 'Compte client Dolibarr', 'diwc' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label><?php esc_html_e( 'Fiche liée', 'diwc' ); ?></label></th>
				<td>
					<?php
					if ( $email && is_email( $email ) ) {
						$tp = $this->thirdparty_by_email_cached( $email );
						if ( null !== $tp ) {
							echo esc_html( '' !== $tp['name'] ? $tp['name'] : $email ) . ' — ';
							echo '<a href="' . esc_url( $this->thirdparty_card_url( $tp['id'] ) ) . '" target="_blank" rel="noopener">'
								. esc_html__( 'Ouvrir la fiche client', 'diwc' )
								. '</a>';
						} else {
							echo '<span style="color:#8a6d3b;">'
								. esc_html__(
									'Aucun compte Dolibarr trouvé pour cet e-mail. Vérifiez que l\'e-mail du tiers dans Dolibarr correspond exactement, et que la clé API a le droit de lecture des tiers (détails dans le Journal).',
									'diwc'
								)
								. '</span>';
						}
					} else {
						echo '<span style="color:#999;">' . esc_html__( 'Aucun e-mail sur ce compte.', 'diwc' ) . '</span>';
					}
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Construit l'URL de téléchargement proxy (admin-post + nonce).
	 *
	 * @param int $order_id   ID de la commande.
	 * @param int $invoice_id ID de la facture Dolibarr.
	 * @return string
	 */
	private function build_download_url( $order_id, $invoice_id ) {
		$nonce = wp_create_nonce( 'diwc_download_' . $order_id . '_' . $invoice_id );
		return add_query_arg(
			array(
				'action'     => 'diwc_download_invoice',
				'order_id'   => $order_id,
				'invoice_id' => $invoice_id,
				'_wpnonce'   => $nonce,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Écrit le contenu d'un PDF dans un fichier temporaire (nom propre) et
	 * retourne son chemin.
	 *
	 * @param string $pdf Contenu binaire du PDF.
	 * @param string $ref Référence de la facture (nom du fichier).
	 * @return string|WP_Error Chemin du fichier, ou erreur.
	 */
	private function write_pdf_temp( $pdf, $ref = '' ) {
		$upload = wp_upload_dir();
		$dir    = ( isset( $upload['path'] ) && is_dir( $upload['path'] ) && wp_is_writable( $upload['path'] ) )
			? $upload['path']
			: sys_get_temp_dir();

		$name = 'diwc-' . ( '' !== $ref ? sanitize_file_name( $ref ) : 'facture' ) . '.pdf';
		$file = trailingslashit( $dir ) . $name;

		$ok = file_put_contents( $file, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $ok ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'diwc_tmp', __( 'Impossible d\'écrire le PDF temporaire.', 'diwc' ) );
		}

		return $file;
	}

	/**
	 * Ajoute la facture Dolibarr en pièce jointe à l'e-mail
	 * « Commande terminée » (customer_completed_order), si une facture est liée.
	 *
	 * @param array    $attachments Pièces jointes existantes.
	 * @param string   $email_id    Identifiant de l'e-mail WooCommerce.
	 * @param WC_Order $order       Objet de l'e-mail.
	 * @return array
	 */
	public function attach_invoice_to_completed_email( $attachments, $email_id, $order ) {
		if ( 'customer_completed_order' !== $email_id || ! $order instanceof WC_Order ) {
			return $attachments;
		}

		$invoice = $this->find_invoice( $order );
		if ( null === $invoice ) {
			return $attachments;
		}

		$pdf = DIWC_API::instance()->download_invoice_pdf( (int) $invoice['id'] );
		if ( is_wp_error( $pdf ) ) {
			DIWC_Log::add( 'Pièce jointe facture (commande ' . (int) $order->get_id() . ') : ' . $pdf->get_error_message() );
			return $attachments;
		}

		$ref = isset( $invoice['ref'] ) ? (string) $invoice['ref'] : '';
		$tmp = $this->write_pdf_temp( $pdf, $ref );
		if ( is_wp_error( $tmp ) ) {
			return $attachments;
		}

		$this->email_attachments[] = $tmp;
		$attachments[]             = $tmp;

		return $attachments;
	}

	/**
	 * Supprime les fichiers temporaires attachés aux e-mails.
	 */
	public function cleanup_email_attachment() {
		foreach ( $this->email_attachments as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		$this->email_attachments = array();
	}

	/**
	 * Bouton « Envoyer la facture par e-mail » (action admin-post) : envoie
	 * le PDF en pièce jointe à l'adresse de facturation de la commande.
	 */
	public function handle_send_invoice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}

		$order_id   = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		$invoice_id = isset( $_GET['invoice_id'] ) ? (int) $_GET['invoice_id'] : 0;
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $order_id || ! $invoice_id || ! wp_verify_nonce( $nonce, 'diwc_send_' . $order_id . '_' . $invoice_id ) ) {
			wp_die( esc_html__( 'Lien invalide ou expiré.', 'diwc' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Commande introuvable.', 'diwc' ) );
		}

		$invoice = $this->find_invoice( $order );
		if ( null === $invoice || (int) $invoice['id'] !== $invoice_id ) {
			$msg = __( 'Facture introuvable pour cette commande.', 'diwc' );
			DIWC_Log::add( 'Envoi facture (commande ' . $order_id . ') : ' . $msg );
			$this->redirect_send_result( $order, false, $msg );
		}

		$pdf = DIWC_API::instance()->download_invoice_pdf( $invoice_id );
		if ( is_wp_error( $pdf ) ) {
			DIWC_Log::add( 'Envoi facture (commande ' . $order_id . ') : ' . $pdf->get_error_message() );
			$this->redirect_send_result( $order, false, $pdf->get_error_message() );
		}

		$ref = isset( $invoice['ref'] ) ? (string) $invoice['ref'] : ( 'facture-' . $invoice_id );
		$tmp = $this->write_pdf_temp( $pdf, $ref );
		if ( is_wp_error( $tmp ) ) {
			$this->redirect_send_result( $order, false, $tmp->get_error_message() );
		}

		$to      = $order->get_billing_email();
		$subject = sprintf( __( 'Votre facture %s', 'diwc' ), $ref );
		$message = sprintf(
			/* translators: 1 : référence facture, 2 : nom du site. */
			__( "Bonjour,\n\nVeuillez trouver en pièce jointe votre facture %1\$s.\n\nMerci pour votre confiance.\n%2\$s", 'diwc' ),
			$ref,
			get_bloginfo( 'name' )
		);
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$sent = wp_mail( $to, $subject, $message, $headers, array( $tmp ) );

		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( $sent ) {
			DIWC_Log::add( 'Facture ' . $ref . ' envoyée par e-mail à ' . $to . ' (commande ' . $order_id . ')' );
			$this->redirect_send_result( $order, true, __( 'Facture envoyée par e-mail.', 'diwc' ) );
		}

		DIWC_Log::add( 'Échec envoi e-mail facture ' . $ref . ' (commande ' . $order_id . ')' );
		$this->redirect_send_result( $order, false, __( 'Échec de l\'envoi de l\'e-mail.', 'diwc' ) );
	}

	/**
	 * Redirige vers la fiche commande avec le résultat de l'envoi.
	 *
	 * @param WC_Order $order Commande.
	 * @param bool     $ok    Succès ?
	 * @param string   $msg   Message.
	 */
	private function redirect_send_result( $order, $ok, $msg ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'diwc_sent' => $ok ? '1' : '0',
					'diwc_msg'  => rawurlencode( $msg ),
				),
				$order->get_edit_order_url()
			)
		);
		exit;
	}

	/**
	 * Enregistre le champ admin. Compatible HPOS et legacy.
	 *
	 * @param int            $order_id ID de la commande.
	 * @param WC_Order|mixed $object   Commande ou post (selon le hook).
	 */
	public function save_admin_field( $order_id, $object = null ) {
		if ( self::$saving ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			return;
		}
		if ( ! isset( $_POST['diwc_invoice_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$value = max( 0, (int) $_POST['diwc_invoice_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput

		self::$saving = true;
		$order->update_meta_data( self::META_INVOICE_ID, $value );
		$order->save();
		self::$saving = false;

		delete_transient( 'diwc_invoice_' . (int) $order_id );
	}

	/**
	 * Actualisation forcée de la recherche (supprime le cache de la commande),
	 * déclenchée par le bouton « Actualiser la recherche » de la page de commande.
	 */
	public function maybe_force_refresh() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! isset( $_GET['diwc_refresh'], $_GET['order_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$order_id = (int) $_GET['order_id']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'diwc_refresh_' . $order_id ) ) {
			return;
		}

		delete_transient( 'diwc_invoice_' . $order_id );
		delete_transient( 'diwc_dl_err_' . $order_id );
		delete_transient( 'diwc_thirdparty_' . $order_id );

		// Purge aussi le cache de recherche par e-mail de cette commande.
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$email = $order->get_billing_email();
			if ( $email && is_email( $email ) ) {
				delete_transient( 'diwc_thirdparty_email_' . md5( strtolower( $email ) ) );
			}
		}

		wp_safe_redirect( remove_query_arg( array( 'diwc_refresh', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Gère le téléchargement / la visualisation du PDF via proxy.
	 */
	public function handle_download() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Vous devez être connecté.', 'diwc' ) );
		}

		$order_id   = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		$invoice_id = isset( $_GET['invoice_id'] ) ? (int) $_GET['invoice_id'] : 0;
		$nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $order_id || ! $invoice_id || ! wp_verify_nonce( $nonce, 'diwc_download_' . $order_id . '_' . $invoice_id ) ) {
			wp_die( esc_html__( 'Lien invalide ou expiré.', 'diwc' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Commande introuvable.', 'diwc' ), '', array( 'response' => 404 ) );
		}

		$is_owner  = (int) $order->get_user_id() === (int) get_current_user_id();
		$is_editor = current_user_can( 'edit_shop_order', $order_id );
		if ( ! $is_owner && ! $is_editor ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ), '', array( 'response' => 403 ) );
		}

		$found = $this->find_invoice( $order );
		if ( null === $found || (int) $found['id'] !== $invoice_id ) {
			wp_die( esc_html__( 'Facture introuvable pour cette commande.', 'diwc' ), '', array( 'response' => 404 ) );
		}

		$pdf = DIWC_API::instance()->download_invoice_pdf( $invoice_id );
		if ( is_wp_error( $pdf ) ) {
			DIWC_Log::add( 'Téléchargement facture ' . $invoice_id . ' (commande ' . $order_id . ') : ' . $pdf->get_error_message() );
			set_transient( 'diwc_dl_err_' . $order_id, $pdf->get_error_message(), 3600 );
			$this->redirect_download_error( $order );
		}

		delete_transient( 'diwc_dl_err_' . $order_id );

		// Vide les tampons de sortie éventuels avant d'envoyer les en-têtes,
		// et prolonge le temps d'exécution pour les PDF volumineux.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 );
		}

		$ref      = isset( $found['ref'] ) ? sanitize_file_name( (string) $found['ref'] ) : '';
		$filename = ( '' !== $ref ? $ref : 'facture-' . $invoice_id ) . '.pdf';

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) strlen( $pdf ) );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Accel-Buffering: no' );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- Corps binaire du PDF.

		exit;
	}

	/**
	 * Redirige vers la page de commande (client) ou l'édition admin avec une
	 * notification, plutôt qu'une page d'erreur 502 opaque.
	 *
	 * @param WC_Order $order Commande.
	 */
	private function redirect_download_error( $order ) {
		$order_id = (int) $order->get_id();
		if ( current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_safe_redirect( add_query_arg( 'diwc_dlerr', '1', $order->get_edit_order_url() ) );
		} else {
			wp_safe_redirect( add_query_arg( 'diwc_dlerr', '1', $order->get_view_order_url() ) );
		}
		exit;
	}

	/**
	 * Charge les styles sur les pages du compte client.
	 */
	public function enqueue_assets() {
		if ( ! is_account_page() ) {
			return;
		}
		wp_enqueue_style( 'diwc', DIWC_URL . 'assets/diwc.css', array(), DIWC_VERSION );
	}
}
