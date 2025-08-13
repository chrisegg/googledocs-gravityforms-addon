<?php
/**
 * Main class for the Google Docs Add-On
 *
 * @package GRGoogleDocs
 */

if (!defined('ABSPATH')) {
    exit;
}

// Ensure Gravity Forms is loaded
if (!class_exists('GFForms')) {
    return;
}

// Include the feed add-on framework
GFForms::include_feed_addon_framework();

// Load the Google API handler
require_once __DIR__ . '/class-gr-google-docs-api.php';

/**
 * Main class for the Google Docs Add-On
 */
class GR_Google_Docs extends GFFeedAddOn {
    /**
     * Contains an instance of this class, if available.
     *
     * @since  1.0
     * @access private
     * @var    GR_Google_Docs $_instance If available, contains an instance of this class.
     */
    private static $_instance = null;

    /**
     * Contains an instance of the API handler.
     *
     * @since  1.0
     * @access private
     * @var    GR_Google_Docs_API
     */
    private $api = null;

    /**
     * Defines the version of the Add-On.
     *
     * @since  1.0
     * @access protected
     * @var    string $_version Contains the version.
     */
    protected $_version = GR_GOOGLE_DOCS_VERSION;

    /**
     * Defines the minimum Gravity Forms version required.
     *
     * @since  1.0
     * @access protected
     * @var    string $_min_gravityforms_version The minimum version required.
     */
    protected $_min_gravityforms_version = GR_GOOGLE_DOCS_MIN_GF_VERSION;

    /**
     * Defines the plugin slug.
     *
     * @since  1.0
     * @access protected
     * @var    string $_slug The slug used for this plugin.
     */
    protected $_slug = 'google_docs';

    /**
     * Defines the main plugin file.
     *
     * @since  1.0
     * @access protected
     * @var    string $_path The path to the main plugin file, relative to the plugins folder.
     */
    protected $_path = 'gr-googledocs-addon/gr-google-docs-addon.php';

    /**
     * Defines the full path to this class file.
     *
     * @since  1.0
     * @access protected
     * @var    string $_full_path The full path.
     */
    protected $_full_path = __FILE__;

    /**
     * Defines the URL where this Add-On can be found.
     *
     * @since  1.0
     * @access protected
     * @var    string $_url The URL of the Add-On.
     */
    protected $_url = 'https://gravityranger.com/plugins/googledocs';

    /**
     * Defines the title of this Add-On.
     *
     * @since  1.0
     * @access protected
     * @var    string $_title The title of the Add-On.
     */
    protected $_title = 'Google Docs Add-On for Gravity Forms';

    /**
     * Defines the short title of the Add-On.
     *
     * @since  1.0
     * @access protected
     * @var    string $_short_title The short title.
     */
    protected $_short_title = 'Google Docs';

    /**
     * Defines if Add-On should use Gravity Forms servers for update data.
     *
     * @since  1.0
     * @access protected
     * @var    bool $_enable_rg_autoupgrade Whether to enable auto-upgrade.
     */
    protected $_enable_rg_autoupgrade = true;

    /**
     * Defines the capabilities needed for the Google Docs Add-On.
     *
     * @since  1.0
     * @access protected
     * @var    array $_capabilities The capabilities needed for the Add-On.
     */
    protected $_capabilities = array('gravityforms_google_docs', 'gravityforms_google_docs_uninstall');

    /**
     * Defines the capability needed to access the Add-On settings page.
     *
     * @since  1.0
     * @access protected
     * @var    string $_capabilities_settings_page The capability needed to access the Add-On settings page.
     */
    protected $_capabilities_settings_page = 'gravityforms_google_docs';

    /**
     * Defines the capability needed to access the Add-On form settings page.
     *
     * @since  1.0
     * @access protected
     * @var    string $_capabilities_form_settings The capability needed to access the Add-On form settings page.
     */
    protected $_capabilities_form_settings = 'gravityforms_google_docs';

    /**
     * Defines the capability needed to uninstall the Add-On.
     *
     * @since  1.0
     * @access protected
     * @var    string $_capabilities_uninstall The capability needed to uninstall the Add-On.
     */
    protected $_capabilities_uninstall = 'gravityforms_google_docs_uninstall';

    /**
     * Enable async feed processing to prevent interference with notifications.
     *
     * @since  1.0
     * @access protected
     * @var    bool $_async_feed_processing Whether to enable async processing.
     */
    protected $_async_feed_processing = true;

    /**
     * Get the menu icon for the plugin.
     *
     * @since  1.0
     * @access public
     *
     * @return string
     */
    public function get_menu_icon() {
        $icon_path = plugin_dir_path(__FILE__) . '../assets/img/google.svg';
        
        // Validate file exists and is within plugin directory for security
        $plugin_dir = plugin_dir_path(__FILE__) . '../';
        $real_icon_path = realpath($icon_path);
        $real_plugin_dir = realpath($plugin_dir);
        
        if ($real_icon_path && $real_plugin_dir && 
            strpos($real_icon_path, $real_plugin_dir) === 0 && 
            file_exists($real_icon_path) && 
            pathinfo($real_icon_path, PATHINFO_EXTENSION) === 'svg') {
            
            $svg_content = file_get_contents($real_icon_path);
            
            // Basic SVG validation - ensure it starts with <?xml or <svg
            if ($svg_content && (strpos($svg_content, '<?xml') === 0 || strpos($svg_content, '<svg') === 0)) {
                // Strip any potential PHP tags for security
                $svg_content = preg_replace('/<\?php.*?\?>/s', '', $svg_content);
                return $svg_content;
            }
        }
        
        // Fallback to Font Awesome if SVG file not found or invalid
        return '<i class="fa fa-google" aria-hidden="true"></i>';
    }



    /**
     * Get instance of this class.
     *
     * @since  1.0
     * @access public
     * @static
     *
     * @return GR_Google_Docs
     */
    public static function get_instance() {
        if (self::$_instance == null) {
            self::$_instance = new GR_Google_Docs();
        }
        return self::$_instance;
    }

