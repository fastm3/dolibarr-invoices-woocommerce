<?php
/**
 * Comparaison des coordonnées de facturation WooCommerce ↔ tiers Dolibarr,
 * avec mise à jour sélective des champs (un par un) dans Dolibarr.
 *
 * @package DIWC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encart admin « Coordonnées de facturation » sur la fiche commande : compare
 * les informations du client (WooCommerce) avec celles du tiers Dolibarr,
 * signale les différences et permet de mettre à jour, champ par champ, le
 * tiers Dolibarr via PUT /thirdparties/{id}.
 */
class DIWC_Thirdparty {

	/**
	 * Instance unique.
	 *
	 * @var DIWC_Thirdparty|null
	 */
	private static $instance = null;

	/**
	 * Retourne l'instance unique.
	 *
	 * @return DIWC_Thirdparty
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
		// Encart sur la fiche commande (admin), après les encarts facture (10)
		// et commande brouillon (20).
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_admin_box' ), 30 );

		// Adresse de LIVRAISON : appliquée d'un seul coup (commande + facture
		// Dolibarr), à la demande.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_shipping_box' ), 40 );

		add_action( 'admin_post_diwc_thirdparty_update', array( $this, 'handle_update' ) );
		add_action( 'admin_post_diwc_thirdparty_create', array( $this, 'handle_create_thirdparty' ) );
		add_action( 'admin_post_diwc_contact_create', array( $this, 'handle_contact_create' ) );
		add_action( 'admin_post_diwc_contact_update', array( $this, 'handle_contact_update' ) );
		add_action( 'admin_post_diwc_shipping_update', array( $this, 'handle_shipping_update' ) );
	}

	/**
	 * Champs comparés (libellé affiché). Aucun n'est pré-sélectionné : la mise
	 * à jour est toujours une action explicite de l'administrateur.
	 *
	 * @return array
	 */
	private function fields_map() {
		return array(
			'name'      => array( 'label' => __( 'Raison sociale / nom', 'diwc' ) ),
			'firstname' => array( 'label' => __( 'Prénom', 'diwc' ) ),
			'lastname'  => array( 'label' => __( 'Nom', 'diwc' ) ),
			'email'     => array( 'label' => __( 'E-mail', 'diwc' ) ),
			'phone'     => array( 'label' => __( 'Téléphone', 'diwc' ) ),
			'address'   => array( 'label' => __( 'Adresse', 'diwc' ) ),
			'zip'       => array( 'label' => __( 'Code postal', 'diwc' ) ),
			'town'      => array( 'label' => __( 'Ville', 'diwc' ) ),
			'country'   => array( 'label' => __( 'Pays', 'diwc' ) ),
		);
	}

	/**
	 * Affiche l'encart de comparaison sur la fiche commande (admin).
	 *
	 * @param WC_Order $order Commande.
	 */
	public function render_admin_box( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order_id = (int) $order->get_id();
		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			return;
		}

