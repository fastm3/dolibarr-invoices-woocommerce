<?php
/**
 * Client de l'API REST Dolibarr.
 *
 * @package DIWC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Client HTTP minimaliste pour l'API REST de Dolibarr
 * (endpoints /api/index.php/...).
 */
class DIWC_API {

	/**
	 * Instance unique.
	 *
	 * @var DIWC_API|null
	 */
	private static $instance = null;

	/**
	 * Retourne l'instance unique.
	 *
	 * @return DIWC_API
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
		// Synchronisation du catalogue produits (mapping extrafield) : bouton
		// admin, relance à la demande, et rafraîchissement quotidien via WP-Cron.
		add_action( 'admin_post_diwc_sync_catalog', array( $this, 'handle_sync_catalog_manual' ) );
		add_action( 'diwc_sync_catalog', array( $this, 'handle_sync_catalog_cron' ), 10, 1 );
		add_action( 'diwc_sync_catalog_daily', array( $this, 'handle_sync_catalog_daily' ) );
		add_action( 'init', array( $this, 'maybe_schedule_daily_sync' ) );
	}

	/**
	 * URL de base de Dolibarr, sans slash final.
	 *
	 * @return string
	 */
	private function base_url() {
		return untrailingslashit( (string) DIWC_Settings::instance()->get( 'url' ) );
	}

	/**
	 * Clé API Dolibarr.
	 *
	 * @return string
	 */
	private function api_key() {
		return (string) DIWC_Settings::instance()->get( 'api_key' );
	}

	/**
	 * En-têtes d'authentification et de contenu.
	 *
	 * @return array
	 */
	private function headers() {
		return array(
			'DOLAPIKEY'   => $this->api_key(),
			'X-DOLAPIKEY' => $this->api_key(),
			'Accept'      => 'application/json',
		);
	}

	/**
	 * Effectue une requête vers l'API Dolibarr.
	 *
	 * @param string $method Méthode HTTP (GET, POST, ...).
	 * @param string $path   Chemin après /api/index.php (ex. '/invoices/12').
	 * @param array  $args   Arguments wp_remote_request supplémentaires.
	 * @return mixed|WP_Error Données JSON décodées, ou WP_Error en cas d'échec.
	 */
	public function request( $method, $path, $args = array() ) {
		$url  = $this->base_url() . '/api/index.php' . $path;
		$args = wp_parse_args(
			$args,
			array(
				'method'    => $method,
				'timeout'   => 45,
				'headers'   => $this->headers(),
				'sslverify' => apply_filters( 'diwc_sslverify', true ),
			)
		);

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'diwc_http', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code >= 400 ) {
			$detail = is_array( $data ) ? wp_json_encode( $data ) : wp_trim_words( (string) $body, 20 );
			// Message volontairement sans parenthèses pour rester compatible
			// avec le contrôle de syntaxe (aucune contrainte réelle ici).
			return new WP_Error( 'diwc_api', sprintf( 'Erreur API Dolibarr HTTP %d : %s', $code, $detail ) );
		}

