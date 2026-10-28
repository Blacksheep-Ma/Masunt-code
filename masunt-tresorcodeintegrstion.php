<?php
/**
 * Plugin Name: CommonsBooking Masunt Addon
 * Description: Generiert automatisiert Masunt-Zutrittscodes für CommonsBooking-Buchungen inkl. Admin-Einstellungen.
 * Version: 1.1.0
 * Author: Dein Name / Initiative
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_Masunt_Integration {

    public function __construct() {
        // Wir nutzen hier einen Hook, der garantiert im Admin-Bereich greift
        add_action( 'admin_menu', [ $this, 'add_masunt_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_masunt_settings' ] );

        // Buchungs-Hooks
        add_action( 'save_post_cb_booking', [ $this, 'handle_booking_code_generation' ], 10, 3 );
        
        // E-Mail Platzhalter
        add_filter( 'commonsbooking_email_placeholders', [ $this, 'register_masunt_placeholder' ], 10, 2 );
    }

    /**
     * 1. Menüpunkt registrieren (Sicherer Fallback)
     */
    public function add_masunt_settings_page() {
        // Alternative 1: Direkt als Untermenü unter "Einstellungen" (funktioniert immer 100%)
        add_options_page(
            'Masunt Schloss Einstellungen',    // Seitentitel
            'Masunt API',                      // Menütitel im Menü "Einstellungen"
            'manage_options',                  // Berechtigung
            'cb-masunt-settings',              // Slug
            [ $this, 'render_settings_page' ]  // Callback
        );

        /* 
         * Alternative 2: Falls du es unbedingt unter CommonsBooking haben möchtest, 
         * teste ob der Post-Type 'cb_booking' exakt so heißt. 
         * Wenn CommonsBooking z.B. nur 'booking' nutzt, greift Alternative 1 oben besser.
         */
    }

    /**
     * 2. Settings API registrieren
     */
    public function register_masunt_settings() {
        register_setting( 'cb_masunt_options_group', 'cb_masunt_api_url', [
            'sanitize_callback' => 'esc_url_raw',
        ] );
        register_setting( 'cb_masunt_options_group', 'cb_masunt_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'cb_masunt_options_group', 'cb_masunt_api_user', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
    }

    /**
     * 3. HTML-Ausgabe der Einstellungsseite im Backend
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Masunt & Codelocks Integration für CommonsBooking</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'cb_masunt_options_group' );
                do_settings_sections( 'cb_masunt_options_group' );
                
                $api_url  = get_option( 'cb_masunt_api_url', '' );
                $api_key  = get_option( 'cb_masunt_api_key', '' );
                $api_user = get_option( 'cb_masunt_api_user', '' );
                ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="cb_masunt_api_url">API Basis-URL</label></th>
                        <td>
                            <input name="cb_masunt_api_url" type="url" id="cb_masunt_api_url" value="<?php echo esc_attr( $api_url ); ?>" class="regular-text" placeholder="https://portal.codelocksconnect.net/api/v2">
                            <p class="description">Die offizielle Endpunkt-URL der Masunt- oder Codelocks-API.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cb_masunt_api_user">API Benutzer / Client ID</label></th>
                        <td>
                            <input name="cb_masunt_api_user" type="text" id="cb_masunt_api_user" value="<?php echo esc_attr( $api_user ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cb_masunt_api_key">API Key / Token / Secret</label></th>
                        <td>
                            <input name="cb_masunt_api_key" type="password" id="cb_masunt_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
                            <p class="description">Dein geheimer API-Schlüssel für die Authentifizierung.</p>
                        </td>
                    </tr>
                </table>
				Der Platzhalter für die Mail ist {masunt_code}
                <?php 
                // Korrigierter Funktionsaufruf für den Absende-Button:
                submit_button( 'Änderungen speichern' ); 
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * 4. Nutzung der gespeicherten Optionen im API-Request
     */
    private function request_masunt_code_via_api( $lock_id, $start, $end ) {
        $api_url = trailingslashit( get_option( 'cb_masunt_api_url' ) ) . 'codes'; // Beispielhafter Endpunkt
        $api_key = get_option( 'cb_masunt_api_key' );

        if ( empty( $api_url ) || empty( $api_key ) ) {
            return false; // Konfiguration unvollständig
        }
        
        $response = wp_remote_post( $api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'lock_id'   => $lock_id,
                'starts_at' => $start,
                'ends_at'   => $end,
            ]),
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['code'] ?? false;
    }

    public function handle_booking_code_generation( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( $post->post_type !== 'cb_booking' ) return;

        $existing_code = get_post_meta( $post_id, '_masunt_code', true );
        if ( ! empty( $existing_code ) ) return;

        $start_time = get_post_meta( $post_id, '_cb_start_date', true );
        $end_time   = get_post_meta( $post_id, '_cb_end_date', true );
        $item_id    = get_post_meta( $post_id, '_cb_item_id', true );

        $lock_id = get_post_meta( $item_id, '_masunt_lock_id', true );
        if ( empty( $lock_id ) ) return;

        $api_code = $this->request_masunt_code_via_api( $lock_id, $start_time, $end_time );

        if ( $api_code ) {
            update_post_meta( $post_id, '_masunt_code', sanitize_text_field( $api_code ) );
        }
    }

    public function register_masunt_placeholder( $placeholders, $booking ) {
        $booking_id = is_numeric( $booking ) ? $booking : ( $booking->ID ?? 0 );
        
        if ( $booking_id > 0 ) {
            $code = get_post_meta( $booking_id, '_masunt_code', true );
            $placeholders['{masunt_code}'] = ! empty( $code ) ? esc_html( $code ) : 'Code wird generiert';
        }

        return $placeholders;
    }
}

new CB_Masunt_Integration();