<?php
/**
 * Plugin Name: SCM Brevo Campaigns
 * Description: Manage Brevo Email Templates and Campaigns via Gutenberg blocks.
 * Version: 0.1.1
 * Author: Screechy Cat Media
 * Text Domain: scm-brevo-campaigns
 * GitHub Plugin URI: joemathieu/scm-brevo-campaigns
 * GitHub Branch: main
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants
define( 'SCM_BREVO_CAMPAIGNS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCM_BREVO_CAMPAIGNS_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes
spl_autoload_register( function( $class ) {
    if ( 0 !== strpos( $class, 'SCM_Brevo_Campaigns_' ) ) {
        return;
    }
    $file = SCM_BREVO_CAMPAIGNS_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// Initialize plugin
add_action( 'plugins_loaded', function() {
    new SCM_Brevo_Campaigns_Admin();
    new SCM_Brevo_Campaigns_Block_Registrar();
} );
