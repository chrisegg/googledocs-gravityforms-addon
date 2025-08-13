<?php
/**
 * Google API Handler Class
 *
 * @package GRGoogleDocs
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class GR_Google_Docs_API
 */
class GR_Google_Docs_API {
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
    private $request_times = array();

    private $cache_enabled = true;
    private $cache_expiration = 3600; // 1 hour
    private $cache_prefix = 'gr_google_docs_cache_';

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
        $plugin_settings = get_option('gravityformsaddon_gr-google-docs_settings', array());
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
     * Check if we're within rate limits
     */
    private function check_rate_limit() {
        $now = time();
        $this->request_times = array_filter($this->request_times, function($time) use ($now) {
            return $time > ($now - $this->rate_window);
        });

        if (count($this->request_times) >= $this->rate_limit) {
            throw new Exception('Rate limit exceeded. Please try again later.');
        }

        $this->request_times[] = $now;
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
                GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::init_client(): Client credentials are missing.');
                return;
            }
            
            $this->client->setClientId($client_id);
            $this->client->setClientSecret($client_secret);
            $this->client->setRedirectUri($this->get_redirect_uri());
            $this->client->addScope(Google_Service_Docs::DOCUMENTS);
            $this->client->addScope(Google_Service_Drive::DRIVE_FILE);
            $this->client->addScope('https://www.googleapis.com/auth/userinfo.email');
            $this->client->addScope('https://www.googleapis.com/auth/userinfo.profile');
            $this->client->setAccessType('offline');
            $this->client->setPrompt('consent');
            
            // Set state parameter for CSRF protection
            $this->client->setState(wp_create_nonce('gr_google_docs_oauth_state'));

            // If we have a valid token, set it
            if ($this->has_valid_token()) {
                $this->client->setAccessToken($this->get_access_token());
            }

            $this->docs_service = new Google_Service_Docs($this->client);
            $this->drive_service = new Google_Service_Drive($this->client);
            
