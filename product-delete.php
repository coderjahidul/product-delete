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

        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                 WHERE post_type = 'product' 
                 ORDER BY ID ASC 
                 LIMIT %d",
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
            'success'         => true,
            'requested_limit' => $limit,
            'deleted_count'   => count( $deleted ),
            'deleted_ids'     => $deleted,
            'failed_ids'      => $errors,
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
     * Settings page HTML
     */
    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $endpoint = rest_url( 'product-delete/v1/delete-products' );
        ?>
        <div class="wrap">
            <h1>Product Delete</h1>
            <p class="description" style="max-width: 700px;">
                Use this tool to permanently delete WooCommerce products along with their featured image and gallery images.
                You can set the delete limit below, and use the REST API endpoint to run automated deletions.
            </p>

            <div style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px; max-width: 700px;">
                <h2 style="margin-top:0;">Settings</h2>
                <form method="post" action="options.php">
                    <?php
                    settings_fields( 'product_delete_group' );
                    do_settings_sections( 'product-delete' );
                    submit_button( 'Save Settings' );
                    ?>
                </form>
            </div>

            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 6px; max-width: 700px;">
                <h2 style="margin-top:0;">API Endpoint</h2>
                <p class="description">
                    You can call this endpoint via <code>POST</code> to delete products.  
                    Use the limit parameter (<code>?limit=20</code>) or rely on the default limit set above.
                </p>
                <input type="text" id="pd-endpoint" value="<?php echo esc_url( $endpoint ); ?>" readonly style="width: 100%; padding:6px;" />
                <p>
                    <button type="button" class="button button-primary" onclick="navigator.clipboard.writeText(document.getElementById('pd-endpoint').value)">Copy Endpoint</button>
                </p>
            </div>
        </div>
        <?php
    }

}

new Product_Delete_Plugin();
