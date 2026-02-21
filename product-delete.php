<?php
/**
 * Plugin Name: Product Delete
 * Plugin URI:  https://github.com/coderjahidul/product-delete
 * Description: Delete WooCommerce products (including thumbnails and gallery images) via REST API with configurable settings.
 * Version:     1.0.0
 * Author:      MD Jahidul Islam Sabuz
 * Author URI:  https://github.com/coderjahidul
 * Tags:        WooCommerce, delete products, bulk delete, product cleanup, product management
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: product-delete
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Product_Delete_Plugin {

    private $option_name = 'product_delete_settings';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
    }

    /**
     * Register REST API endpoint
     */
    public function register_rest_route() {
        register_rest_route(
            'product-delete/v1',
            '/delete-products',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'handle_delete_products_rest_api_request' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Handle delete request
     */
    public function handle_delete_products_rest_api_request( WP_REST_Request $request ) {
        global $wpdb;

        $options = get_option( $this->option_name );
        $limit   = $request->get_param( 'limit' ) ?: ( $options['limit'] ?? 10 );
        $category_ids = $request->get_param( 'category_ids' ) ?: ( $options['category_ids'] ?? [] );

        $query = "SELECT p.ID FROM {$wpdb->posts} p";
        $where = " WHERE p.post_type = 'product' ";

        if ( ! empty( $category_ids ) ) {
            $category_ids_list = implode( ',', array_map( 'intval', (array) $category_ids ) );
            $query .= " JOIN {$wpdb->term_relationships} tr ON (p.ID = tr.object_id)";
            $where .= " AND tr.term_taxonomy_id IN (
                SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} 
                WHERE taxonomy = 'product_cat' AND term_id IN ($category_ids_list)
            )";
        }

        $query .= $where . " GROUP BY p.ID ORDER BY p.ID ASC LIMIT %d";

        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                $query,
                $limit
            )
        );

        if ( empty( $product_ids ) ) {
            return rest_ensure_response( [
                'success' => true,
                'message' => 'No products found to delete.',
            ] );
        }

        $deleted = [];
        $errors  = [];

        foreach ( $product_ids as $product_id ) {
            // Delete thumbnail
            $thumbnail_id = get_post_meta( $product_id, '_thumbnail_id', true );
            if ( $thumbnail_id ) {
                wp_delete_attachment( $thumbnail_id, true );
            }

            // Delete gallery images
            $gallery_ids = get_post_meta( $product_id, '_product_image_gallery', true );
            $gallery_ids = ! empty( $gallery_ids ) ? explode( ',', $gallery_ids ) : [];
            foreach ( $gallery_ids as $gallery_id ) {
                wp_delete_attachment( $gallery_id, true );
            }

            // Delete product
            $result = wp_delete_post( $product_id, true );
            if ( $result ) {
                $deleted[] = $product_id;
            } else {
                $errors[] = $product_id;
            }
        }

        return rest_ensure_response( [
            'success'          => true,
            'requested_limit'  => $limit,
            'category_ids'     => $category_ids,
            'deleted_count'    => count( $deleted ),
            'deleted_ids'      => $deleted,
            'failed_ids'       => $errors,
        ] );
    }

    /**
     * Add admin settings menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Product Delete Settings',
            'Product Delete',
            'manage_options',
            'product-delete',
            [ $this, 'settings_page_html' ]
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting( 'product_delete_group', $this->option_name );

        add_settings_section(
            'product_delete_section',
            'Product Delete Settings',
            null,
            'product-delete'
        );

        add_settings_field(
            'limit',
            'Delete Limit',
            [ $this, 'limit_field_html' ],
            'product-delete',
            'product_delete_section'
        );

        add_settings_field(
            'categories',
            'Product Categories',
            [ $this, 'categories_field_html' ],
            'product-delete',
            'product_delete_section'
        );
    }

    /**
     * Settings field for limit
     */
    public function limit_field_html() {
        $options = get_option( $this->option_name );
        $limit   = $options['limit'] ?? 10;
        echo '<input type="number" name="' . $this->option_name . '[limit]" value="' . esc_attr( $limit ) . '" min="1" />';
    }

    /**
     * Settings field for categories
     */
    public function categories_field_html() {
        $options = get_option( $this->option_name );
        $selected_categories = $options['category_ids'] ?? [];
        $categories = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $categories ) || empty( $categories ) ) {
            echo '<p>No categories found.</p>';
            return;
        }

        echo '<select name="' . $this->option_name . '[category_ids][]" multiple style="min-height: 150px; width: 300px; display: block; margin-bottom: 10px;">';
        foreach ( $categories as $category ) {
            $selected = in_array( $category->term_id, (array)$selected_categories ) ? 'selected' : '';
            echo '<option value="' . esc_attr( $category->term_id ) . '" ' . $selected . '>' . esc_html( $category->name ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Select categories to limit product deletion. Hold Ctrl (Cmd) to select multiple.</p>';
    }

    /**
     * Settings page HTML
     */
    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $endpoint = rest_url( 'product-delete/v1/delete-products' );
        ?>
        <style>
            :root {
                --pd-primary: #2271b1;
                --pd-primary-hover: #135e96;
                --pd-bg: #f0f2f5;
                --pd-card-bg: #ffffff;
                --pd-text: #1d2327;
                --pd-text-muted: #646970;
                --pd-border: #dcdcde;
                --pd-shadow: 0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06);
                --pd-radius: 8px;
            }

            .pd-wrap {
                max-width: 800px;
                margin: 20px auto 40px 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            }

            .pd-header {
                margin-bottom: 30px;
            }

            .pd-header h1 {
                font-size: 28px;
                font-weight: 700;
                color: var(--pd-text);
                margin: 0 0 10px 0;
            }

            .pd-header p {
                font-size: 16px;
                color: var(--pd-text-muted);
                line-height: 1.5;
            }

            .pd-card {
                background: var(--pd-card-bg);
                border: 1px solid var(--pd-border);
                border-radius: var(--pd-radius);
                box-shadow: var(--pd-shadow);
                padding: 30px;
                margin-bottom: 30px;
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }

            .pd-card:hover {
                box-shadow: 0 4px 6px rgba(0,0,0,0.05), 0 10px 15px rgba(0,0,0,0.1);
            }

            .pd-card h2 {
                font-size: 20px;
                font-weight: 600;
                margin: 0 0 20px 0;
                padding-bottom: 15px;
                border-bottom: 1px solid var(--pd-border);
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .pd-card h2 .dashicons {
                color: var(--pd-primary);
                font-size: 24px;
                width: 24px;
                height: 24px;
            }

            /* Form styling */
            .pd-card form .form-table th {
                width: 200px;
                padding: 20px 10px 20px 0;
                font-weight: 600;
            }

            .pd-card input[type="number"],
            .pd-card select[multiple] {
                border: 1px solid var(--pd-border);
                border-radius: 4px;
                padding: 8px 12px;
                transition: border-color 0.2s ease, box-shadow 0.2s ease;
                width: 100%;
                max-width: 400px;
            }

            .pd-card input:focus,
            .pd-card select:focus {
                border-color: var(--pd-primary);
                box-shadow: 0 0 0 1px var(--pd-primary);
                outline: none;
            }

            .pd-card .submit {
                margin-top: 20px;
                padding: 0;
            }

            .pd-card .button-primary {
                background: var(--pd-primary);
                border: none;
                padding: 10px 24px;
                height: auto;
                font-size: 14px;
                font-weight: 600;
                border-radius: 4px;
                transition: background 0.2s ease;
            }

            .pd-card .button-primary:hover {
                background: var(--pd-primary-hover);
            }

            /* API Section */
            .pd-api-box {
                background: var(--pd-bg);
                border-radius: 6px;
                padding: 15px;
                position: relative;
            }

            .pd-endpoint-input {
                width: 100%;
                background: transparent;
                border: none;
                font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 13px;
                color: var(--pd-primary);
                padding-right: 100px;
            }

            .pd-copy-btn {
                position: absolute;
                right: 10px;
                top: 50%;
                transform: translateY(-50%);
            }

            .pd-badge {
                display: inline-block;
                padding: 4px 8px;
                background: #e7f3ff;
                color: #0c63e4;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                margin-bottom: 10px;
            }

            #pd-copy-feedback {
                font-size: 12px;
                color: #22c55e;
                margin-top: 8px;
                display: none;
                align-items: center;
                gap: 4px;
            }
        </style>

        <div class="pd-wrap">
            <header class="pd-header">
                <h1>Product Delete</h1>
                <p>A powerful utility to clean up your WooCommerce store by permanently removing products and their associated media assets.</p>
            </header>

            <div class="pd-card">
                <h2><span class="dashicons dashicons-admin-generic"></span> Configuration</h2>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'product_delete_group' );
                    do_settings_sections( 'product-delete' );
                    submit_button( 'Save Changes', 'button-primary' );
                    ?>
                </form>
            </div>

            <div class="pd-card">
                <span class="pd-badge">Developer Tools</span>
                <h2><span class="dashicons dashicons-rest-api"></span> REST API Integration</h2>
                <p class="description" style="margin-bottom: 20px;">
                    Automate deletions by sending a <code>POST</code> request to this endpoint. Supports <code>limit</code> and <code>category_ids[]</code> parameters.
                </p>
                
                <div class="pd-api-box">
                    <input type="text" id="pd-endpoint" class="pd-endpoint-input" value="<?php echo esc_url( $endpoint ); ?>" readonly />
                    <button type="button" class="button pd-copy-btn" onclick="copyPdEndpoint()">
                        <span class="dashicons dashicons-admin-page" style="font-size: 16px; margin-top:2px;"></span> Copy
                    </button>
                </div>
                
                <div id="pd-copy-feedback">
                    <span class="dashicons dashicons-yes"></span> Endpoint copied to clipboard!
                </div>
            </div>
        </div>

        <script>
            function copyPdEndpoint() {
                const endpointInput = document.getElementById('pd-endpoint');
                const feedback = document.getElementById('pd-copy-feedback');
                
                navigator.clipboard.writeText(endpointInput.value).then(() => {
                    feedback.style.display = 'flex';
                    setTimeout(() => {
                        feedback.style.display = 'none';
                    }, 3000);
                });
            }
        </script>
        <?php
    }

}

new Product_Delete_Plugin();
