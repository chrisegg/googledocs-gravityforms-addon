<?php
/**
 * Google OAuth 2.0 and Drive/Docs REST client (WordPress HTTP API only).
 *
 * @since     1.0
 * @package   Gravity_Forms\Gravity_Forms_GoogleDocs
 * @copyright Copyright (c) 2016-2026, Rocketgenius Inc.
 */

namespace Gravity_Forms\Gravity_Forms_GoogleDocs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Google OAuth and REST calls via wp_remote_*.
 */
class GF_Google_Docs_API {

	const OAUTH_AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
	const OAUTH_TOKEN_URL     = 'https://oauth2.googleapis.com/token';
	const OAUTH_REVOKE_URL    = 'https://oauth2.googleapis.com/revoke';
	const DRIVE_API_BASE      = 'https://www.googleapis.com/drive/v3';
	const DOCS_API_BASE       = 'https://docs.googleapis.com/v1/documents';

	/**
	 * Rate limiting (requests per minute).
	 *
	 * @var int
	 */
	private $rate_limit = 100;

	/**
	 * Rate window in seconds.
	 *
	 * @var int
	 */
	private $rate_window = 60;

	/**
	 * @var bool
	 */
	private $cache_enabled = true;

	/**
	 * @var int
	 */
	private $cache_expiration = 3600;

	/**
	 * @var string
	 */
	private $cache_prefix = 'gf_googledocs_cache_';

	/**
	 * @var bool|null
	 */
	private static $is_authenticated_cache = null;