    /**
     * Initialize the plugin.
     *
     * @since  1.0
     * @access public
     */
    public function init() {
        parent::init();

        // Initialize the API handler
        $this->api = new GR_Google_Docs_API();
        
        // Add OAuth callback handler
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        add_action('admin_init', array($this, 'handle_disconnect'));

        // Add form settings tab
        add_filter('gform_form_settings_menu', array($this, 'add_form_settings_menu'), 10, 2);

        // Add custom CSS for the auth button
        add_action('admin_head', array($this, 'add_auth_button_css'));

        // Add framework scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Initialize AJAX functionality.
     *
     * @since  1.0
     * @access public
     */
    public function init_ajax() {
        parent::init_ajax();

        // Add AJAX actions if needed in the future
        // add_action('wp_ajax_grgoogledocs_deauthorize', array($this, 'ajax_deauthorize'));
    }



    /**
     * Return supported notification events.
     *
     * @since  1.0
     * @access public
     *
     * @param array $form The form object.
     *
     * @return array|false Supported notification events or false if no feeds exist.
     */
    public function supported_notification_events($form) {
        // If this form does not have a Google Docs feed, return false
        if (!$this->has_feed(rgar($form, 'id'))) {
            return false;
        }

        // Return Google Docs notification events
        return array(
            'google_docs_feed_failure' => esc_html__('Google Docs Feed Failure', 'gr-google-docs'),
        );
    }

    /**
     * Enqueue admin scripts.
     *
     * @since  1.0
     * @access public
     */
    public function enqueue_admin_scripts() {
        if ($this->is_feed_edit_page()) {
            // Load Gravity Forms core scripts
            GFForms::enqueue_scripts();
            
            // Define required scripts
            $required_scripts = array(
                'gform_gravityforms',
                'gform_json',
                'gform_form_admin',
                'gform_conditional_logic',
                'gform_settings'
            );
            
            // Enqueue each required script
            foreach ($required_scripts as $script) {
                if (!wp_script_is($script, 'enqueued')) {
                    wp_enqueue_script($script);
                }
            }
            
            // Localize settings script
            wp_localize_script(
                'gform_settings',
                'grGoogleDocsSettings',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('gr_google_docs_settings'),
                    'strings' => array(
                        'error' => esc_html__('Error:', 'gr-google-docs'),
                        'success' => esc_html__('Success:', 'gr-google-docs'),
                    ),
                )
            );
        }
    }

    /**
     * Add custom CSS for the auth button.
     *
     * @since  1.0
     * @access public
     */
    public function add_auth_button_css() {
        ?>
        <style>
            .gr-google-docs-auth-button {
                display: inline-block;
                text-decoration: none;
                font-size: 14px;
                line-height: 2.15384615;
                min-height: 30px;
                margin: 0;
                padding: 0 10px;
                cursor: pointer;
                border-width: 1px;
                border-style: solid;
                -webkit-appearance: none;
                border-radius: 3px;
                white-space: nowrap;
                box-sizing: border-box;
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
                text-decoration: none;
                text-shadow: none;
            }

            .gr-google-docs-auth-button:hover,
            .gr-google-docs-auth-button:focus {
                background: #135e96;
                border-color: #135e96;
                color: #fff;
            }

            .gr-google-docs-auth-button:focus {
                box-shadow: 0 0 0 1px #fff, 0 0 0 3px #2271b1;
            }

            .alert {
                padding: 10px 15px;
                margin: 15px 0;
                border: 1px solid transparent;
                border-radius: 4px;
            }

            .alert-success {
                color: #0f5132;
                background-color: #d1e7dd;
                border-color: #badbcc;
            }

            .alert-warning {
                color: #664d03;
                background-color: #fff3cd;
                border-color: #ffecb5;
            }

            .alert-error {
                color: #842029;
                background-color: #f8d7da;
                border-color: #f5c2c7;
            }

            .gr-google-docs-instructions {
                background: #fff;
                border-left: 4px solid #2271b1;
                padding: 12px 15px;
                margin: 15px 0;
            }

            .gr-google-docs-instructions h4 {
                margin: 0 0 10px 0;
                color: #23282d;
            }

            .gr-google-docs-instructions ol {
                margin: 0 0 10px 0;
                padding-left: 20px;
            }

            .gr-google-docs-instructions li {
                margin-bottom: 5px;
            }

            .gr-google-docs-instructions p {
                margin: 0;
                color: #666;
            }
        </style>
        <?php
    }

    /**
     * Handle the OAuth callback.
     *
     * @since  1.0
     * @access public
     */
    public function handle_oauth_callback() {
        try {
            // Only process if we're on the correct page and have the code parameter
            if (!isset($_GET['page']) || $_GET['page'] !== 'gf_settings' || 
                !isset($_GET['subview']) || $_GET['subview'] !== 'google_docs' || 
                !isset($_GET['code'])) {
                return;
            }

            // Verify user capabilities
            if (!current_user_can('gform_full_access')) {
                wp_die(esc_html__('You do not have permission to access this page.', 'gr-google-docs'));
            }

            // Verify state parameter for CSRF protection
            if (!isset($_GET['state']) || !wp_verify_nonce($_GET['state'], 'gr_google_docs_oauth_state')) {
                wp_die(esc_html__('Security check failed. Invalid OAuth state parameter.', 'gr-google-docs'));
            }

            $debug_mode = defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG;
            
            if ($debug_mode) {
                $this->log_debug(__METHOD__ . '(): Starting OAuth callback handling.');
                $this->log_debug(__METHOD__ . '(): GET parameters: ' . print_r($_GET, true));
            }

            // Get the authorization code and validate it
            $code = sanitize_text_field($_GET['code']);
            if (empty($code) || !preg_match('/^[a-zA-Z0-9\/_\-\.]+$/', $code)) {
                wp_die(esc_html__('Invalid authorization code received.', 'gr-google-docs'));
            }
            
            // Initialize the API
            $api = new GR_Google_Docs_API();
            
            // Exchange the code for tokens
            $success = $api->handle_oauth_callback($code);

            if ($success) {
                // Save the settings to ensure the token is stored
                $settings = $this->get_plugin_settings();
                $this->update_plugin_settings($settings);
                
                GFCommon::add_message(esc_html__('Successfully authenticated with Google.', 'gr-google-docs'));
                if ($debug_mode) {
                    $this->log_debug(__METHOD__ . '(): Successfully authenticated with Google.');
                }
            } else {
                $error_message = esc_html__('Failed to authenticate with Google. Please try again.', 'gr-google-docs');
                GFCommon::add_error_message($error_message);
                $this->log_error(__METHOD__ . '(): ' . $error_message);
            }

            // Redirect back to the settings page
            wp_redirect(add_query_arg(
                array(
                    'page' => 'gf_settings',
                    'subview' => 'google_docs',
                ),
                admin_url('admin.php')
            ));
            exit;
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Error during OAuth callback. Error: ' . $e->getMessage());
            wp_die(esc_html__('An error occurred during authentication. Please try again.', 'gr-google-docs'));
        }
    }