            // Only log initialization in debug mode
            if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::init_client(): Client initialized successfully.');
            }
        } catch (Exception $e) {
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::init_client(): Failed to initialize client. Error: ' . $e->getMessage());
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
        return GR_Google_Docs::get_instance()->get_plugin_setting('client_id');
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
        return GR_Google_Docs::get_instance()->get_plugin_setting('client_secret');
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
                'subview' => 'google_docs'
            ),
            admin_url('admin.php')
        );
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
        // Get token from options
        $token = get_option('gr_google_docs_access_token');
        
        // Only log if debug mode is explicitly enabled
        $debug_mode = defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG;
        
        if (!empty($token)) {
            if ($debug_mode) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::get_access_token(): Retrieved token from options.');
            }
            return $token;
        }
        
        if ($debug_mode) {
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::get_access_token(): No token found in options.');
        }
        return false;
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
        // Save the token
        $result = update_option('gr_google_docs_access_token', $token);
        
        if ($result) {
            // Set the token in the client immediately after saving
            $this->client->setAccessToken($token);
            // Only log success in debug mode to reduce noise
            if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::save_access_token(): Token saved and set in client successfully.');
            }
        } else {
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::save_access_token(): Failed to save token to options.');
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
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::get_auth_url(): No client credentials found, redirecting to Google Cloud Console.');
                return 'https://console.cloud.google.com/apis/credentials';
            }
            
            $auth_url = $this->client->createAuthUrl();
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::get_auth_url(): Generated auth URL successfully.');
            return $auth_url;
        } catch (Exception $e) {
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::get_auth_url(): Failed to generate auth URL. Error: ' . $e->getMessage());
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
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::handle_oauth_callback(): Starting OAuth callback with code.');
            
            // Fetch the access token
            $token = $this->client->fetchAccessTokenWithAuthCode($code);
            
            if (empty($token)) {
                GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::handle_oauth_callback(): No token received from Google.');
                return false;
            }
            
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::handle_oauth_callback(): Token received from Google.');
            
            // Save the token
            $this->save_access_token($token);
            
            // Verify the token is valid
            if (!$this->has_valid_token()) {
                GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::handle_oauth_callback(): Token validation failed after saving.');
                return false;
            }
            
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::handle_oauth_callback(): Successfully authenticated with Google.');
            return true;
        } catch (Exception $e) {
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::handle_oauth_callback(): Error during authentication. Error: ' . $e->getMessage());
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
        $debug_mode = defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG;
        
        if ($debug_mode) {
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::has_valid_token(): Checking token validity.');
        }
        
        $token = $this->get_access_token();
        if (empty($token)) {
            if ($debug_mode) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::has_valid_token(): No token found.');
            }
            return false;
        }

        // Set the token in the client for validation
        $this->client->setAccessToken($token);

        $is_expired = $this->client->isAccessTokenExpired();
        
        if ($is_expired && $this->client->getRefreshToken()) {
            if ($debug_mode) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::has_valid_token(): Token expired, attempting refresh.');
            }
            try {
                $token = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
                $this->save_access_token($token);
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::has_valid_token(): Token refreshed successfully.');
                return true;
            } catch (Exception $e) {
                GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::has_valid_token(): Failed to refresh token. Error: ' . $e->getMessage());
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
                    $this->save_access_token($token);
                    return true;
                } catch (Exception $e) {
                    GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::ensure_valid_token(): ' . $e->getMessage());
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
        // Reduce redundant logging by only checking once per request
        static $auth_status = null;
        static $last_check = 0;
        
        $current_time = time();
        // Cache authentication status for 30 seconds to prevent excessive checks
        if ($auth_status !== null && ($current_time - $last_check) < 30) {
            return $auth_status;
        }
        
        $debug_mode = defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG;
        if ($debug_mode) {
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::is_authenticated(): Checking authentication status.');
        }
        
        $is_valid = $this->has_valid_token();
        $auth_status = $is_valid;
        $last_check = $current_time;
        
        if ($debug_mode) {
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::is_authenticated(): Authentication status: ' . ($is_valid ? 'authenticated' : 'not authenticated'));
        }
        
        return $is_valid;
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
            delete_option('gr_google_docs_access_token');
            return true;
        } catch (Exception $e) {
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::disconnect(): ' . $e->getMessage());
            return false;
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
        try {
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Starting document creation.');
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Document Title: ' . $document_title);
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Template ID: ' . ($template_id ?: 'none (blank document)'));
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Document Body: ' . $document_body);

            // Check authentication
            if (!$this->is_authenticated()) {
                GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::create_document_from_template(): Not authenticated with Google.');
                throw new Exception('Not authenticated with Google.');
            }

            // Create a new document
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Creating new document.');
            $new_file = new Google_Service_Drive_DriveFile(array(
                'name' => $document_title,
                'mimeType' => 'application/vnd.google-apps.document'
            ));

            try {
                $new_file = $this->drive_service->files->create($new_file);
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Successfully created new document. ID: ' . $new_file->getId());
            } catch (Exception $e) {
                GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::create_document_from_template(): Failed to create document. Error: ' . $e->getMessage());
                throw new Exception('Failed to create document. Please check your Google Drive permissions.');
            }

            // If using a template, copy its content
            if ($template_id) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Copying template content.');
                try {
                    $template = $this->drive_service->files->get($template_id);
                    GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Successfully retrieved template document: ' . $template->getName());
                    
                    // Copy the template content to the new document
                    $this->copy_template_content($template_id, $new_file->getId());
                    GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Successfully copied template content.');
                } catch (Exception $e) {
                    GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::create_document_from_template(): Failed to copy template content. Error: ' . $e->getMessage());
                    // Don't throw here, as we still have a new document
                }
            }

            // Add the document body if provided
            if (!empty($document_body)) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Adding document body.');
                try {
                    $this->add_document_body($new_file->getId(), $document_body);
                    GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document_from_template(): Successfully added document body.');
                } catch (Exception $e) {
                    GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::create_document_from_template(): Failed to add document body. Error: ' . $e->getMessage());
                    // Don't throw here, as the document was created successfully
                }
            }

            return $new_file->getId();
        } catch (Exception $e) {
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::create_document_from_template(): Error creating document. Error: ' . $e->getMessage());
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
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::copy_template_content(): Failed to copy template content. Error: ' . $e->getMessage());
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
        try {
            $this->check_rate_limit();
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document(): Starting document creation.');
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document(): Document Title: ' . $document_title);
            if (!empty($folder_id)) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document(): Target Folder ID: ' . $folder_id);
            }

            // Check authentication
            if (!$this->is_authenticated()) {
                GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::create_document(): Not authenticated with Google.');
                throw new Exception('Not authenticated with Google.');
            }

            // Create a new document
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document(): Creating new document.');
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
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document(): Successfully created new document. ID: ' . $new_file->getId());
            } catch (Exception $e) {
                GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::create_document(): Failed to create document. Error: ' . $e->getMessage());
                throw new Exception('Failed to create document. Please check your Google Drive permissions.');
            }

            // Add the document body if provided
            if (!empty($document_body)) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document(): Adding document body.');
                try {
                    $this->add_document_body($new_file->getId(), $document_body);
                    GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::create_document(): Successfully added document body.');
                } catch (Exception $e) {
                    GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::create_document(): Failed to add document body. Error: ' . $e->getMessage());
                    // Don't throw here, as the document was created successfully
                }
            }

            return $new_file->getId();
        } catch (Exception $e) {
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::create_document(): Error creating document. Error: ' . $e->getMessage());
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

            if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::add_document_body(): Successfully added content at index ' . $insertion_index);
            }

            return true;
        } catch (Exception $e) {
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::add_document_body(): Error adding document body. Error: ' . $e->getMessage());
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
            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::append_to_document(): Starting to append content to document ' . $document_id);

            // Get the service
            $service = $this->get_service();
            if (is_wp_error($service)) {
                return $service;
            }

            // Get the current document content
            $document = $service->documents->get($document_id);
            if (!$document) {
                return new WP_Error('document_not_found', esc_html__('Document not found.', 'gr-google-docs'));
            }

            // Create the content to append
            $append_content = array(
                'insertText' => array(
                    'location' => array(
                        'index' => $document->body->content[count($document->body->content) - 1]->endIndex - 1
                    ),
                    'text' => "\n" . $content
                )
            );

            // Execute the request
            $request = array(
                'requests' => array($append_content)
            );

            $response = $service->documents->batchUpdate($document_id, $request);
            if (!$response) {
                return new WP_Error('append_failed', esc_html__('Failed to append content to document.', 'gr-google-docs'));
            }

            GR_Google_Docs::get_instance()->log_debug('GR_Google_Docs_API::append_to_document(): Successfully appended content to document ' . $document_id);
            return $document_id;

        } catch (Exception $e) {
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::append_to_document(): Error appending content to document. Error: ' . $e->getMessage());
            return new WP_Error('append_error', $e->getMessage());
        }
    }

    /**
     * Get document content
     */
    public function get_document_content($document_id) {
        try {
            $this->check_rate_limit();
            $service = new Google_Service_Docs($this->client);
            $document = $service->documents->get($document_id);
            
            $content = '';
            foreach ($document->getBody()->getContent() as $structuralElement) {
                if ($structuralElement->getParagraph()) {
                    $paragraph = $structuralElement->getParagraph();
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
            $this->handle_api_error($e, 'get_document_content');
        }
    }

    /**
     * Update document content
     */
    public function update_document_content($document_id, $content) {
        try {
            $this->check_rate_limit();
            // ... existing update logic ...
            return true;
        } catch (Exception $e) {
            $this->handle_api_error($e, 'update_document_content');
        }
    }

    /**
     * Handle API errors
     */
    private function handle_api_error($e, $context) {
        $error_message = $e->getMessage();
        $error_code = $e->getCode();
        
        // Log the full error details
        GR_Google_Docs::get_instance()->log_error(sprintf(
            'GR_Google_Docs_API::%s(): API Error - Code: %s, Message: %s',
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
        
        // Clear the access token
        delete_option('gr_google_docs_access_token');
        
        // Log the user out
        $this->disconnect();
        
        // Add admin notice
        add_action('admin_notices', function() {
            if (current_user_can('gform_full_access')) {
                echo '<div class="notice notice-error"><p>' . 
                    esc_html__('Your Google authentication has expired. Please reconnect to Google.', 'gr-google-docs') . 
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
            GR_Google_Docs::get_instance()->log_error('GR_Google_Docs_API::get_account_info(): Error getting account info. Error: ' . $e->getMessage());
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
            if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                GR_Google_Docs::get_instance()->log_debug(__METHOD__ . '(): Cache hit for key: ' . $key);
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

        if ($result && defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
            GR_Google_Docs::get_instance()->log_debug(__METHOD__ . '(): Cached data for key: ' . $key . ' (expires in ' . $expiration . 's)');
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

        if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
            GR_Google_Docs::get_instance()->log_debug(__METHOD__ . '(): Cleared all Google Docs API cache');
        }
    }


} 