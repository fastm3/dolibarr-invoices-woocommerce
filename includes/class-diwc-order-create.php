<?php
/**
 * Création d'une commande client Dolibarr (brouillon) depuis une commande
 * WooCommerce : bouton sur la fiche commande (admin), lignes reprises de la
 * commande, produits reliés via un extrafield Dolibarr.
 *
 * @package DIWC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bouton « Créer la commande brouillon Dolibarr » sur la fiche commande
 * (admin WooCommerce). Chaque article WooCommerce est relié à son produit
 * Dolibarr via l'extrafield configuré (défaut : id_du_produit_dans_woocommerce,
 * contenant l'ID du produit WooCommerce). Les articles sans correspondance
 * sont ajoutés en ligne libre, ainsi que les frais de port. La référence de la
 * commande Dolibarr créée est mémorisée (meta) pour éviter les doublons.
 */
class DIWC_Order_Create {

	/**
	 * Meta stockant l'ID de la commande Dolibarr créée (brouillon).
	 */
	const META_ORDER_ID = '_diwc_dolibarr_order_id';

	/**
	 * Meta stockant la référence de la commande Dolibarr créée.
	 */
	const META_ORDER_REF = '_diwc_dolibarr_order_ref';

	/**
	 * Meta stockant l'ID du contact Dolibarr portant l'adresse de LIVRAISON
	 * (créé avec l'adresse de livraison) : il est associé à la commande
	 * Dolibarr via fk_delivery_address.
	 */
	const META_DELIVERY_CONTACT = '_diwc_delivery_contact_id';

	/**
	 * Instance unique.
	 *
	 * @var DIWC_Order_Create|null
	 */
	private static $instance = null;

