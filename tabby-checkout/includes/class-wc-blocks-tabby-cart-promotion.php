<?php
use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Tabby Installments payment method integration
 *
 */
final class WC_Blocks_Tabby_Cart_Promotion implements IntegrationInterface {
    CONST SCRIPT_HANDLE = 'tabby-cart-promotion';

    /**
     * Script identifier
     */
    public function get_name() {
        return self::SCRIPT_HANDLE;
    }

    /**
     * Initialization
     */
	public function initialize() {
        $script_path = '/assets/blocks/tabby-cart-promotion/index.js';
        $script_url = plugins_url( $script_path, dirname( __FILE__ ) );

        wp_register_script(
            self::SCRIPT_HANDLE,
            $script_url,
            ['jquery', 'react', 'react-dom', 'wc-blocks-registry', 'wc-settings'],
            $this->get_file_version( $script_path ),
            true
        );
	}

    /**
     * Returns an array of script handles to enqueue in the frontend context.
     *
     * @return string[]
     */
    public function get_script_handles() {
        return array( self::SCRIPT_HANDLE );
    }

    /**
     * Returns an array of script handles to enqueue in the editor context.
     *
     * @return string[]
     */
    public function get_editor_script_handles() {
        return array( self::SCRIPT_HANDLE );
    }

    /**
     * An array of key, value pairs of data made available to the block on the client side.
     *
     * @return array
     */
    public function get_script_data() {
        return WC_Tabby_Promo::getConfig();
    }

    /**
     * Get the file modified time as a cache buster if we're in dev mode.
     *
     * @param string $file Local path to the file.
     * @return string The cache buster value to use for the given file.
     */
    protected function get_file_version( $file ) {
        if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $file ) ) {
            return filemtime( $file );
        }

        return MODULE_TABBY_CHECKOUT_VERSION;
    }
}
