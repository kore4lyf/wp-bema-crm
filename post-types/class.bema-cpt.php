<?php
namespace Bema\PostTypes;

if (!defined('ABSPATH')) {
    exit;
}

class BEMA_Post_Type
{
    /**
     * Initialize the post type
     */
    public function __construct()
    {
        add_action('init', array($this, 'create_post_type'));
        add_action('init', array($this, 'register_metadata_table'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_post'), 10, 2);
        add_action('delete_post', array($this, 'delete_post'));

        // Column management
        add_filter('manage_bema_crm_posts_columns', array($this, 'set_custom_columns'));
        add_action('manage_bema_crm_posts_custom_column', array($this, 'render_custom_columns'), 10, 2);
        add_filter('manage_edit-bema_crm_sortable_columns', array($this, 'set_sortable_columns'));
    }

    /**
     * Register the Bema CRM post type
     */
    public function create_post_type()
    {
        $labels = array(
            'name'               => _x('BEMA CRM', 'post type general name', 'bema-crm'),
            'singular_name'      => _x('BEMA Entry', 'post type singular name', 'bema-crm'),
            'menu_name'          => _x('BEMA CRM', 'admin menu', 'bema-crm'),
            'name_admin_bar'     => _x('BEMA Entry', 'add new on admin bar', 'bema-crm'),
            'add_new'           => _x('Add New', 'entry', 'bema-crm'),
            'add_new_item'      => __('Add New Entry', 'bema-crm'),
            'edit_item'         => __('Edit Entry', 'bema-crm'),
            'new_item'          => __('New Entry', 'bema-crm'),
            'view_item'         => __('View Entry', 'bema-crm'),
            'search_items'      => __('Search Entries', 'bema-crm'),
            'not_found'         => __('No entries found', 'bema-crm'),
            'not_found_in_trash' => __('No entries found in trash', 'bema-crm'),
            'parent_item_colon' => '',
            'all_items'         => __('All Entries', 'bema-crm')
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'show_ui'           => true,
            'show_in_menu'      => false, // We'll add this under our custom menu
            'capability_type'   => 'post',
            'hierarchical'      => false,
            'rewrite'           => array('slug' => 'bema-entry'),
            'supports'          => array('title', 'editor'),
            'menu_position'     => 5,
            'menu_icon'         => 'dashicons-database',
            'show_in_rest'      => true, // Enable Gutenberg editor
            'rest_base'         => 'bema-entries',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
        );

        register_post_type('bema_crm', $args);
    }

    /**
     * Register the custom metadata table
     */
    public function register_metadata_table()
    {
        global $wpdb;
        $wpdb->bemameta = $wpdb->prefix . 'bemacrmmeta';
    }

    /**
     * Add custom meta boxes
     */
    public function add_meta_boxes()
    {
        add_meta_box(
            'bema_subscriber_details',
            __('Subscriber Details', 'bema-crm'),
            array($this, 'render_meta_box'),
            'bema_crm',
            'normal',
            'high'
        );
    }

    /**
     * Render the meta box content
     */
    public function render_meta_box($post)
    {
        // Add nonce for security
        wp_nonce_field('bema_subscriber_details', 'bema_subscriber_nonce');

        // Get existing meta data
        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT * FROM {$wpdb->bemameta} WHERE bema_id = %d ORDER BY meta_id DESC LIMIT 1",
            $post->ID
        );
        $data = $wpdb->get_row($query, ARRAY_A);

        // Update metabox path
        require_once BEMA_PATH . 'views/bema_crm_metabox.php';
    }

    /**
     * Set custom columns for the post type
     */
    public function set_custom_columns($columns)
    {
        $columns = array(
            'cb'                    => '<input type="checkbox" />',
            'title'                 => __('Title', 'bema-crm'),
            'bema_tier'             => __('Tier', 'bema-crm'),
            'bema_purchase_indicator' => __('Purchase Indicator', 'bema-crm'),
            'bema_campaign'         => __('Campaign', 'bema-crm'),
            'bema_subscriber'       => __('Subscriber', 'bema-crm'),
            'date'                  => __('Date', 'bema-crm')
        );
        return $columns;
    }

    /**
     * Render custom column content
     */
    public function render_custom_columns($column, $post_id)
    {
        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT * FROM {$wpdb->bemameta} WHERE bema_id = %d ORDER BY meta_id DESC LIMIT 1",
            $post_id
        );
        $data = $wpdb->get_row($query, ARRAY_A);

        switch ($column) {
            case 'bema_tier':
                echo isset($data['tier']) ? esc_html($data['tier']) : '';
                break;
            case 'bema_purchase_indicator':
                if (isset($data['purchase_indicator'])) {
                    echo $data['purchase_indicator'] ?
                        '<span class="purchase-yes">✓</span>' :
                        '<span class="purchase-no">✗</span>';
                }
                break;
            case 'bema_campaign':
                echo isset($data['campaign']) ? esc_html($data['campaign']) : '';
                break;
            case 'bema_subscriber':
                if (isset($data['subscriber'])) {
                    printf(
                        '<a href="mailto:%1$s">%1$s</a>',
                        esc_attr($data['subscriber'])
                    );
                }
                break;
        }
    }

    /**
     * Set sortable columns
     */
    public function set_sortable_columns($columns)
    {
        $columns['bema_tier'] = 'tier';
        $columns['bema_campaign'] = 'campaign';
        $columns['bema_subscriber'] = 'subscriber';
        return $columns;
    }

    /**
     * Save post metadata
     */
    public function save_post($post_id, $post)
    {
        // Verify nonce
        if (
            !isset($_POST['bema_subscriber_nonce']) ||
            !wp_verify_nonce($_POST['bema_subscriber_nonce'], 'bema_subscriber_details')
        ) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (
            'bema_crm' !== $_POST['post_type'] ||
            !current_user_can('edit_post', $post_id)
        ) {
            return;
        }

        // Sanitize and save the data
        $fields = array(
            'tier' => 'text',
            'purchase_indicator' => 'int',
            'campaign' => 'text',
            'mailerlite_group_id' => 'text',
            'date_added' => 'datetime',
            'candidate' => 'text',
            'subscriber' => 'email',
            'source' => 'text'
        );

        $data = array();
        foreach ($fields as $field => $type) {
            if (isset($_POST["bema_{$field}"])) {
                $value = $_POST["bema_{$field}"];
                switch ($type) {
                    case 'int':
                        $data[$field] = intval($value);
                        break;
                    case 'email':
                        $data[$field] = sanitize_email($value);
                        break;
                    case 'datetime':
                        $data[$field] = sanitize_text_field($value);
                        break;
                    default:
                        $data[$field] = sanitize_text_field($value);
                }
            }
        }

        if (!empty($data)) {
            global $wpdb;
            $data['bema_id'] = $post_id;

            $wpdb->insert(
                $wpdb->bemameta,
                $data,
                array_fill(0, count($data), '%s')
            );
        }
    }

    /**
     * Handle post deletion
     */
    public function delete_post($post_id)
    {
        if (!current_user_can('delete_posts')) {
            return;
        }

        if (get_post_type($post_id) === 'bema_crm') {
            global $wpdb;
            $wpdb->delete(
                $wpdb->bemameta,
                array('bema_id' => $post_id),
                array('%d')
            );
        }
    }
}