	/**
	 * Retourne l'instance unique.
	 *
	 * @return DIWC_Order_Create
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
		// Encart « Commande client Dolibarr » sur la fiche commande (admin),
		// après l'encart facture (priorité 20).
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_admin_box' ), 20 );

		add_action( 'admin_post_diwc_draft_order_create', array( $this, 'handle_create' ) );
		add_action( 'admin_post_diwc_draft_order_unlink', array( $this, 'handle_unlink' ) );
		add_action( 'admin_post_diwc_draft_order_refresh', array( $this, 'handle_refresh_ref' ) );
	}

	/**
	 * Affiche l'encart « Commande client Dolibarr (brouillon) » sur la fiche
	 * commande : bouton de création, ou état de la commande déjà créée.
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

		// Notification du résultat (création / déliaison).
		if ( isset( $_GET['diwc_draft_ok'], $_GET['diwc_draft_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ok  = '1' === $_GET['diwc_draft_ok'];
			$msg = sanitize_text_field( wp_unslash( $_GET['diwc_draft_msg'] ) );
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		$api  = DIWC_Settings::instance();
		$url  = (string) $api->get( 'url' );
		$key  = (string) $api->get( 'api_key' );
		$doid = (int) $order->get_meta( self::META_ORDER_ID );
		$doref = (string) $order->get_meta( self::META_ORDER_REF );

		echo '<div class="diwc-admin-invoice">';
		echo '<p><strong>' . esc_html__( 'Commande client Dolibarr', 'diwc' ) . '</strong></p>';

		if ( '' === $url || '' === $key ) {
			echo '<p>' . esc_html__( 'API Dolibarr non configurée : renseignez l\'URL et la clé API dans WooCommerce → Factures Dolibarr.', 'diwc' ) . '</p>';
			echo '</div>';
			return;
		}

		if ( $doid > 0 ) {
			// Commande Dolibarr liée : on relit son état réel dans Dolibarr
			// (la référence change à la validation, le statut évolue).
			$card_url = $this->order_card_url( $doid );
			$live     = DIWC_API::instance()->get_customer_order( $doid );

			if ( is_array( $live ) ) {
				$ref    = ( isset( $live['ref'] ) && '' !== (string) $live['ref'] ) ? (string) $live['ref'] : $doref;
				$status = $this->order_status_label( $live );

				echo '<p>' . esc_html__( 'Commande Dolibarr liée :', 'diwc' ) . ' <strong>' . esc_html( $ref ) . '</strong>';
				echo ' — ' . esc_html__( 'statut :', 'diwc' ) . ' <strong>' . esc_html( $status ) . '</strong>';
				if ( ! empty( $live['date_validation'] ) && (int) $live['date_validation'] > 0 ) {
					echo ' <span class="description">(' . esc_html( sprintf(
						/* translators: %s : date de validation dans Dolibarr. */
						__( 'validée le %s', 'diwc' ),
						date_i18n( get_option( 'date_format' ), (int) $live['date_validation'] )
					) ) . ')</span>';
				}
				echo '</p>';

				// La référence Dolibarr a changé (validation…) : on n'écrit RIEN
				// sans une demande explicite — un bouton est proposé.
				if ( '' !== $ref && '' !== $doref && $ref !== $doref ) {
					echo '<p>' . esc_html(
						sprintf(
							/* translators: %s : référence actuellement mémorisée sur la commande. */
							__( 'La référence mémorisée sur la commande (« %s ») diffère de la référence Dolibarr actuelle.', 'diwc' ),
							$doref
						)
					) . ' ';
					echo '<a class="button button-small" href="' . esc_url( $this->refresh_url( $order_id ) ) . '">' . esc_html__( 'Mémoriser la référence Dolibarr', 'diwc' ) . '</a></p>';
				}
			} else {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: %s : référence mémorisée de la commande Dolibarr. */
						__( 'Commande Dolibarr liée : %s — état Dolibarr indisponible (connexion API ? commande supprimée dans Dolibarr ?).', 'diwc' ),
						$doref
					)
				) . '</p>';
			}

			echo '<p>';
			echo '<a class="button" href="' . esc_url( $card_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Ouvrir dans Dolibarr', 'diwc' ) . '</a> ';
			echo '<a class="button button-secondary" href="' . esc_url( $this->create_url( $order_id ) ) . '">' . esc_html__( 'Recréer un brouillon', 'diwc' ) . '</a> ';
			echo '<a class="button button-secondary" href="' . esc_url( $this->unlink_url( $order_id ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Ne plus associer cette commande Dolibarr (elle reste telle quelle dans Dolibarr) ?', 'diwc' ) ) . '\');">' . esc_html__( 'Ne plus associer', 'diwc' ) . '</a>';
			echo '</p>';
			echo '<p class="description">' . esc_html__( '« Recréer un brouillon » crée une nouvelle commande Dolibarr (la précédente reste telle quelle dans Dolibarr).', 'diwc' ) . '</p>';
		} else {
			// Aucune commande créée : bouton de création.
			echo '<p>' . esc_html__(
				'Crée dans Dolibarr une commande client au statut brouillon pour ce client, avec les lignes de la commande WooCommerce. Les articles sont reliés aux produits Dolibarr via l\'extrafield produit configuré ; les articles non retrouvés et les frais de port sont ajoutés en ligne libre.',
				'diwc'
			) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $this->create_url( $order_id ) ) . '">' . esc_html__( 'Créer la commande brouillon Dolibarr', 'diwc' ) . '</a></p>';
		}

		// Correspondance article → produit Dolibarr (lecture seule), utile pour
		// vérifier avant création — et pour expliquer une commande déjà créée.
		$this->render_mapping_preview( $order );

		echo '</div>';
	}

	/**
	 * Gère la création de la commande brouillon Dolibarr (action admin-post).
	 */
	public function handle_create() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'diwc_draft_order_' . $order_id ) ) {
			wp_die( esc_html__( 'Lien invalide ou expiré.', 'diwc' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->redirect( $order_id, false, __( 'Commande introuvable.', 'diwc' ) );
		}

		$built = $this->build_payload( $order );
		if ( is_wp_error( $built ) ) {
			$this->redirect( $order_id, false, $built->get_error_message() );
		}

		$created = DIWC_API::instance()->create_draft_customer_order( $built['payload'] );
		if ( is_wp_error( $created ) ) {
			$msg = __( 'Échec de la création de la commande Dolibarr : ', 'diwc' ) . $created->get_error_message();
			$this->redirect( $order_id, false, $msg );
		}

		$doid = (int) $created['id'];
		$ref  = isset( $created['ref'] ) ? (string) $created['ref'] : (string) $doid;

		$order->update_meta_data( self::META_ORDER_ID, $doid );
		$order->update_meta_data( self::META_ORDER_REF, $ref );
		$order->save();

		DIWC_Log::add(
			'Commande client brouillon ' . $ref . ' (id ' . $doid . ') créée pour la commande WooCommerce #'
			. $order->get_order_number() . ' (' . $order_id . ')'
		);

		// Adresse de livraison distincte : on associe le contact de livraison à la
		// commande créée (route dédiée), avec vérification et repli.
		$delivery_msg = '';
		if ( ! empty( $built['delivery_contact'] ) ) {
			$this->apply_delivery_contact( $order, $doid, (int) $built['delivery_contact'], $built['delivery_address'] );
			$delivery_msg = ' ' . sprintf(
				/* translators: %d : ID du contact Dolibarr. */
				__( 'Adresse de livraison : contact Dolibarr %d associé à la commande.', 'diwc' ),
				(int) $built['delivery_contact']
			);
		} elseif ( ! empty( $built['delivery_address'] ) ) {
			$delivery_msg = ' ' . __( 'Adresse de livraison écrite sur la commande Dolibarr (aucun contact correspondant).', 'diwc' );
		}

		$msg = sprintf(
			/* translators: %s : référence de la commande Dolibarr. */
			__( 'Commande brouillon Dolibarr créée : %s.', 'diwc' ),
			$ref
		) . $delivery_msg;
		if ( ! empty( $built['free'] ) ) {
			$msg .= ' ' . sprintf(
				/* translators: %d : nombre de lignes libres. */
				__( '%d article(s) ajouté(s) en ligne libre (produit Dolibarr non retrouvé).', 'diwc' ),
				count( $built['free'] )
			);
			if ( ! empty( $built['scheduled'] ) ) {
				$msg .= ' ' . __( 'La synchronisation du catalogue a été relancée en arrière-plan : recréez la commande dans quelques minutes pour lier les produits.', 'diwc' );
			}
		}

		$this->redirect( $order_id, true, $msg );
	}

	/**
	 * Gère la déliaison locale (action admin-post) : efface la meta, le
	 * brouillon Dolibarr reste tel quel.
	 */
	public function handle_unlink() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'diwc_draft_order_unlink_' . $order_id ) ) {
			wp_die( esc_html__( 'Lien invalide ou expiré.', 'diwc' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->redirect( $order_id, false, __( 'Commande introuvable.', 'diwc' ) );
		}

		$order->delete_meta_data( self::META_ORDER_ID );
		$order->delete_meta_data( self::META_ORDER_REF );
		$order->save();

		DIWC_Log::add( 'Déliaison commande Dolibarr (commande WooCommerce #' . $order_id . ')' );

		$this->redirect( $order_id, true, __( 'Commande Dolibarr déliée (le brouillon reste dans Dolibarr).', 'diwc' ) );
	}

	/**
	 * Mémorise (sur demande explicite) la référence actuelle de la commande
	 * Dolibarr — par exemple après validation, quand la référence définitive
	 * remplace la référence provisoire du brouillon.
	 */
	public function handle_refresh_ref() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'diwc_draft_order_refresh_' . $order_id ) ) {
			wp_die( esc_html__( 'Lien invalide ou expiré.', 'diwc' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$this->redirect( $order_id, false, __( 'Commande introuvable.', 'diwc' ) );
		}

		$doid = (int) $order->get_meta( self::META_ORDER_ID );
		if ( $doid <= 0 ) {
			$this->redirect( $order_id, false, __( 'Aucune commande Dolibarr n\'est associée à cette commande.', 'diwc' ) );
		}

		// La référence est relue côté Dolibarr (jamais depuis l'URL).
		$live = DIWC_API::instance()->get_customer_order( $doid );
		if ( ! is_array( $live ) ) {
			$this->redirect( $order_id, false, __( 'État Dolibarr indisponible : réessayez plus tard.', 'diwc' ) );
		}

		$ref = isset( $live['ref'] ) ? trim( (string) $live['ref'] ) : '';
		if ( '' === $ref ) {
			$this->redirect( $order_id, false, __( 'Référence Dolibarr vide : rien n\'a été mémorisé.', 'diwc' ) );
		}

		$old = (string) $order->get_meta( self::META_ORDER_REF );
		$order->update_meta_data( self::META_ORDER_REF, $ref );
		$order->save();

		DIWC_Log::add(
			'Commande WooCommerce #' . $order->get_order_number() . ' : référence Dolibarr mémorisée sur demande « '
			. $old . ' » → « ' . $ref . ' ».'
		);

		$this->redirect(
			$order_id,
			true,
			sprintf(
				/* translators: %s : référence de la commande Dolibarr. */
				__( 'Référence Dolibarr mémorisée : %s.', 'diwc' ),
				$ref
			)
		);
	}

	/**
	 * Correspondance article WooCommerce → produit Dolibarr, selon la stratégie
	 * réglée (déclinaison seule, parent seul, ou déclinaison puis parent).
	 * Renvoie une ligne par article, avec le produit trouvé et l'ID Woo utilisé.
	 *
	 * @param WC_Order $order Commande.
	 * @return array
	 */
	private function map_order_items( $order ) {
		$extrafield = (string) DIWC_Settings::instance()->get( 'product_extrafield' );
		$match_mode = (string) DIWC_Settings::instance()->get( 'product_match_mode' );
		$rows       = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$qty = (float) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}

			$total_ht = (float) $item->get_total(); // Net (remises déduites).
			if ( $total_ht <= 0 ) {
				continue;
			}

			$variation_id = (int) $item->get_variation_id();
			$parent_id    = (int) $item->get_product_id();

			// Attributs copiés sur la ligne à l'achat : ils sont stockés en
			// MÉTADONNÉES de la ligne (« attribute_pa_xxx » / « attribute_xxx »).
			// NB : WC_Order_Item_Product n'expose pas get_variation_attributes()
			// (cette méthode appartient à WC_Product_Variation).
			$attrs     = array();
			$attrs_txt = array();

			foreach ( (array) $item->get_meta_data() as $meta ) {
				if ( ! is_object( $meta ) ) {
					continue;
				}

				$meta_key = is_callable( array( $meta, 'get_key' ) ) ? (string) $meta->get_key() : ( isset( $meta->key ) ? (string) $meta->key : '' );
				if ( '' === $meta_key || 0 !== strpos( $meta_key, 'attribute_' ) ) {
					continue;
				}

				$raw       = is_callable( array( $meta, 'get_value' ) ) ? $meta->get_value() : ( isset( $meta->value ) ? $meta->value : '' );
				$attr_value = is_scalar( $raw ) ? (string) $raw : '';
				if ( '' === $attr_value ) {
					continue;
				}

				$clean           = preg_replace( '/^attribute_/', '', $meta_key );
				$attrs[ $clean ] = $attr_value;
				$attrs_txt[]     = $clean . '=' . $attr_value;
			}

			// Déclinaison que ces attributs désignent RÉELLEMENT dans le
			// catalogue : si son ID diffère de celui enregistré sur la ligne,
			// la ligne porte un ID erroné (le choix du client, lui, est bon).
			$parent_product = ( $parent_id > 0 ) ? wc_get_product( $parent_id ) : false;
			$expected_id    = 0;
			if ( $parent_product && $parent_product->is_type( 'variable' ) && ! empty( $attrs ) && class_exists( 'WC_Data_Store' ) ) {
				$data_store  = WC_Data_Store::load( 'product' );
				$expected_id = (int) $data_store->find_matching_product_variation( $parent_product, $attrs );
			}

			// Contrôle de cohérence DANS WooCommerce : la déclinaison enregistrée
			// sur la ligne correspond-elle au choix du client, et appartient-elle
			// bien au produit de la ligne ?
			$wc_warning        = '';
			$variation_summary = '';
			$expected_summary  = '';

			if ( $variation_id > 0 ) {
				$variation = wc_get_product( $variation_id );

				if ( ! $variation ) {
					$wc_warning = sprintf(
						/* translators: %d : ID de la déclinaison WooCommerce. */
						__( 'incohérence WooCommerce : la déclinaison %d n\'existe plus dans le catalogue', 'diwc' ),
						$variation_id
					);
				} elseif ( ! empty( $attrs ) && ! $this->same_attributes( $attrs, $variation->get_attributes() ) ) {
					// Les attributs copiés sur la ligne ne correspondent plus à la
					// déclinaison actuelle : celle-ci a été MODIFIÉE depuis l'achat
					// (sans création ni suppression), l'historique est réécrit.
					$variation_summary = $this->variation_summary( $variation );

					if ( $expected_id > 0 ) {
						$expected_summary = $this->variation_summary( wc_get_product( $expected_id ) );
						$wc_warning        = sprintf(
							/* translators: 1 : ID enregistré sur la ligne, 2 : attributs actuels de cette déclinaison, 3 : attributs de la ligne, 4 : ID de la déclinaison attendue, 5 : attributs de cette déclinaison. */
							__( 'incohérence WooCommerce : la ligne enregistre la déclinaison %1$d [%2$s], alors que les attributs de la ligne (%3$s) désignent la déclinaison %4$d [%5$s]', 'diwc' ),
							$variation_id,
							$variation_summary,
							implode( ', ', $attrs_txt ),
							$expected_id,
							$expected_summary
						);
					} else {
						$wc_warning = sprintf(
							/* translators: 1 : attributs de la ligne, 2 : ID de la déclinaison enregistrée, 3 : attributs actuels de cette déclinaison. */
							__( 'incohérence WooCommerce : les attributs de la ligne (%1$s) ne correspondent à AUCUNE déclinaison du catalogue ; la déclinaison %2$d est aujourd\'hui définie [%3$s] — elle a donc été modifiée depuis l\'achat', 'diwc' ),
							implode( ', ', $attrs_txt ),
							$variation_id,
							$variation_summary
						);
					}
				} else {
					$variation_parent = (int) $variation->get_parent_id();
					if ( $parent_id > 0 && $variation_parent !== $parent_id ) {
						$other      = $variation_parent > 0 ? wc_get_product( $variation_parent ) : false;
						$wc_warning = sprintf(
							/* translators: 1 : ID de la déclinaison, 2 : nom du produit auquel la déclinaison appartient, 3 : ID de ce produit, 4 : ID du produit porté par la ligne. */
							__( 'incohérence WooCommerce : la déclinaison %1$d appartient au produit « %2$s » (ID %3$d), pas au produit de la ligne (ID %4$d)', 'diwc' ),
							$variation_id,
							$other ? (string) $other->get_name() : '?',
							$variation_parent,
							$parent_id
						);
					}
				}
			}

			switch ( $match_mode ) {
				case 'variation':
					$tried = ( $variation_id > 0 ) ? array( $variation_id ) : array( $parent_id );
					break;
				case 'parent':
					$tried = array( $parent_id );
					break;
				default:
					$tried = array_values( array_unique( array_filter( array( $variation_id, $parent_id ) ) ) );
			}

			$found   = null;
			$used_id = 0;
			foreach ( $tried as $wc_id ) {
				$candidate = DIWC_API::instance()->search_product_by_extrafield( $extrafield, $wc_id );
				if ( $candidate ) {
					$found   = $candidate;
					$used_id = (int) $wc_id;
					break;
				}
			}

			$rows[] = array(
				'label'             => (string) $item->get_name(),
				'qty'               => $qty,
				'total_ht'          => $total_ht,
				'total_tax'         => (float) $item->get_total_tax(),
				'variation_id'      => $variation_id,
				'parent_id'         => $parent_id,
				'tried'             => $tried,
				'used_id'           => $used_id,
				'product'           => $found,
				'wc_warning'        => $wc_warning,
				'attrs'             => implode( ', ', $attrs_txt ),
				'expected_id'       => $expected_id,
				'variation_summary' => $variation_summary,
				'expected_summary'  => $expected_summary,
			);
		}

		return $rows;
	}

	/**
	 * Construit le message d'erreur expliquant pourquoi la création est
	 * refusée : produit (et sa déclinaison) + ID Woo manquant, pour chaque
	 * article sans correspondance Dolibarr.
	 *
	 * @param array  $rows       Lignes non mappées issues de map_order_items().
	 * @param string $extrafield Nom de l'extrafield utilisé.
	 * @return string
	 */
	private function unmapped_message( $rows, $extrafield ) {
		$details = array();

		foreach ( $rows as $row ) {
			$ids = array();

			if ( (int) $row['variation_id'] > 0 ) {
				/* translators: %d : ID de la déclinaison WooCommerce. */
				$ids[] = sprintf( __( 'déclinaison Woo %d', 'diwc' ), (int) $row['variation_id'] );
			}
			if ( (int) $row['parent_id'] > 0 ) {
				/* translators: %d : ID du produit parent WooCommerce. */
				$ids[] = sprintf( __( 'produit parent Woo %d', 'diwc' ), (int) $row['parent_id'] );
			}

			$detail = '« ' . (string) $row['label'] . ' »';

			// Déclinaison achetée : les attributs copiés sur la ligne.
			if ( '' !== (string) $row['attrs'] ) {
				$detail .= ' [' . (string) $row['attrs'] . ']';
			}
			if ( ! empty( $ids ) ) {
				/* translators: %s : liste des IDs WooCommerce recherchés. */
				$detail .= ' — ' . sprintf( __( 'ID Woo manquant : %s', 'diwc' ), implode( ' / ', $ids ) );
			}

			$details[] = $detail;
		}

		return sprintf(
			/* translators: 1 : nombre d'articles, 2 : nom de l'extrafield, 3 : détail des articles. */
			__(
				'Création refusée : %1$d article(s) de la commande n\'ont pas de produit Dolibarr correspondant (extrafield « %2$s »). Aucune commande n\'a été créée. %3$s. Renseignez l\'extrafield sur le produit Dolibarr concerné (ou corrigez sa valeur), cliquez sur « Synchroniser le catalogue », puis réessayez. Pour autoriser exceptionnellement les lignes libres, changez le réglage « Article sans produit Dolibarr ».',
				'diwc'
			),
			count( $rows ),
			$extrafield,
			implode( ' • ', $details )
		);
	}

	/**
	 * Compare deux jeux d'attributs de déclinaison (clés normalisées, valeurs
	 * en minuscules) : « attribute_type-imprimante » et « type-imprimante »
	 * sont équivalents.
	 *
	 * @param array $line_attrs      Attributs copiés sur la ligne de commande.
	 * @param array $variation_attrs Attributs actuels de la déclinaison.
	 * @return bool
	 */
	private function same_attributes( $line_attrs, $variation_attrs ) {
		$clean = function ( $attrs ) {
			$out = array();
			foreach ( (array) $attrs as $key => $value ) {
				$key         = preg_replace( '/^attribute_/', '', (string) $key );
				$out[ strtolower( $key ) ] = strtolower( trim( (string) $value ) );
			}
			ksort( $out );
			return $out;
		};

		return $clean( $line_attrs ) === $clean( $variation_attrs );
	}

	/**
	 * Résumé des attributs d'une déclinaison (« type-imprimante=Laser, gamme=2019 »).
	 *
	 * @param WC_Product|false $variation Déclinaison.
	 * @return string
	 */
	private function variation_summary( $variation ) {
		if ( ! $variation || ! is_object( $variation ) || ! method_exists( $variation, 'get_attributes' ) ) {
			return '';
		}

		$parts = array();
		foreach ( (array) $variation->get_attributes() as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$parts[] = preg_replace( '/^attribute_/', '', (string) $key ) . '=' . $value;
		}

		return implode( ', ', $parts );
	}

	/**
	 * Aperçu en lecture seule de la correspondance article → produit Dolibarr,
	 * affiché AVANT toute création : permet de repérer une incohérence
	 * (repli sur le parent, ou article non relié).
	 *
	 * @param WC_Order $order Commande.
	 */
	private function render_mapping_preview( $order ) {
		if ( '' === (string) DIWC_Settings::instance()->get( 'url' ) ) {
			return;
		}

		try {
			$rows = $this->map_order_items( $order );
		} catch ( \Throwable $e ) {
			// Ne jamais casser la page de commande : on journalise et on sort.
			DIWC_Log::add( 'Aperçu des articles indisponible (commande #' . $order->get_order_number() . ') : ' . $e->getMessage() );
			return;
		}

		if ( empty( $rows ) ) {
			return;
		}

		echo '<hr />';
		echo '<p><strong>' . esc_html__( 'Aperçu des articles (WooCommerce → produits Dolibarr)', 'diwc' ) . '</strong></p>';
		echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Article WooCommerce', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'IDs Woo (ligne)', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'Attributs de la ligne', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'Produit Dolibarr', 'diwc' ) . '</th>';
		echo '<th>' . esc_html__( 'Remarque', 'diwc' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$ids = array();
			if ( $row['variation_id'] > 0 ) {
				$ids[] = __( 'déclinaison', 'diwc' ) . ' ' . (int) $row['variation_id'];
			}
			if ( $row['parent_id'] > 0 ) {
				$ids[] = __( 'parent', 'diwc' ) . ' ' . (int) $row['parent_id'];
			}

			$fallback = ( null !== $row['product'] && $row['variation_id'] > 0 && (int) $row['used_id'] === (int) $row['parent_id'] );
			$broken   = ( '' !== (string) $row['wc_warning'] );

			if ( $broken ) {
				// Incohérence dans WooCommerce lui-même : la ligne de commande
				// porte une déclinaison qui n'est pas celle du choix du client
				// (ou qui appartient à un autre produit).
				$remark = '⚠ ' . (string) $row['wc_warning'];
				if ( (int) $row['expected_id'] > 0 ) {
					$remark .= ' ' . sprintf(
						/* translators: %d : ID de déclinaison attendu. */
						__( '→ ID attendu : %d (à corriger côté WooCommerce).', 'diwc' ),
						(int) $row['expected_id']
					);
				}
			} elseif ( null === $row['product'] ) {
				$remark = __( 'aucune correspondance : ligne libre', 'diwc' );
			} elseif ( $fallback ) {
				$remark = __( '⚠ repli sur le PARENT (déclinaison non reliée)', 'diwc' );
			} else {
				$remark = sprintf(
					/* translators: %d : ID WooCommerce utilisé pour la correspondance. */
					__( 'via l\'ID %d', 'diwc' ),
					(int) $row['used_id']
				);
			}

			echo '<tr' . ( ( null === $row['product'] || $fallback || $broken ) ? ' style="background:#fff8e5;"' : '' ) . '>';
			echo '<td>' . esc_html( (string) $row['label'] ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $ids ) ) . '</td>';
			echo '<td>' . ( '' !== (string) $row['attrs'] ? esc_html( (string) $row['attrs'] ) : '<span class="description">—</span>' ) . '</td>';
			echo '<td>' . ( $row['product']
				? esc_html( (string) $row['product']['ref'] . ' — ' . (string) $row['product']['label'] . ' (id ' . (int) $row['product']['id'] . ')' )
				: '<span class="description">—</span>' ) . '</td>';
			echo '<td>' . esc_html( $remark ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__(
			'Vérifiez cette correspondance : une « incohérence WooCommerce » signifie que la déclinaison enregistrée sur la ligne de commande appartient à un AUTRE produit du catalogue (déclinaison supprimée/recréée, produit converti, import) — c\'est la donnée WooCommerce elle-même qu\'il faut corriger (réajouter la bonne déclinaison sur la ligne de commande). Un repli sur le produit parent ou une ligne libre signale, lui, un extrafield manquant ou modifié dans Dolibarr.',
			'diwc'
		) . '</p>';
	}

	/**
	 * Construit le corps de la requête de création : tiers (par e-mail),
	 * lignes produits mappées via l'extrafield, lignes libres pour les
	 * articles non retrouvés, et frais de port en ligne libre.
	 *
	 * @param WC_Order $order Commande.
	 * @return array|WP_Error array( 'payload' => …, 'free' => […], 'missing' => […] ).
	 */
	private function build_payload( $order ) {
		$email = (string) $order->get_billing_email();
		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error( 'diwc_tp', __( 'Aucun e-mail client sur cette commande : impossible de retrouver le compte Dolibarr.', 'diwc' ) );
		}

		$tp = DIWC_API::instance()->search_thirdparty_by_email( $email );
		if ( ! $tp ) {
			return new WP_Error(
				'diwc_tp',
				sprintf(
					/* translators: %s : e-mail du client. */
					__( 'Aucun compte client Dolibarr trouvé pour %s (e-mail). Vérifiez l\'e-mail du tiers dans Dolibarr et les droits de la clé API (détails dans le Journal). Vous pouvez aussi créer le tiers depuis l\'encart « Coordonnées de facturation » de la commande.', 'diwc' ),
					$email
				)
			);
		}
		$socid = (int) $tp['id'];

		$extrafield = (string) DIWC_Settings::instance()->get( 'product_extrafield' );
		$lines      = array();
		$free       = array();
		$missing    = array();
		$unmapped   = array(); // Articles sans produit Dolibarr (création refusée).
		$scheduled  = false; // Une resynchro du mapping a-t-elle été planifiée ?

		// Réglage : refuse-t-on la création quand un article n'a pas de produit
		// Dolibarr correspondant ? (Par défaut oui : on ne fabrique pas de
		// document comptable avec un article générique.)
		$block_unmapped = ( 'no' !== (string) DIWC_Settings::instance()->get( 'block_unmapped_products' ) );

		// 1) Articles produits de la commande.
		try {
			$item_rows = $this->map_order_items( $order );
		} catch ( \Throwable $e ) {
			DIWC_Log::add( 'Correspondance des articles impossible (commande #' . $order->get_order_number() . ') : ' . $e->getMessage() );
			return new WP_Error(
				'diwc_mapping',
				__( 'Erreur lors de la correspondance des articles : ', 'diwc' ) . $e->getMessage()
			);
		}

		foreach ( $item_rows as $row ) {
			$qty   = (float) $row['qty'];
			$tva_tx = round( ( $row['total_tax'] / $row['total_ht'] ) * 100, 2 );
			$price  = round( $row['total_ht'] / $qty, 4 );
			$label  = (string) $row['label'];
			$found  = $row['product'];

			$line = array(
				'qty'             => $qty,
				'subprice'        => $price,
				'tva_tx'          => $tva_tx,
				'price_base_type' => 'HT',
				'desc'            => $label,
			);

			if ( $found ) {
				$line['fk_product']   = (int) $found['id'];
				$line['product_type'] = (int) $found['type'];

				DIWC_Log::add(
					'Article « ' . $label . ' » → produit Dolibarr ' . (string) $found['ref'] . ' (id ' . (int) $found['id']
					. ') via l\'ID Woo ' . (int) $row['used_id'] . '.'
				);

				// Repli sur le parent alors que l'article est une déclinaison :
				// c'est la cause classique d'un produit incohérent dans Dolibarr.
				if ( $row['variation_id'] > 0 && (int) $row['used_id'] === (int) $row['parent_id'] ) {
					DIWC_Log::add(
						'⚠ « ' . $label . ' » : aucune correspondance pour la DÉCLINAISON Woo ' . (int) $row['variation_id']
						. ' — repli sur le produit parent ' . (int) $row['parent_id'] . ' (produit Dolibarr '
						. (string) $found['ref'] . '). Vérifiez l\'extrafield du produit correspondant à la déclinaison.'
					);
				}
			} else {
				// Le mapping est peut-être simplement en retard (produit créé
				// depuis la dernière synchro) : on relance une synchronisation
				// dans tous les cas (au plus 1 fois / 10 min).
				DIWC_API::instance()->schedule_product_sync( $extrafield, true );
				$scheduled = true;

				if ( $block_unmapped ) {
					// Refus : aucune commande ne sera créée (voir plus bas).
					$unmapped[] = $row;
					DIWC_Log::add(
						'CRÉATION REFUSÉE — aucun produit Dolibarr pour « ' . $label . ' » (IDs Woo essayés : '
						. implode( ', ', $row['tried'] ) . ' — extrafield ' . $extrafield . ').'
					);
					continue;
				}

				$line['product_type'] = 0; // Ligne libre (sans produit).
				$free[]               = $label;
				$missing[]            = $label;
				DIWC_Log::add(
					'Produit Dolibarr non trouvé pour « ' . $label . ' » (IDs Woo essayés : ' . implode( ', ', $row['tried'] )
					. ' — extrafield ' . $extrafield . ') : article ajouté en ligne libre.'
				);
			}

			$lines[] = $line;

			if ( '' !== (string) $row['wc_warning'] ) {
				DIWC_Log::add(
					'⚠ Commande #' . $order->get_order_number() . ' — « ' . $label . ' » : ' . (string) $row['wc_warning'] . '.'
				);
			}
		}

		// Création refusée : au moins un article n'a pas de produit Dolibarr.
		// Aucune ligne libre n'est fabriquée, aucun envoi n'est tenté.
		if ( ! empty( $unmapped ) ) {
			return new WP_Error( 'diwc_unmapped', $this->unmapped_message( $unmapped, $extrafield ) );
		}

		// 2) Frais de port en ligne libre.
		foreach ( $order->get_items( 'shipping' ) as $ship ) {
			$total_ht  = (float) $ship->get_total();
			$total_tax = (float) $ship->get_total_tax();
			if ( $total_ht <= 0 ) {
				continue;
			}

			$tva_tx = round( ( $total_tax / $total_ht ) * 100, 2 );
			$label  = (string) $ship->get_method_title();
			if ( '' === $label ) {
				$label = __( 'Expédition', 'diwc' );
			}

			$lines[] = array(
				'qty'             => 1,
				'subprice'        => round( $total_ht, 4 ),
				'tva_tx'          => $tva_tx,
				'price_base_type' => 'HT',
				'product_type'    => 0, // Ligne libre (frais de port).
				'desc'            => $label,
			);
		}

		DIWC_Log::add(
			'Préparation de la commande brouillon (commande WooCommerce #' . $order->get_order_number() . ') : '
			. count( $lines ) . ' ligne(s) — ' . ( count( $lines ) - count( $free ) ) . ' reliée(s) à un produit Dolibarr, '
			. count( $free ) . ' en ligne libre.'
		);

		if ( empty( $lines ) ) {
			return new WP_Error( 'diwc_lines', __( 'Aucune ligne à reporter (articles et frais de port à zéro).', 'diwc' ) );
		}

		$created = $order->get_date_created();

		$payload = array(
			'socid'      => $socid,
			'date'       => $created ? (int) $created->getTimestamp() : time(),
			'ref_client' => (string) $order->get_order_number(),
			'lines'      => $lines,
		);

		// Adresse de livraison distincte de la facturation : on prépare
		// l'association du CONTACT qui porte cette adresse (fk_delivery_address),
		// et l'écriture directe de l'adresse en repli.
		$delivery_contact = 0;
		$delivery_address = array();
		$shipping         = $this->shipping_address_values( $order );

		if ( $this->shipping_differs_from_billing( $order ) ) {
			$delivery_contact = $this->delivery_contact_id( $order, $socid );

			if ( $delivery_contact > 0 ) {
				$payload['fk_delivery_address'] = $delivery_contact;
			}

			if ( '' !== trim( (string) $shipping['address'] ) ) {
				$delivery_address['address'] = (string) $shipping['address'];
			}
			if ( '' !== trim( (string) $shipping['zip'] ) ) {
				$delivery_address['zip'] = (string) $shipping['zip'];
			}
			if ( '' !== trim( (string) $shipping['town'] ) ) {
				$delivery_address['town'] = (string) $shipping['town'];
			}
			if ( '' !== (string) $shipping['country'] ) {
				$cid = DIWC_API::instance()->country_id_from_code( (string) $shipping['country'] );
				if ( $cid > 0 ) {
					$delivery_address['country_id'] = $cid;
				}
			}

			if ( 0 === $delivery_contact ) {
				// Aucun contact ne porte cette adresse : on l'écrit sur la commande.
				$payload = array_merge( $payload, $delivery_address );
				DIWC_Log::add(
					'Commande brouillon (commande WooCommerce #' . $order->get_order_number()
					. ') : aucun contact Dolibarr ne porte l\'adresse de livraison — adresse écrite directement sur la commande.'
				);
			}
		}

		return array(
			'payload'          => $payload,
			'free'             => $free,
			'missing'          => $missing,
			'scheduled'        => $scheduled,
			'delivery_contact' => $delivery_contact,
			'delivery_address' => $delivery_address,
		);
	}

	/**
	 * Associe effectivement le contact de livraison à la commande créée via la
	 * route dédiée de l'API (POST /orders/{id}/contact/{contactid}/{type}), en
	 * vérifiant le résultat sur la commande (fk_delivery_address). Si
	 * l'association échoue, l'adresse de livraison est écrite directement sur la
	 * commande pour ne rien perdre.
	 *
	 * @param WC_Order $order      Commande WooCommerce.
	 * @param int      $dolibarr_id ID de la commande Dolibarr.
	 * @param int      $contact_id  ID du contact de livraison.
	 * @param array    $address     Adresse de livraison (repli).
	 */
	private function apply_delivery_contact( $order, $dolibarr_id, $contact_id, $address ) {
		foreach ( array( 'DELIVERY', 'SHIPPING' ) as $type ) {
			$res = DIWC_API::instance()->set_order_contact( $dolibarr_id, $contact_id, $type );

			if ( is_wp_error( $res ) ) {
				DIWC_Log::add(
					'Association du contact ' . $contact_id . ' (' . $type . ') à la commande Dolibarr ' . $dolibarr_id
					. ' : ' . $res->get_error_message()
				);
				continue;
			}

			// Vérifie l'effet réel sur la commande.
			$live = DIWC_API::instance()->get_customer_order( $dolibarr_id );
			$fk   = ( is_array( $live ) && isset( $live['fk_delivery_address'] ) ) ? (int) $live['fk_delivery_address'] : 0;

			if ( $fk === (int) $contact_id ) {
				DIWC_Log::add(
					'Contact de livraison ' . $contact_id . ' associé à la commande Dolibarr ' . $dolibarr_id
					. ' (type ' . $type . ', commande WooCommerce #' . $order->get_order_number() . ').'
				);
				return;
			}

			DIWC_Log::add(
				'Association ' . $type . ' du contact ' . $contact_id . ' acceptée par l\'API mais la commande ' . $dolibarr_id
				. ' indique fk_delivery_address = ' . $fk . '.'
			);
		}

		// Repli : écrire l'adresse de livraison directement sur la commande.
		if ( ! empty( $address ) ) {
			$upd = DIWC_API::instance()->update_customer_order( $dolibarr_id, $address );
			DIWC_Log::add(
				is_wp_error( $upd )
					? 'Repli adresse de livraison sur la commande ' . $dolibarr_id . ' impossible : ' . $upd->get_error_message()
					: 'Adresse de livraison écrite directement sur la commande ' . $dolibarr_id . ' (contact non associable).'
			);
		}
	}

	/**
	 * ID du contact Dolibarr à associer comme adresse de livraison de la
	 * commande : contact mémorisé (créé avec l'adresse de livraison) s'il porte
	 * toujours cette adresse, sinon tout contact du tiers dont l'adresse
	 * correspond à celle de la commande WooCommerce.
	 *
	 * @param WC_Order $order Commande.
	 * @param int      $socid ID du tiers Dolibarr.
	 * @return int ID du contact, ou 0 si aucun ne correspond.
	 */
	private function delivery_contact_id( $order, $socid ) {
		$target = $this->normalize_address( $this->shipping_address_values( $order ) );
		if ( '' === $target ) {
			return 0;
		}

		$contacts = DIWC_API::instance()->search_contacts_by_thirdparty( (int) $socid );
		if ( empty( $contacts ) ) {
			return 0;
		}

		$stored = (int) $order->get_meta( self::META_DELIVERY_CONTACT );

		// 1) Contact mémorisé, si son adresse correspond toujours.
		if ( $stored > 0 ) {
			foreach ( $contacts as $contact ) {
				if ( is_array( $contact ) && (int) ( isset( $contact['id'] ) ? $contact['id'] : 0 ) === $stored
					&& $this->normalize_address( $contact ) === $target ) {
					return $stored;
				}
			}
		}

		// 2) Tout contact du tiers portant l'adresse de livraison.
		foreach ( $contacts as $contact ) {
			if ( ! is_array( $contact ) || empty( $contact['id'] ) ) {
				continue;
			}
			if ( $this->normalize_address( $contact ) === $target ) {
				return (int) $contact['id'];
			}
		}

		return 0;
	}

	/**
	 * Adresse de livraison de la commande WooCommerce (repli sur la facturation
	 * si aucune adresse de livraison distincte).
	 *
	 * @param WC_Order $order Commande.
	 * @return array array( 'address', 'zip', 'town', 'country' ).
	 */
	private function shipping_address_values( $order ) {
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
	 * Adresse de facturation de la commande WooCommerce.
	 *
	 * @param WC_Order $order Commande.
	 * @return array array( 'address', 'zip', 'town', 'country' ).
	 */
	private function billing_address_values( $order ) {
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
	 * L'adresse de livraison diffère-t-elle de l'adresse de facturation ?
	 *
	 * @param WC_Order $order Commande.
	 * @return bool
	 */
	private function shipping_differs_from_billing( $order ) {
		return ( $this->normalize_address( $this->shipping_address_values( $order ) )
			!== $this->normalize_address( $this->billing_address_values( $order ) ) );
	}

	/**
	 * Normalise une adresse (commande WooCommerce ou contact Dolibarr) pour
	 * comparaison : texte brut, espaces réduits, minuscules.
	 *
	 * @param array $values Champs address/zip/town + country (ou country_code).
	 * @return string
	 */
	private function normalize_address( $values ) {
		$country = '';
		if ( isset( $values['country_code'] ) && '' !== (string) $values['country_code'] ) {
			$country = (string) $values['country_code'];
		} elseif ( isset( $values['country'] ) ) {
			$country = (string) $values['country'];
		}

		$text = implode(
			' ',
			array(
				isset( $values['address'] ) ? (string) $values['address'] : '',
				isset( $values['zip'] ) ? (string) $values['zip'] : '',
				isset( $values['town'] ) ? (string) $values['town'] : '',
				$country,
			)
		);

		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/**
	 * Libellé du statut d'une commande client Dolibarr.
	 * Statuts Dolibarr : 0 brouillon, 1 validée, 2 en cours, 3 livrée.
	 *
	 * @param array $dolibarr_order Commande retournée par l'API.
	 * @return string
	 */
	private function order_status_label( $dolibarr_order ) {
		$status = -1;
		foreach ( array( 'statut', 'status', 'fk_statut' ) as $key ) {
			if ( isset( $dolibarr_order[ $key ] ) && '' !== (string) $dolibarr_order[ $key ] ) {
				$status = (int) $dolibarr_order[ $key ];
				break;
			}
		}

		switch ( $status ) {
			case 0:
				return __( 'Brouillon', 'diwc' );
			case 1:
				return __( 'Validée', 'diwc' );
			case 2:
				return __( 'En cours', 'diwc' );
			case 3:
				return __( 'Livrée', 'diwc' );
			default:
				/* translators: %d : code de statut Dolibarr. */
				return sprintf( __( 'statut %d', 'diwc' ), $status );
		}
	}

	/**
	 * URL de la fiche commande Dolibarr dans l'interface.
	 * NB : comme la fiche tiers (/societe/card.php), le module « Commande »
	 * (commande/card.php) est servi à la RACINE de Dolibarr, indépendamment du
	 * sous-dossier d'interface des factures (réglage « Chemin de l'interface »,
	 * ex. /compta pour /compta/facture/card.php).
	 *
	 * @param int $order_id ID de la commande Dolibarr.
	 * @return string
	 */
	private function order_card_url( $order_id ) {
		$base = untrailingslashit( (string) DIWC_Settings::instance()->get( 'url' ) );

		return $base . '/commande/card.php?id=' . (int) $order_id;
	}

	/**
	 * URL d'action « créer la commande brouillon » (admin-post + nonce).
	 *
	 * @param int $order_id ID de la commande WooCommerce.
	 * @return string
	 */
	private function create_url( $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'diwc_draft_order_create',
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			'diwc_draft_order_' . $order_id
		);
	}

	/**
	 * URL d'action « ne plus associer » (admin-post + nonce).
	 *
	 * @param int $order_id ID de la commande WooCommerce.
	 * @return string
	 */
	private function unlink_url( $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'diwc_draft_order_unlink',
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			'diwc_draft_order_unlink_' . $order_id
		);
	}

	/**
	 * URL d'action « mémoriser la référence Dolibarr » (admin-post + nonce).
	 *
	 * @param int $order_id ID de la commande WooCommerce.
	 * @return string
	 */
	private function refresh_url( $order_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'diwc_draft_order_refresh',
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			'diwc_draft_order_refresh_' . $order_id
		);
	}

	/**
	 * Redirige vers la fiche commande avec le résultat.
	 *
	 * @param int    $order_id ID de la commande WooCommerce.
	 * @param bool   $ok       Succès ?
	 * @param string $msg      Message.
	 */
	private function redirect( $order_id, $ok, $msg ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'diwc_draft_ok'   => $ok ? '1' : '0',
						'diwc_draft_msg'  => rawurlencode( $msg ),
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
