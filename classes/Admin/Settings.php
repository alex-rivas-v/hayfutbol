<?php
namespace HayFutbol\Admin;

use HayFutbol\Encryption;
use HayFutbol\Cloudflare\Api;
use HayFutbol\HayFutbol as Plugin;
use HayFutbol\Cron;
use HayFutbol\PingerClient;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin settings page: Cloudflare credentials, status dashboard, and manual check trigger.
 */
class Settings {

    const PAGE_SLUG    = 'hayfutbol-settings';
    const OPTION_GROUP = 'hayfutbol_settings_group';

    public function register(): void {
        add_action( 'admin_menu',            array( $this, 'add_page' ) );
        add_action( 'admin_init',            array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_hayfutbol_save_token',    array( $this, 'ajax_save_token' ) );
        add_action( 'wp_ajax_hayfutbol_verify_token',  array( $this, 'ajax_verify_token' ) );
        add_action( 'wp_ajax_hayfutbol_detect_record', array( $this, 'ajax_detect_record' ) );
        add_action( 'wp_ajax_hayfutbol_run_check',     array( $this, 'ajax_run_check' ) );
        add_action( 'wp_ajax_hayfutbol_retry_pinger',  array( $this, 'ajax_retry_pinger' ) );
    }

    public function add_page(): void {
        add_options_page(
            'Hay Futbol',
            'Hay Futbol',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * The API token is intentionally excluded here: it is saved via a dedicated
     * AJAX action to avoid WordPress double-calling the sanitize callback (which
     * would double-encrypt the value before storage).
     */
    public function register_settings(): void {
        register_setting( self::OPTION_GROUP, 'hayfutbol_cf_zone_id',           'sanitize_text_field' );
        register_setting( self::OPTION_GROUP, 'hayfutbol_cf_record_id',         'sanitize_text_field' );
        register_setting( self::OPTION_GROUP, 'hayfutbol_check_interval',        'absint' );
        register_setting( self::OPTION_GROUP, 'hayfutbol_notification_email',    'sanitize_email' );
    }

    public function enqueue_scripts( string $hook_suffix ): void {
        if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
            return;
        }

        wp_enqueue_style(
            'hayfutbol-admin',
            plugins_url( 'assets/admin.css', HAYFUTBOL_PLUGIN_FILE ),
            array(),
            HAYFUTBOL_VERSION
        );

        wp_enqueue_script(
            'hayfutbol-admin',
            plugins_url( 'assets/admin.js', HAYFUTBOL_PLUGIN_FILE ),
            array( 'jquery' ),
            HAYFUTBOL_VERSION,
            true
        );

        wp_localize_script( 'hayfutbol-admin', 'hayfutbol', array(
            'nonce'     => wp_create_nonce( 'hayfutbol_admin' ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'imgUrl'    => plugins_url( 'assets/images/', HAYFUTBOL_PLUGIN_FILE ),
        ) );
    }

    /* ------------------------------------------------------------------
       AJAX handlers
    ------------------------------------------------------------------ */

    public function ajax_save_token(): void {
        check_ajax_referer( 'hayfutbol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $raw = trim( sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ) );

        if ( '' === $raw ) {
            wp_send_json_error( 'Introduce el token antes de guardar.' );
        }

        update_option( 'hayfutbol_cf_api_token', Encryption::encrypt( $raw ) );
        wp_send_json_success( 'Token guardado correctamente.' );
    }

    public function ajax_verify_token(): void {
        check_ajax_referer( 'hayfutbol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $token = Encryption::decrypt( get_option( 'hayfutbol_cf_api_token', '' ) );

        if ( ! $token ) {
            wp_send_json_error( 'No hay token guardado en el plugin.' );
        }

        $response = wp_remote_get( 'https://api.cloudflare.com/client/v4/user/tokens/verify', array(
            'timeout' => 10,
            'headers' => array( 'Authorization' => 'Bearer ' . $token ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Error de conexión: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $body['success'] ) ) {
            wp_send_json_success( 'Token verificado correctamente en Cloudflare.' );
        }

        wp_send_json_error( 'Token rechazado: ' . wp_json_encode( $body['errors'] ?? array() ) );
    }

    public function ajax_detect_record(): void {
        check_ajax_referer( 'hayfutbol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        $token   = Encryption::decrypt( get_option( 'hayfutbol_cf_api_token', '' ) );
        $zone_id = get_option( 'hayfutbol_cf_zone_id', '' );

        if ( ! $token || ! $zone_id ) {
            wp_send_json_error( 'Guarda primero el API Token y el Zone ID.' );
        }

        $api    = new Api( $token, $zone_id );
        $result = $api->get_dns_records();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( 'Error de Cloudflare: ' . wp_json_encode( $result['errors'] ?? array() ) );
        }

        $records = array_map( static function ( $r ) {
            return array(
                'id'      => $r['id'],
                'name'    => $r['name'],
                'content' => $r['content'],
                'proxied' => $r['proxied'],
            );
        }, $result['result'] );

        $hostname   = parse_url( home_url(), PHP_URL_HOST );
        $is_local   = $this->is_local_hostname( $hostname );
        $auto_match = null;

        if ( ! $is_local ) {
            foreach ( $records as $record ) {
                if ( $record['name'] === $hostname ) {
                    $auto_match = $record;
                    break;
                }
            }
        }

        wp_send_json_success( array(
            'records'    => $records,
            'auto_match' => $auto_match,
            'is_local'   => $is_local,
        ) );
    }

    public function ajax_retry_pinger(): void {
        check_ajax_referer( 'hayfutbol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        Plugin::instance()->pinger->register();

        if ( '1' === get_option( PingerClient::STATUS_OPTION, '0' ) ) {
            wp_send_json_success( 'Registrado correctamente.' );
        }

        wp_send_json_error( 'No se pudo registrar. Revisa que el pinger este accesible.' );
    }

    public function ajax_run_check(): void {
        check_ajax_referer( 'hayfutbol_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.', 403 );
        }

        Plugin::instance()->block_check->run();

        wp_send_json_success( array(
            'last_check'   => get_option( 'hayfutbol_last_check', '' ),
            'last_ip'      => get_option( 'hayfutbol_last_check_ip', '' ),
            'is_blocked'   => get_option( 'hayfutbol_last_check_blocked', '' ),
            'proxy_paused' => get_option( 'hayfutbol_proxy_paused', '0' ),
            'cf_error'     => get_option( 'hayfutbol_last_cf_error', '' ),
        ) );
    }

    /* ------------------------------------------------------------------
       Helpers
    ------------------------------------------------------------------ */

    private function get_cf_proxy_state(): string {
        $token     = Encryption::decrypt( get_option( 'hayfutbol_cf_api_token', '' ) );
        $zone_id   = get_option( 'hayfutbol_cf_zone_id', '' );
        $record_id = get_option( 'hayfutbol_cf_record_id', '' );

        if ( ! $token || ! $zone_id || ! $record_id ) {
            return '';
        }

        $api    = new Api( $token, $zone_id );
        $result = $api->get_dns_record( $record_id );

        if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
            return '';
        }

        return ! empty( $result['result']['proxied'] ) ? 'active' : 'inactive';
    }

    private function is_local_hostname( string $hostname ): bool {
        if ( in_array( $hostname, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
            return true;
        }
        foreach ( array( '.local', '.test', '.dev', '.localhost' ) as $tld ) {
            if ( substr( $hostname, -strlen( $tld ) ) === $tld ) {
                return true;
            }
        }
        return false;
    }

    /* ------------------------------------------------------------------
       Page render
    ------------------------------------------------------------------ */

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $token_set    = '' !== get_option( 'hayfutbol_cf_api_token', '' );
        $zone_id      = get_option( 'hayfutbol_cf_zone_id', '' );
        $record_id    = get_option( 'hayfutbol_cf_record_id', '' );
        $last_check   = get_option( 'hayfutbol_last_check', '' );
        $last_ip      = get_option( 'hayfutbol_last_check_ip', '' );
        $is_blocked   = get_option( 'hayfutbol_last_check_blocked', '' );
        $cf_error     = get_option( 'hayfutbol_last_cf_error', '' );
        $interval     = (int) get_option( 'hayfutbol_check_interval', 5 );
        $notify_email = get_option( 'hayfutbol_notification_email', '' );
        $can_detect   = $token_set && $zone_id;

        $intervals = array(
            5  => 'Cada 5 minutos',
            10 => 'Cada 10 minutos',
            15 => 'Cada 15 minutos',
            30 => 'Cada 30 minutos',
            60 => 'Cada 60 minutos',
        );

        $blocked_class = '' === $is_blocked ? 'is-neutral' : ( '1' === $is_blocked ? 'is-blocked' : 'is-ok' );
        $blocked_text  = '' === $is_blocked ? 'Sin datos' : ( '1' === $is_blocked ? 'Sí, bloqueada' : 'No bloqueada' );

        $cf_proxy_state = $this->get_cf_proxy_state();
        $proxy_class    = '' === $cf_proxy_state ? 'is-neutral' : ( 'active' === $cf_proxy_state ? 'is-ok' : 'is-paused' );
        $proxy_text     = '' === $cf_proxy_state ? 'N/A' : ( 'active' === $cf_proxy_state ? 'Activo' : 'Desactivado' );

        $hay_partido   = get_option( 'hayfutbol_hay_partido', '' );
        $partido_class = '' === $hay_partido ? 'is-neutral' : ( '1' === $hay_partido ? 'is-blocked' : 'is-ok' );
        $partido_text  = '' === $hay_partido ? 'Sin datos' : ( '1' === $hay_partido ? 'Sí' : 'No' );

        $ips = $last_ip ? array_values( array_filter( array_map( 'trim', explode( ',', $last_ip ) ) ) ) : array();

        $pinger_registered = get_option( PingerClient::STATUS_OPTION, '' );
        $pinger_class      = '1' === $pinger_registered ? 'is-ok' : ( '' === $pinger_registered ? 'is-neutral' : 'is-blocked' );
        $pinger_text       = '1' === $pinger_registered ? 'Conectado' : ( '' === $pinger_registered ? 'Sin datos' : 'Error al conectar' );
        $verify_debug      = get_option( 'hayfutbol_verify_debug', array() );

        ?>
        <div class="wrap hf-page">
            <h1>Hay Futbol</h1>

            <?php if ( '1' !== $pinger_registered ) : ?>
                <div class="notice notice-error">
                    <p>
                        <strong>Cron externo desconectado.</strong>
                        El plugin no realizará cambios en Cloudflare hasta que el cron se conecte correctamente.
                        Usa el botón <strong>Sincronizar</strong> en la sección de configuración.
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( $cf_error ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>Error de Cloudflare:</strong> <?php echo esc_html( $cf_error ); ?></p>
                </div>
            <?php endif; ?>

            <div class="hf-dashboard">
                <div class="hf-stat">
                    <div class="hf-stat__label">Última comprobación</div>
                    <div class="hf-stat__value <?php echo $last_check ? '' : 'is-neutral'; ?>">
                        <?php echo $last_check ? esc_html( $last_check ) : 'Nunca'; ?>
                    </div>
                </div>
                <div class="hf-stat">
                    <div class="hf-stat__label">IP detectada</div>
                    <div class="hf-stat__value <?php echo $ips ? '' : 'is-neutral'; ?>">
                        <?php if ( count( $ips ) > 1 ) : ?>
                            <ul style="margin:0;padding:0 0 0 14px;font-weight:400">
                                <?php foreach ( $ips as $ip ) : ?>
                                    <li><?php echo esc_html( $ip ); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <?php echo $ips ? esc_html( $ips[0] ) : '—'; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hf-stat">
                    <div class="hf-stat__label">En listado de bloqueos</div>
                    <div class="hf-stat__value <?php echo esc_attr( $blocked_class ); ?>">
                        <?php echo esc_html( $blocked_text ); ?>
                    </div>
                </div>
                <div class="hf-stat">
                    <div class="hf-stat__label">Proxy Cloudflare</div>
                    <div class="hf-stat__value <?php echo esc_attr( $proxy_class ); ?>">
                        <?php echo esc_html( $proxy_text ); ?>
                    </div>
                </div>
                <div class="hf-stat">
                    <div class="hf-stat__label">¿Hay fútbol?</div>
                    <div class="hf-stat__value <?php echo esc_attr( $partido_class ); ?>">
                        <?php echo esc_html( $partido_text ); ?>
                    </div>
                </div>
            </div>

            <div class="hf-actions">
                <button type="button" class="button button-primary" id="hayfutbol-run-check">Comprobar ahora</button>
                <span id="hayfutbol-check-spinner" class="spinner"></span>
                <span id="hayfutbol-check-result" class="hf-feedback"></span>
            </div>

            <hr style="margin:0 0 24px">

            <h2 class="hf-section-title">Configuración de Cloudflare</h2>

            <div class="hf-config-grid">

                <div>
                    <div class="hf-cron-status">
                        <span class="hf-cron-status__label">Estado del cron</span>
                        <span class="hf-stat__value <?php echo esc_attr( $pinger_class ); ?>">
                            <?php echo esc_html( $pinger_text ); ?>
                        </span>
                        <?php if ( '1' !== $pinger_registered ) : ?>
                            <button type="button" class="button button-small" id="hayfutbol-retry-pinger">Sincronizar</button>
                            <span id="hayfutbol-pinger-spinner" class="spinner" style="float:none;vertical-align:middle;margin:0 4px"></span>
                            <span id="hayfutbol-pinger-result" class="hf-feedback"></span>
                        <?php endif; ?>
                        <?php
                        $pinger_error = get_option( 'hayfutbol_pinger_last_error', '' );
                        if ( $pinger_error && '1' !== $pinger_registered ) : ?>
                            <span class="hf-cron-status__error"><?php echo esc_html( $pinger_error ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ( $verify_debug && '1' !== $pinger_registered ) : ?>
                        <div class="hf-verify-debug">
                            <strong>Debug handshake (token recibido en /verify):</strong>
                            recibido: <code><?php echo esc_html( $verify_debug['received_pfx'] ?? '?' ); ?></code>
                            (<?php echo (int) ( $verify_debug['received_len'] ?? 0 ); ?> chars)
                            &nbsp;&mdash;&nbsp;
                            guardado: <code><?php echo esc_html( $verify_debug['stored_pfx'] ?? '?' ); ?></code>
                            (<?php echo (int) ( $verify_debug['stored_len'] ?? 0 ); ?> chars)
                            &nbsp;&mdash;&nbsp;
                            <?php echo esc_html( $verify_debug['time'] ?? '' ); ?>
                        </div>
                    <?php endif; ?>

                    <table class="form-table" style="margin-bottom:0" role="presentation">
                        <tr>
                            <th scope="row">
                                API Token
                                <button type="button" class="hf-help-btn" data-help="api-token" title="Ver ayuda">
                                    <span class="dashicons dashicons-editor-help"></span>
                                </button>
                            </th>
                            <td>
                                <div class="hf-token-row">
                                    <input
                                        type="password"
                                        id="hayfutbol_cf_api_token"
                                        value=""
                                        class="regular-text"
                                        autocomplete="off"
                                        placeholder="<?php echo $token_set ? 'Token guardado — introduce uno nuevo para cambiarlo' : 'Introduce tu API Token de Cloudflare'; ?>"
                                    >
                                    <button type="button" class="button" id="hayfutbol-save-token">Guardar</button>
                                    <button type="button" class="button" id="hayfutbol-verify-token"<?php echo $token_set ? '' : ' disabled'; ?>>Verificar</button>
                                </div>
                                <span id="hayfutbol-token-spinner" class="spinner" style="float:none;vertical-align:middle;margin-top:4px"></span>
                                <span id="hayfutbol-token-result" class="hf-feedback"></span>
                                <p class="description">
                                    Token con permisos <strong>Zone &gt; DNS &gt; Edit</strong>.
                                    <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Crear token &rarr;</a>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <form method="post" action="options.php">
                        <?php settings_fields( self::OPTION_GROUP ); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="hayfutbol_cf_zone_id">Zone ID</label>
                                    <button type="button" class="hf-help-btn" data-help="zone-id" title="Ver ayuda">
                                        <span class="dashicons dashicons-editor-help"></span>
                                    </button>
                                </th>
                                <td>
                                    <input
                                        type="text"
                                        id="hayfutbol_cf_zone_id"
                                        name="hayfutbol_cf_zone_id"
                                        value="<?php echo esc_attr( $zone_id ); ?>"
                                        class="regular-text"
                                        placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                    >
                                    <p class="description">
                                        Panel de Cloudflare &rarr; tu dominio &rarr; pestaña <em>Overview</em> &rarr; columna derecha, sección API.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="hayfutbol_cf_record_id">DNS Record ID</label>
                                </th>
                                <td>
                                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                        <input
                                            type="text"
                                            id="hayfutbol_cf_record_id"
                                            name="hayfutbol_cf_record_id"
                                            value="<?php echo esc_attr( $record_id ); ?>"
                                            class="regular-text"
                                            placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                        >
                                        <button
                                            type="button"
                                            class="button"
                                            id="hayfutbol-detect-record"
                                            <?php echo $can_detect ? '' : 'disabled'; ?>
                                        >Auto-detectar</button>
                                        <span id="hayfutbol-detect-spinner" class="spinner" style="float:none;vertical-align:middle;margin:0"></span>
                                    </div>
                                    <p class="description">
                                        ID del registro A del dominio raíz. Guarda primero el token y el Zone ID, luego usa Auto-detectar.
                                    </p>
                                    <div id="hayfutbol-records-list" style="margin-top:10px"></div>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="hayfutbol_check_interval">Frecuencia de verificación</label>
                                </th>
                                <td>
                                    <select id="hayfutbol_check_interval" name="hayfutbol_check_interval">
                                        <?php foreach ( $intervals as $val => $label ) : ?>
                                            <option value="<?php echo esc_attr( $val ); ?>"<?php selected( $interval, $val ); ?>>
                                                <?php echo esc_html( $label ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="hf-interval-warning" id="hayfutbol-interval-warning">
                                        Con una frecuencia de 60 minutos el plugin puede tardar hasta una hora en reaccionar ante un bloqueo.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="hayfutbol_notification_email">Email de notificación</label>
                                </th>
                                <td>
                                    <input
                                        type="email"
                                        id="hayfutbol_notification_email"
                                        name="hayfutbol_notification_email"
                                        value="<?php echo esc_attr( $notify_email ); ?>"
                                        class="regular-text"
                                        placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
                                    >
                                    <p class="description">
                                        Dirección a la que se enviarán los avisos cuando el proxy se active o desactive.
                                        Si se deja vacío se usa el email del administrador de WordPress.
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button( 'Guardar ajustes' ); ?>
                    </form>
                </div>

                <div class="hf-help" id="hayfutbol-help">
                    <div class="hf-help__header" id="hayfutbol-help-title">Ayuda</div>
                    <div class="hf-help__body">
                        <div class="hf-help__empty" id="hayfutbol-help-empty">
                            <span class="dashicons dashicons-editor-help"></span>
                            Haz clic en <strong>?</strong> junto a un campo para ver dónde encontrar el dato en Cloudflare.
                        </div>
                        <div id="hayfutbol-help-content" style="display:none">
                            <img id="hayfutbol-help-img" src="" alt="">
                            <p id="hayfutbol-help-text"></p>
                        </div>
                    </div>
                </div>

            </div><!-- .hf-config-grid -->
        </div><!-- .wrap -->
        <?php
    }
}
