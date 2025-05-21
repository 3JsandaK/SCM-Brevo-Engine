<?php
/**
 * Plugin Name: SCM Brevo Engine
 * Description: Integrates Brevo attributes, lists, and provides [ksj_brevo] shortcode.
 * Version: 1.14.1
 */
require plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://updates.centralcoast.app/scm-brevo-engine/updates.json',
    __FILE__,
    'scm-brevo-engine'
);


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'brevo-settings.php';
new Brevo_Settings();

/**
 * Shortcode: [ksj_brevo]
 * Renders a form using selected Brevo attributes and adds contact to selected list.
 */
function ksj_brevo_shortcode_handler() {
    $selected = get_option( 'ksj_brevo_selected_attributes', array() );
    $api_key  = get_option( 'ksj_brevo_api_key', '' );

    if ( empty( $selected ) || empty( $api_key ) ) {
        return '<p>Please configure your Brevo API key and attributes in the admin settings.</p>';
    }

    // Fetch lists
    $lists = array();
    $resp = wp_remote_get( 'https://api.brevo.com/v3/contacts/lists', array(
        'headers' => array( 'api-key' => $api_key ),
    ) );
    if ( ! is_wp_error( $resp ) ) {
        $body = wp_remote_retrieve_body( $resp );
        $d    = json_decode( $body, true );
        if ( isset( $d['lists'] ) && is_array( $d['lists'] ) ) {
            $lists = $d['lists'];
        } elseif ( is_array( $d ) ) {
            $lists = $d;
        }
    }

    $output = '';

    // Handle form submission
    if (
        'POST' === $_SERVER['REQUEST_METHOD']
        && ! empty( $_POST['ksj_brevo_nonce'] )
        && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ksj_brevo_nonce'] ) ), 'ksj_brevo_submit' )
    ) {
        $submitted = array();
        foreach ( $selected as $attr ) {
            if ( isset( $_POST[ $attr ] ) ) {
                $submitted[ $attr ] = sanitize_text_field( wp_unslash( $_POST[ $attr ] ) );
            }
        }

        $email   = isset( $submitted['EMAIL'] ) ? $submitted['EMAIL'] : '';
        $list_id = isset( $_POST['ksj_brevo_list'] )
            ? intval( wp_unslash( $_POST['ksj_brevo_list'] ) )  // <- cast to integer
            : 0;

        if ( ! is_email( $email ) ) {
            $output .= '<p style="color:red;">Please enter a valid email address.</p>';
        } else {
            $body_arr = array(
                'email'         => $email,
                'attributes'    => $submitted,
                'updateEnabled' => true,
            );
            if ( $list_id ) {
                $body_arr['listIds'] = array( $list_id );  // now a numeric array
            }

            $response = wp_remote_post( 'https://api.brevo.com/v3/contacts', array(
                'headers' => array(
                    'api-key'      => $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body'    => wp_json_encode( $body_arr ),
            ) );

            if ( is_wp_error( $response ) ) {
                $output .= '<p style="color:red;">Error connecting to Brevo: ' . esc_html( $response->get_error_message() ) . '</p>';
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                $body = wp_remote_retrieve_body( $response );
                if ( $code >= 200 && $code < 300 ) {
                    $output .= '<p style="color:green;">Thank you! Your contact has been created or updated and added to the list.</p>';
                } else {
                    $output .= '<p style="color:red;">Brevo error: ' . esc_html( $body ) . '</p>';
                }
            }
        }
    }

    ob_start();
    echo $output;
    echo '<form method="post" class="ksj-brevo-form">';
    wp_nonce_field( 'ksj_brevo_submit', 'ksj_brevo_nonce' );

    // Attribute fields
    foreach ( $selected as $attr ) {
        $val   = isset( $_POST[ $attr ] ) ? esc_attr( wp_unslash( $_POST[ $attr ] ) ) : '';
        $label = ucwords( str_replace( '_', ' ', $attr ) );
        echo '<p><label for="' . esc_attr( $attr ) . '">' . esc_html( $label ) . '</label><br>';
        echo '<input type="text" name="' . esc_attr( $attr ) . '" id="' . esc_attr( $attr ) . '" value="' . $val . '"/></p>';
    }

    // List dropdown
    if ( ! empty( $lists ) ) {
        echo '<p><label for="ksj_brevo_list">Select List</label><br>';
        echo '<select name="ksj_brevo_list" id="ksj_brevo_list"><option value="">-- Choose a list --</option>';
        foreach ( $lists as $list ) {
            $lid   = intval( $list['id'] );
            $lname = esc_html( $list['name'] );
            $sel   = ( isset( $list_id ) && $list_id === $lid ) ? ' selected' : '';
            echo "<option value='{$lid}'{$sel}>{$lname}</option>";
        }
        echo '</select></p>';
    }

    echo '<p><button type="submit">Submit</button></p>';
    echo '</form>';

    return ob_get_clean();
}
add_shortcode( 'ksj_brevo', 'ksj_brevo_shortcode_handler' );
