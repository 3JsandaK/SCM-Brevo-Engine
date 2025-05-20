<?php
/**
 * brevo-settings.php
 * Version: 1.12
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Brevo_Settings {

    public function __construct() {
        add_action( 'admin_menu',           array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init',           array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_brevo_fetch_attributes', array( $this, 'fetch_attributes' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
    }

    public function register_settings() {
        register_setting(
            'ksj_brevo_option_group',
            'ksj_brevo_api_key',
            'sanitize_text_field'
        );
        register_setting(
            'ksj_brevo_option_group',
            'ksj_brevo_selected_attributes',
            array( $this, 'sanitize_attribute_array' )
        );
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( $hook !== 'settings_page_ksj-brevo-integration-admin' ) {
            return;
        }
        wp_enqueue_script(
            'ksj-brevo-admin',
            plugins_url( 'js/admin.js', __FILE__ ),
            array( 'jquery' ),
            null,
            true
        );
        wp_localize_script( 'ksj-brevo-admin', 'ksjBrevoData', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'brevo_fetch_nonce' ),
        ) );
    }

    public function add_plugin_page() {
        add_options_page(
            'KSJ Brevo Integration Settings',
            'KSJ Brevo Integration',
            'manage_options',
            'ksj-brevo-integration-admin',
            array( $this, 'create_admin_page' )
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>KSJ Brevo Integration Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'ksj_brevo_option_group' ); ?>

                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">
                            <label for="ksj_brevo_api_key">Brevo API Key</label>
                        </th>
                        <td>
                            <input
                                type="text"
                                id="ksj_brevo_api_key"
                                name="ksj_brevo_api_key"
                                value="<?php echo esc_attr( get_option( 'ksj_brevo_api_key', '' ) ); ?>"
                                class="regular-text"
                            />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Available Attributes</th>
                        <td>
                            <button
                                id="brevo-refresh-attributes"
                                class="button-secondary"
                            >Refresh Attributes from Brevo</button>
                            <div id="brevo-attribute-results" style="margin-top:8px;"></div>
                            <div id="brevo-attribute-list" style="margin-top:12px;">
                                <?php
                                $saved = get_option( 'ksj_brevo_selected_attributes', array() );
                                foreach ( $saved as $attr ) {
                                    printf(
                                        "<label><input type='checkbox' name='ksj_brevo_selected_attributes[]' value='%s' checked /> %s</label><br>",
                                        esc_attr( $attr ),
                                        esc_html( $attr )
                                    );
                                }
                                ?>
                            </div>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Save Changes' ); ?>
            </form>
        </div>
        <?php
    }

    public function sanitize_attribute_array( $input ) {
        return array_map( 'sanitize_text_field', (array) $input );
    }

    public function fetch_attributes() {
        check_ajax_referer( 'brevo_fetch_nonce', 'security' );

        $api_key = get_option( 'ksj_brevo_api_key', '' );
        if ( ! $api_key ) {
            wp_send_json_error( array( 'message' => 'API Key is missing.' ) );
        }

        $response = wp_remote_get(
            'https://api.brevo.com/v3/contacts/attributes',
            array( 'headers' => array( 'api-key' => $api_key ) )
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array(
                'message' => 'API request failed: ' . $response->get_error_message()
            ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        $flat = array();
        if ( isset( $data['attributes'] ) && is_array( $data['attributes'] ) ) {
            foreach ( $data['attributes'] as $group ) {
                if ( isset( $group['name'] ) ) {
                    $flat[] = $group['name'];
                } elseif ( is_array( $group ) ) {
                    foreach ( $group as $attr ) {
                        if ( isset( $attr['name'] ) ) {
                            $flat[] = $attr['name'];
                        }
                    }
                }
            }
        } elseif ( is_array( $data ) ) {
            foreach ( $data as $attr ) {
                if ( isset( $attr['name'] ) ) {
                    $flat[] = $attr['name'];
                }
            }
        }

        $flat = array_unique( $flat );
        if ( ! in_array( 'EMAIL', $flat, true ) ) {
            array_unshift( $flat, 'EMAIL' );
        }

        $saved = get_option( 'ksj_brevo_selected_attributes', array() );
        $html  = '';
        foreach ( $flat as $name ) {
            $checked = in_array( $name, $saved, true ) ? 'checked' : '';
            $html   .= sprintf(
                "<label><input type='checkbox' name='ksj_brevo_selected_attributes[]' value='%s' %s /> %s</label><br>",
                esc_attr( $name ),
                $checked,
                esc_html( $name )
            );
        }

        wp_send_json_success( array(
            'message'         => 'Attributes fetched!',
            'attributes_html' => $html,
        ) );
    }
}