	/**
	 * @var int
	 */
	private static $is_authenticated_cache_time = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->load_rate_limit_settings();
	}

	/**
	 * OAuth scopes (space-separated for authorize URL).
	 *
	 * @return string
	 */
	private function get_oauth_scopes() {
		return implode(
			' ',
			array(
				'https://www.googleapis.com/auth/documents',
				'https://www.googleapis.com/auth/drive.file',
				'https://www.googleapis.com/auth/drive.metadata.readonly',
				'https://www.googleapis.com/auth/userinfo.email',
				'https://www.googleapis.com/auth/userinfo.profile',
			)
		);
	}

	/**
	 * Load rate limit from plugin settings.
	 */
	private function load_rate_limit_settings() {
		if ( ! class_exists( GFGoogleDocs::class ) ) {
			return;
		}
		$plugin_settings = GFGoogleDocs::get_instance()->get_plugin_settings();
		$rate_limit      = rgar( $plugin_settings, 'rate_limit', 100 );
		$rate_limit      = absint( $rate_limit );
		if ( $rate_limit < 1 || $rate_limit > 1000 ) {
			$rate_limit = 100;
		}
		$this->rate_limit = $rate_limit;
	}

	/**
	 * Rate limit guard (transient-based sliding window).
	 *
	 * @return void
	 * @throws \Exception When over limit.
	 */
	private function check_rate_limit() {
		$now = time();
		$key = 'gf_googledocs_api_rate';
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data = array_values(
			array_filter(
				$data,
				function ( $timestamp ) use ( $now ) {
					return is_numeric( $timestamp ) && (int) $timestamp > ( $now - $this->rate_window );
				}
			)
		);
		if ( count( $data ) >= $this->rate_limit ) {
			throw new \Exception( 'Rate limit exceeded. Please try again later.' );
		}
		$data[] = $now;
		set_transient( $key, $data, $this->rate_window + 10 );
	}

	/**
	 * Normalize raw stored token option for use.
	 *
	 * @param mixed $raw Raw option value.
	 * @return array|null
	 */
	private function normalize_stored_token( $raw ) {
		if ( $raw === null || $raw === false || $raw === '' ) {
			return null;
		}
		if ( is_string( $raw ) ) {
			$trim = trim( $raw );
			if ( '' === $trim ) {
				return null;
			}
			$first = $trim[0];
			if ( '{' === $first || '[' === $first ) {
				$dec = json_decode( $trim, true );
				if ( JSON_ERROR_NONE === json_last_error() && is_array( $dec ) ) {
					$raw = $dec;
				} else {
					return array(
						'access_token' => $trim,
						'issued_at'    => time(),
					);
				}
			} else {
				return array(
					'access_token' => $trim,
					'issued_at'    => time(),
				);
			}
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}
		if ( ! empty( $raw['error'] ) ) {
			return null;
		}
		if ( empty( $raw['access_token'] ) || ! is_string( $raw['access_token'] ) ) {
			return null;
		}
		return $raw;
	}

	/**
	 * Raw token array from options.
	 *
	 * @return array|false
	 */
	private function get_access_token() {
		$raw   = get_option( 'gf_googledocs_access_token' );
		$token = $this->normalize_stored_token( $raw );
		if ( null === $token ) {
			if ( is_array( $raw ) || is_string( $raw ) ) {
				delete_option( 'gf_googledocs_access_token' );
				self::reset_authentication_cache();
				GFGoogleDocs::get_instance()->log_error(
					'GF_Google_Docs_API::get_access_token(): Removed invalid stored OAuth data. Reconnect Google in Forms → Settings → Google Docs.'
				);
			}
			return false;
		}
		return $token;
	}

	/**
	 * Whether access token is expired (with 60s skew).
	 *
	 * @param array $token Token row.
	 * @return bool
	 */
	private function is_access_token_expired( $token ) {
		$expires_in = isset( $token['expires_in'] ) ? (int) $token['expires_in'] : 3600;
		$issued_at  = isset( $token['issued_at'] ) ? (int) $token['issued_at'] : 0;
		if ( $issued_at < 1 && ! empty( $token['created'] ) ) {
			$issued_at = (int) $token['created'];
		}
		if ( $issued_at < 1 ) {
			return true;
		}
		return ( time() >= ( $issued_at + $expires_in - 60 ) );
	}

	/**
	 * Merge token response with existing row and persist.
	 *
	 * @param array $new_token Response from token endpoint.
	 * @param array $existing  Prior token row (optional).
	 */
	private function merge_and_save_token( $new_token, $existing = null ) {
		if ( ! is_array( $new_token ) || ! empty( $new_token['error'] ) ) {
			if ( is_array( $new_token ) && ! empty( $new_token['error'] ) ) {
				GFGoogleDocs::get_instance()->log_error(
					'GF_Google_Docs_API::merge_and_save_token(): ' . rgar( $new_token, 'error' ) . ' ' . rgar( $new_token, 'error_description' )
				);
			}
			return;
		}
		if ( empty( $new_token['access_token'] ) || ! is_string( $new_token['access_token'] ) ) {
			return;
		}
		if ( null === $existing ) {
			$existing = $this->get_access_token();
		}
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$merged = array_merge( $existing, $new_token );
		if ( empty( $new_token['refresh_token'] ) && ! empty( $existing['refresh_token'] ) ) {
			$merged['refresh_token'] = $existing['refresh_token'];
		}
		$merged['issued_at'] = time();
		update_option( 'gf_googledocs_access_token', $merged );
		self::reset_authentication_cache();
		if ( defined( 'GF_GOOGLE_DOCS_DEBUG' ) && GF_GOOGLE_DOCS_DEBUG ) {
			GFGoogleDocs::get_instance()->log_debug( __METHOD__ . '(): Token saved.' );
		}
	}

	/**
	 * POST x-www-form-urlencoded to token endpoint; returns decoded array or WP_Error.
	 *
	 * @param array $fields Body fields.
	 * @return array|\WP_Error
	 */
	private function post_token_request( $fields ) {
		$response = wp_remote_post(
			self::OAUTH_TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $fields, '', '&' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) ? rgar( $data, 'error_description', rgar( $data, 'error', $body ) ) : $body;
			if ( is_array( $data ) && isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$msg = $data['error'] . ( ! empty( $data['error_description'] ) ? ': ' . $data['error_description'] : '' );
			}
			return new \WP_Error( 'token_http_' . $code, $msg ? $msg : 'Token request failed.', array( 'status' => $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'token_bad_json', 'Invalid token response.' );
		}
		return $data;
	}

	/**
	 * Refresh access token using refresh_token.
	 *
	 * @return bool
	 */
	private function refresh_access_token() {
		$existing = $this->get_access_token();
		if ( ! is_array( $existing ) || empty( $existing['refresh_token'] ) ) {
			return false;
		}
		$data = $this->post_token_request(
			array(
				'client_id'     => $this->get_client_id(),
				'client_secret' => $this->get_client_secret(),
				'refresh_token' => $existing['refresh_token'],
				'grant_type'    => 'refresh_token',
			)
		);
		if ( is_wp_error( $data ) ) {
			GFGoogleDocs::get_instance()->log_error( 'GF_Google_Docs_API::refresh_access_token(): ' . $data->get_error_message() );
			return false;
		}
		$this->merge_and_save_token( $data, $existing );
		return true;
	}

	/**
	 * Bearer access token string, refreshing if needed.
	 *
	 * @return string|false
	 */
	private function get_valid_access_token_string() {
		$token = $this->get_access_token();
		if ( ! is_array( $token ) ) {
			return false;
		}
		if ( $this->is_access_token_expired( $token ) ) {
			if ( ! $this->refresh_access_token() ) {
				return false;
			}
			$token = $this->get_access_token();
			if ( ! is_array( $token ) ) {
				return false;
			}
		}
		return rgar( $token, 'access_token' );
	}

	/**
	 * HTTP request to Google API with Bearer auth.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Full URL.
	 * @param array  $args   Optional. body (array assoc for JSON), query (array).
	 * @return array|\WP_Error Decoded JSON array on success, or WP_Error.
	 */
	private function api_request( $method, $url, $args = array() ) {
		$bearer = $this->get_valid_access_token_string();
		if ( ! $bearer ) {
			return new \WP_Error(
				'not_authenticated',
				esc_html__( 'Not authenticated with Google.', 'gravityformsgoogledocs' )
			);
		}
		if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
			$url = add_query_arg( $args['query'], $url );
		}
		$request_args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $bearer,
			),
		);
		if ( ! empty( $args['json'] ) && is_array( $args['json'] ) ) {
			$request_args['headers']['Content-Type'] = 'application/json';
			$request_args['body']                    = wp_json_encode( $args['json'] );
		}
		$response = wp_remote_request( $url, $request_args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $body;
			if ( is_array( $data ) ) {
				if ( isset( $data['error']['message'] ) ) {
					$msg = $data['error']['message'];
				} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
					$msg = $data['error'];
				}
			}
			return new \WP_Error(
				'google_api_' . $code,
				$msg ? $msg : 'Request failed.',
				array( 'status' => $code, 'body' => $body )
			);
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * API request with one refresh retry on 401.
	 *
	 * @param string $method Method.
	 * @param string $url    URL.
	 * @param array  $args   Args for api_request.
	 * @return array|\WP_Error
	 */
	private function api_request_with_retry( $method, $url, $args = array() ) {
		$result = $this->api_request( $method, $url, $args );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		$d       = $result->get_error_data();
		$status  = is_array( $d ) ? (int) rgar( $d, 'status' ) : 0;
		if ( 401 !== $status ) {
			return $result;
		}
		if ( ! $this->refresh_access_token() ) {
			return $result;
		}
		return $this->api_request( $method, $url, $args );
	}

	private function get_client_id() {
		return GFGoogleDocs::get_instance()->get_plugin_setting( 'client_id' );
	}

	private function get_client_secret() {
		return GFGoogleDocs::get_instance()->get_plugin_setting( 'client_secret' );
	}

	/**
	 * OAuth redirect URI registered in Google Cloud.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public static function get_oauth_redirect_uri() {
		if ( ! class_exists( 'GFForms' ) ) {
			return '';
		}
		return admin_url( 'admin.php?page=gf_settings&subview=gravityformsgoogledocs' );
	}

	private function get_redirect_uri() {
		return self::get_oauth_redirect_uri();
	}

	/**
	 * Authorization URL for "Connect with Google".
	 *
	 * @return string
	 */
	public function get_auth_url() {
		$client_id     = $this->get_client_id();
		$client_secret = $this->get_client_secret();
		if ( rgblank( $client_id ) || rgblank( $client_secret ) ) {
			return 'https://console.cloud.google.com/apis/credentials';
		}
		$params = array(
			'client_id'     => $this->get_client_id(),
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => $this->get_oauth_scopes(),
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => wp_create_nonce( 'gf_googledocs_oauth_state' ),
		);
		return self::OAUTH_AUTHORIZE_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange authorization code for tokens.
	 *
	 * @param string $code Authorization code.
	 * @return bool
	 */
	public function handle_oauth_callback( $code ) {
		GFGoogleDocs::get_instance()->log_debug( __METHOD__ . '(): Exchanging code for tokens.' );
		$data = $this->post_token_request(
			array(
				'code'          => $code,
				'client_id'     => $this->get_client_id(),
				'client_secret' => $this->get_client_secret(),
				'redirect_uri'  => $this->get_redirect_uri(),
				'grant_type'    => 'authorization_code',
			)
		);
		if ( is_wp_error( $data ) ) {
			GFGoogleDocs::get_instance()->log_error( __METHOD__ . '(): ' . $data->get_error_message() );
			return false;
		}
		$this->merge_and_save_token( $data, array() );
		return $this->has_valid_token();
	}

	/**
	 * Whether a usable access token exists (refresh if expired).
	 *
	 * @return bool
	 */
	private function has_valid_token() {
		$token = $this->get_access_token();
		if ( ! is_array( $token ) ) {
			return false;
		}
		if ( $this->is_access_token_expired( $token ) ) {
			if ( empty( $token['refresh_token'] ) ) {
				return false;
			}
			return $this->refresh_access_token() && is_array( $this->get_access_token() );
		}
		return true;
	}

	/**
	 * @return bool
	 */
	public function is_authenticated() {
		$current_time = time();
		if ( null !== self::$is_authenticated_cache && ( $current_time - self::$is_authenticated_cache_time ) < 30 ) {
			return self::$is_authenticated_cache;
		}
		$is_valid                          = $this->has_valid_token();
		self::$is_authenticated_cache     = $is_valid;
		self::$is_authenticated_cache_time = $current_time;
		return $is_valid;
	}

	/**
	 * Clear auth state cache.
	 */
	public static function reset_authentication_cache() {
		self::$is_authenticated_cache      = null;
		self::$is_authenticated_cache_time = 0;
	}

	/**
	 * Disconnect and optionally revoke tokens at Google.
	 *
	 * @return bool
	 */
	public function disconnect() {
		try {
			$token = get_option( 'gf_googledocs_access_token' );
			if ( is_array( $token ) && ! empty( $token['access_token'] ) ) {
				wp_remote_post(
					self::OAUTH_REVOKE_URL,
					array(
						'timeout' => 15,
						'body'    => array( 'token' => $token['access_token'] ),
					)
				);
			}
			delete_option( 'gf_googledocs_access_token' );
			self::reset_authentication_cache();
			return true;
		} catch ( \Exception $e ) {
			GFGoogleDocs::get_instance()->log_error( 'GF_Google_Docs_API::disconnect(): ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * @return array|\WP_Error
	 */
	public function list_documents() {
		try {
			if ( ! $this->is_authenticated() ) {
				return new \WP_Error(
					'not_authenticated',
					esc_html__( 'Not authenticated with Google.', 'gravityformsgoogledocs' )
				);
			}
			$this->check_rate_limit();
			$docs        = array();
			$page_token = null;
			do {
				$query = array(
					'q'       => "mimeType='application/vnd.google-apps.document' and trashed=false",
					'fields'  => 'nextPageToken, files(id, name)',
					'pageSize' => 100,
					'orderBy' => 'modifiedTime desc',
				);
				if ( $page_token ) {
					$query['pageToken'] = $page_token;
				}
				$url    = add_query_arg( $query, self::DRIVE_API_BASE . '/files' );
				$result = $this->api_request_with_retry( 'GET', $url );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$files = rgar( $result, 'files', array() );
				foreach ( $files as $file ) {
					$docs[] = array(
						'id'    => rgar( $file, 'id' ),
						'title' => rgar( $file, 'name' ),
					);
				}
				$page_token = rgar( $result, 'nextPageToken' );
			} while ( ! rgblank( $page_token ) );

			return $docs;
		} catch ( \Exception $e ) {
			GFGoogleDocs::get_instance()->log_error( 'GF_Google_Docs_API::list_documents(): ' . $e->getMessage() );
			return new \WP_Error( 'list_documents_failed', $e->getMessage() );
		}
	}

	/**
	 * Create doc from optional template (Drive copy) or blank; append body.
	 *
	 * @param string      $document_title Title.
	 * @param string      $document_body  Body text.
	 * @param string|null $template_id    Template file ID.
	 * @param string      $folder_id      Optional folder for new file.
	 * @return string|\WP_Error New document ID.
	 */
	public function create_document_from_template( $document_title, $document_body, $template_id = null, $folder_id = '' ) {
		try {
			if ( ! $this->is_authenticated() ) {
				return new \WP_Error(
					'not_authenticated',
					esc_html__( 'Not authenticated with Google.', 'gravityformsgoogledocs' )
				);
			}
			$this->check_rate_limit();
			$new_id = null;
			if ( ! rgblank( $template_id ) ) {
				$copy_json = array( 'name' => $document_title );
				if ( ! rgblank( $folder_id ) ) {
					$copy_json['parents'] = array( $folder_id );
				}
				$url    = self::DRIVE_API_BASE . '/files/' . rawurlencode( $template_id ) . '/copy';
				$result = $this->api_request_with_retry(
					'POST',
					$url,
					array( 'json' => $copy_json )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$new_id = rgar( $result, 'id' );
			} else {
				$body = array(
					'name'     => $document_title,
					'mimeType' => 'application/vnd.google-apps.document',
				);
				if ( ! rgblank( $folder_id ) ) {
					$body['parents'] = array( $folder_id );
				}
				$result = $this->api_request_with_retry(
					'POST',
					self::DRIVE_API_BASE . '/files',
					array( 'json' => $body )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$new_id = rgar( $result, 'id' );
			}
			if ( rgblank( $new_id ) ) {
				return new \WP_Error( 'no_file_id', esc_html__( 'Google did not return a document ID.', 'gravityformsgoogledocs' ) );
			}
			if ( ! rgblank( $document_body ) ) {
				$add = $this->add_document_body( $new_id, $document_body );
				if ( is_wp_error( $add ) ) {
					GFGoogleDocs::get_instance()->log_error( __METHOD__ . '(): ' . $add->get_error_message() );
				}
			}
			return $new_id;
		} catch ( \Exception $e ) {
			GFGoogleDocs::get_instance()->log_error( 'GF_Google_Docs_API::create_document_from_template(): ' . $e->getMessage() );
			return new \WP_Error( 'create_failed', $e->getMessage() );
		}
	}

	/**
	 * Create a Google Doc and optionally insert body.
	 *
	 * @param string $document_title Title.
	 * @param string $document_body  Plain text / merge output.
	 * @param string $folder_id      Optional Drive folder ID.
	 * @return string|\WP_Error File ID.
	 */
	public function create_document( $document_title, $document_body, $folder_id = '' ) {
		try {
			$this->check_rate_limit();
			if ( ! $this->is_authenticated() ) {
				return new \WP_Error(
					'not_authenticated',
					esc_html__( 'Not authenticated with Google.', 'gravityformsgoogledocs' )
				);
			}
			if ( defined( 'GF_GOOGLE_DOCS_DEBUG' ) && GF_GOOGLE_DOCS_DEBUG ) {
				GFGoogleDocs::get_instance()->log_debug( __METHOD__ . '(): Creating document: ' . $document_title );
			}
			$metadata = array(
				'name'     => $document_title,
				'mimeType' => 'application/vnd.google-apps.document',
			);
			if ( ! rgblank( $folder_id ) ) {
				$metadata['parents'] = array( $folder_id );
			}
			$result = $this->api_request_with_retry(
				'POST',
				self::DRIVE_API_BASE . '/files',
				array( 'json' => $metadata )
			);
			if ( is_wp_error( $result ) ) {
				GFGoogleDocs::get_instance()->log_error( __METHOD__ . '(): ' . $result->get_error_message() );
				return $result;
			}
			$new_id = rgar( $result, 'id' );
			if ( rgblank( $new_id ) ) {
				return new \WP_Error( 'no_file_id', esc_html__( 'Google did not return a document ID.', 'gravityformsgoogledocs' ) );
			}
			if ( ! rgblank( $document_body ) ) {
				$add = $this->add_document_body( $new_id, $document_body );
				if ( is_wp_error( $add ) ) {
					GFGoogleDocs::get_instance()->log_error( __METHOD__ . '(): Body insert failed: ' . $add->get_error_message() );
				}
			}
			return $new_id;
		} catch ( \Exception $e ) {
			GFGoogleDocs::get_instance()->log_error( __METHOD__ . '(): ' . $e->getMessage() );
			return new \WP_Error( 'create_document_failed', $e->getMessage() );
		}
	}

	/**
	 * End index for insertText at end of document body.
	 *
	 * @param array $document Documents.get JSON.
	 * @return int
	 */
	private function get_document_end_insert_index( $document ) {
		$content = isset( $document['body']['content'] ) ? $document['body']['content'] : array();
		if ( empty( $content ) || ! is_array( $content ) ) {
			return 1;
		}
		$last = end( $content );
		if ( is_array( $last ) && isset( $last['endIndex'] ) ) {
			return max( 1, (int) $last['endIndex'] - 1 );
		}
		return 1;
	}

	/**
	 * Insert text at end of document.
	 *
	 * @param string $document_id Doc ID.
	 * @param string $content     Text.
	 * @return true|\WP_Error
	 */
	private function add_document_body( $document_id, $content ) {
		$content = trim( (string) $content );
		if ( '' === $content ) {
			return true;
		}
		$this->check_rate_limit();
		$doc_url = self::DOCS_API_BASE . '/' . rawurlencode( $document_id );
		$doc     = $this->api_request_with_retry( 'GET', $doc_url );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$index = $this->get_document_end_insert_index( $doc );
		$batch = array(
			'requests' => array(
				array(
					'insertText' => array(
						'location' => array( 'index' => $index ),
						'text'     => "\n" . $content,
					),
				),
			),
		);
		$result = $this->api_request_with_retry(
			'POST',
			self::DOCS_API_BASE . '/' . rawurlencode( $document_id ) . ':batchUpdate',
			array( 'json' => $batch )
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Append plain text to a document.
	 *
	 * @param string $document_id ID.
	 * @param string $content     Text.
	 * @return string|\WP_Error
	 */
	public function append_to_document( $document_id, $content ) {
		$this->check_rate_limit();
		$doc_url = self::DOCS_API_BASE . '/' . rawurlencode( $document_id );
		$doc     = $this->api_request_with_retry( 'GET', $doc_url );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		$index = $this->get_document_end_insert_index( $doc );
		$batch = array(
			'requests' => array(
				array(
					'insertText' => array(
						'location' => array( 'index' => $index ),
						'text'     => "\n" . $content,
					),
				),
			),
		);
		$result = $this->api_request_with_retry(
			'POST',
			self::DOCS_API_BASE . '/' . rawurlencode( $document_id ) . ':batchUpdate',
			array( 'json' => $batch )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $document_id;
	}

	/**
	 * Extract plain text from document structure.
	 *
	 * @param string $document_id ID.
	 * @return string|\WP_Error
	 */
	public function get_document_content( $document_id ) {
		try {
			$this->check_rate_limit();
			$doc_url = self::DOCS_API_BASE . '/' . rawurlencode( $document_id );
			$doc     = $this->api_request_with_retry( 'GET', $doc_url );
			if ( is_wp_error( $doc ) ) {
				return $doc;
			}
			if ( empty( $doc['body']['content'] ) ) {
				return '';
			}
			return $this->structural_elements_to_plain_text( $doc['body']['content'] );
		} catch ( \Exception $e ) {
			return new \WP_Error( 'get_document_content_failed', $e->getMessage() );
		}
	}

	/**
	 * @param array $elements body.content array.
	 * @return string
	 */
	private function structural_elements_to_plain_text( $elements ) {
		$out = '';
		foreach ( $elements as $el ) {
			if ( ! empty( $el['paragraph']['elements'] ) && is_array( $el['paragraph']['elements'] ) ) {
				foreach ( $el['paragraph']['elements'] as $pe ) {
					if ( ! empty( $pe['textRun']['content'] ) ) {
						$out .= $pe['textRun']['content'];
					}
				}
				$out .= "\n";
			}
		}
		return $out;
	}

	/**
	 * @param string $document_id ID.
	 * @param string $content     Unused.
	 * @return \WP_Error
	 */
	public function update_document_content( $document_id, $content ) {
		return new \WP_Error(
			'not_implemented',
			esc_html__( 'Updating full document content is not supported by this add-on.', 'gravityformsgoogledocs' )
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	public function get_account_info() {
		if ( ! $this->is_authenticated() ) {
			return new \WP_Error( 'not_authenticated', 'Not authenticated with Google.' );
		}
		$bearer = $this->get_valid_access_token_string();
		if ( ! $bearer ) {
			return new \WP_Error( 'no_access_token', 'No access token available.' );
		}
		$response = wp_remote_get(
			'https://www.googleapis.com/oauth2/v2/userinfo',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $bearer,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body      = wp_remote_retrieve_body( $response );
		$user_info = json_decode( $body, true );
		if ( empty( $user_info ) || ! is_array( $user_info ) ) {
			return new \WP_Error( 'invalid_response', 'Invalid response from Google API.' );
		}
		return array(
			'email'          => rgar( $user_info, 'email', '' ),
			'name'           => rgar( $user_info, 'name', '' ),
			'picture'        => rgar( $user_info, 'picture', '' ),
			'verified_email' => (bool) rgar( $user_info, 'verified_email', false ),
		);
	}

	/**
	 * @return array|false
	 */
	public function validate_connection() {
		if ( ! $this->is_authenticated() ) {
			return false;
		}
		$account_info = $this->get_account_info();
		if ( is_wp_error( $account_info ) ) {
			return false;
		}
		return $account_info;
	}

	private function get_cache( $key ) {
		if ( ! $this->cache_enabled ) {
			return false;
		}
		$cache_key   = $this->cache_prefix . md5( $key );
		$cached_data = get_transient( $cache_key );
		return false !== $cached_data ? $cached_data : false;
	}

	private function set_cache( $key, $data, $expiration = null ) {
		if ( ! $this->cache_enabled ) {
			return false;
		}
		if ( null === $expiration ) {
			$expiration = $this->cache_expiration;
		}
		$cache_key = $this->cache_prefix . md5( $key );
		return set_transient( $cache_key, $data, $expiration );
	}

	private function clear_cache( $key ) {
		$cache_key = $this->cache_prefix . md5( $key );
		return delete_transient( $cache_key );
	}

	/**
	 * Clear API cache transients.
	 */
	public function clear_all_cache() {
		global $wpdb;
		$cache_prefix = '_transient_' . $this->cache_prefix;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$cache_prefix . '%',
				'_transient_timeout_' . $this->cache_prefix . '%'
			)
		);
	}
}