		// Notification du résultat de la mise à jour.
		if ( isset( $_GET['diwc_tp_ok'], $_GET['diwc_tp_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ok  = '1' === $_GET['diwc_tp_ok'];
			$msg = sanitize_text_field( wp_unslash( $_GET['diwc_tp_msg'] ) );
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		// Script de collecte des cases cochées (les <form> imbriqués ne
		// fonctionnent pas dans la page de commande WordPress).
		$this->print_submit_script();

		$settings = DIWC_Settings::instance();
		$url      = (string) $settings->get( 'url' );
		$key      = (string) $settings->get( 'api_key' );

		echo '<div class="diwc-admin-invoice">';
		echo '<p><strong>' . esc_html__( 'Coordonnées de facturation (WooCommerce ↔ Dolibarr)', 'diwc' ) . '</strong></p>';

		if ( '' === $url || '' === $key ) {
			echo '<p>' . esc_html__( 'API Dolibarr non configurée : renseignez l\'URL et la clé API dans WooCommerce → Factures Dolibarr.', 'diwc' ) . '</p>';
			echo '</div>';
			return;
		}

		$thirdparty = $this->find_thirdparty( $order );
		if ( null === $thirdparty ) {
			$this->render_create_proposal( $order );
			echo '</div>';
			return;
		}

		$live = DIWC_API::instance()->get_thirdparty( (int) $thirdparty['id'] );
		if ( null === $live ) {
			echo '<p>' . esc_html( __( 'Fiche client Dolibarr illisible (droits de lecture du tiers ? connexion API ?).', 'diwc' ) ) . '</p>';
			echo '</div>';
			return;
		}

		$woo      = $this->woo_values( $order );
		$fields   = $this->fields_map();
		$dolibarr = $this->dolibarr_values( $live );

		// Prénom / Nom : Dolibarr ne gère ces champs que pour un tiers de type
		// « Particulier ». On ne les compare donc que si la commande est un
		// particulier ET que le tiers est de ce type (ou porte déjà ces valeurs) ;
		// sinon ils sont proposés sous forme de CONTACT (encart ci-dessous).
		$individual   = $this->thirdparty_is_individual( $live );
		$names_filled = ( '' !== trim( (string) ( isset( $live['firstname'] ) ? $live['firstname'] : '' ) )
			|| '' !== trim( (string) ( isset( $live['lastname'] ) ? $live['lastname'] : '' ) ) );
		$has_person   = ( '' !== trim( (string) $woo['firstname'] ) || '' !== trim( (string) $woo['lastname'] ) );

		$compare_names = ( empty( $woo['is_company'] ) && ( $individual || $names_filled ) );

		if ( ! $compare_names ) {
			unset( $fields['firstname'], $fields['lastname'] );
		}

		$diffs = array();
		foreach ( $fields as $key_name => $def ) {
			$woo_val = isset( $woo[ $key_name ] ) ? (string) $woo[ $key_name ] : '';
			$dol_val = isset( $dolibarr[ $key_name ] ) ? (string) $dolibarr[ $key_name ] : '';

			// Pour la raison sociale, l'ordre des mots n'a pas d'importance
			// (« DUPONT Jean » = « Jean DUPONT »).
			$diffs[ $key_name ] = ( 'name' === $key_name )
				? ! $this->same_name( $woo_val, $dol_val )
				: ( $this->normalize( $woo_val ) !== $this->normalize( $dol_val ) );
		}

		$nb_diff = count( array_filter( $diffs ) );

		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1 : nom du tiers Dolibarr, 2 : nombre de différences. */
				__( 'Tiers Dolibarr : %1$s — %2$d différence(s) détectée(s).', 'diwc' ),
				( isset( $live['name'] ) && '' !== (string) $live['name'] ) ? (string) $live['name'] : (string) $thirdparty['id'],
				$nb_diff
			)
		) . '</p>';

		if ( ! $compare_names && $has_person ) {
			echo '<p class="description">' . esc_html__(
				'Prénom et Nom ne sont pas comparés sur la fiche du tiers : celui-ci n\'est pas de type « Particulier » (Dolibarr ne gère pas ces champs pour une société). Ils sont proposés plus bas sous forme de contact rattaché au tiers.',
				'diwc'
			) . '</p>';
		}

		if ( 0 === $nb_diff ) {
			echo '<p class="description">' . esc_html__( 'Les coordonnées de la commande et celles du tiers Dolibarr sont identiques.', 'diwc' ) . '</p>';
			$this->render_contact_box( $order, $thirdparty, $woo, ! $compare_names );
			echo '</div>';
			return;
		}

		// NB : pas de <form> ici — les encarts sont affichés DANS le formulaire
		// WordPress de la commande, et un <form> imbriqué est ignoré par le
		// navigateur (le clic partait dans le formulaire parent). On utilise donc
		// un lien admin-post + nonce, avec collecte JS des cases cochées.
		$box_id = 'diwc-tp-fields-' . $order_id;
		$action = $this->action_url( 'diwc_thirdparty_update', $order_id, 'diwc_thirdparty_' . $order_id );

		echo '<div id="' . esc_attr( $box_id ) . '">';

		echo '<table class="widefat striped" style="max-width:900px;">';
		echo '<thead><tr>';
		echo '<th style="width:26px;"><input type="checkbox" onclick="var c=this.checked, f=document.getElementById(\'' . esc_attr( $box_id ) . '\').getElementsByTagName(\'input\');for(var i=0;i<f.length;i++){if(f[i].type===\'checkbox\'){f[i].checked=c;}}" /></th>';
		echo '<th>' . esc_html__( 'Champ', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'WooCommerce (à appliquer)', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'Dolibarr (actuel)', 'diwc' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $fields as $key_name => $def ) {
			$woo_val   = isset( $woo[ $key_name ] ) ? (string) $woo[ $key_name ] : '';
			$dol_val   = isset( $dolibarr[ $key_name ] ) ? (string) $dolibarr[ $key_name ] : '';
			$different = $diffs[ $key_name ];

			echo '<tr' . ( $different ? ' style="background:#fff8e5;"' : '' ) . '>';
			echo '<td>';
			if ( $different ) {
				// Aucune case n'est pré-cochée : la mise à jour se fait
				// uniquement sur sélection explicite de l'administrateur.
				echo '<input type="checkbox" name="diwc_fields[]" value="' . esc_attr( $key_name ) . '" />';
			}
			echo '</td>';
			echo '<td><strong>' . esc_html( $def['label'] ) . '</strong>';
			if ( $different ) {
				echo '<br /><span class="description">' . esc_html__( 'différent', 'diwc' ) . '</span>';
			} else {
				echo '<br /><span class="description">' . esc_html__( 'identique', 'diwc' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . ( '' !== $woo_val ? nl2br( esc_html( $woo_val ) ) : '<span class="description">—</span>' ) . '</td>';
			echo '<td>' . ( '' !== $dol_val ? nl2br( esc_html( $dol_val ) ) : '<span class="description">—</span>' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<p><a class="button button-primary" href="' . esc_url( $action ) . '" onclick="return diwcSubmitChecked(\'' . esc_js( $box_id ) . '\', this, \'diwc_fields[]\');">'
			. esc_html__( 'Mettre à jour dans Dolibarr les champs cochés', 'diwc' ) . '</a></p>';
		echo '<p class="description">' . esc_html__(
			'Aucun champ n\'est coché par défaut : cochez uniquement ceux à mettre à jour, puis validez. Seuls les champs cochés sont envoyés à Dolibarr (PUT /thirdparties). Attention : cocher un champ vide côté WooCommerce effacera la valeur correspondante dans Dolibarr.',
			'diwc'
		) . '</p>';
		echo '</div>';

		$this->render_contact_box( $order, $thirdparty, $woo, ! $compare_names );
		echo '</div>';
	}

	/**
	 * Aucun tiers Dolibarr ne correspond au client : propose la création
	 * complète d'un nouveau tiers à partir des informations de la commande
	 * (toujours sur clic explicite).
	 *
	 * @param WC_Order $order Commande.
	 */
	private function render_create_proposal( $order ) {
		$order_id = (int) $order->get_id();
		$values   = $this->woo_values( $order );
		$labels   = array(
			'name'      => __( 'Raison sociale / nom', 'diwc' ),
			'firstname' => __( 'Prénom', 'diwc' ),
			'lastname'  => __( 'Nom', 'diwc' ),
			'email'     => __( 'E-mail', 'diwc' ),
			'phone'     => __( 'Téléphone', 'diwc' ),
			'address'   => __( 'Adresse', 'diwc' ),
			'zip'       => __( 'Code postal', 'diwc' ),
			'town'      => __( 'Ville', 'diwc' ),
			'country'   => __( 'Pays', 'diwc' ),
		);

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s : e-mail de facturation du client. */
				__( 'Aucun compte client Dolibarr ne correspond à %s. Vous pouvez créer le tiers à partir des informations de la commande :', 'diwc' ),
				(string) $order->get_billing_email()
			)
		) . '</p>';

		echo '<table class="widefat striped" style="max-width:900px;"><tbody>';
		foreach ( $labels as $key_name => $label ) {
			$value = isset( $values[ $key_name ] ) ? (string) $values[ $key_name ] : '';
			echo '<tr>';
			echo '<td style="width:220px;"><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td>' . ( '' !== $value ? nl2br( esc_html( $value ) ) : '<span class="description">—</span>' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( '' === trim( (string) ( isset( $values['name'] ) ? $values['name'] : '' ) ) ) {
			echo '<p>' . esc_html__( 'Création impossible : la commande n\'a ni raison sociale ni nom de client.', 'diwc' ) . '</p>';
			return;
		}

		$needs_delivery = $this->has_distinct_shipping_address( $order );

		echo '<p><a class="button button-primary" href="' . esc_url( $this->action_url( 'diwc_thirdparty_create', $order_id, 'diwc_thirdparty_create_' . $order_id ) ) . '">'
			. esc_html__( 'Créer ce tiers dans Dolibarr', 'diwc' ) . '</a></p>';
		echo '<p class="description">' . esc_html__(
			'Le tiers est créé comme client (code client généré automatiquement) avec ces coordonnées. Aucune création n\'a lieu sans ce clic.',
			'diwc'
		);
		if ( $needs_delivery ) {
			echo ' ' . esc_html__( 'L\'adresse de livraison étant différente de l\'adresse de facturation, un contact « Livraison » (avec cette adresse) sera créé en même temps et rattaché au tiers.', 'diwc' );
		}
		echo '</p>';
	}

	/**
	 * Crée le tiers Dolibarr à partir de la commande (action admin-post).
	 */
	public function handle_create_thirdparty() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}

		$order_id = (int) $this->request_param( 'order_id', 0 );
		if ( ! $order_id ) {
			$this->redirect( 0, false, __( 'Paramètres manquants.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_thirdparty_create_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->redirect( $order_id, false, __( 'Commande introuvable.', 'diwc' ) );
		}

		$values = $this->woo_values( $order );

		$fields = array(
			'name'    => trim( (string) $values['name'] ),
			'client'  => 1, // Compte client.
			'code_client' => -1, // Code client généré par Dolibarr.
		);

		foreach ( array( 'firstname', 'lastname', 'email', 'phone', 'address', 'zip', 'town' ) as $key_name ) {
			$value = isset( $values[ $key_name ] ) ? trim( (string) $values[ $key_name ] ) : '';
			if ( '' !== $value ) {
				$fields[ $key_name ] = $value;
			}
		}

		if ( '' === $fields['name'] ) {
			$this->redirect( $order_id, false, __( 'Impossible de créer le tiers : aucune raison sociale ni nom sur la commande.', 'diwc' ) );
		}

		$code = strtoupper( trim( (string) $order->get_billing_country() ) );
		if ( '' !== $code ) {
			$cid = DIWC_API::instance()->country_id_from_code( $code );
			if ( $cid > 0 ) {
				$fields['country_id'] = $cid;
			}
		}

		// Client particulier (aucune société) : Dolibarr ne prend en compte
		// prénom/nom que si le tiers est de type « Particulier ».
		if ( '' === trim( (string) $order->get_billing_company() ) ) {
			$typent = DIWC_API::instance()->individual_typent_id();
			if ( $typent > 0 ) {
				$fields['typent_id'] = $typent;
				DIWC_Log::add( 'Création tiers : type de tiers « Particulier » appliqué (typent_id ' . $typent . ').' );
			} else {
				DIWC_Log::add( 'Création tiers : type « Particulier » introuvable dans le dictionnaire Dolibarr — prénom/nom ne pourront pas être portés par la fiche (ils iront dans un contact).' );
			}
		}

		$created = DIWC_API::instance()->create_thirdparty( $fields );
		if ( is_wp_error( $created ) ) {
			$this->redirect( $order_id, false, __( 'Échec de la création du tiers : ', 'diwc' ) . $created->get_error_message() );
		}

		$created_id = (int) $created['id'];

		// Certaines versions ignorent prénom/nom à la création : on les écrit
		// ensuite (PUT) si le tiers ne les porte pas.
		$missing = array();
		$live    = DIWC_API::instance()->get_thirdparty( $created_id );

		if ( is_array( $live ) ) {
			foreach ( array( 'firstname', 'lastname' ) as $key_name ) {
				if ( empty( $fields[ $key_name ] ) ) {
					continue;
				}
				if ( ! isset( $live[ $key_name ] ) || '' === trim( (string) $live[ $key_name ] ) ) {
					$missing[ $key_name ] = (string) $fields[ $key_name ];
				}
			}
		}

		if ( ! empty( $missing ) ) {
			$fixed    = DIWC_API::instance()->update_thirdparty( $created_id, $missing );
			$names_ok = false;

			if ( is_wp_error( $fixed ) ) {
				DIWC_Log::add( 'Complément prénom/nom du tiers ' . $created_id . ' impossible : ' . $fixed->get_error_message() );
			} else {
				// Contrôle réel : les champs ont-ils bien été écrits ?
				$check       = DIWC_API::instance()->get_thirdparty( $created_id );
				$still_empty = array();

				if ( is_array( $check ) ) {
					foreach ( array_keys( $missing ) as $key_name ) {
						if ( ! isset( $check[ $key_name ] ) || '' === trim( (string) $check[ $key_name ] ) ) {
							$still_empty[] = $key_name;
						}
					}
				}

				if ( empty( $still_empty ) ) {
					$names_ok = true;
					DIWC_Log::add( 'Complément prénom/nom écrit sur le tiers ' . $created_id . ' (' . implode( ', ', array_keys( $missing ) ) . ')' );
				} else {
					DIWC_Log::add(
						'Prénom/nom refusés par l\'API Dolibarr pour le tiers ' . $created_id . ' (' . implode( ', ', $still_empty )
						. ') — ces informations sont à porter par un contact rattaché (encart « Contact »).'
					);
				}
			}
		}

		$this->clear_cache( $order );

		$msg = sprintf(
			/* translators: 1 : nom du tiers créé, 2 : ID du tiers. */
			__( 'Tiers Dolibarr créé : %1$s (id %2$d). Rechargez la page : les encarts de comparaison et de création de commande l\'utiliseront.', 'diwc' ),
			( isset( $created['name'] ) && '' !== (string) $created['name'] ) ? (string) $created['name'] : $fields['name'],
			$created_id
		);

		if ( ! empty( $missing ) && ! empty( $names_ok ) ) {
			$msg .= ' ' . __( 'Prénom/nom complétés sur la fiche du tiers.', 'diwc' );
		} elseif ( ! empty( $missing ) ) {
			$msg .= ' ' . __( 'Prénom/nom non acceptés par l\'API Dolibarr pour une fiche de tiers : ils sont proposés dans l\'encart « Contact » (rattaché au tiers).', 'diwc' );
		}

		// Adresse de livraison différente de la facturation : on crée dans la
		// foulée un contact « Livraison » portant cette adresse, rattaché au tiers
		// (c'est ce contact que Dolibarr utilise comme adresse de livraison).
		if ( $this->has_distinct_shipping_address( $order ) ) {
			$created_contact = $this->create_delivery_contact( $order, $created_id );

			$msg .= ' ' . ( is_wp_error( $created_contact )
				? sprintf(
					/* translators: %s : message d'erreur Dolibarr. */
					__( 'Attention : le contact de livraison n\'a pas pu être créé (%s).', 'diwc' ),
					$created_contact->get_error_message()
				)
				: sprintf(
					/* translators: %s : nom du contact de livraison créé. */
					__( 'Contact de livraison créé : %s (avec l\'adresse de livraison).', 'diwc' ),
					( isset( $created_contact['firstname'] ) ? (string) $created_contact['firstname'] . ' ' : '' )
					. ( isset( $created_contact['lastname'] ) ? (string) $created_contact['lastname'] : '' )
				) );
		}

		$this->redirect( $order_id, true, $msg );
	}

	/**
	 * Champs téléphone d'un CONTACT Dolibarr : l'API attend « phone_pro » ou
	 * « phone_mobile » (le champ « phone » n'existe pas pour les contacts).
	 *
	 * @param string $phone Numéro de téléphone.
	 * @return array Tableau de champs à fusionner dans le contact.
	 */
	private function contact_phone_fields( $phone ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return array();
		}

		$digits    = preg_replace( '/[^0-9+]/', '', $phone );
		$is_mobile = (bool) preg_match( '/(^(\+|00)?33?|^0)[67]/', (string) $digits );

		return $is_mobile ? array( 'phone_mobile' => $phone ) : array( 'phone_pro' => $phone );
	}

	/**
	 * Téléphone actuel d'un contact Dolibarr, quel que soit le champ utilisé
	 * par la version (« phone_pro », « phone_mobile », « phone », « phone_perso »).
	 *
	 * @param array $contact Contact retourné par l'API.
	 * @return string
	 */
	private function contact_phone_value( $contact ) {
		foreach ( array( 'phone_pro', 'phone_mobile', 'phone', 'phone_perso' ) as $key ) {
			if ( isset( $contact[ $key ] ) && '' !== trim( (string) $contact[ $key ] ) ) {
				return (string) $contact[ $key ];
			}
		}
		return '';
	}

	/**
	 * Crée un contact « Livraison » rattaché au tiers, portant l'adresse de
	 * livraison de la commande (utilisé quand elle diffère de la facturation).
	 *
	 * @param WC_Order $order      Commande.
	 * @param int      $socid      ID du tiers Dolibarr.
	 * @return array|WP_Error Contact créé, ou erreur.
	 */
	private function create_delivery_contact( $order, $socid ) {
		$shipping = $this->woo_shipping_values( $order );

		$first = trim( (string) $order->get_shipping_first_name() );
		$last  = trim( (string) $order->get_shipping_last_name() );

		if ( '' === $first && '' === $last ) {
			$last = __( 'Livraison', 'diwc' );
		}

		$contact = array(
			'socid'    => (int) $socid,
			'lastname' => ( '' !== $last ) ? $last : $first,
		);
		if ( '' !== $first ) {
			$contact['firstname'] = $first;
		}

		$phone = method_exists( $order, 'get_shipping_phone' ) ? trim( (string) $order->get_shipping_phone() ) : '';
		if ( '' === $phone ) {
			$phone = trim( (string) $order->get_billing_phone() );
		}
		$contact = array_merge( $contact, $this->contact_phone_fields( $phone ) );

		if ( '' !== (string) $shipping['address'] ) {
			$contact['address'] = (string) $shipping['address'];
		}
		if ( '' !== (string) $shipping['zip'] ) {
			$contact['zip'] = (string) $shipping['zip'];
		}
		if ( '' !== (string) $shipping['town'] ) {
			$contact['town'] = (string) $shipping['town'];
		}
		if ( '' !== (string) $shipping['country'] ) {
			$cid = DIWC_API::instance()->country_id_from_code( (string) $shipping['country'] );
			if ( $cid > 0 ) {
				$contact['country_id'] = $cid;
			}
		}

		$email = trim( (string) $order->get_billing_email() );
		if ( '' !== $email && is_email( $email ) ) {
			$contact['email'] = $email;
		}

		$created = DIWC_API::instance()->create_contact( $contact );

		// Mémorise le contact de livraison : il sera associé à la commande
		// Dolibarr (fk_delivery_address) lors de la création du brouillon.
		if ( is_array( $created ) && ! empty( $created['id'] ) ) {
			$order->update_meta_data( DIWC_Order_Create::META_DELIVERY_CONTACT, (int) $created['id'] );
			$order->save();
		}

		return $created;
	}

	/**
	 * Gère la mise à jour sélective du tiers Dolibarr (action admin-post).
	 */
	public function handle_update() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}

		$order_id = (int) $this->request_param( 'order_id', 0 );
		if ( ! $order_id ) {
			$this->redirect( 0, false, __( 'Paramètres manquants.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_thirdparty_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->redirect( $order_id, false, __( 'Commande introuvable.', 'diwc' ) );
		}

		$selected = array();
		$raw      = $this->request_param( 'diwc_fields', array() );
		if ( is_array( $raw ) ) {
			foreach ( array_map( 'sanitize_key', $raw ) as $field_key ) {
				if ( array_key_exists( $field_key, $this->fields_map() ) ) {
					$selected[] = $field_key;
				}
			}
		}

		// Client « société » : prénom/nom ne s'écrivent pas sur le tiers
		// (ils relèvent du contact Dolibarr) — même en cas de requête forgée.
		if ( '' !== trim( (string) $order->get_billing_company() ) ) {
			$selected = array_values( array_diff( $selected, array( 'firstname', 'lastname' ) ) );
		}

		if ( empty( $selected ) ) {
			$this->redirect( $order_id, false, __( 'Aucun champ sélectionné : rien n\'a été modifié.', 'diwc' ) );
		}

		$thirdparty = $this->find_thirdparty( $order );
		if ( null === $thirdparty ) {
			$this->redirect(
				$order_id,
				false,
				sprintf(
					/* translators: %s : e-mail de facturation du client. */
					__( 'Aucun compte client Dolibarr trouvé pour %s.', 'diwc' ),
					(string) $order->get_billing_email()
				)
			);
		}

		$woo     = $this->woo_values( $order );
		$fields  = $this->fields_map();
		$payload = array();
		$labels  = array();

		foreach ( $selected as $field_key ) {
			if ( 'country' === $field_key ) {
				$code = (string) $order->get_billing_country();
				$cid  = DIWC_API::instance()->country_id_from_code( $code );
				if ( $cid <= 0 ) {
					$this->redirect(
						$order_id,
						false,
						sprintf(
							/* translators: %s : code pays. */
							__( 'Pays « %s » introuvable dans le dictionnaire Dolibarr : mise à jour annulée (décochez le champ Pays pour continuer).', 'diwc' ),
							$code
						)
					);
				}
				$payload['country_id'] = $cid;
				$labels[]              = $fields[ $field_key ]['label'];
				continue;
			}

			$payload[ $field_key ] = isset( $woo[ $field_key ] ) ? (string) $woo[ $field_key ] : '';
			$labels[]              = $fields[ $field_key ]['label'];
		}

		$res = DIWC_API::instance()->update_thirdparty( (int) $thirdparty['id'], $payload );
		if ( is_wp_error( $res ) ) {
			$this->redirect( $order_id, false, __( 'Échec de la mise à jour : ', 'diwc' ) . $res->get_error_message() );
		}

		// Purge des caches locaux du plugin pour ce client.
		$this->clear_cache( $order );

		DIWC_Log::add(
			'Coordonnées mises à jour dans Dolibarr (tiers ' . (int) $thirdparty['id'] . ', commande WooCommerce #'
			. $order->get_order_number() . ') : ' . implode( ', ', $labels )
		);

		$this->redirect(
			$order_id,
			true,
			sprintf(
				/* translators: %s : liste des champs mis à jour. */
				__( 'Champs mis à jour dans Dolibarr : %s.', 'diwc' ),
				implode( ', ', $labels )
			)
		);
	}

	/**
	 * Affiche l'encart « Adresse de livraison » : compare l'adresse de
	 * livraison WooCommerce avec celle portée par la commande et la facture
	 * Dolibarr, et propose de l'appliquer EN UNE FOIS (à la demande).
	 *
	 * @param WC_Order $order Commande.
	 */
	public function render_shipping_box( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order_id = (int) $order->get_id();
		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			return;
		}

		if ( isset( $_GET['diwc_ship_ok'], $_GET['diwc_ship_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ok  = '1' === $_GET['diwc_ship_ok'];
			$msg = sanitize_text_field( wp_unslash( $_GET['diwc_ship_msg'] ) );
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		$settings = DIWC_Settings::instance();
		if ( '' === (string) $settings->get( 'url' ) || '' === (string) $settings->get( 'api_key' ) ) {
			return; // Déjà signalé dans l'encart des coordonnées.
		}

		$woo     = $this->woo_shipping_values( $order );
		$billing = $this->woo_billing_address_values( $order );

		echo '<div class="diwc-admin-invoice">';
		echo '<p><strong>' . esc_html__( 'Adresse de livraison (WooCommerce → Dolibarr)', 'diwc' ) . '</strong></p>';

		// Adresse de livraison identique à l'adresse de facturation : Dolibarr
		// utilise par défaut l'adresse du compte client — rien à proposer.
		if ( $this->normalize( $this->format_address( $woo ) ) === $this->normalize( $this->format_address( $billing ) ) ) {
			echo '<p class="description">' . esc_html__(
				'L\'adresse de livraison est identique à l\'adresse de facturation : Dolibarr utilise par défaut l\'adresse du compte client. Aucune mise à jour n\'est nécessaire.',
				'diwc'
			) . '</p>';
			echo '</div>';
			return;
		}

		$targets = $this->dolibarr_targets( $order );

		if ( empty( $targets ) ) {
			echo '<p class="description">' . esc_html__( 'Aucune commande ni facture Dolibarr liée à cette commande : créez d\'abord la commande brouillon Dolibarr.', 'diwc' ) . '</p>';
			echo '</div>';
			return;
		}

		$woo_text = $this->format_address( $woo );
		$diff     = false;

		echo '<table class="widefat striped" style="max-width:900px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Document Dolibarr', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'Adresse de livraison actuelle', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'WooCommerce (à appliquer)', 'diwc' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $targets as $target ) {
			$target_text = $this->format_address( $target );
			$same        = ( $this->normalize( $target_text ) === $this->normalize( $woo_text ) );
			if ( ! $same ) {
				$diff = true;
			}

			echo '<tr' . ( $same ? '' : ' style="background:#fff8e5;"' ) . '>';
			echo '<td><strong>' . esc_html( $target['label'] ) . '</strong>';
			if ( '' !== $target['ref'] ) {
				echo '<br /><span class="description">' . esc_html( $target['ref'] ) . '</span>';
			}
			if ( '' !== $target['kind'] ) {
				echo '<br /><span class="description">' . esc_html( $target['kind'] ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . ( '' !== trim( $target_text ) ? nl2br( esc_html( $target_text ) ) : '<span class="description">' . esc_html__( '(vide)', 'diwc' ) . '</span>' ) . '</td>';
			echo '<td>' . ( $same ? '<span class="description">' . esc_html__( 'identique', 'diwc' ) . '</span>' : nl2br( esc_html( $woo_text ) ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( ! $diff ) {
			echo '<p class="description">' . esc_html__( 'L\'adresse de livraison est identique dans WooCommerce et dans Dolibarr.', 'diwc' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<p><a class="button button-primary" href="' . esc_url( $this->action_url( 'diwc_shipping_update', $order_id, 'diwc_shipping_' . $order_id ) ) . '">'
			. esc_html__( 'Appliquer l\'adresse de livraison de la commande WooCommerce (d\'un seul coup)', 'diwc' ) . '</a></p>';
		echo '<p class="description">' . esc_html__(
			'L\'adresse complète (rue, code postal, ville, pays) est écrite sur la commande Dolibarr et sur la facture liée. Aucune écriture n\'a lieu sans ce clic.',
			'diwc'
		) . '</p>';
		echo '</div>';
	}

	/**
	 * Gère l'application en une fois de l'adresse de livraison (action admin-post).
	 */
	public function handle_shipping_update() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}

		$order_id = (int) $this->request_param( 'order_id', 0 );
		if ( ! $order_id ) {
			$this->redirect_shipping( 0, false, __( 'Paramètres manquants.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_shipping_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->redirect_shipping( $order_id, false, __( 'Commande introuvable.', 'diwc' ) );
		}

		$woo     = $this->woo_shipping_values( $order );
		$billing = $this->woo_billing_address_values( $order );

		// Garde-fou : adresse de livraison identique à la facturation → Dolibarr
		// se sert de l'adresse du tiers, on ne modifie rien.
		if ( $this->normalize( $this->format_address( $woo ) ) === $this->normalize( $this->format_address( $billing ) ) ) {
			$this->redirect_shipping(
				$order_id,
				false,
				__( 'L\'adresse de livraison est identique à l\'adresse de facturation : Dolibarr utilise l\'adresse du compte client par défaut, rien n\'a été modifié.', 'diwc' )
			);
		}

		$fields = array(
			'address' => (string) $woo['address'],
			'zip'     => (string) $woo['zip'],
			'town'    => (string) $woo['town'],
		);

		if ( '' === trim( $fields['address'] . $fields['zip'] . $fields['town'] ) ) {
			$this->redirect_shipping( $order_id, false, __( 'Adresse de livraison vide côté WooCommerce : rien n\'a été modifié.', 'diwc' ) );
		}

		if ( '' !== (string) $woo['country'] ) {
			$cid = DIWC_API::instance()->country_id_from_code( (string) $woo['country'] );
			if ( $cid > 0 ) {
				$fields['country_id'] = $cid;
			}
		}

		$targets = $this->dolibarr_targets( $order );
		if ( empty( $targets ) ) {
			$this->redirect_shipping( $order_id, false, __( 'Aucune commande ni facture Dolibarr liée : rien n\'a été modifié.', 'diwc' ) );
		}

		$ok = array();
		$ko = array();

		foreach ( $targets as $target ) {
			$res = ( 'order' === $target['type'] )
				? DIWC_API::instance()->update_customer_order( (int) $target['id'], $fields )
				: DIWC_API::instance()->update_invoice( (int) $target['id'], $fields );

			if ( is_wp_error( $res ) ) {
				$ko[] = $target['label'] . ' : ' . $res->get_error_message();
			} else {
				$ok[] = $target['label'] . ( '' !== $target['ref'] ? ' (' . $target['ref'] . ')' : '' );
			}
		}

		DIWC_Log::add(
			'Adresse de livraison appliquée depuis WooCommerce (commande #' . $order->get_order_number() . ') — '
			. 'mise à jour : ' . ( empty( $ok ) ? 'aucune' : implode( ', ', $ok ) )
			. ( empty( $ko ) ? '' : ' — échecs : ' . implode( ' | ', $ko ) )
		);

		if ( empty( $ko ) ) {
			$this->redirect_shipping(
				$order_id,
				true,
				sprintf(
					/* translators: %s : liste des documents mis à jour. */
					__( 'Adresse de livraison appliquée : %s.', 'diwc' ),
					implode( ', ', $ok )
				)
			);
		}

		$this->redirect_shipping(
			$order_id,
			false,
			sprintf(
				/* translators: 1 : documents mis à jour, 2 : échecs. */
				__( 'Mise à jour partielle — réussite : %1$s ; échecs : %2$s', 'diwc' ),
				empty( $ok ) ? __( 'aucune', 'diwc' ) : implode( ', ', $ok ),
				implode( ' | ', $ko )
			)
		);
	}

	/**
	 * Documents Dolibarr portant l'adresse de livraison d'une commande :
	 * la commande client (créée par le plugin) et la facture liée.
	 *
	 * @param WC_Order $order Commande.
	 * @return array Liste de array( 'type', 'label', 'id', 'ref', 'address', 'zip', 'town', 'country', 'kind' ).
	 */
	private function dolibarr_targets( $order ) {
		$api     = DIWC_API::instance();
		$targets = array();

		// 1) Commande client Dolibarr.
		$doid = (int) $order->get_meta( DIWC_Order_Create::META_ORDER_ID );
		if ( $doid > 0 ) {
			$live = $api->get_customer_order( $doid );
			if ( is_array( $live ) ) {
				$targets[] = array(
					'type'    => 'order',
					'label'   => __( 'Commande Dolibarr', 'diwc' ),
					'kind'    => '',
					'id'      => $doid,
					'ref'     => isset( $live['ref'] ) ? (string) $live['ref'] : '',
					'address' => isset( $live['address'] ) ? (string) $live['address'] : '',
					'zip'     => isset( $live['zip'] ) ? (string) $live['zip'] : '',
					'town'    => isset( $live['town'] ) ? (string) $live['town'] : '',
					'country' => isset( $live['country_code'] ) ? strtoupper( (string) $live['country_code'] ) : '',
				);
			}
		}

		// 2) Facture Dolibarr liée (réf. client = numéro de commande).
		$invoices = $api->search_invoices_by_ref_client( $order->get_order_number() );
		if ( ! empty( $invoices ) && ! empty( $invoices[0]['id'] ) ) {
			$inv_id = (int) $invoices[0]['id'];
			$live   = $api->request( 'GET', '/invoices/' . $inv_id );
			$inv    = ( ! is_wp_error( $live ) && is_array( $live ) ) ? $live : $invoices[0];

			$targets[] = array(
				'type'    => 'invoice',
				'label'   => __( 'Facture Dolibarr', 'diwc' ),
				'kind'    => '',
				'id'      => $inv_id,
				'ref'     => isset( $inv['ref'] ) ? (string) $inv['ref'] : '',
				'address' => isset( $inv['address'] ) ? (string) $inv['address'] : '',
				'zip'     => isset( $inv['zip'] ) ? (string) $inv['zip'] : '',
				'town'    => isset( $inv['town'] ) ? (string) $inv['town'] : '',
				'country' => isset( $inv['country_code'] ) ? strtoupper( (string) $inv['country_code'] ) : '',
			);
		}

		return $targets;
	}

	/**
	 * Adresse de livraison côté WooCommerce (repli sur la facturation si la
	 * commande n'a pas d'adresse de livraison distincte).
	 *
	 * @param WC_Order $order Commande.
	 * @return array array( 'address', 'zip', 'town', 'country' ).
	 */
	private function woo_shipping_values( $order ) {
		$addr1 = trim( (string) $order->get_shipping_address_1() );
		$addr2 = trim( (string) $order->get_shipping_address_2() );
		$zip   = trim( (string) $order->get_shipping_postcode() );
		$town  = trim( (string) $order->get_shipping_city() );
		$ctry  = strtoupper( trim( (string) $order->get_shipping_country() ) );

		if ( '' === $addr1 && '' === $zip && '' === $town ) {
			$addr1 = trim( (string) $order->get_billing_address_1() );
			$addr2 = trim( (string) $order->get_billing_address_2() );
			$zip   = trim( (string) $order->get_billing_postcode() );
			$town  = trim( (string) $order->get_billing_city() );
			$ctry  = strtoupper( trim( (string) $order->get_billing_country() ) );
		}

		$address = $addr1;
		if ( '' !== $addr2 ) {
			$address .= "\n" . $addr2;
		}

		return array(
			'address' => $address,
			'zip'     => $zip,
			'town'    => $town,
			'country' => $ctry,
		);
	}

	/**
	 * Adresse de FACTURATION côté WooCommerce (mêmes clés que l'adresse de
	 * livraison) : sert à détecter le cas « livraison = facturation », pour
	 * lequel Dolibarr utilise l'adresse du compte client par défaut.
	 *
	 * @param WC_Order $order Commande.
	 * @return array array( 'address', 'zip', 'town', 'country' ).
	 */
	private function woo_billing_address_values( $order ) {
		$addr1 = trim( (string) $order->get_billing_address_1() );
		$addr2 = trim( (string) $order->get_billing_address_2() );

		$address = $addr1;
		if ( '' !== $addr2 ) {
			$address .= "\n" . $addr2;
		}

		return array(
			'address' => $address,
			'zip'     => trim( (string) $order->get_billing_postcode() ),
			'town'    => trim( (string) $order->get_billing_city() ),
			'country' => strtoupper( trim( (string) $order->get_billing_country() ) ),
		);
	}

	/**
	 * Formate une adresse pour l'affichage et la comparaison.
	 *
	 * @param array $values array( 'address', 'zip', 'town', 'country' ).
	 * @return string
	 */
	private function format_address( $values ) {
		$lines = array();
		if ( ! empty( $values['address'] ) ) {
			$lines[] = (string) $values['address'];
		}
		$line2 = trim( ( isset( $values['zip'] ) ? (string) $values['zip'] : '' ) . ' ' . ( isset( $values['town'] ) ? (string) $values['town'] : '' ) );
		if ( '' !== $line2 ) {
			$lines[] = $line2;
		}
		if ( ! empty( $values['country'] ) ) {
			$lines[] = $this->country_label( (string) $values['country'] );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Redirige vers la fiche commande avec le résultat (adresse de livraison).
	 *
	 * @param int    $order_id ID de la commande WooCommerce.
	 * @param bool   $ok       Succès ?
	 * @param string $msg      Message.
	 */
	private function redirect_shipping( $order_id, $ok, $msg ) {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'diwc_ship_ok'  => $ok ? '1' : '0',
						'diwc_ship_msg' => rawurlencode( $msg ),
					),
					$order->get_edit_order_url()
				)
			);
			exit;
		}
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Le tiers Dolibarr est-il de type « Particulier » (personne physique) ?
	 * Seul ce type exploite les champs firstname/lastname dans Dolibarr.
	 *
	 * @param array $thirdparty Tiers retourné par l'API.
	 * @return bool
	 */
	private function thirdparty_is_individual( $thirdparty ) {
		if ( ! is_array( $thirdparty ) ) {
			return false;
		}

		$code  = isset( $thirdparty['typent_code'] ) ? strtoupper( (string) $thirdparty['typent_code'] ) : '';
		$label = isset( $thirdparty['typent_label'] ) ? (string) $thirdparty['typent_label'] : '';

		if ( '' !== $code && false !== strpos( $code, 'PRIVATE' ) ) {
			return true;
		}
		if ( '' !== $label && false !== stripos( $label, 'particulier' ) ) {
			return true;
		}

		$typent_id = isset( $thirdparty['typent_id'] ) ? (int) $thirdparty['typent_id'] : 0;
		$known     = DIWC_API::instance()->individual_typent_id();

		return ( $typent_id > 0 && $known > 0 && $typent_id === $known );
	}

	/**
	 * Encart « Contact (personne physique) » : quand le prénom/nom de la personne
	 * ne peut pas être porté par la fiche du tiers (client société, ou tiers
	 * Dolibarr qui n'est pas de type « Particulier »), il est géré via un contact
	 * Dolibarr rattaché au tiers. Propose sa création, ou sa mise à jour champ
	 * par champ (rien de pré-coché).
	 *
	 * @param WC_Order $order      Commande.
	 * @param array    $thirdparty Tiers Dolibarr (id, name).
	 * @param array    $woo        Valeurs WooCommerce (voir woo_values()).
	 * @param bool     $force      Afficher même pour un client particulier
	 *                             (tiers Dolibarr non « Particulier »).
	 */
	private function render_contact_box( $order, $thirdparty, $woo, $force = false ) {
		if ( empty( $woo['is_company'] ) && ! $force ) {
			return; // Client particulier : prénom/nom gérés sur le tiers.
		}

		$first = trim( (string) $woo['firstname'] );
		$last  = trim( (string) $woo['lastname'] );
		if ( '' === $first && '' === $last ) {
			return;
		}

		$order_id = (int) $order->get_id();
		$contact  = $this->find_contact_for_thirdparty( (int) $thirdparty['id'], $woo );

		echo '<hr />';
		echo '<p><strong>' . esc_html__( 'Contact (personne physique) dans Dolibarr', 'diwc' ) . '</strong></p>';

		$rows = array(
			'firstname' => __( 'Prénom', 'diwc' ),
			'lastname'  => __( 'Nom', 'diwc' ),
			'email'     => __( 'E-mail', 'diwc' ),
			'phone'     => __( 'Téléphone', 'diwc' ),
		);

		if ( null === $contact ) {
			echo '<p>' . esc_html__( 'Aucun contact Dolibarr ne correspond à cette personne : vous pouvez le créer et le rattacher au tiers.', 'diwc' ) . '</p>';

			echo '<table class="widefat striped" style="max-width:900px;"><tbody>';
			foreach ( $rows as $key_name => $label ) {
				$value = isset( $woo[ $key_name ] ) ? (string) $woo[ $key_name ] : '';
				echo '<tr><td style="width:220px;"><strong>' . esc_html( $label ) . '</strong></td><td>'
					. ( '' !== $value ? esc_html( $value ) : '<span class="description">—</span>' ) . '</td></tr>';
			}
			echo '</tbody></table>';

			echo '<p><a class="button" href="' . esc_url( $this->action_url( 'diwc_contact_create', $order_id, 'diwc_contact_create_' . $order_id ) ) . '">'
				. esc_html__( 'Créer ce contact dans Dolibarr', 'diwc' ) . '</a></p>';
			echo '<p class="description">' . esc_html__( 'Le contact est rattaché au tiers Dolibarr (prénom, nom, e-mail, téléphone). Aucune création sans ce clic.', 'diwc' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s : nom du contact Dolibarr existant. */
				__( 'Contact Dolibarr existant : %s.', 'diwc' ),
				trim( ( isset( $contact['firstname'] ) ? (string) $contact['firstname'] : '' ) . ' ' . ( isset( $contact['lastname'] ) ? (string) $contact['lastname'] : '' ) )
			)
		) . '</p>';

		$box_id = 'diwc-contact-fields-' . $order_id;
		$action = $this->action_url(
			'diwc_contact_update',
			$order_id,
			'diwc_contact_update_' . $order_id
		);
		$action = add_query_arg( 'contact_id', (int) $contact['id'], $action );

		echo '<div id="' . esc_attr( $box_id ) . '">';
		echo '<table class="widefat striped" style="max-width:900px;"><thead><tr>';
		echo '<th style="width:26px;"></th>';
		echo '<th>' . esc_html__( 'Champ', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'WooCommerce (à appliquer)', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'Dolibarr (actuel)', 'diwc' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $key_name => $label ) {
			$woo_val = isset( $woo[ $key_name ] ) ? (string) $woo[ $key_name ] : '';
			$dol_val = ( 'phone' === $key_name )
				? $this->contact_phone_value( $contact )
				: ( isset( $contact[ $key_name ] ) ? (string) $contact[ $key_name ] : '' );
			$different = ( $this->normalize( $woo_val ) !== $this->normalize( $dol_val ) );

			echo '<tr' . ( $different ? ' style="background:#fff8e5;"' : '' ) . '>';
			echo '<td>' . ( $different ? '<input type="checkbox" name="diwc_contact_fields[]" value="' . esc_attr( $key_name ) . '" />' : '' ) . '</td>';
			echo '<td><strong>' . esc_html( $label ) . '</strong><br /><span class="description">'
				. esc_html( $different ? __( 'différent', 'diwc' ) : __( 'identique', 'diwc' ) ) . '</span></td>';
			echo '<td>' . ( '' !== $woo_val ? esc_html( $woo_val ) : '<span class="description">—</span>' ) . '</td>';
			echo '<td>' . ( '' !== $dol_val ? esc_html( $dol_val ) : '<span class="description">—</span>' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p><a class="button" href="' . esc_url( $action ) . '" onclick="return diwcSubmitChecked(\'' . esc_js( $box_id ) . '\', this, \'diwc_contact_fields[]\');">'
			. esc_html__( 'Mettre à jour le contact (champs cochés)', 'diwc' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Aucun champ n\'est coché par défaut : seuls les champs cochés sont envoyés à Dolibarr (PUT /contacts).', 'diwc' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Crée le contact Dolibarr (personne physique) rattaché au tiers.
	 */
	public function handle_contact_create() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}

		$order_id = (int) $this->request_param( 'order_id', 0 );
		if ( ! $order_id ) {
			$this->redirect( 0, false, __( 'Paramètres manquants.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_contact_create_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->redirect( $order_id, false, __( 'Commande introuvable.', 'diwc' ) );
		}

		$thirdparty = $this->find_thirdparty( $order );
		if ( null === $thirdparty ) {
			$this->redirect( $order_id, false, __( 'Aucun tiers Dolibarr pour cette commande : créez d\'abord le compte client.', 'diwc' ) );
		}

		$woo    = $this->woo_values( $order );
		$fields = array( 'socid' => (int) $thirdparty['id'] );

		foreach ( array( 'firstname', 'lastname', 'email' ) as $key_name ) {
			$value = trim( (string) $woo[ $key_name ] );
			if ( '' !== $value ) {
				$fields[ $key_name ] = $value;
			}
		}

		// Le téléphone d'un contact s'écrit dans « phone_pro » / « phone_mobile ».
		$fields = array_merge( $fields, $this->contact_phone_fields( isset( $woo['phone'] ) ? (string) $woo['phone'] : '' ) );

		if ( empty( $fields['lastname'] ) && empty( $fields['firstname'] ) ) {
			$this->redirect( $order_id, false, __( 'Impossible de créer le contact : ni prénom ni nom sur la commande.', 'diwc' ) );
		}

		$created = DIWC_API::instance()->create_contact( $fields );
		if ( is_wp_error( $created ) ) {
			$this->redirect( $order_id, false, __( 'Échec de la création du contact : ', 'diwc' ) . $created->get_error_message() );
		}

		$this->redirect(
			$order_id,
			true,
			sprintf(
				/* translators: 1 : nom du contact, 2 : ID du contact. */
				__( 'Contact Dolibarr créé : %1$s (id %2$d), rattaché au tiers.', 'diwc' ),
				trim( ( isset( $created['firstname'] ) ? (string) $created['firstname'] : '' ) . ' ' . ( isset( $created['lastname'] ) ? (string) $created['lastname'] : '' ) ),
				(int) $created['id']
			)
		);
	}

	/**
	 * Met à jour les champs cochés d'un contact Dolibarr.
	 */
	public function handle_contact_update() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}

		$order_id   = (int) $this->request_param( 'order_id', 0 );
		$contact_id = (int) $this->request_param( 'contact_id', 0 );

		if ( ! $order_id || $contact_id <= 0 ) {
			$this->redirect( 0, false, __( 'Paramètres manquants.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_contact_update_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->redirect( $order_id, false, __( 'Commande introuvable.', 'diwc' ) );
		}

		$allowed  = array( 'firstname', 'lastname', 'email', 'phone' );
		$selected = array();
		$raw      = $this->request_param( 'diwc_contact_fields', array() );

		if ( is_array( $raw ) ) {
			foreach ( array_map( 'sanitize_key', $raw ) as $key_name ) {
				if ( in_array( $key_name, $allowed, true ) ) {
					$selected[] = $key_name;
				}
			}
		}

		if ( empty( $selected ) ) {
			$this->redirect( $order_id, false, __( 'Aucun champ de contact sélectionné : rien n\'a été modifié.', 'diwc' ) );
		}

		$woo     = $this->woo_values( $order );
		$payload = array();
		$labels  = array(
			'firstname' => __( 'Prénom', 'diwc' ),
			'lastname'  => __( 'Nom', 'diwc' ),
			'email'     => __( 'E-mail', 'diwc' ),
			'phone'     => __( 'Téléphone', 'diwc' ),
		);
		$done    = array();

		foreach ( $selected as $key_name ) {
			if ( 'phone' === $key_name ) {
				// Champ téléphone propre aux contacts : phone_pro / phone_mobile.
				$payload = array_merge( $payload, $this->contact_phone_fields( isset( $woo['phone'] ) ? (string) $woo['phone'] : '' ) );
			} else {
				$payload[ $key_name ] = isset( $woo[ $key_name ] ) ? (string) $woo[ $key_name ] : '';
			}
			$done[] = $labels[ $key_name ];
		}

		$res = DIWC_API::instance()->update_contact( $contact_id, $payload );
		if ( is_wp_error( $res ) ) {
			$this->redirect( $order_id, false, __( 'Échec de la mise à jour du contact : ', 'diwc' ) . $res->get_error_message() );
		}

		$this->redirect(
			$order_id,
			true,
			sprintf(
				/* translators: %s : liste des champs mis à jour. */
				__( 'Contact Dolibarr mis à jour : %s.', 'diwc' ),
				implode( ', ', $done )
			)
		);
	}

	/**
	 * Cherche le contact Dolibarr du tiers correspondant à la personne de la
	 * commande (e-mail identique, sinon nom + prénom).
	 *
	 * @param int   $thirdparty_id ID du tiers Dolibarr.
	 * @param array $woo           Valeurs WooCommerce.
	 * @return array|null Contact trouvé, ou null.
	 */
	private function find_contact_for_thirdparty( $thirdparty_id, $woo ) {
		$contacts = DIWC_API::instance()->search_contacts_by_thirdparty( $thirdparty_id );
		if ( empty( $contacts ) ) {
			return null;
		}

		$email = strtolower( trim( (string) $woo['email'] ) );
		if ( '' !== $email ) {
			foreach ( $contacts as $contact ) {
				if ( is_array( $contact ) && isset( $contact['email'] ) && strtolower( trim( (string) $contact['email'] ) ) === $email ) {
					return $contact;
				}
			}
		}

		$last  = $this->normalize( (string) $woo['lastname'] );
		$first = $this->normalize( (string) $woo['firstname'] );
		if ( '' === $last && '' === $first ) {
			return null;
		}

		foreach ( $contacts as $contact ) {
			if ( ! is_array( $contact ) ) {
				continue;
			}
			$c_last  = $this->normalize( isset( $contact['lastname'] ) ? $contact['lastname'] : '' );
			$c_first = $this->normalize( isset( $contact['firstname'] ) ? $contact['firstname'] : '' );
			if ( '' !== $last && $c_last === $last && ( '' === $first || $c_first === $first ) ) {
				return $contact;
			}
		}

		return null;
	}

	/**
	 * Compare deux noms sans tenir compte de l'ordre des mots ni de la casse
	 * (« DUPONT Jean » = « Jean DUPONT »).
	 *
	 * @param string $a Premier nom.
	 * @param string $b Second nom.
	 * @return bool
	 */
	private function same_name( $a, $b ) {
		$a_words = preg_split( '/\s+/u', $this->normalize( $a ), -1, PREG_SPLIT_NO_EMPTY );
		$b_words = preg_split( '/\s+/u', $this->normalize( $b ), -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $a_words ) || ! is_array( $b_words ) ) {
			return $this->normalize( $a ) === $this->normalize( $b );
		}

		sort( $a_words );
		sort( $b_words );

		return $a_words === $b_words;
	}

	/**
	 * Retrouve le tiers Dolibarr d'une commande via l'e-mail de facturation.
	 *
	 * @param WC_Order $order Commande.
	 * @return array|null array( 'id', 'name' ) ou null.
	 */
	private function find_thirdparty( $order ) {
		$email = (string) $order->get_billing_email();
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

		$tp = DIWC_API::instance()->search_thirdparty_by_email( $email );
		if ( ! $tp || empty( $tp['id'] ) ) {
			return null;
		}

		return array(
			'id'   => (int) $tp['id'],
			'name' => isset( $tp['name'] ) ? (string) $tp['name'] : '',
		);
	}

	/**
	 * Valeurs de facturation côté WooCommerce (clé = nom du champ Dolibarr).
	 *
	 * @param WC_Order $order Commande.
	 * @return array
	 */
	private function woo_values( $order ) {
		$company = trim( (string) $order->get_billing_company() );
		$first   = trim( (string) $order->get_billing_first_name() );
		$last    = trim( (string) $order->get_billing_last_name() );
		$addr1   = trim( (string) $order->get_billing_address_1() );
		$addr2   = trim( (string) $order->get_billing_address_2() );
		$country = strtoupper( trim( (string) $order->get_billing_country() ) );

		$name = ( '' !== $company ) ? $company : trim( $last . ' ' . $first );

		$values = array(
			'name'       => $name,
			'firstname'  => $first,
			'lastname'   => $last,
			'email'      => (string) $order->get_billing_email(),
			'phone'      => (string) $order->get_billing_phone(),
			'address'    => ( '' !== $addr2 ) ? $addr1 . "\n" . $addr2 : $addr1,
			'zip'        => (string) $order->get_billing_postcode(),
			'town'       => (string) $order->get_billing_city(),
			'country'    => ( '' !== $country ) ? $this->country_label( $country ) : '',
			'is_company' => ( '' !== $company ),
		);

		return $values;
	}

	/**
	 * Valeurs actuelles du tiers Dolibarr (clé = nom du champ Dolibarr).
	 *
	 * @param array $thirdparty Tiers retourné par l'API.
	 * @return array
	 */
	private function dolibarr_values( $thirdparty ) {
		$code = strtoupper( (string) ( isset( $thirdparty['country_code'] ) ? $thirdparty['country_code'] : '' ) );

		$label = '';
		if ( '' !== $code ) {
			$label = $this->country_label( $code );
		} elseif ( ! empty( $thirdparty['country'] ) ) {
			$label = (string) $thirdparty['country'];
		}

		return array(
			'name'      => isset( $thirdparty['name'] ) ? (string) $thirdparty['name'] : '',
			'firstname' => isset( $thirdparty['firstname'] ) ? (string) $thirdparty['firstname'] : '',
			'lastname'  => isset( $thirdparty['lastname'] ) ? (string) $thirdparty['lastname'] : '',
			'email'     => isset( $thirdparty['email'] ) ? (string) $thirdparty['email'] : '',
			'phone'     => isset( $thirdparty['phone'] ) ? (string) $thirdparty['phone'] : '',
			'address'   => isset( $thirdparty['address'] ) ? (string) $thirdparty['address'] : '',
			'zip'       => isset( $thirdparty['zip'] ) ? (string) $thirdparty['zip'] : '',
			'town'      => isset( $thirdparty['town'] ) ? (string) $thirdparty['town'] : '',
			'country'   => $label,
		);
	}

	/**
	 * Libellé d'un pays depuis son code ISO (liste WooCommerce, repli sur le code).
	 *
	 * @param string $code Code pays ISO2.
	 * @return string
	 */
	private function country_label( $code ) {
		$code = strtoupper( (string) $code );
		if ( function_exists( 'WC' ) && WC()->countries && isset( WC()->countries->countries[ $code ] ) ) {
			return $code . ' — ' . WC()->countries->countries[ $code ];
		}
		return $code;
	}

	/**
	 * Normalise une valeur pour la comparaison (espaces, casse, HTML).
	 *
	 * @param string $value Valeur.
	 * @return string
	 */
	private function normalize( $value ) {
		$value = wp_strip_all_tags( (string) $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = trim( (string) $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	/**
	 * Purge les caches du plugin liés au client d'une commande.
	 *
	 * @param WC_Order $order Commande.
	 */
	private function clear_cache( $order ) {
		$order_id = (int) $order->get_id();
		delete_transient( 'diwc_thirdparty_' . $order_id );

		$email = (string) $order->get_billing_email();
		if ( '' !== $email && is_email( $email ) ) {
			delete_transient( 'diwc_thirdparty_email_' . md5( strtolower( $email ) ) );
		}
	}

	/**
	 * Lit un paramètre de requête (GET puis POST). Les encarts de la fiche
	 * commande utilisent des LIENS admin-post (GET) car un <form> imbriqué dans
	 * le formulaire WordPress de la commande est ignoré par le navigateur ; on
	 * tolère néanmoins un envoi POST.
	 *
	 * @param string $key     Nom du paramètre.
	 * @param mixed  $default Valeur par défaut.
	 * @return mixed
	 */
	private function request_param( $key, $default = '' ) {
		if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		return $default;
	}

	/**
	 * URL d'action admin-post protégée par un nonce.
	 *
	 * @param string $action       Action admin-post.
	 * @param int    $order_id     ID de la commande WooCommerce.
	 * @param string $nonce_action Action du nonce.
	 * @return string
	 */
	private function action_url( $action, $order_id, $nonce_action ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => $action,
					'order_id' => (int) $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			$nonce_action
		);
	}

	/**
	 * L'adresse de livraison est-elle distincte de l'adresse de facturation ?
	 *
	 * @param WC_Order $order Commande.
	 * @return bool
	 */
	private function has_distinct_shipping_address( $order ) {
		$shipping = $this->woo_shipping_values( $order );
		$billing  = $this->woo_billing_address_values( $order );

		return ( $this->normalize( $this->format_address( $shipping ) ) !== $this->normalize( $this->format_address( $billing ) ) );
	}

	/**
	 * Script (affiché une seule fois) qui transforme la sélection de cases à
	 * cocher en URL admin-post : nécessaire car les <form> imbriqués ne
	 * fonctionnent pas dans la page de commande WordPress.
	 */
	private function print_submit_script() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<script>
		function diwcSubmitChecked( boxId, link, fieldName ) {
			var box = document.getElementById( boxId );
			if ( ! box ) { return false; }
			var url = link.getAttribute( 'href' );
			var inputs = box.getElementsByTagName( 'input' );
			var count = 0;
			for ( var i = 0; i < inputs.length; i++ ) {
				if ( inputs[i].type === 'checkbox' && inputs[i].checked && inputs[i].name === fieldName ) {
					url += '&' + encodeURIComponent( fieldName ) + '=' + encodeURIComponent( inputs[i].value );
					count++;
				}
			}
			if ( 0 === count ) {
				window.alert( '<?php echo esc_js( __( 'Aucun champ sélectionné : cochez au moins un champ.', 'diwc' ) ); ?>' );
				return false;
			}
			window.location.href = url;
			return false;
		}
		</script>
		<?php
	}

	/**
	 * Redirige vers la fiche commande avec le résultat.
	 *
	 * @param int    $order_id ID de la commande WooCommerce.
	 * @param bool   $ok       Succès ?
	 * @param string $msg      Message.
	 */
	private function redirect( $order_id, $ok, $msg ) {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'diwc_tp_ok'  => $ok ? '1' : '0',
						'diwc_tp_msg' => rawurlencode( $msg ),
					),
					$order->get_edit_order_url()
				)
			);
			exit;
		}
		wp_safe_redirect( admin_url() );
		exit;
	}
}