		return $data;
	}

	/**
	 * Teste la connexion à l'API : interroge la liste des factures (limitée à 1).
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( '' === $this->base_url() || '' === $this->api_key() ) {
			return new WP_Error( 'diwc_config', __( 'L\'URL de Dolibarr et la clé API sont obligatoires.', 'diwc' ) );
		}
		$res = $this->request( 'GET', '/invoices?limit=1' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return true;
	}

	/**
	 * Recherche les factures Dolibarr dont le champ « Réf. client » (ref_client)
	 * correspond à la référence passée (numéro de commande WooCommerce).
	 *
	 * @param string $ref Référence client à chercher.
	 * @return array Liste des factures affichables (statut 1 ou 2).
	 */
	public function search_invoices_by_ref_client( $ref ) {
		$ref = preg_replace( "/[:\\(\\)'\"]/", '', (string) $ref );
		if ( '' === $ref ) {
			return array();
		}

		// Valeur texte entre guillemets : sans eux, Dolibarr l'interprète
		// comme un nom de colonne SQL (« Unknown column ... in 'WHERE' »).
		$filter = rawurlencode( "(ref_client:=:'" . $ref . "')" );
		$res    = $this->request(
			'GET',
			'/invoices?sqlfilters=' . $filter . '&limit=20&sortfield=t.rowid&sortorder=DESC'
		);

		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return array();
		}

		return array_values( array_filter( $res, array( $this, 'is_displayable_invoice' ) ) );
	}

	/**
	 * Recherche un tiers Dolibarr (compte client) par son e-mail.
	 *
	 * @param string $email Adresse e-mail du client.
	 * @return array|null Tiers trouvé (id, name...), ou null.
	 */
	public function search_thirdparty_by_email( $email ) {
		$email = preg_replace( "/[:\\(\\)'\"]/", '', (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

		// Valeur texte entre guillemets (cf. « Unknown column » sinon).
		$filter = rawurlencode( "(email:=:'" . $email . "')" );
		$res    = $this->request(
			'GET',
			'/thirdparties?sqlfilters=' . $filter . '&limit=5&sortfield=t.rowid&sortorder=DESC'
		);

		if ( is_wp_error( $res ) ) {
			DIWC_Log::add( 'Recherche tiers par e-mail (' . $email . ') : ' . $res->get_error_message() );
			return null;
		}
		if ( ! is_array( $res ) ) {
			return null;
		}

		foreach ( $res as $tp ) {
			if ( is_array( $tp ) && ! empty( $tp['id'] ) ) {
				return $tp;
			}
		}

		return null;
	}

	/**
	 * Récupère une facture par son ID.
	 *
	 * @param int $id ID de la facture Dolibarr.
	 * @return array|null Facture affichable, ou null.
	 */
	public function get_invoice( $id ) {
		$res = $this->request( 'GET', '/invoices/' . (int) $id );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return null;
		}
		return $this->is_displayable_invoice( $res ) ? $res : null;
	}

	/**
	 * Une facture est affichable si elle existe et n'est ni un brouillon (0)
	 * ni annulée (3). Statuts Dolibarr : 0 brouillon, 1 validée, 2 payée, 3 annulée.
	 *
	 * @param mixed $invoice Facture retournée par l'API.
	 * @return bool
	 */
	public function is_displayable_invoice( $invoice ) {
		if ( ! is_array( $invoice ) || empty( $invoice['id'] ) ) {
			return false;
		}
		$status = isset( $invoice['status'] ) ? (int) $invoice['status'] : -1;
		return in_array( $status, array( 1, 2 ), true );
	}

	/**
	 * Retourne la liste des chemins de documents PDF attachés à une facture.
	 *
	 * @param int $id ID de la facture.
	 * @return array Chemins de fichiers (original_file / filename).
	 */
	public function get_invoice_documents( $id ) {
		$files = array();

		// 1) Endpoint dédié par objet (absent selon les versions de Dolibarr).
		$res = $this->request( 'GET', '/invoices/' . (int) $id . '/documents' );
		if ( is_wp_error( $res ) ) {
			DIWC_Log::add( 'Endpoint /invoices/' . (int) $id . '/documents indisponible : ' . $res->get_error_message() );
		} else {
			$files = $this->parse_documents_response( $res );
		}

		// 2) Endpoint générique /documents — moduleparts API anglais puis français.
		if ( empty( $files ) ) {
			foreach ( array( 'invoice', 'facture' ) as $modulepart ) {
				$res = $this->request( 'GET', '/documents?modulepart=' . $modulepart . '&id=' . (int) $id . '&limit=100' );
				if ( is_wp_error( $res ) ) {
					DIWC_Log::add( '/documents modulepart=' . $modulepart . ' id=' . (int) $id . ' : ' . $res->get_error_message() );
					continue;
				}
				$files = $this->parse_documents_response( $res );
				if ( ! empty( $files ) ) {
					DIWC_Log::add( 'Pièces trouvées via /documents modulepart=' . $modulepart . ' id=' . (int) $id . ' : ' . implode( ', ', $files ) );
					break;
				}
			}
		}

		if ( empty( $files ) ) {
			DIWC_Log::add( 'Aucune pièce jointe PDF trouvée pour la facture ' . (int) $id );
		}

		return $files;
	}

	/**
	 * Extrait la liste des chemins de fichiers d'une réponse de l'API
	 * (endpoint dédié ou générique /documents).
	 *
	 * @param mixed $res Réponse JSON décodée.
	 * @return array
	 */
	private function parse_documents_response( $res ) {
		if ( is_array( $res ) && isset( $res['files'] ) && is_array( $res['files'] ) ) {
			$res = $res['files'];
		}
		if ( ! is_array( $res ) ) {
			return array();
		}

		$files = array();
		foreach ( $res as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}
			$file = '';
			foreach ( array( 'original_file', 'filename', 'path', 'name', 'file' ) as $key ) {
				if ( ! empty( $doc[ $key ] ) && is_string( $doc[ $key ] ) ) {
					$file = $doc[ $key ];
					break;
				}
			}
			if ( '' !== $file ) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * Récupère le contenu du PDF d'une facture directement depuis la réponse
	 * de /documents, quand Dolibarr l'embarque en base64 (champ « content »).
	 * Comportement des versions récentes (ex. 19+ / 23.x), qui ne servent plus
	 * le binaire via /documents/download.
	 *
	 * @param int $id ID de la facture.
	 * @return string|null Contenu binaire du PDF, ou null.
	 */
	public function get_invoice_pdf_content( $id ) {
		foreach ( array( 'invoice', 'facture' ) as $modulepart ) {
			$res = $this->request( 'GET', '/documents?modulepart=' . $modulepart . '&id=' . (int) $id . '&limit=100' );
			if ( is_wp_error( $res ) ) {
				continue;
			}
			$pdf = $this->extract_pdf_from_response( $res );
			if ( null !== $pdf ) {
				DIWC_Log::add( 'PDF récupéré via /documents (modulepart=' . $modulepart . ', ' . strlen( $pdf ) . ' octets)' );
				return $pdf;
			}
		}
		return null;
	}

	/**
	 * Extrait un PDF (base64) d'une réponse de l'API /documents.
	 * Gère une réponse objet unique ou une liste (clé « files » ou tableau).
	 *
	 * @param mixed $res Réponse JSON décodée.
	 * @return string|null Contenu binaire du PDF, ou null.
	 */
	private function extract_pdf_from_response( $res ) {
		$list = $res;
		if ( is_array( $res ) && isset( $res['files'] ) && is_array( $res['files'] ) ) {
			$list = $res['files'];
		}
		if ( is_array( $list ) && ! isset( $list[0] ) && isset( $list['content'] ) ) {
			// Réponse objet unique.
			$list = array( $list );
		}
		if ( ! is_array( $list ) ) {
			return null;
		}

		foreach ( $list as $doc ) {
			if ( ! is_array( $doc ) || empty( $doc['content'] ) || ! is_string( $doc['content'] ) ) {
				continue;
			}
			$decoded = base64_decode( preg_replace( '/\s+/', '', $doc['content'] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false !== $decoded && false !== strpos( substr( $decoded, 0, 1024 ), '%PDF-' ) ) {
				return $decoded;
			}
		}

		return null;
	}

	/**
	 * Détermine les chemins PDF candidats d'une facture : liste de l'API, puis
	 * nommage par défaut de Dolibarr ({réf}/{rér}.pdf) ajouté en secours.
	 *
	 * @param int $id ID de la facture.
	 * @return array Liste des chemins PDF candidats (vide si introuvable).
	 */
	private function get_invoice_pdf_file( $id ) {
		$files = $this->get_invoice_documents( $id );

		$invoice = $this->get_invoice( $id );
		$ref     = ( $invoice && isset( $invoice['ref'] ) ) ? (string) $invoice['ref'] : '';
		if ( '' !== $ref ) {
			$default = $ref . '/' . $ref . '.pdf';
			if ( ! in_array( $default, $files, true ) ) {
				$files[] = $default;
			}
		}

		return $files;
	}

	/**
	 * URL de téléchargement d'un PDF de facture via l'API.
	 *
	 * @param string $modulepart Modulepart API (invoice ou facture).
	 * @param string $file       Chemin relatif du PDF.
	 * @return string
	 */
	private function documents_download_url( $modulepart, $file ) {
		$args = array(
			'modulepart'    => $modulepart,
			'original_file' => $file,
			'file'          => $file, // Paramètre historique de Dolibarr.
		);

		// Multi-sociétés : forcer l'entité si le filtre est utilisé.
		$entity = (int) apply_filters( 'diwc_entity', 0 );
		if ( $entity > 0 ) {
			$args['entity'] = $entity;
		}

		return add_query_arg( $args, $this->base_url() . '/api/index.php/documents/download' );
	}

	/**
	 * Tente un téléchargement de PDF pour un modulepart et un chemin donnés.
	 *
	 * @param string $modulepart Modulepart API.
	 * @param string $file       Chemin relatif du PDF.
	 * @return array|WP_Error array( 'body', 'content_type' ) ou erreur.
	 */
	private function try_download( $modulepart, $file ) {
		$url = $this->documents_download_url( $modulepart, $file );
		return $this->try_download_url( $url, 'modulepart=' . $modulepart . ' file=' . $file );
	}

	/**
	 * Télécharge une URL et vérifie que le contenu est bien un PDF.
	 *
	 * @param string $url   URL à télécharger.
	 * @param string $label Libellé de diagnostic.
	 * @return array|WP_Error array( 'body', 'content_type' ) ou erreur.
	 */
	private function try_download_url( $url, $label ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 90,
				'headers'     => $this->headers(),
				'sslverify'   => apply_filters( 'diwc_sslverify', true ),
				'redirection' => 5,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			DIWC_Log::add( 'Téléchargement ' . $label . ' → HTTP ' . $code . ' : ' . wp_trim_words( (string) wp_remote_retrieve_body( $response ), 15 ) );
			return new WP_Error( 'diwc_api', sprintf( 'Erreur API Dolibarr HTTP %d (%s) lors du téléchargement du PDF.', $code, $label ) );
		}

		$body         = (string) wp_remote_retrieve_body( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		if ( '' === $body ) {
			DIWC_Log::add( 'Réponse vide (' . $label . ')' );
			return new WP_Error( 'diwc_empty', __( 'Réponse vide de Dolibarr lors du téléchargement du PDF.', 'diwc' ) );
		}

		// Vérifie que le contenu est bien un PDF (Dolibarr renvoie parfois un
		// code 200 avec un descripteur JSON ou une page d'erreur HTML).
		if ( false === strpos( substr( $body, 0, 1024 ), '%PDF-' ) ) {
			// Dolibarr récents : le PDF peut être embarqué en base64 dans le
			// JSON (champ « content », « encoding »: "base64") — le décoder
			// et le considérer comme un succès.
			if ( false !== stripos( $content_type, 'json' ) ) {
				$data = json_decode( $body, true );
				if ( is_array( $data ) && isset( $data['content'] ) && is_string( $data['content'] ) ) {
					$decoded = base64_decode( preg_replace( '/\s+/', '', $data['content'] ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					if ( false !== $decoded && false !== strpos( substr( $decoded, 0, 1024 ), '%PDF-' ) ) {
						DIWC_Log::add( 'PDF embarqué (base64) décodé depuis ' . $label . ' — ' . strlen( $decoded ) . ' octets' );
						return array(
							'body'         => $decoded,
							'content_type' => 'application/pdf',
						);
					}
				}
			}

			$extra = '';
			if ( false !== stripos( $content_type, 'json' ) ) {
				$data = json_decode( $body, true );
				if ( is_array( $data ) ) {
					$extra = ' JSON clés=' . implode( ',', array_keys( $data ) );
					if ( isset( $data['content'] ) && is_string( $data['content'] ) ) {
						$extra .= ' content_base64=' . strlen( $data['content'] ) . ' caractères';
					}
				} else {
					$extra = ' corps : ' . wp_trim_words( $body, 10 );
				}
			}
			DIWC_Log::add( 'Réponse non-PDF (' . $label . ', type ' . $content_type . ', début : ' . bin2hex( substr( $body, 0, 40 ) ) . $extra . ')' );
			return new WP_Error( 'diwc_notpdf', sprintf( __( 'La réponse de Dolibarr n\'est pas un PDF (type %s).', 'diwc' ), $content_type ) );
		}

		return array(
			'body'         => $body,
			'content_type' => $content_type,
		);
	}

	/**
	 * Télécharge le PDF d'une facture via l'API et retourne son contenu.
	 * (Chargé en mémoire : les PDF de factures sont de petite taille.)
	 *
	 * @param int $id ID de la facture.
	 * @return string|WP_Error Contenu binaire du PDF, ou erreur.
	 */
	public function download_invoice_pdf( $id ) {
		// 0) Contenu PDF directement embarqué (base64) dans la réponse de
		//    /documents — comportement des versions récentes de Dolibarr.
		$content = $this->get_invoice_pdf_content( $id );
		if ( null !== $content ) {
			return $content;
		}

		$candidates = $this->get_invoice_pdf_file( $id );
		if ( empty( $candidates ) ) {
			return new WP_Error( 'diwc_nodoc', __( 'Aucun PDF de facture trouvé côté Dolibarr. Générez-le dans Dolibarr (bouton « Générer le PDF »).', 'diwc' ) );
		}

		$last_error = null;

		// 1) URL directe fournie par Dolibarr (DIRECTDOWNLOAD_URL_INVOICE).
		foreach ( $this->get_invoice_direct_url( $id ) as $direct_url ) {
			$result = $this->try_download_url( $direct_url, 'URL directe' );
			if ( ! is_wp_error( $result ) ) {
				return $result['body'];
			}
			$last_error = $result;
		}

		// 2) Chemins candidats × moduleparts API.
		foreach ( $candidates as $file ) {
			foreach ( array( 'invoice', 'facture' ) as $modulepart ) {
				$result = $this->try_download( $modulepart, $file );
				if ( ! is_wp_error( $result ) ) {
					return $result['body'];
				}
				$last_error = $result;
			}
		}

		return ( $last_error instanceof WP_Error )
			? $last_error
			: new WP_Error( 'diwc_nodoc', __( 'Aucun PDF de facture téléchargeable.', 'diwc' ) );
	}

	/**
	 * Retourne les URL directes de téléchargement du PDF d'une facture,
	 * exposées par Dolibarr via les options de l'objet facture
	 * (DIRECTDOWNLOAD_URL_INVOICE, versions récentes).
	 *
	 * @param int $id ID de la facture.
	 * @return array
	 */
	private function get_invoice_direct_url( $id ) {
		$invoice = $this->get_invoice( $id );
		if ( ! $invoice ) {
			return array();
		}

		$candidates = array();

		// Champ dans array_options (format habituel de l'API).
		if ( isset( $invoice['array_options'] ) && is_array( $invoice['array_options'] ) ) {
			foreach ( $invoice['array_options'] as $key => $value ) {
				if ( is_string( $value ) && false !== stripos( (string) $key, 'directdownload_url_invoice' ) && '' !== trim( $value ) ) {
					$candidates[] = $value;
				}
			}
		}

		// Champ directement sur l'objet (selon les versions).
		foreach ( array( 'directdownload_url_invoice', 'DIRECTDOWNLOAD_URL_INVOICE' ) as $key ) {
			if ( isset( $invoice[ $key ] ) && is_string( $invoice[ $key ] ) && '' !== trim( $invoice[ $key ] ) ) {
				$candidates[] = $invoice[ $key ];
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Test complet du parcours de téléchargement : API, liste des pièces,
	 * puis téléchargement du PDF de la dernière facture. Sert de diagnostic
	 * quand le client obtient une erreur 502.
	 *
	 * @return array|WP_Error Résultat (octets, type MIME, référence) ou erreur.
	 */
	public function test_download() {
		// 1) API : dernière facture affichable.
		$res = $this->request( 'GET', '/invoices?limit=1&sortfield=t.rowid&sortorder=DESC' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! is_array( $res ) || empty( $res ) ) {
			return new WP_Error( 'diwc_noinvoice', __( 'Aucune facture trouvée dans Dolibarr pour ce test.', 'diwc' ) );
		}
		$invoice = $res[0];

		// 2) Pièces jointes (avec repli sur le nommage par défaut).
		$files = $this->get_invoice_pdf_file( $invoice['id'] );
		if ( empty( $files ) ) {
			return new WP_Error(
				'diwc_nodoc',
				sprintf(
					/* translators: %s : référence de la facture. */
					__( 'La facture %s n\'a pas de PDF : générez-le dans Dolibarr (bouton « Générer le PDF »).', 'diwc' ),
					isset( $invoice['ref'] ) ? $invoice['ref'] : (string) $invoice['id']
				)
			);
		}

		// 3) Téléchargement réel du PDF : contenu embarqué (base64), URL
		//    directe, puis tous chemins × moduleparts.
		$downloaded = null;
		$last_error = null;

		$inline = $this->get_invoice_pdf_content( $invoice['id'] );
		if ( null !== $inline ) {
			$downloaded = array(
				'body'         => $inline,
				'content_type' => 'application/pdf',
			);
		}

		if ( null === $downloaded ) {
			foreach ( $this->get_invoice_direct_url( $invoice['id'] ) as $direct_url ) {
				$result = $this->try_download_url( $direct_url, 'URL directe' );
				if ( ! is_wp_error( $result ) ) {
					$downloaded = $result;
					break;
				}
				$last_error = $result;
			}
		}

		if ( null === $downloaded ) {
			foreach ( $files as $file ) {
				foreach ( array( 'invoice', 'facture' ) as $modulepart ) {
					$result = $this->try_download( $modulepart, $file );
					if ( ! is_wp_error( $result ) ) {
						$downloaded = $result;
						break 2;
					}
					$last_error = $result;
				}
			}
		}

		if ( null === $downloaded ) {
			return ( $last_error instanceof WP_Error )
				? $last_error
				: new WP_Error( 'diwc_nodoc', __( 'Aucun PDF de facture téléchargeable.', 'diwc' ) );
		}

		return array(
			'ref'          => isset( $invoice['ref'] ) ? $invoice['ref'] : '',
			'bytes'        => strlen( (string) $downloaded['body'] ),
			'content_type' => (string) $downloaded['content_type'],
		);
	}

	/**
	 * Recherche un produit Dolibarr dont l'extrafield correspond à une valeur
	 * (ex. extrafield « id_du_produit_dans_woocommerce » = ID produit WooCommerce).
	 *
	 * Stratégie (aucun scan lourd ici — le clic reste instantané) :
	 * 1. filtre natif (t.custom_<champ>:=:'valeur'), si supporté par la version ;
	 * 2. sinon, lecture du MAPPING synchronisé en arrière-plan (sync_product_map()).
	 *
	 * @param string $field Nom technique de l'extrafield.
	 * @param mixed  $value Valeur attendue.
	 * @return array|null array( 'id', 'ref', 'label', 'type' ) ou null.
	 */
	public function search_product_by_extrafield( $field, $value ) {
		$field = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $field );
		if ( '' === $field || '' === (string) $value ) {
			return null;
		}

		$value_key = (string) $value;
		$flag_key  = 'diwc_efnosql_' . md5( $field );

		// 1) Filtre SQL natif sur l'extrafield (t.custom_<champ>), si supporté.
		if ( false === get_transient( $flag_key ) ) {
			$filter = rawurlencode( "(t.custom_" . $field . ":=:'" . $value_key . "')" );
			$res    = $this->request(
				'GET',
				'/products?sqlfilters=' . $filter . '&limit=5&sortfield=t.rowid&sortorder=ASC'
			);

			if ( is_wp_error( $res ) ) {
				$msg = (string) $res->get_error_message();
				if ( false !== strpos( $msg, 'custom_' . $field ) || false !== strpos( $msg, 'Unknown column' ) ) {
					// Dolibarr sans filtrage des extrafields dans la liste /products :
					// on bascule sur le mapping synchronisé en arrière-plan.
					set_transient( $flag_key, 1, DAY_IN_SECONDS );
					DIWC_Log::add( 'Filtrage des produits par extrafield non supporté par ce Dolibarr — utilisation du mapping synchronisé (extrafield ' . $field . ').' );
				} else {
					DIWC_Log::add( 'Recherche produit extrafield ' . $field . '=' . $value_key . ' : ' . $msg );
				}
			} elseif ( is_array( $res ) ) {
				foreach ( $res as $product ) {
					if ( is_array( $product ) && ! empty( $product['id'] ) ) {
						return $this->compact_product( $product );
					}
				}
				// Filtre fonctionnel, mais aucune correspondance : inutile d'aller plus loin.
				return null;
			}
		}

		// 2) Mapping synchronisé en arrière-plan (lecture instantanée).
		$map = $this->get_product_map( $field );
		if ( is_array( $map ) && isset( $map['map'][ $value_key ] ) && ! empty( $map['map'][ $value_key ]['id'] ) ) {
			return $map['map'][ $value_key ];
		}

		return null;
	}

	/**
	 * Clé de l'option WordPress stockant le mapping extrafield → produit.
	 *
	 * @param string $field Nom technique de l'extrafield.
	 * @return string
	 */
	private function product_map_key( $field ) {
		return 'diwc_product_map_' . md5( $field );
	}

	/**
	 * Lit le mapping produit synchronisé en arrière-plan.
	 *
	 * @param string $field Nom technique de l'extrafield.
	 * @return array|null array( 'at', 'mode', 'map' ) ou null si jamais synchronisé.
	 */
	public function get_product_map( $field ) {
		$data = get_option( $this->product_map_key( $field ), null );
		return ( is_array( $data ) && isset( $data['map'] ) && is_array( $data['map'] ) ) ? $data : null;
	}

	/**
	 * Construit (ou rafraîchit) le mapping extrafield → produit en parcourant
	 * TOUT le catalogue Dolibarr, page par page. À exécuter EN ARRIÈRE-PLAN
	 * (WP-Cron) : si la liste /products n'inclut pas les extrafields, chaque
	 * produit est vérifié individuellement (GET /products/{id}), ce qui peut
	 * prendre plusieurs minutes sur un gros catalogue.
	 *
	 * @param string $field Nom technique de l'extrafield.
	 * @return array array( 'count' => int, 'mode' => string, 'error' => string ).
	 */
	public function sync_product_map( $field ) {
		$field = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $field );
		if ( '' === $field ) {
			return array( 'count' => 0, 'mode' => '', 'error' => 'champ invalide' );
		}

		// Garde anti-parallélisme (un seul scan à la fois).
		$lock = 'diwc_sync_lock_' . md5( $field );
		if ( get_transient( $lock ) ) {
			// Une synchronisation est déjà en cours : on repasse dans 5 minutes
			// pour ne pas perdre la demande.
			if ( ! wp_next_scheduled( 'diwc_sync_catalog' ) ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'diwc_sync_catalog', array( $field ) );
			}
			return array( 'count' => 0, 'mode' => '', 'error' => 'synchronisation déjà en cours (relancée dans 5 min)' );
		}
		set_transient( $lock, 1, 15 * MINUTE_IN_SECONDS );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}

		$limit     = 100;
		$max_items = (int) apply_filters( 'diwc_sync_max_products', 20000 );
		$page      = 0;
		$seen      = 0;
		$inline    = null;
		$map       = array();
		$mode      = '';
		$error     = '';
		$sample    = false; // Aperçu des clés extrafields loggé une fois par passe.

		DIWC_Log::add( 'Synchronisation du catalogue produits (extrafield ' . $field . ')…' );

		while ( $seen < $max_items ) {
			$res = $this->request(
				'GET',
				'/products?limit=' . $limit . '&page=' . $page . '&sortfield=t.rowid&sortorder=ASC'
			);

			if ( is_wp_error( $res ) ) {
				$error = $res->get_error_message();
				DIWC_Log::add( 'Synchronisation catalogue produits : ' . $error );
				break;
			}
			if ( ! is_array( $res ) || empty( $res ) ) {
				break; // Fin du catalogue.
			}

			foreach ( $res as $product ) {
				$seen++;
				if ( ! is_array( $product ) || empty( $product['id'] ) ) {
					continue;
				}

				// Détecte le mode de lecture des extrafields sur le premier article.
				if ( null === $inline ) {
					$inline = ( null !== $this->product_extrafield_value( $product, $field ) );
					$mode   = $inline ? 'inline' : 'per_product';
					if ( ! $inline ) {
						DIWC_Log::add( 'Extrafields absents de la liste /products — vérification produit par produit (catalogue : ' . $max_items . ' max).' );
					}
				}

				$val    = null;
				$source = $product; // Objet servant au mapping (type/ref exacts).
				if ( $inline ) {
					$val = $this->product_extrafield_value( $product, $field );
				} else {
					$single = $this->request( 'GET', '/products/' . (int) $product['id'] );
					if ( is_array( $single ) && ! empty( $single['id'] ) ) {
						$source = $single;
						$val    = $this->product_extrafield_value( $single, $field );
					}
				}

				if ( ! $sample ) {
					$sample = true;
					$opts   = ( isset( $product['array_options'] ) && is_array( $product['array_options'] ) ) ? $product['array_options'] : array();
					$keys   = empty( $opts ) ? '(aucune clé array_options)' : implode( ', ', array_slice( array_keys( $opts ), 0, 20 ) );
					DIWC_Log::add(
						'Aperçu extrafields (produit ' . (int) $product['id'] . ') : clés array_options = ' . $keys
						. ' — valeur lue pour ' . $field . ' = ' . ( null === $val ? '(aucune)' : var_export( $val, true ) ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					);
				}

				if ( null !== $val && '' !== (string) $val ) {
					$key      = (string) $val;
					$compact  = $this->compact_product( $source );

					if ( isset( $map[ $key ] ) && (int) $map[ $key ]['id'] !== (int) $compact['id'] ) {
						// Deux produits Dolibarr portent la même valeur d'extrafield :
						// source classique d'une correspondance incohérente.
						DIWC_Log::add(
							'⚠ Doublon d\'extrafield ' . $field . ' = ' . $key . ' : produits Dolibarr '
							. (string) $map[ $key ]['ref'] . ' (id ' . (int) $map[ $key ]['id'] . ') et '
							. (string) $compact['ref'] . ' (id ' . (int) $compact['id'] . ') — le premier est conservé.'
						);
					} else {
						$map[ $key ] = $compact;
					}
				}

				if ( $seen >= $max_items ) {
					break 2;
				}
			}

			$page++;
			if ( count( $res ) < $limit ) {
				break;
			}
		}

		delete_transient( $lock );

		$key      = $this->product_map_key( $field );
		$previous = get_option( $key, null );
		$prev_map = ( is_array( $previous ) && isset( $previous['map'] ) && is_array( $previous['map'] ) ) ? $previous['map'] : array();
		$new_count  = count( $map );
		$prev_count = count( $prev_map );

		// Une synchronisation ne doit JAMAIS remplacer un mapping valide par un
		// mapping vide issu d'un échec : une erreur transitoire (502 Cloudflare,
		// coupure réseau, catalogue indisponible) suffirait sinon à faire créer
		// des commandes client avec des lignes libres au lieu des vrais produits.
		if ( '' === $error && 0 === $new_count ) {
			$error = __( 'réponse vide du catalogue', 'diwc' );
		}

		$preserve = ( '' !== $error ) && $prev_count > 0;

		if ( $preserve ) {
			$previous['attempt'] = time();
			$previous['error']   = $error;
			update_option( $key, $previous, false );

			DIWC_Log::add(
				'Synchronisation du catalogue EN ÉCHEC — mapping précédent conservé (' . $prev_count . ' produit(s), inchangé depuis le '
				. date_i18n( 'd/m/Y H:i', (int) ( isset( $previous['at'] ) ? $previous['at'] : time() ) ) . ') : ' . $error
			);

			return array(
				'count'     => $prev_count,
				'mode'      => $mode,
				'error'     => $error,
				'preserved' => true,
			);
		}

		update_option( $key, array( 'at' => time(), 'mode' => $mode, 'map' => $map ), false );

		$loss = ( $prev_count > 0 && $new_count < $prev_count )
			? ' — attention : ' . ( $prev_count - $new_count ) . ' correspondance(s) perdue(s) par rapport au mapping précédent (' . $prev_count . ')'
			: '';

		DIWC_Log::add(
			'Synchronisation du catalogue terminée : ' . $new_count . ' produit(s) mappé(s) (mode ' . $mode . ')'
			. ( '' !== $error ? ' — erreur : ' . $error : '' ) . $loss
		);

		return array( 'count' => $new_count, 'mode' => $mode, 'error' => $error );
	}

	/**
	 * Planifie une synchronisation du mapping en arrière-plan (WP-Cron),
	 * sauf si une est déjà planifiée.
	 *
	 * @param string $field Nom technique de l'extrafield.
	 */
	public function schedule_product_sync( $field, $throttle = false ) {
		$field = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $field );
		if ( '' === $field ) {
			return;
		}

		// Anti-rafale : au maximum une relance « à la demande » toutes les 10 min.
		if ( $throttle ) {
			$throttle_key = 'diwc_sync_throttle_' . md5( $field );
			if ( get_transient( $throttle_key ) ) {
				return;
			}
			set_transient( $throttle_key, 1, 10 * MINUTE_IN_SECONDS );
		}

		if ( ! wp_next_scheduled( 'diwc_sync_catalog' ) ) {
			wp_schedule_single_event( time() + 2, 'diwc_sync_catalog', array( $field ) );
			DIWC_Log::add( 'Synchronisation du catalogue planifiée en arrière-plan (extrafield ' . $field . ').' );
		}
	}

	/**
	 * Planifie la synchronisation QUOTIDIENNE du mapping (une seule fois :
	 * l'événement récurrent se replanifie ensuite tout seul).
	 */
	public function maybe_schedule_daily_sync() {
		if ( ! wp_next_scheduled( 'diwc_sync_catalog_daily' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', 'diwc_sync_catalog_daily' );
		}
	}

	/**
	 * Synchronisation quotidienne du mapping (WP-Cron, sans argument).
	 */
	public function handle_sync_catalog_daily() {
		$field = (string) DIWC_Settings::instance()->get( 'product_extrafield' );
		$this->sync_product_map( $field );
	}

	/**
	 * Exécute la synchronisation planifiée (WP-Cron).
	 *
	 * @param string $field Nom technique de l'extrafield.
	 */
	public function handle_sync_catalog_cron( $field ) {
		$field = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $field );
		if ( '' === $field ) {
			$field = (string) DIWC_Settings::instance()->get( 'product_extrafield' );
		}
		$this->sync_product_map( $field );
	}

	/**
	 * Bouton « Synchroniser le catalogue » (action admin-post) : planifie la
	 * synchronisation en arrière-plan puis redirige vers les réglages.
	 */
	public function handle_sync_catalog_manual() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'diwc' ) );
		}
		check_admin_referer( 'diwc_sync_catalog' );

		$field = (string) DIWC_Settings::instance()->get( 'product_extrafield' );
		$this->schedule_product_sync( $field );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'diwc-settings',
					'diwc_test' => '1',
					'diwc_msg'  => rawurlencode( __( 'Synchronisation du catalogue lancée en arrière-plan (WP-Cron). Rechargez cette page dans quelques minutes : le mapping des produits sera à jour.', 'diwc' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Lit la valeur d'un extrafield dans les array_options d'un objet (produit
	 * ou facture…). Selon les versions de Dolibarr, la clé est le nom du champ,
	 * préfixé par « custom_ » ou par « options_ » — les trois formes sont
	 * essayées (ex. id_du_produit_dans_woocommerce,
	 * custom_id_du_produit_dans_woocommerce, options_id_du_produit_dans_woocommerce).
	 *
	 * @param array  $product Objet retourné par l'API (array_options inclus).
	 * @param string $field   Nom technique de l'extrafield.
	 * @return mixed|null Valeur, ou null si absente.
	 */
	private function product_extrafield_value( $product, $field ) {
		$opts = ( isset( $product['array_options'] ) && is_array( $product['array_options'] ) ) ? $product['array_options'] : array();
		foreach ( array( $field, 'custom_' . $field, 'options_' . $field ) as $key ) {
			if ( array_key_exists( $key, $opts ) ) {
				return $opts[ $key ];
			}
		}
		return null;
	}

	/**
	 * Réduit un produit brut de l'API aux champs utiles.
	 *
	 * @param array $product Produit retourné par l'API.
	 * @return array
	 */
	private function compact_product( $product ) {
		return array(
			'id'    => (int) $product['id'],
			'ref'   => isset( $product['ref'] ) ? (string) $product['ref'] : '',
			'label' => isset( $product['label'] ) ? (string) $product['label'] : '',
			'type'  => isset( $product['type'] ) ? (int) $product['type'] : 0,
		);
	}

	/**
	 * Crée une commande client Dolibarr (statut brouillon) via l'API.
	 *
	 * @param array $payload Corps de la requête (socid, date, ref_client, lines…).
	 * @return array|WP_Error Commande créée (id, ref…), ou erreur.
	 */
	public function create_draft_customer_order( $payload ) {
		if ( empty( $payload['socid'] ) || empty( $payload['lines'] ) || ! is_array( $payload['lines'] ) ) {
			return new WP_Error( 'diwc_args', __( 'Paramètres incomplets pour créer la commande Dolibarr.', 'diwc' ) );
		}

		$res = $this->request_with_body( 'POST', '/orders', $payload );

		if ( ! $res['ok'] ) {
			DIWC_Log::add(
				'Création commande client (socid ' . (int) $payload['socid'] . ') : ' . $res['error']
				. ' — envoyé : ' . wp_trim_words( (string) wp_json_encode( $payload ), 80 )
			);
			return new WP_Error( 'diwc_order_api', $res['error'] );
		}

		// Réponse attendue : l'objet commande créé (id, ref…).
		if ( is_array( $res['data'] ) && ! empty( $res['data']['id'] ) ) {
			$created = $res['data'];
			DIWC_Log::add(
				'Commande client brouillon créée : ' . ( isset( $created['ref'] ) ? (string) $created['ref'] : (string) $created['id'] )
				. ' (id ' . (int) $created['id'] . ', ' . count( $payload['lines'] ) . ' ligne(s))'
			);
			return $created;
		}

		// Réponse ambiguë (corps vide ou format inattendu) : la commande a
		// peut-être été créée quand même — on la recherche par réf. client.
		$found = array();
		if ( ! empty( $payload['ref_client'] ) ) {
			$found = $this->search_orders_by_ref_client( (string) $payload['ref_client'] );
		}

		if ( ! empty( $found ) ) {
			$created = $found[0];
			DIWC_Log::add(
				'Commande client créée (réponse API ambiguë, retrouvée par réf. client) : '
				. ( isset( $created['ref'] ) ? (string) $created['ref'] : (string) $created['id'] )
				. ' (id ' . (int) $created['id'] . ', HTTP ' . $res['code'] . ', corps : ' . wp_trim_words( (string) $res['body'], 20 ) . ')'
			);
			return $created;
		}

		$detail = 'HTTP ' . (int) $res['code'] . ' — corps : ' . wp_trim_words( (string) $res['body'], 30 );
		DIWC_Log::add(
			'Création commande client (socid ' . (int) $payload['socid'] . ') : réponse inattendue. ' . $detail
			. ' — envoyé : ' . wp_trim_words( (string) wp_json_encode( $payload ), 80 )
		);
		return new WP_Error(
			'diwc_order',
			sprintf(
				/* translators: %s : détail (code HTTP, corps de la réponse). */
				__( 'Réponse inattendue de Dolibarr lors de la création de la commande (%s). Détails dans le Journal.', 'diwc' ),
				$detail
			)
		);
	}

	/**
	 * Recherche les commandes client Dolibarr dont la « réf. client »
	 * (ref_client) correspond à une référence. Sert à retrouver une commande
	 * créée dont la réponse API a été ambiguë.
	 *
	 * @param string $ref Référence client à chercher.
	 * @return array Liste des commandes trouvées.
	 */
	public function search_orders_by_ref_client( $ref ) {
		$ref = preg_replace( "/[:\\(\\)'\"]/", '', (string) $ref );
		if ( '' === $ref ) {
			return array();
		}

		$filter = rawurlencode( "(ref_client:=:'" . $ref . "')" );
		$res    = $this->request(
			'GET',
			'/orders?sqlfilters=' . $filter . '&limit=5&sortfield=t.rowid&sortorder=DESC'
		);

		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return array();
		}

		return $res;
	}

	/**
	 * Liste les contacts (personnes physiques) rattachés à un tiers Dolibarr.
	 * Le nom de colonne du lien varie selon les versions (fk_soc ou socid).
	 *
	 * @param int $socid ID du tiers.
	 * @return array Liste des contacts.
	 */
	public function search_contacts_by_thirdparty( $socid ) {
		$socid = (int) $socid;
		if ( $socid <= 0 ) {
			return array();
		}

		foreach ( array( 'fk_soc', 'socid' ) as $column ) {
			$res = $this->request(
				'GET',
				'/contacts?sqlfilters=' . rawurlencode( '(t.' . $column . ':=:' . $socid . ')' ) . '&limit=100&sortfield=t.rowid&sortorder=ASC'
			);
			if ( ! is_wp_error( $res ) && is_array( $res ) ) {
				return $res;
			}
		}

		DIWC_Log::add( 'Recherche des contacts du tiers ' . $socid . ' indisponible.' );
		return array();
	}

	/**
	 * Crée un contact (personne physique) rattaché à un tiers Dolibarr.
	 *
	 * @param array $fields Champs du contact (lastname, firstname, email, phone, socid…).
	 * @return array|WP_Error Contact créé, ou erreur.
	 */
	public function create_contact( $fields ) {
		if ( empty( $fields['lastname'] ) && empty( $fields['firstname'] ) ) {
			return new WP_Error( 'diwc_args', __( 'Le nom du contact est obligatoire.', 'diwc' ) );
		}

		$res = $this->request_with_body( 'POST', '/contacts', $fields );

		if ( ! $res['ok'] ) {
			DIWC_Log::add( 'Création du contact Dolibarr : ' . $res['error'] );
			return new WP_Error( 'diwc_contact_api', $res['error'] );
		}

		if ( is_array( $res['data'] ) && ! empty( $res['data']['id'] ) ) {
			$contact = $res['data'];
			DIWC_Log::add(
				'Contact Dolibarr créé : ' . trim( (string) ( isset( $contact['firstname'] ) ? $contact['firstname'] : '' ) . ' ' . ( isset( $contact['lastname'] ) ? $contact['lastname'] : '' ) )
				. ' (id ' . (int) $contact['id'] . ', tiers ' . ( isset( $fields['socid'] ) ? (int) $fields['socid'] : 0 ) . ')'
			);
			return $contact;
		}

		// Réponse ambiguë (2xx sans objet) : on retrouve le contact créé.
		$found = $this->find_contact_after_create( $fields );
		if ( is_array( $found ) && ! empty( $found['id'] ) ) {
			DIWC_Log::add(
				'Contact Dolibarr créé (réponse API ambiguë, retrouvé dans le tiers) : '
				. trim( (string) ( isset( $found['firstname'] ) ? $found['firstname'] : '' ) . ' ' . ( isset( $found['lastname'] ) ? $found['lastname'] : '' ) )
				. ' (id ' . (int) $found['id'] . ', HTTP ' . (int) $res['code'] . ', corps : ' . wp_trim_words( (string) $res['body'], 20 ) . ')'
			);
			return $found;
		}

		$detail = 'HTTP ' . (int) $res['code'] . ' — corps : ' . wp_trim_words( (string) $res['body'], 30 );
		DIWC_Log::add( 'Création du contact Dolibarr : réponse inattendue. ' . $detail );
		return new WP_Error(
			'diwc_contact_create',
			sprintf(
				/* translators: %s : détail (code HTTP, corps de la réponse). */
				__( 'Réponse inattendue de Dolibarr lors de la création du contact (%s). Détails dans le Journal.', 'diwc' ),
				$detail
			)
		);
	}

	/**
	 * Retrouve un contact juste créé quand la réponse de l'API est ambiguë
	 * (recherche dans les contacts du tiers, par e-mail puis nom + prénom).
	 *
	 * @param array $fields Champs envoyés à la création.
	 * @return array|null Contact trouvé, ou null.
	 */
	private function find_contact_after_create( $fields ) {
		$socid = isset( $fields['socid'] ) ? (int) $fields['socid'] : 0;
		if ( $socid <= 0 ) {
			return null;
		}

		$contacts = $this->search_contacts_by_thirdparty( $socid );
		if ( empty( $contacts ) ) {
			return null;
		}

		$email = isset( $fields['email'] ) ? strtolower( trim( (string) $fields['email'] ) ) : '';
		$last  = isset( $fields['lastname'] ) ? strtolower( trim( (string) $fields['lastname'] ) ) : '';
		$first = isset( $fields['firstname'] ) ? strtolower( trim( (string) $fields['firstname'] ) ) : '';

		foreach ( $contacts as $contact ) {
			if ( ! is_array( $contact ) || empty( $contact['id'] ) ) {
				continue;
			}

			if ( '' !== $email && isset( $contact['email'] ) && strtolower( trim( (string) $contact['email'] ) ) === $email ) {
				return $contact;
			}

			if ( '' !== $last && isset( $contact['lastname'] ) && strtolower( trim( (string) $contact['lastname'] ) ) === $last
				&& ( '' === $first || ( isset( $contact['firstname'] ) && strtolower( trim( (string) $contact['firstname'] ) ) === $first ) ) ) {
				return $contact;
			}
		}

		return null;
	}

	/**
	 * Met à jour des champs d'un contact Dolibarr (PUT /contacts/{id}).
	 *
	 * @param int   $id     ID du contact.
	 * @param array $fields Champs à écrire (firstname, lastname, email, phone…).
	 * @return true|WP_Error
	 */
	public function update_contact( $id, $fields ) {
		return $this->update_object_fields( (int) $id, '/contacts/', $fields, __( 'contact', 'diwc' ) );
	}

	/**
	 * Crée un tiers Dolibarr (compte client) via l'API (POST /thirdparties).
	 *
	 * Certaines versions répondent 2xx SANS renvoyer l'objet créé : on récupère
	 * alors le tiers par e-mail/nom pour ne pas signaler un faux échec.
	 *
	 * @param array $fields Champs du tiers (name, firstname, lastname, email,
	 *                      phone, address, zip, town, country_id, typent_id, client…).
	 * @return array|WP_Error Tiers créé (id, name…), ou erreur.
	 */
	public function create_thirdparty( $fields ) {
		if ( empty( $fields['name'] ) || '' === trim( (string) $fields['name'] ) ) {
			return new WP_Error( 'diwc_args', __( 'Le nom (raison sociale) est obligatoire pour créer un tiers Dolibarr.', 'diwc' ) );
		}

		$res = $this->request_with_body( 'POST', '/thirdparties', $fields );

		if ( ! $res['ok'] ) {
			DIWC_Log::add( 'Création du tiers Dolibarr : ' . $res['error'] );
			return new WP_Error( 'diwc_tp_api', $res['error'] );
		}

		if ( is_array( $res['data'] ) && ! empty( $res['data']['id'] ) ) {
			$this->log_thirdparty_created( $res['data'] );
			return $res['data'];
		}

		// Réponse ambiguë (corps vide ou format inattendu) : le tiers a très
		// probablement été créé — on le retrouve par e-mail, puis par nom.
		$found = $this->find_thirdparty_after_create( $fields );
		if ( is_array( $found ) && ! empty( $found['id'] ) ) {
			DIWC_Log::add(
				'Tiers Dolibarr créé (réponse API ambiguë, retrouvé par e-mail/nom) : '
				. ( isset( $found['name'] ) ? (string) $found['name'] : '' ) . ' (id ' . (int) $found['id']
				. ', HTTP ' . (int) $res['code'] . ', corps : ' . wp_trim_words( (string) $res['body'], 20 ) . ')'
			);
			return $found;
		}

		$detail = 'HTTP ' . (int) $res['code'] . ' — corps : ' . wp_trim_words( (string) $res['body'], 30 );
		DIWC_Log::add( 'Création du tiers Dolibarr : réponse inattendue. ' . $detail );
		return new WP_Error(
			'diwc_tp_create',
			sprintf(
				/* translators: %s : détail (code HTTP, corps de la réponse). */
				__( 'Réponse inattendue de Dolibarr lors de la création du tiers (%s). Détails dans le Journal.', 'diwc' ),
				$detail
			)
		);
	}

	/**
	 * Journalise la création d'un tiers.
	 *
	 * @param array $thirdparty Tiers retourné par l'API.
	 */
	private function log_thirdparty_created( $thirdparty ) {
		DIWC_Log::add(
			'Tiers Dolibarr créé : ' . ( isset( $thirdparty['name'] ) ? (string) $thirdparty['name'] : '(sans nom)' )
			. ' (id ' . (int) $thirdparty['id'] . ', code ' . ( isset( $thirdparty['code_client'] ) ? (string) $thirdparty['code_client'] : '-' ) . ')'
		);
	}

	/**
	 * Retrouve un tiers juste créé quand la réponse de l'API est ambiguë :
	 * recherche par e-mail exact, puis par nom (avec contrôle du nom).
	 *
	 * @param array $fields Champs envoyés à la création.
	 * @return array|null Tiers trouvé, ou null.
	 */
	private function find_thirdparty_after_create( $fields ) {
		if ( ! empty( $fields['email'] ) && is_email( $fields['email'] ) ) {
			$by_email = $this->search_thirdparty_by_email( (string) $fields['email'] );
			if ( $by_email && ! empty( $by_email['id'] ) ) {
				return $by_email;
			}
		}

		$name = isset( $fields['name'] ) ? trim( (string) $fields['name'] ) : '';
		if ( '' === $name ) {
			return null;
		}

		$escaped = preg_replace( "/[:\\(\\)'\"]/", '', $name );
		$res     = $this->request(
			'GET',
			'/thirdparties?sqlfilters=' . rawurlencode( "(t.nom:=:'" . $escaped . "')" ) . '&limit=5&sortfield=t.rowid&sortorder=DESC'
		);

		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return null;
		}

		foreach ( $res as $thirdparty ) {
			if ( is_array( $thirdparty ) && ! empty( $thirdparty['id'] ) ) {
				return $thirdparty;
			}
		}

		return null;
	}

	/**
	 * ID du type de tiers « Particulier » dans le dictionnaire Dolibarr
	 * (nécessaire pour que prénom/nom soient pris en compte). 0 si introuvable.
	 *
	 * @return int
	 */
	public function individual_typent_id() {
		$cached = get_transient( 'diwc_typent_individual' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$id  = 0;
		$res = $this->request( 'GET', '/setup/dictionary/typent?limit=200&sortfield=code&sortorder=ASC' );

		if ( ! is_wp_error( $res ) && is_array( $res ) ) {
			foreach ( $res as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$code  = isset( $row['code'] ) ? strtoupper( (string) $row['code'] ) : '';
				$label = isset( $row['label'] ) ? (string) $row['label'] : '';

				if ( false !== strpos( $code, 'PRIVATE' ) || false !== stripos( $label, 'particulier' ) ) {
					if ( ! empty( $row['id'] ) ) {
						$id = (int) $row['id'];
					} elseif ( ! empty( $row['rowid'] ) ) {
						$id = (int) $row['rowid'];
					}
					break;
				}
			}
		}

		set_transient( 'diwc_typent_individual', $id, DAY_IN_SECONDS );
		return $id;
	}

	/**
	 * Récupère un tiers Dolibarr (compte client) par son ID.
	 *
	 * @param int $id ID du tiers.
	 * @return array|null Tiers, ou null si introuvable.
	 */
	public function get_thirdparty( $id ) {
		$res = $this->request( 'GET', '/thirdparties/' . (int) $id );
		if ( is_wp_error( $res ) || ! is_array( $res ) || empty( $res['id'] ) ) {
			return null;
		}
		return $res;
	}

	/**
	 * Met à jour les champs d'un tiers Dolibarr (PUT /thirdparties/{id}).
	 * Seuls les champs transmis sont modifiés.
	 *
	 * @param int   $id     ID du tiers.
	 * @param array $fields Champs à écrire (name, email, address, zip, town, country_id…).
	 * @return true|WP_Error
	 */
	public function update_thirdparty( $id, $fields ) {
		$id = (int) $id;
		if ( $id <= 0 || empty( $fields ) || ! is_array( $fields ) ) {
			return new WP_Error( 'diwc_args', __( 'Paramètres incomplets pour mettre à jour le tiers Dolibarr.', 'diwc' ) );
		}

		$res = $this->request(
			'PUT',
			'/thirdparties/' . $id,
			array(
				'headers' => array_merge( $this->headers(), array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode( $fields ),
			)
		);

		if ( is_wp_error( $res ) ) {
			DIWC_Log::add( 'Mise à jour du tiers ' . $id . ' : ' . $res->get_error_message() );
			return $res;
		}

		DIWC_Log::add( 'Tiers Dolibarr ' . $id . ' mis à jour (champs : ' . implode( ', ', array_keys( $fields ) ) . ')' );
		return true;
	}

	/**
	 * Convertit un code pays ISO (ex. FR) en ID de pays Dolibarr, via le
	 * dictionnaire des pays (/setup/dictionary/countries), mis en cache 24 h.
	 *
	 * @param string $code Code pays ISO2.
	 * @return int ID du pays Dolibarr (0 si introuvable).
	 */
	public function country_id_from_code( $code ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $code ) );
		if ( '' === $code ) {
			return 0;
		}

		$map = get_transient( 'diwc_country_map' );
		if ( ! is_array( $map ) ) {
			$map = array();
			$res = $this->request( 'GET', '/setup/dictionary/countries?limit=500&sortfield=code&sortorder=ASC' );
			if ( is_wp_error( $res ) || ! is_array( $res ) ) {
				DIWC_Log::add( 'Dictionnaire des pays indisponible : ' . ( is_wp_error( $res ) ? $res->get_error_message() : 'réponse inattendue' ) );
				return 0;
			}
			foreach ( $res as $row ) {
				if ( ! is_array( $row ) || empty( $row['code'] ) ) {
					continue;
				}
				$id = 0;
				if ( ! empty( $row['id'] ) ) {
					$id = (int) $row['id'];
				} elseif ( ! empty( $row['rowid'] ) ) {
					$id = (int) $row['rowid'];
				}
				if ( $id > 0 ) {
					$map[ strtoupper( (string) $row['code'] ) ] = $id;
				}
			}
			set_transient( 'diwc_country_map', $map, DAY_IN_SECONDS );
		}

		return isset( $map[ $code ] ) ? (int) $map[ $code ] : 0;
	}

	/**
	 * Met à jour des champs d'une commande client Dolibarr
	 * (PUT /orders/{id}) — utilisé pour l'adresse de livraison.
	 *
	 * @param int   $id     ID de la commande Dolibarr.
	 * @param array $fields Champs à écrire (address, zip, town, country_id…).
	 * @return true|WP_Error
	 */
	public function update_customer_order( $id, $fields ) {
		return $this->update_object_fields( (int) $id, '/orders/', $fields, __( 'commande', 'diwc' ) );
	}

	/**
	 * Associe un contact à une commande Dolibarr via la route dédiée
	 * (POST /orders/{id}/contact/{contactid}/{type}) : pour le type « DELIVERY »,
	 * Dolibarr définit l'adresse de livraison de la commande.
	 *
	 * @param int    $order_id   ID de la commande Dolibarr.
	 * @param int    $contact_id ID du contact Dolibarr.
	 * @param string $type       Type de contact (DELIVERY, SHIPPING…).
	 * @return true|WP_Error
	 */
	public function set_order_contact( $order_id, $contact_id, $type ) {
		$order_id   = (int) $order_id;
		$contact_id = (int) $contact_id;
		$type       = preg_replace( '/[^A-Za-z_]/', '', (string) $type );

		if ( $order_id <= 0 || $contact_id <= 0 || '' === $type ) {
			return new WP_Error( 'diwc_args', __( 'Paramètres incomplets pour associer le contact à la commande.', 'diwc' ) );
		}

		$res = $this->request(
			'POST',
			'/orders/' . $order_id . '/contact/' . $contact_id . '/' . $type
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return true;
	}

	/**
	 * Met à jour des champs d'une facture Dolibarr
	 * (PUT /invoices/{id}) — utilisé pour l'adresse de livraison.
	 *
	 * @param int   $id     ID de la facture Dolibarr.
	 * @param array $fields Champs à écrire (address, zip, town, country_id…).
	 * @return true|WP_Error
	 */
	public function update_invoice( $id, $fields ) {
		return $this->update_object_fields( (int) $id, '/invoices/', $fields, __( 'facture', 'diwc' ) );
	}

	/**
	 * Écriture générique de champs sur un objet Dolibarr (PUT).
	 *
	 * @param int    $id      ID de l'objet.
	 * @param string $path    Préfixe de chemin (ex. /orders/).
	 * @param array  $fields  Champs à écrire.
	 * @param string $type    Libellé du type d'objet (journalisation).
	 * @return true|WP_Error
	 */
	private function update_object_fields( $id, $path, $fields, $type ) {
		if ( $id <= 0 || empty( $fields ) || ! is_array( $fields ) ) {
			return new WP_Error( 'diwc_args', __( 'Paramètres incomplets pour la mise à jour Dolibarr.', 'diwc' ) );
		}

		$res = $this->request(
			'PUT',
			$path . $id,
			array(
				'headers' => array_merge( $this->headers(), array( 'Content-Type' => 'application/json' ) ),
				'body'    => wp_json_encode( $fields ),
			)
		);

		if ( is_wp_error( $res ) ) {
			DIWC_Log::add( 'Mise à jour ' . $type . ' ' . $id . ' : ' . $res->get_error_message() );
			return $res;
		}

		DIWC_Log::add( 'Mise à jour ' . $type . ' ' . $id . ' (champs : ' . implode( ', ', array_keys( $fields ) ) . ')' );
		return true;
	}

	/**
	 * Récupère une commande client Dolibarr par son ID (pour connaître son
	 * état réel : référence définitive après validation, statut…).
	 *
	 * @param int $id ID de la commande Dolibarr.
	 * @return array|null Commande, ou null si introuvable.
	 */
	public function get_customer_order( $id ) {
		$res = $this->request( 'GET', '/orders/' . (int) $id );
		if ( is_wp_error( $res ) || ! is_array( $res ) || empty( $res['id'] ) ) {
			return null;
		}
		return $res;
	}

	/**
	 * Requête HTTP avec accès au corps brut de la réponse (diagnostic des
	 * créations : certaines versions de Dolibarr répondent 2xx sans corps JSON
	 * exploitable, ou avec un format différent de l'objet créé).
	 *
	 * @param string      $method Méthode HTTP.
	 * @param string      $path   Chemin après /api/index.php.
	 * @param array|null  $body   Corps JSON éventuel.
	 * @return array array( 'ok', 'code', 'data', 'body', 'error' ).
	 */
	private function request_with_body( $method, $path, $body = null ) {
		$url  = $this->base_url() . '/api/index.php' . $path;
		$args = array(
			'method'    => $method,
			'timeout'   => 45,
			'headers'   => array_merge( $this->headers(), ( null !== $body ) ? array( 'Content-Type' => 'application/json' ) : array() ),
			'sslverify' => apply_filters( 'diwc_sslverify', true ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'code' => 0, 'data' => null, 'body' => '', 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$detail = is_array( $data ) ? wp_json_encode( $data ) : wp_trim_words( $raw, 20 );
			return array( 'ok' => false, 'code' => $code, 'data' => $data, 'body' => $raw, 'error' => 'HTTP ' . $code . ' : ' . $detail );
		}

		return array( 'ok' => true, 'code' => $code, 'data' => $data, 'body' => $raw, 'error' => '' );
	}
}
