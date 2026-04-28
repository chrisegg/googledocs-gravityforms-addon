<?php
/**
 * Google API Handler Class
 *
 * @package GFGoogleDocs
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class GF_Google_Docs_API
 */
class GF_Google_Docs_API {
    /**
     * Google Client instance.
     *
     * @var Google_Client
     */
    private $client;

    /**
     * Google Docs Service instance.
     *
     * @var Google_Service_Docs
     */
    private $docs_service;

    /**
     * Google Drive Service instance.
     *
     * @var Google_Service_Drive
     */
    private $drive_service;

    /**
     * Rate limiting settings
     */
    private $rate_limit = 100; // Maximum requests per minute
    private $rate_window = 60; // Time window in seconds

    private $cache_enabled = true;
    private $cache_expiration = 3600; // 1 hour
    private $cache_prefix = 'gf_googledocs_cache_';

    /**
     * Short-lived cache for is_authenticated() within a request (cleared when token changes).
     *
     * @var bool|null
     */
    private static $is_authenticated_cache = null;

    /**
     * Unix time when is_authenticated_cache was set.
     *
     * @var int
     */
    private static $is_authenticated_cache_time = 0;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->init_client();
        $this->load_rate_limit_settings();
    }

    /**
     * Load rate limit settings from options
     */
    private function load_rate_limit_settings() {
        // Get rate limit from plugin settings
        if (!class_exists('GFGoogleDocs')) {
            $this->rate_limit = 100;
            $this->rate_window = 60;
            return;
        }
        $plugin_settings = GFGoogleDocs::get_instance()->get_plugin_settings();
        $rate_limit = rgar($plugin_settings, 'rate_limit', 100);
        
        // Validate and sanitize rate limit
        $rate_limit = absint($rate_limit);
        if ($rate_limit < 1 || $rate_limit > 1000) {
            $rate_limit = 100; // Default fallback
        }
        
        $this->rate_limit = $rate_limit;
        $this->rate_window = 60; // Fixed 1 minute window
    }

    /**
     * Check if we're within rate limits (site-wide sliding window, persisted in a transient).
     *
     * @return void
     * @throws Exception When limit exceeded.
     */
    private function check_rate_limit() {
        $now = time();
        $key = 'gf_googledocs_api_rate';
        $data = get_transient($key);
        if (!is_array($data)) {
            $data = array();
        }

        $data = array_values(
            array_filter(
                $data,
                function ($timestamp) use ($now) {
                    return is_numeric($timestamp) && (int) $timestamp > ($now - $this->rate_window);
                }
            )
        );

        if (count($data) >= $this->rate_limit) {
            throw new Exception('Rate limit exceeded. Please try again later.');
        }

        $data[] = $now;
        set_transient($key, $data, $this->rate_window + 10);
    }

    /**
     * Initialize the Google Client.
     *
     * @since  1.0
     * @access private
     */
    private function init_client() {
        try {
            // Only initialize if not already initialized
            if ($this->client !== null) {
                return;
            }

            $this->client = new Google_Client();
            
            $client_id = $this->get_client_id();
            $client_secret = $this->get_client_secret();
            
            if (empty($client_id) || empty($client_secret)) {
                GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::init_client(): Client credentials are missing.');
                return;
            }
            
            $this->client->setClientId($client_id);
            $this->client->setClientSecret($client_secret);
            $this->client->setRedirectUri($this->get_redirect_uri());
            $this->client->addScope(Google_Service_Docs::DOCUMENTS);
            $this->client->addScope(Google_Service_Drive::DRIVE_FILE);
            $this->client->addScope('https://www.googleapis.com/auth/drive.metadata.readonly');
            $this->client->addScope('https://www.googleapis.com/auth/userinfo.email');
            $this->client->addScope('https://www.googleapis.com/auth/userinfo.profile');
            $this->client->setAccessType('offline');
            $this->client->setPrompt('consent');
            
            // Set state parameter for CSRF protection
            $this->client->setState(wp_create_nonce('gf_googledocs_oauth_state'));

            // If we have a valid token, set it
            if ($this->has_valid_token()) {
                $this->client->setAccessToken($this->get_access_token());
            }

            $this->docs_service = new Google_Service_Docs($this->client);
            $this->drive_service = new Google_Service_Drive($this->client);
            
            // Only log initialization in debug mode
            if (defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::init_client(): Client initialized successfully.');
            }
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::init_client(): Failed to initialize client. Error: ' . $e->getMessage());
        }
    }

    /**
     * Get the client ID from settings.
     *
     * @since  1.0
     * @access private
     *
     * @return string
     */
    private function get_client_id() {
        return GFGoogleDocs::get_instance()->get_plugin_setting('client_id');
    }

    /**
     * Get the client secret from settings.
     *
     * @since  1.0
     * @access private
     *
     * @return string
     */
    private function get_client_secret() {
        return GFGoogleDocs::get_instance()->get_plugin_setting('client_secret');
    }

    /**
     * Get the redirect URI.
     *
     * @since  1.0
     * @access private
     *
     * @return string
     */
    private function get_redirect_uri() {
        return add_query_arg(
            array(
                'page' => 'gf_settings',
                'subview' => 'gravityformsgoogledocs'
            ),
            admin_url('admin.php')
        );
    }

    /**
     * Convert stored option value to the array shape required by Google_Client::setAccessToken().
     * Returns null if the value is empty, an OAuth error payload, or missing access_token
     * (avoids "Invalid token format" from the Google API client).
     *
     * @param mixed $raw Value from the database (array, JSON string, or legacy bare token string).
     * @return array|null Token array, or null if unusable.
     */
    private function normalize_stored_token($raw) {
        if ($raw === null || $raw === false || $raw === '') {
            return null;
        }

        if (is_string($raw)) {
            $trim = trim($raw);
            if ($trim === '') {
                return null;
            }
            $first = $trim[0];
            if ($first === '{' || $first === '[') {
                $dec = json_decode($trim, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                    $raw = $dec;
                } else {
                    return array('access_token' => $trim);
                }
            } else {
                return array('access_token' => $trim);
            }
        }

        if (!is_array($raw)) {
            return null;
        }
        if (!empty($raw['error'])) {
            return null;
        }
        if (empty($raw['access_token']) || !is_string($raw['access_token'])) {
            return null;
        }
        return $raw;
    }

    /**
     * Get the access token from settings.
     *
     * @since  1.0
     * @access private
     *
     * @return array|false
     */
    private function get_access_token() {
        $raw = get_option('gf_googledocs_access_token');
        $debug_mode = defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG;

        if ($raw === null || $raw === false || $raw === '') {
            if ($debug_mode) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::get_access_token(): No token found in options.');
            }
            return false;
        }

        $token = $this->normalize_stored_token($raw);
        if ($token === null) {
            delete_option('gf_googledocs_access_token');
            self::reset_authentication_cache();
            GFGoogleDocs::get_instance()->log_error(
                'GF_Google_Docs_API::get_access_token(): Removed invalid stored OAuth data (missing access_token, or error payload). Reconnect Google in Forms → Settings → Google Docs.'
            );
            return false;
        }

        if ($debug_mode) {
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::get_access_token(): Retrieved token from options.');
        }
        return $token;
    }

    /**
     * Save the access token.
     *
     * @since  1.0
     * @access private
     *
     * @param array $token The access token to save.
     */
    private function save_access_token($token) {
        if (!is_array($token) || !empty($token['error']) || empty($token['access_token']) || !is_string($token['access_token'])) {
            if (is_array($token) && !empty($token['error'])) {
                GFGoogleDocs::get_instance()->log_error(
                    'GF_Google_Docs_API::save_access_token(): Not saving token; OAuth response error: ' . $token['error']
                );
            }
            return;
        }

        update_option('gf_googledocs_access_token', $token);
        $this->client->setAccessToken($token);
        self::reset_authentication_cache();
        if (defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG) {
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::save_access_token(): Token saved and set in client successfully.');
        }
    }

    /**
     * Get the authorization URL.
     *
     * @since  1.0
     * @access public
     *
     * @return string
     */
    public function get_auth_url() {
        try {
            // If we don't have client credentials, redirect to Google Cloud Console
            if (empty($this->get_client_id()) || empty($this->get_client_secret())) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::get_auth_url(): No client credentials found, redirecting to Google Cloud Console.');
                return 'https://console.cloud.google.com/apis/credentials';
            }
            
            $auth_url = $this->client->createAuthUrl();
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::get_auth_url(): Generated auth URL successfully.');
            return $auth_url;
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::get_auth_url(): Failed to generate auth URL. Error: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Handle the OAuth callback.
     *
     * @since  1.0
     * @access public
     *
     * @param string $code The authorization code.
     *
     * @return bool
     */
    public function handle_oauth_callback($code) {
        try {
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::handle_oauth_callback(): Starting OAuth callback with code.');
            
            // Fetch the access token
            $token = $this->client->fetchAccessTokenWithAuthCode($code);

            if (empty($token) || !empty($token['error'])) {
                if (is_array($token) && !empty($token['error'])) {
                    GFGoogleDocs::get_instance()->log_error(
                        'GF_Google_Docs_API::handle_oauth_callback(): ' . $token['error'] . (isset($token['error_description']) ? ' — ' . $token['error_description'] : '')
                    );
                } else {
                    GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::handle_oauth_callback(): No token received from Google.');
                }
                return false;
            }
            
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::handle_oauth_callback(): Token received from Google.');
            
            // Save the token
            $this->save_access_token($token);
            
            // Verify the token is valid
            if (!$this->has_valid_token()) {
                GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::handle_oauth_callback(): Token validation failed after saving.');
                return false;
            }
            
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::handle_oauth_callback(): Successfully authenticated with Google.');
            return true;
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::handle_oauth_callback(): Error during authentication. Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if we have a valid access token.
     *
     * @since  1.0
     * @access private
     *
     * @return bool
     */
    private function has_valid_token() {
        // Only log detailed steps if explicitly enabled for debugging
        $debug_mode = defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG;
        
        if ($debug_mode) {
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::has_valid_token(): Checking token validity.');
        }
        
        $token = $this->get_access_token();
        if (empty($token)) {
            if ($debug_mode) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::has_valid_token(): No token found.');
            }
            return false;
        }

        // Set the token in the client for validation
        $this->client->setAccessToken($token);

        $is_expired = $this->client->isAccessTokenExpired();
        
        if ($is_expired && $this->client->getRefreshToken()) {
            if ($debug_mode) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::has_valid_token(): Token expired, attempting refresh.');
            }
            try {
                $token = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                if (empty($token['access_token']) || !empty($token['error'])) {
                    GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::has_valid_token(): Token refresh did not return a valid access token.');
                    return false;
                }
                $this->save_access_token($token);
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::has_valid_token(): Token refreshed successfully.');
                return true;
            } catch (Exception $e) {
                GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::has_valid_token(): Failed to refresh token. Error: ' . $e->getMessage());
                return false;
            }
        }
        
        return !$is_expired;
    }

    /**
     * Ensure we have a valid access token.
     *
     * @since  1.0
     * @access private
     *
     * @return bool
     */
    private function ensure_valid_token() {
        if (!$this->has_valid_token()) {
            return false;
        }

        if ($this->client->isAccessTokenExpired()) {
            if ($this->client->getRefreshToken()) {
                try {
                    $token = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                    if (empty($token['access_token']) || !empty($token['error'])) {
                        return false;
                    }
                    $this->save_access_token($token);
                    return true;
                } catch (Exception $e) {
                    GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::ensure_valid_token(): ' . $e->getMessage());
                    return false;
                }
            }
            return false;
        }

        return true;
    }

    /**
     * Check if we are authenticated with Google.
     *
     * @since  1.0
     * @access public
     *
     * @return bool
     */
    public function is_authenticated() {
        $current_time = time();
        // Cache authentication status briefly to limit API/token checks.
        if (self::$is_authenticated_cache !== null && ($current_time - self::$is_authenticated_cache_time) < 30) {
            return self::$is_authenticated_cache;
        }

        $debug_mode = defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG;
        if ($debug_mode) {
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::is_authenticated(): Checking authentication status.');
        }

        $is_valid = $this->has_valid_token();
        self::$is_authenticated_cache = $is_valid;
        self::$is_authenticated_cache_time = $current_time;

        if ($debug_mode) {
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::is_authenticated(): Authentication status: ' . ($is_valid ? 'authenticated' : 'not authenticated'));
        }

        return $is_valid;
    }

    /**
     * Clear cached result of is_authenticated() (call after token save, revoke, or disconnect).
     *
     * @return void
     */
    public static function reset_authentication_cache() {
        self::$is_authenticated_cache = null;
        self::$is_authenticated_cache_time = 0;
    }

    /**
     * Disconnect from Google.
     *
     * @since  1.0
     * @access public
     *
     * @return bool
     */
    public function disconnect() {
        try {
            // Delete the access token
            delete_option('gf_googledocs_access_token');
            self::reset_authentication_cache();
            return true;
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::disconnect(): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * List Google Docs files in the authenticated user's Drive (for template selection).
     *
     * @since 1.0
     *
     * @return array|WP_Error List of array( 'id' => string, 'title' => string ) or WP_Error.
     */
    public function list_documents() {
        try {
            if (!$this->is_authenticated()) {
                return new WP_Error(
                    'not_authenticated',
                    esc_html__('Not authenticated with Google.', 'gravityformsgoogledocs')
                );
            }
            if (!$this->drive_service) {
                return new WP_Error(
                    'drive_unavailable',
                    esc_html__('Google Drive API is not available.', 'gravityformsgoogledocs')
                );
            }

            $this->check_rate_limit();

            $docs = array();
            $page_token = null;

            do {
                $params = array(
                    'q' => "mimeType='application/vnd.google-apps.document' and trashed=false",
                    'fields' => 'nextPageToken, files(id, name)',
                    'pageSize' => 100,
                    'orderBy' => 'modifiedTime desc',
                );
                if ($page_token) {
                    $params['pageToken'] = $page_token;
                }

                $response = $this->drive_service->files->listFiles($params);

                foreach ($response->getFiles() as $file) {
                    $docs[] = array(
                        'id' => $file->getId(),
                        'title' => $file->getName(),
                    );
                }

                $page_token = $response->getNextPageToken();
            } while ($page_token);

            return $docs;
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::list_documents(): ' . $e->getMessage());

            return new WP_Error('list_documents_failed', $e->getMessage());
        }
    }

    /**
     * Create a new document from a template or as a blank document.
     *
     * @since  1.0
     * @access public
     *
     * @param string $document_title The new document title.
     * @param string $document_body The document body content.
     * @param string $template_id Optional. The template document ID if using a template.
     *
     * @return string|WP_Error The new document ID or WP_Error on failure.
     */
    public function create_document_from_template($document_title, $document_body, $template_id = null) {
        $debug_mode = defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG;
        try {
            if ($debug_mode) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Starting document creation.');
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Document Title: ' . $document_title);
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Template ID: ' . ($template_id ?: 'none (blank document)'));
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Document Body: ' . $document_body);
            }

            // Check authentication
            if (!$this->is_authenticated()) {
                GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::create_document_from_template(): Not authenticated with Google.');
                throw new Exception('Not authenticated with Google.');
            }

            // Create a new document
            if ($debug_mode) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Creating new document.');
            }
            $new_file = new Google_Service_Drive_DriveFile(array(
                'name' => $document_title,
                'mimeType' => 'application/vnd.google-apps.document'
            ));

            try {
                $new_file = $this->drive_service->files->create($new_file);
                if ($debug_mode) {
                    GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Successfully created new document. ID: ' . $new_file->getId());
                }
            } catch (Exception $e) {
                GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::create_document_from_template(): Failed to create document. Error: ' . $e->getMessage());
                throw new Exception('Failed to create document. Please check your Google Drive permissions.');
            }

            // If using a template, copy its content
            if ($template_id) {
                if ($debug_mode) {
                    GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Copying template content.');
                }
                try {
                    $template = $this->drive_service->files->get($template_id);
                    if ($debug_mode) {
                        GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Successfully retrieved template document: ' . $template->getName());
                    }
                    
                    // Copy the template content to the new document
                    $this->copy_template_content($template_id, $new_file->getId());
                    if ($debug_mode) {
                        GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Successfully copied template content.');
                    }
                } catch (Exception $e) {
                    GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::create_document_from_template(): Failed to copy template content. Error: ' . $e->getMessage());
                    // Don't throw here, as we still have a new document
                }
            }

            // Add the document body if provided
            if (!empty($document_body)) {
                if ($debug_mode) {
                    GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Adding document body.');
                }
                try {
                    $this->add_document_body($new_file->getId(), $document_body);
                    if ($debug_mode) {
                        GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document_from_template(): Successfully added document body.');
                    }
                } catch (Exception $e) {
                    GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::create_document_from_template(): Failed to add document body. Error: ' . $e->getMessage());
                    // Don't throw here, as the document was created successfully
                }
            }

            return $new_file->getId();
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::create_document_from_template(): Error creating document. Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Copy content from a template document to a new document.
     *
     * @since  1.0
     * @access private
     *
     * @param string $template_id The template document ID.
     * @param string $new_doc_id The new document ID.
     */
    private function copy_template_content($template_id, $new_doc_id) {
        try {
            // Get the template document content
            $template_doc = $this->docs_service->documents->get($template_id);
            
            // Create requests to copy the content
            $requests = array();
            
            // Copy the title
            if ($template_doc->getTitle()) {
                $requests[] = new Google_Service_Docs_Request(array(
                    'updateTitle' => array(
                        'title' => $template_doc->getTitle()
                    )
                ));
            }
            
            // Copy the content
            if ($template_doc->getBody()) {
                $requests[] = new Google_Service_Docs_Request(array(
                    'insertText' => array(
                        'location' => array(
                            'index' => 1
                        ),
                        'text' => $template_doc->getBody()->getContent()
                    )
                ));
            }
            
            // Apply the requests
            if (!empty($requests)) {
                $batch_update = new Google_Service_Docs_BatchUpdateDocumentRequest(array(
                    'requests' => $requests
                ));
                
                $this->docs_service->documents->batchUpdate($new_doc_id, $batch_update);
            }
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::copy_template_content(): Failed to copy template content. Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the service with rate limiting
     */
    private function get_service() {
        $this->check_rate_limit();
        return $this->docs_service;
    }

    /**
     * Create a new blank document.
     *
     * @since  1.0
     * @access public
     *
     * @param string $document_title The title of the new document.
     * @param string $document_body  The content to add to the document.
     * @param string $folder_id      Optional. The ID of the folder to create the document in.
     *
     * @return string|WP_Error The new document ID or WP_Error on failure.
     */
    public function create_document($document_title, $document_body, $folder_id = '') {
        $debug_mode = defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG;
        try {
            $this->check_rate_limit();
            if ($debug_mode) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document(): Starting document creation.');
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document(): Document Title: ' . $document_title);
                if (!empty($folder_id)) {
                    GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document(): Target Folder ID: ' . $folder_id);
                }
            }

            // Check authentication
            if (!$this->is_authenticated()) {
                GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::create_document(): Not authenticated with Google.');
                throw new Exception('Not authenticated with Google.');
            }

            // Create a new document
            if ($debug_mode) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document(): Creating new document.');
            }
            $file_metadata = array(
                'name' => $document_title,
                'mimeType' => 'application/vnd.google-apps.document'
            );

            // If folder ID is provided, add it to parents
            if (!empty($folder_id)) {
                $file_metadata['parents'] = array($folder_id);
            }

            $new_file = new Google_Service_Drive_DriveFile($file_metadata);

            try {
                $new_file = $this->drive_service->files->create($new_file);
                if ($debug_mode) {
                    GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document(): Successfully created new document. ID: ' . $new_file->getId());
                }
            } catch (Exception $e) {
                GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::create_document(): Failed to create document. Error: ' . $e->getMessage());
                throw new Exception('Failed to create document. Please check your Google Drive permissions.');
            }

            // Add the document body if provided
            if (!empty($document_body)) {
                if ($debug_mode) {
                    GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document(): Adding document body.');
                }
                try {
                    $this->add_document_body($new_file->getId(), $document_body);
                    if ($debug_mode) {
                        GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::create_document(): Successfully added document body.');
                    }
                } catch (Exception $e) {
                    GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::create_document(): Failed to add document body. Error: ' . $e->getMessage());
                    // Don't throw here, as the document was created successfully
                }
            }

            return $new_file->getId();
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::create_document(): Error creating document. Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add document body to a document.
     *
     * @since  1.0
     * @access private
     *
     * @param string $document_id The ID of the document to add content to.
     * @param string $content     The content to add.
     *
     * @return bool
     */
    private function add_document_body($document_id, $content) {
        try {
            // Validate document ID
            if (empty($document_id) || !is_string($document_id)) {
                throw new Exception('Invalid document ID provided.');
            }

            // Validate content
            if (!is_string($content)) {
                throw new Exception('Invalid content provided. Content must be a string.');
            }

            // Prepare content - clean up and ensure proper formatting
            $content = trim($content);
            if (empty($content)) {
                return true;
            }

            // Get the service
            $service = $this->get_service();
            $document = $service->documents->get($document_id);
            if (!$document || !$document->getBody()) {
                throw new Exception('Failed to get document structure.');
            }

            // Find the correct insertion point (end of document)
            $insertion_index = 1; // Default to start
            $content_elements = $document->getBody()->getContent();
            
            if (!empty($content_elements)) {
                // Find the last element and get its end index
                $last_element = end($content_elements);
                if ($last_element && isset($last_element->endIndex)) {
                    // Insert before the last character (usually a newline)
                    $insertion_index = max(1, $last_element->endIndex - 1);
                }
            }

            // Create a single request to insert all content at once
            $requests = array(
                array(
                    'insertText' => array(
                        'location' => array(
                            'index' => $insertion_index,
                        ),
                        'text' => "\n" . $content,
                    ),
                )
            );

            // Execute the batch update
            $batch_request = new Google_Service_Docs_BatchUpdateDocumentRequest(array(
                'requests' => $requests,
            ));

            $service->documents->batchUpdate($document_id, $batch_request);

            if (defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG) {
                GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::add_document_body(): Successfully added content at index ' . $insertion_index);
            }

            return true;
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::add_document_body(): Error adding document body. Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Append content to an existing document.
     *
     * @since  1.0
     * @access public
     *
     * @param string $document_id The ID of the document to append to.
     * @param string $content     The content to append.
     *
     * @return string|WP_Error The document ID on success, or WP_Error on failure.
     */
    public function append_to_document($document_id, $content) {
        try {
            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::append_to_document(): Starting to append content to document ' . $document_id);

            // Get the service
            $service = $this->get_service();
            if (!$service) {
                return new WP_Error('no_service', esc_html__('Google Docs API is not available.', 'gravityformsgoogledocs'));
            }

            // Get the current document content
            $document = $service->documents->get($document_id);
            if (!$document) {
                return new WP_Error('document_not_found', esc_html__('Document not found.', 'gravityformsgoogledocs'));
            }

            $body = $document->getBody();
            $content_parts = $body ? $body->getContent() : array();
            if (empty($content_parts)) {
                return new WP_Error('empty_document', esc_html__('Document has no content to append to.', 'gravityformsgoogledocs'));
            }

            $last_element = end($content_parts);
            if (!$last_element || !isset($last_element->endIndex)) {
                return new WP_Error('empty_document', esc_html__('Document has no content to append to.', 'gravityformsgoogledocs'));
            }

            $insert_index = max(1, (int) $last_element->endIndex - 1);

            // Create the content to append
            $append_content = array(
                'insertText' => array(
                    'location' => array(
                        'index' => $insert_index,
                    ),
                    'text' => "\n" . $content,
                ),
            );

            $batch_request = new Google_Service_Docs_BatchUpdateDocumentRequest(array(
                'requests' => array($append_content),
            ));

            $response = $service->documents->batchUpdate($document_id, $batch_request);
            if (!$response) {
                return new WP_Error('append_failed', esc_html__('Failed to append content to document.', 'gravityformsgoogledocs'));
            }

            GFGoogleDocs::get_instance()->log_debug('GF_Google_Docs_API::append_to_document(): Successfully appended content to document ' . $document_id);
            return $document_id;

        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::append_to_document(): Error appending content to document. Error: ' . $e->getMessage());
            return new WP_Error('append_error', $e->getMessage());
        }
    }

    /**
     * Get document content.
     *
     * @param string $document_id Document ID.
     * @return string|WP_Error
     */
    public function get_document_content($document_id) {
        try {
            $service = $this->get_service();
            if (!$service) {
                return new WP_Error(
                    'no_service',
                    esc_html__('Google Docs API is not available.', 'gravityformsgoogledocs')
                );
            }

            $document = $service->documents->get($document_id);
            if (!$document || !$document->getBody()) {
                return '';
            }

            $content = '';
            foreach ($document->getBody()->getContent() as $structural_element) {
                if ($structural_element->getParagraph()) {
                    $paragraph = $structural_element->getParagraph();
                    foreach ($paragraph->getElements() as $element) {
                        if ($element->getTextRun()) {
                            $content .= $element->getTextRun()->getContent();
                        }
                    }
                    $content .= "\n";
                }
            }

            return $content;
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::get_document_content(): ' . $e->getMessage());

            return new WP_Error('get_document_content_failed', $e->getMessage());
        }
    }

    /**
     * Replace full document body is not supported; callers must use create/append APIs.
     *
     * @param string $document_id Document ID.
     * @param string $content     Intended body (unused).
     * @return WP_Error
     */
    public function update_document_content($document_id, $content) {
        return new WP_Error(
            'not_implemented',
            esc_html__('Updating full document content is not supported by this add-on.', 'gravityformsgoogledocs')
        );
    }

    /**
     * Handle API errors
     */
    private function handle_api_error($e, $context) {
        $error_message = $e->getMessage();
        $error_code = $e->getCode();
        
        // Log the full error details
        GFGoogleDocs::get_instance()->log_error(sprintf(
            'GF_Google_Docs_API::%s(): API Error - Code: %s, Message: %s',
            $context,
            $error_code,
            $error_message
        ));

        // Handle specific error cases
        switch ($error_code) {
            case 401:
                // Authentication error
                $this->handle_auth_error();
                throw new Exception('Authentication failed. Please reconnect to Google.');
            case 403:
                // Permission error
                throw new Exception('Permission denied. Please check your Google Drive permissions.');
            case 429:
                // Rate limit error
                throw new Exception('Rate limit exceeded. Please try again later.');
            case 500:
            case 503:
                // Server error
                throw new Exception('Google service temporarily unavailable. Please try again later.');
            default:
                // General error
                throw new Exception('An error occurred while communicating with Google: ' . $error_message);
        }
    }

    /**
     * Handle authentication errors
     */
    private function handle_auth_error() {
        // Verify user capabilities before clearing tokens
        if (!current_user_can('gform_full_access')) {
            return;
        }

        $this->disconnect();

        // Add admin notice
        add_action('admin_notices', function() {
            if (current_user_can('gform_full_access')) {
                echo '<div class="notice notice-error"><p>' . 
                    esc_html__('Your Google authentication has expired. Please reconnect to Google.', 'gravityformsgoogledocs') . 
                    '</p></div>';
            }
        });
    }

    /**
     * Get Google account information
     *
     * @since  1.0
     * @access public
     *
     * @return array|WP_Error Account information or error
     */
    public function get_account_info() {
        try {
            if (!$this->is_authenticated()) {
                return new WP_Error('not_authenticated', 'Not authenticated with Google.');
            }

            // Get user info via direct API call
            $access_token = $this->client->getAccessToken();
            if (empty($access_token['access_token'])) {
                return new WP_Error('no_access_token', 'No access token available.');
            }
            
            $response = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token['access_token']
                )
            ));
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $body = wp_remote_retrieve_body($response);
            $user_info = json_decode($body, true);
            
            if (empty($user_info)) {
                return new WP_Error('invalid_response', 'Invalid response from Google API.');
            }
            
            return array(
                'email' => isset($user_info['email']) ? $user_info['email'] : '',
                'name' => isset($user_info['name']) ? $user_info['name'] : '',
                'picture' => isset($user_info['picture']) ? $user_info['picture'] : '',
                'verified_email' => isset($user_info['verified_email']) ? $user_info['verified_email'] : false
            );
        } catch (Exception $e) {
            GFGoogleDocs::get_instance()->log_error('GF_Google_Docs_API::get_account_info(): Error getting account info. Error: ' . $e->getMessage());
            return new WP_Error('account_info_error', $e->getMessage());
        }
    }

    /**
     * Validate API connection and get account info
     *
     * @since  1.0
     * @access public
     *
     * @return array|false Account information on success, false on failure
     */
    public function validate_connection() {
        if (!$this->is_authenticated()) {
            return false;
        }

        $account_info = $this->get_account_info();
        if (is_wp_error($account_info)) {
            return false;
        }

        return $account_info;
    }

    /**
     * Get cached data by key.
     *
     * @since  1.0
     * @access private
     *
     * @param string $key The cache key.
     *
     * @return mixed|false The cached data or false if not found/expired.
     */
    private function get_cache($key) {
        if (!$this->cache_enabled) {
            return false;
        }

        $cache_key = $this->cache_prefix . md5($key);
        $cached_data = get_transient($cache_key);

        if ($cached_data !== false) {
            if (defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG) {
                GFGoogleDocs::get_instance()->log_debug(__METHOD__ . '(): Cache hit for key: ' . $key);
            }
            return $cached_data;
        }

        return false;
    }

    /**
     * Set cached data by key.
     *
     * @since  1.0
     * @access private
     *
     * @param string $key  The cache key.
     * @param mixed  $data The data to cache.
     * @param int    $expiration Optional. Cache expiration in seconds. Defaults to class setting.
     *
     * @return bool True on success, false on failure.
     */
    private function set_cache($key, $data, $expiration = null) {
        if (!$this->cache_enabled) {
            return false;
        }

        if ($expiration === null) {
            $expiration = $this->cache_expiration;
        }

        $cache_key = $this->cache_prefix . md5($key);
        $result = set_transient($cache_key, $data, $expiration);

        if ($result && defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG) {
            GFGoogleDocs::get_instance()->log_debug(__METHOD__ . '(): Cached data for key: ' . $key . ' (expires in ' . $expiration . 's)');
        }

        return $result;
    }

    /**
     * Clear specific cache entry.
     *
     * @since  1.0
     * @access private
     *
     * @param string $key The cache key to clear.
     *
     * @return bool True on success, false on failure.
     */
    private function clear_cache($key) {
        $cache_key = $this->cache_prefix . md5($key);
        return delete_transient($cache_key);
    }

    /**
     * Clear all Google Docs API cache.
     *
     * @since  1.0
     * @access public
     *
     * @return void
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

        if (defined('GF_GOOGLE_DOCS_DEBUG') && GF_GOOGLE_DOCS_DEBUG) {
            GFGoogleDocs::get_instance()->log_debug(__METHOD__ . '(): Cleared all Google Docs API cache');
        }
    }


} 