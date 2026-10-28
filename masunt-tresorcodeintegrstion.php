<?php
/**
 * Plugin Name: CommonsBooking Masunt Addon
 * Description: Generiert automatisiert Masunt-Zutrittscodes für CommonsBooking-Buchungen inkl. Admin-Einstellungen.
 * Version: 1.1.0
 * Author: Patrick Hammel/ Dein Lastenvelo Mannheim e.V.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CB_Masunt_Integration {

    public function __construct() {
        // 1) Beim Laden des WordPress-Admins werden die Menü- und Einstellungsfunktionen registriert.
        //    Dadurch erscheint die API-Konfiguration im Backend und kann von Admins gepflegt werden.
        add_action( 'admin_menu', [ $this, 'add_masunt_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_masunt_settings' ] );

        // 2) Wenn eine Buchung gespeichert wird, wird ein Masunt-Code erzeugt.
        //    Der Hook reagiert auf den Beitragstyp 'cb_booking' und startet die API-Anfrage.
        add_action( 'save_post_cb_booking', [ $this, 'handle_booking_code_generation' ], 10, 3 );

        // 3) Für E-Mails wird ein Platzhalter registriert, damit der Code im Inhalt einer Buchungsbestätigung genutzt werden kann.
        add_filter( 'commonsbooking_email_placeholders', [ $this, 'register_masunt_placeholder' ], 10, 2 );
    }

    /**
     * 1. Menüpunkt registrieren (sicherer Fallback)
     * Dieser Schritt sorgt dafür, dass die Konfiguration im WordPress-Backend sichtbar wird.
     */
    public function add_masunt_settings_page() {
        // Menüpunkt wird als Unterseite unter "Einstellungen" erzeugt.
        // Dadurch ist die Konfiguration immer erreichbar, auch wenn der Pluginscope nicht exakt zu CommonsBooking passt.
        add_options_page(
            'Masunt Schloss Einstellungen',    // Seitentitel in der Admin-Ansicht
            'Masunt API',                      // Menübezeichnung im Abschnitt "Einstellungen"
            'manage_options',                  // Nur Benutzer mit Admin-Rechten dürfen die Seite öffnen
            'cb-masunt-settings',              // eindeutiger Slug für die URL der Einstellungsseite
            [ $this, 'render_settings_page' ]  // Funktion, die das Formular rendert
        );

        /* 
         * Alternative 2: Falls die Integration später unter einem eigenen CommonsBooking-Menü erscheinen soll,
         * könnte hier auch ein eigenes Menü mit add_menu_page() oder add_submenu_page() eingebaut werden.
         * Für Stabilität und Kompatibilität ist die Variante unter "Einstellungen" hier die sicherere Lösung.
         */
    }

    /**
     * 2. Settings API registrieren
     * Hier werden die Optionen definiert, die im Admin-Formular gespeichert und später per get_option() gelesen werden.
     */
    public function register_masunt_settings() {
        // Die Basis-URL der API wird als gültige URL validiert und bereinigt.
        register_setting( 'cb_masunt_options_group', 'cb_masunt_api_url', [
            'sanitize_callback' => 'esc_url_raw',
        ] );

        // Der API-Key wird als einfacher Text gespeichert und gegen unerwünschte Zeichen bereinigt.
        register_setting( 'cb_masunt_options_group', 'cb_masunt_api_key', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        // Der Benutzername/Client-ID wird ebenfalls als Text gespeichert.
        register_setting( 'cb_masunt_options_group', 'cb_masunt_api_user', [
            'sanitize_callback' => 'sanitize_text_field',
        ] );
    }

    /**
     * 3. HTML-Ausgabe der Einstellungsseite im Backend
     * Dieser Schritt zeigt den Benutzer ein Formular an, in dem die API-Daten eingegeben werden.
     */
    public function render_settings_page() {
        // Nur Administratoren dürfen diese Seite betreten.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Masunt & Codelocks Integration für CommonsBooking</h1>
            <form method="post" action="options.php">
                <?php
                // WordPress-API: Lädt die zugehörigen Einstellungenfelder und speichert Änderungen beim Absenden.
                settings_fields( 'cb_masunt_options_group' );
                do_settings_sections( 'cb_masunt_options_group' );

                // Bereits gespeicherte Werte werden hier geladen und im Formular angezeigt.
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
                // WordPress-Schaltfläche zum Speichern der Einstellungen.
                submit_button( 'Änderungen speichern' );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * 4. Nutzung der gespeicherten Optionen im API-Request
     * Diese Methode baut die Anfrage für die Masunt-API und verarbeitet die Antwort.
     */
    private function request_masunt_code_via_api( $lock_id, $start, $end ) {
        // 1) API-URL aus den gespeicherten Optionen zusammensetzen.
        //    'codes' ist hier der Endpunkt, der den Code für ein Schloss erzeugt.
        $api_url = trailingslashit( get_option( 'cb_masunt_api_url' ) ) . 'codes';
        $api_key = get_option( 'cb_masunt_api_key' );

        // 2) Wenn die Konfiguration fehlt, wird sofort abgebrochen.
        if ( empty( $api_url ) || empty( $api_key ) ) {
            return false;
        }

        // 3) HTTP-Request an die Masunt-API senden.
        $response = wp_remote_post( $api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode( [
                'lock_id'   => $lock_id,
                'starts_at' => $start,
                'ends_at'   => $end,
            ] ),
        ] );

        // 4) Wenn die Anfrage fehlschlägt, gibt die Funktion false zurück.
        if ( is_wp_error( $response ) ) {
            return false;
        }

        // 5) Antwort als JSON lesen und den Code extrahieren.
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // 6) Rückgabe nur des tatsächlich vorhandenen Codes; sonst false.
        return $body['code'] ?? false;
    }

    public function handle_booking_code_generation( $post_id, $post, $update ) {
        // 1) Autosave überspringen, damit beim Speichern im Editor kein Code zufällig erzeugt wird.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

        // 2) Nur für den Booking-Post-Type 'cb_booking' weiterarbeiten.
        if ( $post->post_type !== 'cb_booking' ) return;

        // 3) Bereits vorhandenen Code nicht erneut erzeugen.
        $existing_code = get_post_meta( $post_id, '_masunt_code', true );
        if ( ! empty( $existing_code ) ) return;

        // 4) Start, Ende und Buchungs-Item aus den Meta-Feldern der Buchung lesen.
        $start_time = get_post_meta( $post_id, '_cb_start_date', true );
        $end_time   = get_post_meta( $post_id, '_cb_end_date', true );
        $item_id    = get_post_meta( $post_id, '_cb_item_id', true );

        // 5) Zum Item gehört das zugehörige Schloss/Lock. Dieses Lock-ID wird aus den Meta-Daten gelesen.
        $lock_id = get_post_meta( $item_id, '_masunt_lock_id', true );
        if ( empty( $lock_id ) ) return;

        // 6) Masunt-API aufrufen, damit ein Zugangscode für den gewählten Zeitraum erzeugt wird.
        $api_code = $this->request_masunt_code_via_api( $lock_id, $start_time, $end_time );

        // 7) Falls ein Code zurückkommt, wird er als Post-Meta gespeichert.
        if ( $api_code ) {
            update_post_meta( $post_id, '_masunt_code', sanitize_text_field( $api_code ) );
        }
    }

    public function register_masunt_placeholder( $placeholders, $booking ) {
        // 1) Ermitteln, ob ein Booking-ID oder ein Objekt übergeben wurde.
        $booking_id = is_numeric( $booking ) ? $booking : ( $booking->ID ?? 0 );

        // 2) Wenn eine gültige Buchung vorhanden ist, wird der gespeicherte Code gelesen.
        if ( $booking_id > 0 ) {
            $code = get_post_meta( $booking_id, '_masunt_code', true );

            // 3) Platzhalter für E-Mail-Inhalte registrieren.
            //    Wenn kein Code vorhanden ist, erscheint ein Hinweistext statt eines leeren Werts.
            $placeholders['{masunt_code}'] = ! empty( $code ) ? esc_html( $code ) : 'Code wird generiert';
        }

        return $placeholders;
    }
}

new CB_Masunt_Integration();