    /**
     * Handle disconnect action.
     *
     * @since  1.0
     * @access public
     */
    public function handle_disconnect() {
        if (!isset($_GET['action']) || $_GET['action'] !== 'disconnect' || !isset($_GET['page']) || $_GET['page'] !== 'gf_settings' || !isset($_GET['subview']) || $_GET['subview'] !== 'google_docs') {
            return;
        }

        // Verify user capabilities
        if (!current_user_can('gform_full_access')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gr-google-docs'));
        }

        // Verify nonce for security
        if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'gr_google_docs_disconnect')) {
            wp_die(esc_html__('Security check failed. Please try again.', 'gr-google-docs'));
        }

        $api = new GR_Google_Docs_API();
        $success = $api->disconnect();

        if ($success) {
            GFCommon::add_message(esc_html__('Successfully disconnected from Google.', 'gr-google-docs'));
            if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                $this->log_debug(__METHOD__ . '(): Successfully disconnected from Google.');
            }
        } else {
            $error_message = esc_html__('Failed to disconnect from Google. Please try again.', 'gr-google-docs');
            GFCommon::add_error_message($error_message);
            $this->log_error(__METHOD__ . '(): ' . $error_message);
        }

        // Redirect back to the settings page
        wp_redirect(add_query_arg(
            array(
                'page' => 'gf_settings',
                'subview' => 'google_docs',
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Process the feed
     *
     * @param array $feed  The feed object to be processed
     * @param array $entry The entry object currently being processed
     * @param array $form  The form object currently being processed
     *
     * @return bool|void
     */
    public function process_feed($feed, $entry, $form) {
        try {
            // Only log feed processing start in debug mode
            if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                $this->log_debug(__METHOD__ . '(): Processing feed.');
            }

            // Validate inputs
            if (!is_array($feed) || !is_array($entry) || !is_array($form)) {
                throw new Exception('Invalid input parameters provided to process_feed.');
            }

            // Get feed settings (match working version exactly)
            $document_title = GFCommon::replace_variables(rgars($feed, 'meta/documentTitle'), $form, $entry, false, true, false, 'text');
            $document_content = GFCommon::replace_variables(rgars($feed, 'meta/documentContent'), $form, $entry, false, true, false, 'text');
            $folder_id = rgars($feed, 'meta/documentFolder');

            if (empty($document_title) || empty($document_content)) {
                throw new Exception('Document title or content is empty.');
            }

            // Initialize API if not already done
            if (!$this->api) {
                $this->api = new GR_Google_Docs_API();
            }

        // Check API authentication
        if (!$this->api->is_authenticated()) {
            $this->add_feed_error(esc_html__('Feed was not processed because API was not authenticated.', 'gr-google-docs'), $feed, $entry, $form);
            return new WP_Error('api_not_authenticated', 'API was not authenticated.');
        }

        // Create the document
        $doc_id = $this->api->create_document($document_title, $document_content, $folder_id);

        if (is_wp_error($doc_id)) {
            $this->add_feed_error(sprintf(
                esc_html__('Failed to create document: %s', 'gr-google-docs'),
                $doc_id->get_error_message()
            ), $feed, $entry, $form);
            return $doc_id; // Return the WP_Error object
        }

        // Handle string response (document ID)
        if (is_string($doc_id)) {
            // Validate document ID format
            if (!preg_match('/^[a-zA-Z0-9_-]{44}$/', $doc_id)) {
                $this->add_feed_error(esc_html__('Invalid document ID format received from Google.', 'gr-google-docs'), $feed, $entry, $form);
                return new WP_Error('invalid_document_id', 'Invalid document ID format received from Google.');
            }
            
            $doc_url = 'https://docs.google.com/document/d/' . sanitize_text_field($doc_id) . '/edit';
            
            // Store the document info in entry meta
            gform_update_meta($entry['id'], 'gr_google_doc_id', sanitize_text_field($doc_id));
            gform_update_meta($entry['id'], 'gr_google_doc_url', esc_url_raw($doc_url));

            // Add note using the framework's built-in note handling
            $this->add_note($entry['id'], sprintf(
                esc_html__('Google Doc created successfully. View document at: %s', 'gr-google-docs'),
                $doc_url
            ), 'success');

            // Always log successful document creation as this is important info
            $this->log_debug(__METHOD__ . '(): Document created successfully. ID: ' . $doc_id);
            return true;
        }

            $this->add_feed_error(esc_html__('Invalid response format from Google Docs API', 'gr-google-docs'), $feed, $entry, $form);
            return new WP_Error('invalid_response_format', 'Invalid response format from Google Docs API');
        } catch (Exception $e) {
            $error_message = sprintf(
                esc_html__('Feed processing failed: %s', 'gr-google-docs'),
                $e->getMessage()
            );
            $this->add_feed_error($error_message, $feed, $entry, $form);
            $this->log_error(__METHOD__ . '(): ' . $error_message);
            return new WP_Error('feed_processing_failed', $error_message);
        }
    }

    /**
     * Add an error note for the feed.
     *
     * @param string $message The error message
     * @param array  $feed    The feed object
     * @param array  $entry   The entry object
     * @param array  $form    The form object
     */
    public function add_feed_error($message, $feed, $entry, $form) {
        // Sanitize message
        $message = sanitize_text_field($message);
        
        // Log the error with context
        $this->log_error(__METHOD__ . '(): ' . $message . ' | Feed ID: ' . rgar($feed, 'id') . ' | Entry ID: ' . rgar($entry, 'id') . ' | Form ID: ' . rgar($form, 'id'));

        // Add an error note to the entry using the framework's method
        $this->add_note(
            rgar($entry, 'id'),
            sprintf(
                esc_html__('Google Docs Feed Error: %s', 'gr-google-docs'),
                $message
            ),
            'error'
        );
        
        // Also add entry meta for tracking
        gform_update_meta(rgar($entry, 'id'), 'gr_google_docs_error', array(
            'message' => $message,
            'feed_id' => rgar($feed, 'id'),
            'timestamp' => current_time('mysql')
        ));
    }

    /**
     * Handle document type change.
     *
     * @since  1.0
     * @access public
     */
    public function handle_document_type_change() {
        // Verify nonce for security
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gforms_save_feed')) {
            wp_die(esc_html__('Security check failed. Please try again.', 'gr-google-docs'));
        }

        // Verify user capabilities
        if (!current_user_can('gform_full_access')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'gr-google-docs'));
        }

        if (!isset($_POST['_gform_setting_documentType'])) {
            return;
        }

        // Get the current feed ID
        $feed_id = rgget('fid');
        if (empty($feed_id)) {
            return;
        }

        // Get the form ID
        $form_id = rgget('id');
        if (empty($form_id)) {
            return;
        }

        // Get the feed
        $feed = $this->get_feed($feed_id);
        if (!$feed) {
            return;
        }

        // Sanitize and validate the document type
        $document_type = sanitize_text_field($_POST['_gform_setting_documentType']);
        if (empty($document_type)) {
            return;
        }

        // Update the feed meta
        $feed['meta']['documentType'] = $document_type;
        $this->update_feed_meta($feed_id, $feed['meta']);

        // Redirect back to the feed edit page
        wp_redirect(add_query_arg(
            array(
                'page' => 'gf_edit',
                'subview' => 'google_docs',
                'id' => $form_id,
                'fid' => $feed_id,
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Configure the feed settings fields.
     *
     * @since  1.0
     * @access public
     *
     * @return array
     */
    public function feed_settings_fields() {
        return array(
            array(
                'title'  => esc_html__('Feed Settings', 'gr-google-docs'),
                'fields' => array(
                    array(
                        'name'     => 'feedName',
                        'label'    => esc_html__('Name', 'gr-google-docs'),
                        'type'     => 'text',
                        'required' => true,
                        'class'    => 'medium',
                        'tooltip'  => esc_html__('Enter a name to uniquely identify this feed.', 'gr-google-docs')
                    ),
                )
            ),
            array(
                'title'  => esc_html__('Document Settings', 'gr-google-docs'),
                'fields' => array(
                    array(
                        'name'          => 'documentTitle',
                        'label'         => esc_html__('Document Title', 'gr-google-docs'),
                        'type'          => 'text',
                        'required'      => true,
                        'class'         => 'merge-tag-support medium',
                        'tooltip'       => esc_html__('Enter the title for the Google Doc. You can use merge tags like {Name:2} or {Date of Entry}. Maximum 400 characters.', 'gr-google-docs'),
                        'placeholder'   => esc_html__('e.g., Application from {Name:2} - {Date of Entry}', 'gr-google-docs'),
                    ),
                    array(
                        'name'          => 'documentContent',
                        'label'         => esc_html__('Document Content', 'gr-google-docs'),
                        'type'          => 'textarea',
                        'required'      => true,
                        'class'         => 'merge-tag-support medium',
                        'tooltip'       => esc_html__('Enter the content template for the Google Doc. Use merge tags to include form data. Supports basic HTML formatting.', 'gr-google-docs'),
                        'placeholder'   => esc_html__("Name: {Name:2}\nEmail: {Email:3}\nMessage: {Message:4}\n\nSubmitted on: {Date of Entry}", 'gr-google-docs'),
                    ),
                    array(
                        'name'          => 'documentFolder',
                        'label'         => esc_html__('Google Drive Folder ID (Optional)', 'gr-google-docs'),
                        'type'          => 'text',
                        'class'         => 'medium',
                        'tooltip'       => esc_html__('Enter the Google Drive folder ID where documents should be created. Leave blank to create in your root Drive folder. Find this ID in the folder URL: https://drive.google.com/drive/folders/[FOLDER_ID]', 'gr-google-docs'),
                        'placeholder'   => esc_html__('e.g., 1EM5x98stf2OItV9CFFw6r-qYmEHuZ5jA', 'gr-google-docs'),
                    ),
                ),
            ),
            array(
                'title'  => esc_html__('Feed Conditional Logic', 'gr-google-docs'),
                'fields' => array(
                    array(
                        'name'           => 'feedCondition',
                        'type'           => 'feed_condition',
                        'label'          => esc_html__('Conditional Logic', 'gr-google-docs'),
                        'checkbox_label' => esc_html__('Enable', 'gr-google-docs'),
                        'instructions'   => esc_html__('Export to Google Docs if', 'gr-google-docs'),
                    ),
                ),
            ),
        );
    }

    /**
     * Get the field map for feed settings.
     *
     * @return array
     */
    public function get_field_map() {
        return array(
            array(
                'label' => esc_html__('Select a Template Variable', 'gr-google-docs'),
                'value' => '',
            ),
            array(
                'label' => esc_html__('Add New Template Variable', 'gr-google-docs'),
                'value' => 'gf_custom',
            ),
        );
    }

    /**
     * Define the columns to be displayed in the feed list table.
     *
     * @return array
     */
    public function feed_list_columns() {
        return array(
            'feedName' => esc_html__('Name', 'gr-google-docs'),
            'documentTitle' => esc_html__('Document Title', 'gr-google-docs'),
        );
    }

    /**
     * Get the value for the feed list table columns.
     *
     * @param array  $feed The feed being included in the feed list.
     * @param string $column The column name.
     *
     * @return string
     */
    public function get_column_value($feed, $column) {
        switch ($column) {
            case 'documentTitle':
                return rgars($feed, 'meta/documentTitle');
            default:
                return parent::get_column_value($feed, $column);
        }
    }

    /**
     * Plugin settings fields.
     *
     * @since  1.0
     * @access public
     *
     * @return array
     */
    public function plugin_settings_fields() {
        return array(
            array(
                'title'  => esc_html__('Google Docs Settings', 'gr-google-docs'),
                'description' => '<p>' . esc_html__('Connect your site to Google Docs to enable document creation from form submissions.', 'gr-google-docs') . '</p>
                    <div class="gr-google-docs-instructions">
                        <h4>' . esc_html__('How to Set Up:', 'gr-google-docs') . '</h4>
                        <ol>
                            <li>' . sprintf(
                                esc_html__('Go to the %sGoogle Cloud Console%s.', 'gr-google-docs'),
                                '<a href="https://console.cloud.google.com/" target="_blank">',
                                '</a>'
                            ) . '</li>
                            <li>' . esc_html__('Create a new project or select an existing one.', 'gr-google-docs') . '</li>
                            <li>' . esc_html__('Enable the Google Docs API and Google Drive API for your project.', 'gr-google-docs') . '</li>
                            <li>' . esc_html__('Go to Credentials and create OAuth 2.0 Client ID credentials (Web Application).', 'gr-google-docs') . '</li>
                            <li>' . esc_html__('Add your site\'s domain to the authorized domains.', 'gr-google-docs') . '</li>
                            <li>' . esc_html__('Add the redirect URI: ', 'gr-google-docs') . '<code>' . esc_url(add_query_arg(
                                array(
                                    'page' => 'gf_settings',
                                    'subview' => 'google_docs'
                                ),
                                admin_url('admin.php')
                            )) . '</code></li>
                            <li>' . esc_html__('Copy the Client ID and Client Secret below.', 'gr-google-docs') . '</li>
                            <li>' . esc_html__('Click "Save Settings" and then "Connect with Google".', 'gr-google-docs') . '</li>
                        </ol>
                        <p>' . esc_html__('Once connected, you can create feeds in your forms to automatically create Google Docs from form submissions.', 'gr-google-docs') . '</p>
                    </div>',
                'fields' => array(
                    array(
                        'name'              => 'client_id',
                        'label'             => esc_html__('Google OAuth Client ID', 'gr-google-docs'),
                        'type'              => 'text',
                        'class'             => 'medium',
                        'feedback_callback' => array($this, 'is_valid_setting'),
                        'tooltip'           => esc_html__('Enter your Google OAuth 2.0 Client ID from the Google Cloud Console. This looks like: 123456789-abcdefg.googleusercontent.com', 'gr-google-docs'),
                        'placeholder'       => esc_html__('e.g., 123456789-abcdefg.googleusercontent.com', 'gr-google-docs'),
                    ),
                    array(
                        'name'              => 'client_secret',
                        'label'             => esc_html__('Google OAuth Client Secret', 'gr-google-docs'),
                        'type'              => 'text',
                        'class'             => 'medium',
                        'feedback_callback' => array($this, 'is_valid_setting'),
                        'tooltip'           => esc_html__('Enter your Google OAuth 2.0 Client Secret from the Google Cloud Console. Keep this value confidential.', 'gr-google-docs'),
                        'placeholder'       => esc_html__('e.g., GOCSPX-1234567890abcdefghijklmnop', 'gr-google-docs'),
                    ),
                    array(
                        'name'              => 'rate_limit',
                        'label'             => esc_html__('API Rate Limit (per minute)', 'gr-google-docs'),
                        'type'              => 'text',
                        'class'             => 'small',
                        'default_value'     => '100',
                        'tooltip'           => esc_html__('Maximum number of API requests per minute. Default: 100', 'gr-google-docs'),
                    ),
                    array(
                        'type'    => 'save',
                        'messages' => array(
                            'success' => esc_html__('Settings have been saved. You can now connect with Google.', 'gr-google-docs'),
                        ),
                    ),
                    array(
                        'type'    => 'auth_button',
                        'name'    => 'auth_button',
                        'label'   => esc_html__('Authentication', 'gr-google-docs'),
                        'tooltip' => esc_html__('Click to connect or disconnect from Google Docs.', 'gr-google-docs'),
                    ),
                    array(
                        'type' => 'google_docs_status_message',
                    ),
                ),
            ),
        );
    }

    /**
     * Render the auth button field.
     *
     * @since  1.0
     * @access public
     *
     * @param array $field The field properties.
     * @param bool  $echo Whether to echo the output.
     *
     * @return string
     */
    public function render_auth_button($field, $echo = true) {
        try {
            $api = new GR_Google_Docs_API();
            
            // Check if we have valid client credentials first
            $client_id = $this->get_plugin_setting('client_id');
            $client_secret = $this->get_plugin_setting('client_secret');
            
            if (empty($client_id) || empty($client_secret)) {
                $html = '<div class="alert alert-warning">' . 
                    esc_html__('Please enter your Google OAuth credentials above and save settings before connecting.', 'gr-google-docs') . 
                    '</div>';
            } else {
                // Check if we're authenticated
                if ($api->is_authenticated()) {
                    $html = sprintf(
                        '<a href="%s" class="gr-google-docs-auth-button">%s</a>',
                        esc_url(add_query_arg(
                            array(
                                'page' => 'gf_settings',
                                'subview' => 'google_docs',
                                'action' => 'disconnect',
                                'nonce' => wp_create_nonce('gr_google_docs_disconnect'),
                            ),
                            admin_url('admin.php')
                        )),
                        esc_html__('Disconnect from Google', 'gr-google-docs')
                    );

                } else {
                    $auth_url = $api->get_auth_url();
                    if (!empty($auth_url)) {
                        $html = sprintf(
                            '<a href="%s" class="gr-google-docs-auth-button">%s</a>',
                            esc_url($auth_url),
                            esc_html__('Connect with Google', 'gr-google-docs')
                        );
                    } else {
                        $html = '<div class="alert alert-error">' . 
                            esc_html__('Unable to generate authentication URL. Please check your credentials.', 'gr-google-docs') . 
                            '</div>';
                    }
                }
            }

            if ($echo) {
                echo $html;
            }

            return $html;
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Error rendering auth button. Error: ' . $e->getMessage());
            $error_message = '<div class="alert alert-error">' . 
                esc_html__('Error rendering authentication button. Please check the logs.', 'gr-google-docs') . 
                '</div>';
            
            if ($echo) {
                echo $error_message;
            }
            return $error_message;
        }
    }

    /**
     * Display the status message for Google Docs connection.
     *
     * @since  1.0
     * @access public
     */
    public function settings_google_docs_status_message() {
        $client_id = $this->get_plugin_setting('client_id');
        $client_secret = $this->get_plugin_setting('client_secret');
        
        // Don't show status if credentials aren't entered yet
        if (empty($client_id) || empty($client_secret)) {
            return;
        }

        try {
            $api = new GR_Google_Docs_API();
            
            // Check if we have a stored access token (indicating a connection attempt was made)
            $has_token = get_option('gr_google_docs_access_token');
            
            if (!$has_token) {
                // No connection attempt made yet - show neutral "ready to connect" message
                printf(
                    '<div class="alert" style="background-color: #f0f8ff; border-color: #0073aa; color: #0073aa;">%s</div>',
                    esc_html__('Ready to connect. Click "Connect with Google" above to authenticate.', 'gr-google-docs')
                );
                return;
            }
            
            // Connection attempt was made - validate the connection
            $account_info = $api->validate_connection();
            
            if (!$account_info) {
                printf(
                    '<div class="alert gforms_note_error">%s</div>',
                    esc_html__('Connection failed. Please check your credentials and try connecting again.', 'gr-google-docs')
                );
                return;
            }

            // Display success message with checkmark styling
            printf(
                '<div class="alert gforms_note_success">%s<br/>%s: %s</div>',
                esc_html__('Successfully connected to Google Docs!', 'gr-google-docs'),
                esc_html__('Account Email', 'gr-google-docs'),
                esc_html($account_info['email'])
            );
        } catch (Exception $e) {
            printf(
                '<div class="alert gforms_note_error">%s</div>',
                esc_html__('Unable to verify connection status.', 'gr-google-docs')
            );
        }
    }

    /**
     * Process merge tags with optimization and validation.
     *
     * @since  1.0
     * @access private
     *
     * @param string $template The template containing merge tags.
     * @param array  $form     The form object.
     * @param array  $entry    The entry object.
     * @param string $format   The output format ('text' or 'html').
     *
     * @return string The processed template with merge tags replaced.
     */
    private function process_merge_tags($template, $form, $entry, $format = 'text') {
        if (empty($template)) {
            return '';
        }

        // Validate inputs
        if (!is_array($form) || !is_array($entry)) {
            if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                $this->log_debug(__METHOD__ . '(): Invalid form or entry data provided for merge tag processing');
            }
            return $template;
        }

        // Cache key for merge tag processing
        $cache_key = 'merge_tags_' . md5($template . serialize($entry));
        
        // Check if we have cached result (short cache for performance)
        static $merge_cache = array();
        if (isset($merge_cache[$cache_key])) {
            if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                $this->log_debug(__METHOD__ . '(): Using cached merge tag result');
            }
            return $merge_cache[$cache_key];
        }

        try {
            // Process merge tags with proper formatting
            $processed = GFCommon::replace_variables(
                $template,
                $form,
                $entry,
                false,    // url_encode
                false,    // esc_html (disable for text format)
                false,    // nl2br
                $format   // format
            );

            // For text format, strip HTML and convert to plain text
            if ($format === 'text') {
                // Strip HTML tags and decode entities
                $processed = wp_strip_all_tags($processed);
                $processed = html_entity_decode($processed, ENT_QUOTES, 'UTF-8');
                $processed = sanitize_text_field($processed);
            } else {
                $processed = wp_kses_post($processed);
            }

            // Cache the result (limit cache size)
            if (count($merge_cache) < 50) {
                $merge_cache[$cache_key] = $processed;
            }

            if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                $this->log_debug(__METHOD__ . '(): Successfully processed merge tags');
            }

            return $processed;

        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Error processing merge tags: ' . $e->getMessage());
            return $template; // Return original template on error
        }
    }

    /**
     * Log and handle errors in a consistent way.
     *
     * @since  1.0
     * @access private
     *
     * @param string $error_message The error message to log.
     * @param string $context       Optional. Additional context information.
     * @param array  $data          Optional. Additional data to log.
     */
    private function handle_error($error_message, $context = '', $data = array()) {
        // Always log errors regardless of debug mode
        $log_message = $error_message;
        if (!empty($context)) {
            $log_message = $context . ': ' . $error_message;
        }
        
        $this->log_error($log_message);
        
        // Log additional data in debug mode
        if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG && !empty($data)) {
            $this->log_debug('Additional error data: ' . wp_json_encode($data));
        }
        
        // Add admin notice for critical errors if user can manage options
        if (is_admin() && current_user_can('gform_full_access')) {
            add_action('admin_notices', function() use ($error_message) {
                printf(
                    '<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
                    esc_html__('Google Docs Add-On Error', 'gr-google-docs'),
                    esc_html($error_message)
                );
            });
        }
    }

    /**
     * Determine if feeds can be created.
     *
     * @since  1.0
     * @access public
     *
     * @return bool True if feeds can be created, false otherwise.
     */
    public function can_create_feed() {
        // Check if plugin settings are configured
        $settings = $this->get_plugin_settings();
        $client_id = rgar($settings, 'client_id');
        $client_secret = rgar($settings, 'client_secret');
        
        if (empty($client_id) || empty($client_secret)) {
            return false;
        }
        
        // Check if API is authenticated
        try {
            $api = new GR_Google_Docs_API();
            return $api->is_authenticated();
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Error checking authentication: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Enable feed duplication.
     *
     * @since  1.0
     * @access public
     *
     * @param int|array $id The ID of the feed to be duplicated or the feed object when duplicating a form.
     *
     * @return bool True if feed can be duplicated, false otherwise.
     */
    public function can_duplicate_feed($id) {
        return $this->can_create_feed();
    }

    /**
     * Check if the settings are valid.
     *
     * @since  1.0
     * @access public
     *
     * @param string $value The value to check.
     *
     * @return bool
     */
    public function is_valid_setting($value) {
        return !empty($value);
    }

    /**
     * Validate plugin settings and clear cache when needed.
     *
     * @since  1.0
     * @access public
     *
     * @param array $settings Plugin settings to be validated.
     *
     * @return array Validated settings.
     */
    public function plugin_settings_validation($settings) {
        try {
            // Get current settings to compare
            $current_settings = $this->get_plugin_settings();
            $current_client_id = rgar($current_settings, 'client_id');
            $current_client_secret = rgar($current_settings, 'client_secret');
            
            $new_client_id = rgar($settings, 'client_id');
            $new_client_secret = rgar($settings, 'client_secret');
            
            // If credentials changed, clear the stored token so status resets
            if ($current_client_id !== $new_client_id || $current_client_secret !== $new_client_secret) {
                delete_option('gr_google_docs_access_token');
                
                if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                    $this->log_debug(__METHOD__ . '(): Cleared access token due to credential change');
                }
            }
            
            // Clear cache when settings change to force re-validation
            if (!empty($settings['client_id']) && !empty($settings['client_secret'])) {
                $api = new GR_Google_Docs_API();
                $api->clear_all_cache();
                
                if (defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG) {
                    $this->log_debug(__METHOD__ . '(): Cleared API cache due to settings change');
                }
            }

            return $settings;
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Settings validation failed. Error: ' . $e->getMessage());
            return $settings;
        }
    }



    /**
     * Add form settings menu.
     *
     * @since  1.0
     * @access public
     *
     * @param array $menu_items The menu items array.
     * @param int   $form_id The form ID.
     *
     * @return array
     */
    public function add_form_settings_menu($menu_items, $form_id) {
        $menu_items[] = array(
            'name'          => 'google_docs',
            'label'         => $this->_short_title,
            'icon'          => 'fa-google',
            'callback'      => array($this, 'feed_settings_page'),
            'permission'    => $this->_capabilities_form_settings,
        );
        return $menu_items;
    }

    /**
     * Display the feed settings page.
     *
     * @since  1.0
     * @access public
     */
    public function feed_settings_page() {
        try {
            // Only log detailed steps if debug mode is enabled
            $debug_mode = defined('GR_GOOGLE_DOCS_DEBUG') && GR_GOOGLE_DOCS_DEBUG;
            
            if ($debug_mode) {
                $this->log_debug(__METHOD__ . '(): Starting to render feed settings page.');
            }
            
            // Get form ID
            $form_id = rgget('id');
            if (empty($form_id)) {
                $this->log_error(__METHOD__ . '(): No form ID provided.');
                wp_die(esc_html__('No form ID provided.', 'gr-google-docs'));
            }

            // Get form
            $form = GFAPI::get_form($form_id);
            if (!$form) {
                $this->log_error(__METHOD__ . '(): Form not found.');
                wp_die(esc_html__('Form not found.', 'gr-google-docs'));
            }

            if ($debug_mode) {
                $this->log_debug(__METHOD__ . '(): Retrieved form #' . $form_id);
            }

            // Display the page
            GFFormSettings::page_header($form);
            $feed_list = $this->get_feeds($form_id);
            $this->feed_list_page($feed_list, $form);
            GFFormSettings::page_footer();

            if ($debug_mode) {
                $this->log_debug(__METHOD__ . '(): Successfully rendered feed settings page.');
            }
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Error rendering feed settings page: ' . $e->getMessage());
            wp_die(esc_html__('Error loading feed settings page.', 'gr-google-docs'));
        }
    }



    /**
     * Get template document choices.
     *
     * @since  1.0
     * @access private
     *
     * @return array
     */
    private function get_template_choices() {
        try {
            $choices = array(
                array(
                    'label' => esc_html__('Select a Template', 'gr-google-docs'),
                    'value' => '',
                ),
            );

            // Get API instance
            $api = new GR_Google_Docs_API();

            // Check if we're authenticated
            if (!$api->is_authenticated()) {
                $choices[] = array(
                    'label' => esc_html__('Please connect to Google first', 'gr-google-docs'),
                    'value' => 'not_connected',
                );
                return $choices;
            }

            // Get list of documents
            $documents = $api->list_documents();
            if (is_wp_error($documents)) {
                $choices[] = array(
                    'label' => esc_html__('Error loading templates', 'gr-google-docs'),
                    'value' => 'error',
                );
                return $choices;
            }

            // Add documents to choices
            foreach ($documents as $document) {
                $choices[] = array(
                    'label' => esc_html($document['title']),
                    'value' => esc_attr($document['id']),
                );
            }

            return $choices;
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Error getting template choices. Error: ' . $e->getMessage());
            return array(
                array(
                    'label' => esc_html__('Error loading templates', 'gr-google-docs'),
                    'value' => 'error',
                ),
            );
        }
    }

    /**
     * Validate feed settings.
     *
     * @since  1.0
     * @access public
     *
     * @param array $feed The feed object to validate.
     *
     * @return bool
     */
    public function validate_feed_settings($feed) {
        try {
            $this->log_debug(__METHOD__ . '(): Starting feed validation.');
            
            // Get feed meta
            $feed_meta = rgar($feed, 'meta', array());
            
            // Validate feed name
            if (empty($feed_meta['feedName'])) {
                $this->set_field_error('feedName', esc_html__('Please enter a feed name.', 'gr-google-docs'));
                return false;
            }
            
            // Validate document title
            if (empty($feed_meta['documentTitle'])) {
                $this->set_field_error('documentTitle', esc_html__('Please enter a document title.', 'gr-google-docs'));
                return false;
            }
            
            // Validate document content
            if (empty($feed_meta['documentContent'])) {
                $this->set_field_error('documentContent', esc_html__('Please enter document content.', 'gr-google-docs'));
                return false;
            }
            
            // Validate document content for merge tags if any are specified
            $content = $feed_meta['documentContent'];
            if (strpos($content, '{') !== false) {
                // Basic merge tag validation
                if (!preg_match('/\{[^}]+\}/', $content)) {
                    $this->set_field_error('documentContent', esc_html__('Invalid merge tag format detected.', 'gr-google-docs'));
                    return false;
                }
            }
            
            $this->log_debug(__METHOD__ . '(): Feed validation successful.');
            return true;
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Feed validation failed. Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate plugin settings.
     *
     * @since  1.0
     * @access public
     *
     * @param array $fields The fields to validate.
     * @param array $settings The settings to validate.
     *
     * @return array
     */
    public function validate_settings($fields, $settings) {
        try {
            $this->log_debug(__METHOD__ . '(): Starting settings validation.');
            
            // Get parent validation
            $settings = parent::validate_settings($fields, $settings);
            
            // Validate client ID
            if (empty($settings['client_id'])) {
                $this->set_field_error('client_id', esc_html__('Please enter your Google Client ID.', 'gr-google-docs'));
            }
            
            // Validate client secret
            if (empty($settings['client_secret'])) {
                $this->set_field_error('client_secret', esc_html__('Please enter your Google Client Secret.', 'gr-google-docs'));
            }
            
            // Validate client ID format
            if (!empty($settings['client_id']) && !preg_match('/^[0-9]+-[a-zA-Z0-9_]+\.apps\.googleusercontent\.com$/', $settings['client_id'])) {
                $this->set_field_error('client_id', esc_html__('Invalid Google Client ID format.', 'gr-google-docs'));
            }
            
            // Validate client secret format
            if (!empty($settings['client_secret']) && strlen($settings['client_secret']) < 20) {
                $this->set_field_error('client_secret', esc_html__('Invalid Google Client Secret format.', 'gr-google-docs'));
            }
            
            // Validate rate limit
            if (isset($settings['rate_limit'])) {
                $rate_limit = absint($settings['rate_limit']);
                if ($rate_limit < 1 || $rate_limit > 1000) {
                    $this->set_field_error('rate_limit', esc_html__('Rate limit must be between 1 and 1000 requests per minute.', 'gr-google-docs'));
                } else {
                    $settings['rate_limit'] = $rate_limit;
                }
            }
            
            $this->log_debug(__METHOD__ . '(): Settings validation completed.');
            return $settings;
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Settings validation failed. Error: ' . $e->getMessage());
            return $settings;
        }
    }

    /**
     * Register custom field types.
     *
     * @since  1.0
     * @access public
     */
    public function register_custom_fields($field_groups) {
        foreach ($field_groups as &$group) {
            if ($group['name'] === 'standard_fields') {
                $group['fields'][] = array(
                    'class' => 'button',
                    'data-type' => 'auth_button',
                    'value' => esc_html__('Auth Button', 'gr-google-docs'),
                );
                break;
            }
        }
        return $field_groups;
    }

    /**
     * Render custom field types.
     *
     * @since  1.0
     * @access public
     *
     * @param string $input    The field input markup.
     * @param array  $field    The field properties.
     * @param string $value    The field value.
     * @param int    $entry_id The entry ID.
     * @param int    $form_id  The form ID.
     *
     * @return string
     */
    public function render_custom_fields($input, $field, $value, $entry_id, $form_id) {
        if ($field['type'] === 'auth_button') {
            return $this->render_auth_button($field, false);
        }
        return $input;
    }

    /**
     * Get field type configuration for custom fields.
     *
     * @since  1.0
     * @access public
     *
     * @return array
     */
    public function get_field_type_configuration() {
        return array(
            'auth_button' => array(
                'template' => $this->get_base_path() . '/templates/auth-button.php',
                'callback' => array($this, 'render_auth_button'),
            ),
        );
    }

    /**
     * Get base path for the plugin.
     *
     * @since  1.0
     * @access public
     *
     * @param string $full_path Optional. The full path to the plugin directory.
     * @return string
     */
    public function get_base_path($full_path = '') {
        if (!empty($full_path)) {
            return $full_path;
        }
        return plugin_dir_path(__FILE__);
    }

    /**
     * Render the auth button in settings.
     *
     * @since  1.0
     * @access public
     *
     * @param array $field The field properties.
     * @param bool  $echo  Whether to echo the output.
     *
     * @return string
     */
    public function settings_auth_button($field, $echo = true) {
        try {
            $api = new GR_Google_Docs_API();
            
            // Check if we have valid client credentials first
            $client_id = $this->get_plugin_setting('client_id');
            $client_secret = $this->get_plugin_setting('client_secret');
            
            if (empty($client_id) || empty($client_secret)) {
                $html = '<div class="alert alert-warning">' . 
                    esc_html__('Please enter your Google OAuth credentials above and save settings before connecting.', 'gr-google-docs') . 
                    '</div>';
            } else {
                // Check if we're authenticated
                if ($api->is_authenticated()) {
                    $html = sprintf(
                        '<a href="%s" class="gr-google-docs-auth-button">%s</a>',
                        esc_url(add_query_arg(
                            array(
                                'page' => 'gf_settings',
                                'subview' => 'google_docs',
                                'action' => 'disconnect',
                                'nonce' => wp_create_nonce('gr_google_docs_disconnect'),
                            ),
                            admin_url('admin.php')
                        )),
                        esc_html__('Disconnect from Google', 'gr-google-docs')
                    );

                } else {
                    $auth_url = $api->get_auth_url();
                    if (!empty($auth_url)) {
                        $html = sprintf(
                            '<a href="%s" class="gr-google-docs-auth-button">%s</a>',
                            esc_url($auth_url),
                            esc_html__('Connect with Google', 'gr-google-docs')
                        );
                    } else {
                        $html = '<div class="alert alert-error">' . 
                            esc_html__('Unable to generate authentication URL. Please check your credentials.', 'gr-google-docs') . 
                            '</div>';
                    }
                }
            }

            if ($echo) {
                echo $html;
            }

            return $html;
        } catch (Exception $e) {
            $this->log_error(__METHOD__ . '(): Error rendering auth button. Error: ' . $e->getMessage());
            $error_message = '<div class="alert alert-error">' . 
                esc_html__('Error rendering authentication button. Please check the logs.', 'gr-google-docs') . 
                '</div>';
            
            if ($echo) {
                echo $error_message;
            }
            return $error_message;
        }
    }
} 