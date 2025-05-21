<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin: CPTs, meta-boxes, Brevo API sync for Templates & Campaigns
 */
class SCM_Brevo_Campaigns_Admin {

    public function __construct() {
        add_action( 'init',          [ $this, 'register_post_types' ] );
        add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
        add_action( 'save_post',      [ $this, 'save_meta' ], 10, 2 );
    }

    public function register_post_types() {
        register_post_type( 'scm_email_template', [
            'labels'       => [
                'name'          => 'Email Templates',
                'singular_name' => 'Email Template',
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-email-alt',
            'supports'     => [ 'title' ],
            'rewrite'      => false,
        ] );

        register_post_type( 'scm_email_campaign', [
            'labels'       => [
                'name'          => 'Email Campaigns',
                'singular_name' => 'Email Campaign',
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-megaphone',
            'supports'     => [ 'title' ],
            'rewrite'      => false,
        ] );
    }

    public function register_meta_boxes() {
        add_meta_box(
            'scm_template_fields',
            'Template Settings',
            [ $this, 'render_template_metabox' ],
            'scm_email_template',
            'normal',
            'high'
        );

        add_meta_box(
            'scm_campaign_fields',
            'Campaign Settings',
            [ $this, 'render_campaign_metabox' ],
            'scm_email_campaign',
            'normal',
            'high'
        );
    }

    public function render_template_metabox( $post ) {
        wp_nonce_field( 'scm_save_template', 'scm_template_nonce' );
        $subject = get_post_meta( $post->ID, '_scm_template_subject', true );
        $sender  = get_post_meta( $post->ID, '_scm_template_sender',  true );
        $content = get_post_meta( $post->ID, '_scm_template_content', true );
        ?>
        <p><label>Subject:<br>
            <input type="text" name="scm_template_subject" value="<?php echo esc_attr( $subject ); ?>" class="widefat">
        </label></p>
        <p><label>Sender (either “Name <email>” or just email):<br>
            <input type="text" name="scm_template_sender" value="<?php echo esc_attr( $sender ); ?>" class="widefat">
        </label></p>
        <p><label>HTML Content:</label><br>
            <?php
            wp_editor(
                wp_kses_post( $content ),
                'scm_template_content',
                [
                    'textarea_name' => 'scm_template_content',
                    'textarea_rows' => 10,
                ]
            );
            ?>
        </p>
        <?php
    }

    public function render_campaign_metabox( $post ) {
        wp_nonce_field( 'scm_campaign_nonce', 'scm_campaign_nonce' );
        $tpl_id   = get_post_meta( $post->ID, '_scm_campaign_template', true );
        $list_ids = get_post_meta( $post->ID, '_scm_campaign_lists', true ) ?: [];
        $schedule = get_post_meta( $post->ID, '_scm_campaign_schedule', true );

        $templates = get_posts([
            'post_type'   => 'scm_email_template',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);
        ?>
        <p><label>Template:<br>
            <select name="scm_campaign_template" class="widefat">
                <option value="">— Select a Template —</option>
                <?php foreach ( $templates as $tpl ) : ?>
                    <option value="<?php echo esc_attr( $tpl->ID ); ?>" <?php selected( $tpl_id, $tpl->ID ); ?>>
                        <?php echo esc_html( $tpl->post_title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label></p>
        <p><label>Brevo List IDs (comma-separated):<br>
            <input type="text" name="scm_campaign_lists" value="<?php echo esc_attr( implode( ',', (array) $list_ids ) ); ?>" class="widefat">
        </label></p>
        <p><label>Scheduled At:<br>
            <input type="datetime-local" name="scm_campaign_schedule" value="<?php echo esc_attr( $schedule ); ?>" class="widefat">
        </label></p>
        <?php
    }

    public function save_meta( $post_id, $post ) {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Prepare debug log
        $logfile = WP_CONTENT_DIR . '/ksj-sci.log';
        if ( ! file_exists( $logfile ) ) {
            @touch( $logfile );
            @chmod( $logfile, 0664 );
        }
        $log = function( $msg ) use ( $logfile ) {
            error_log( date( 'c' ) . ' ' . $msg . "\n", 3, $logfile );
        };

        // ─── Email Template CPT ─────────────────────────────────────────
        if ( $post->post_type === 'scm_email_template' ) {
            if ( empty( $_POST['scm_template_nonce'] ) || ! wp_verify_nonce( $_POST['scm_template_nonce'], 'scm_save_template' ) ) {
                $log( 'Template save: nonce failed' );
                return;
            }

            // Save local meta
            $subj = sanitize_text_field( $_POST['scm_template_subject'] ?? '' );
            $send = sanitize_text_field( $_POST['scm_template_sender']  ?? '' );
            $cont = wp_kses_post( $_POST['scm_template_content'] ?? '' );
            update_post_meta( $post_id, '_scm_template_subject', $subj );
            update_post_meta( $post_id, '_scm_template_sender',  $send );
            update_post_meta( $post_id, '_scm_template_content', $cont );

            // Brevo sync
            $api_key = get_option( 'ksj_brevo_api_key' );
            $log( 'Template save: API key=' . ( $api_key ? 'present' : 'missing' ) );
            if ( ! $api_key ) {
                return;
            }

            $brevo_id = get_post_meta( $post_id, '_scm_template_brevo_id', true );

            // Parse sender into object with fallback for name
            if ( preg_match( '/^(.+)\s*<(.+)>$/', $send, $m ) ) {
                $sender_name  = trim( $m[1] );
                $sender_email = trim( $m[2] );
            } else {
                $sender_email = $send;
                // use email as name when none provided
                $sender_name  = $send;
            }

            $payload = [
                'templateName' => get_the_title( $post_id ),
                'tag'          => 'tpl_' . $post_id,
                'subject'      => $subj,
                'sender'       => [
                    'name'  => $sender_name,
                    'email' => $sender_email,
                ],
                'htmlContent'  => $cont,
            ];

            $log( 'Payload: ' . wp_json_encode( $payload ) );

            $args = [
                'headers' => [
                    'api-key'      => $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body'    => wp_json_encode( $payload ),
            ];

            if ( empty( $brevo_id ) ) {
                $log( 'POST new template to Brevo' );
                $resp = wp_remote_post( 'https://api.brevo.com/v3/smtp/templates', $args );
            } else {
                $log( "PUT template {$brevo_id}" );
                $resp = wp_remote_request(
                    "https://api.brevo.com/v3/smtp/templates/{$brevo_id}",
                    [
                        'method'  => 'PUT',
                        'headers' => $args['headers'],
                        'body'    => $args['body'],
                    ]
                );
            }

            if ( is_wp_error( $resp ) ) {
                $log( 'Brevo API error: ' . $resp->get_error_message() );
            } else {
                $code = wp_remote_retrieve_response_code( $resp );
                $body = wp_remote_retrieve_body( $resp );
                $log( "Brevo HTTP {$code}: {$body}" );
                if ( $code >= 200 && $code < 300 ) {
                    $data = json_decode( $body, true );
                    if ( ! empty( $data['id'] ) ) {
                        update_post_meta( $post_id, '_scm_template_brevo_id', $data['id'] );
                        $log( 'Brevo template ID saved: ' . $data['id'] );
                    }
                }
            }
        }

        // ─── Email Campaign CPT ────────────────────────────────────────
        if ( $post->post_type === 'scm_email_campaign' ) {
            if ( empty( $_POST['scm_campaign_nonce'] ) || ! wp_verify_nonce( $_POST['scm_campaign_nonce'], 'scm_campaign_nonce' ) ) {
                $log( 'Campaign save: nonce failed' );
                return;
            }

            $tpl_id = intval( $_POST['scm_campaign_template'] ?? 0 );
            $lists  = array_filter( array_map( 'intval', explode( ',', $_POST['scm_campaign_lists'] ?? '' ) ) );
            $sched  = sanitize_text_field( $_POST['scm_campaign_schedule'] ?? '' );
            update_post_meta( $post_id, '_scm_campaign_template', $tpl_id );
            update_post_meta( $post_id, '_scm_campaign_lists',    $lists );
            update_post_meta( $post_id, '_scm_campaign_schedule', $sched );
            $log( 'Campaign meta saved for post ' . $post_id );
            // TODO: Brevo campaign sync
        }
    }
}
