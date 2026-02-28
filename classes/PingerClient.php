<?php
namespace HayFutbol;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles registration with the central pinger at hayfutbol.alexrivas.net
 * and exposes the REST endpoint used for the handshake during registration.
 */
class PingerClient {

    const REGISTER_URL  = 'https://hayfutbol.alexrivas.net/';
    const TOKEN_OPTION  = 'hayfutbol_ping_token';
    const STATUS_OPTION = 'hayfutbol_pinger_registered';

    public function register_hooks(): void {
        add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
    }

    public function register_endpoint(): void {
        register_rest_route( 'hayfutbol/v1', '/verify', array(
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => array( $this, 'handle_verify' ),
            'permission_callback' => array( $this, 'throttle_verify' ),
            'show_in_index'       => false,
        ) );
    }

    /**
     * Rate-limits the verify endpoint to one attempt every 5 seconds per IP.
     */
    public function throttle_verify(): bool {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'hayfutbol_rl_' . md5( $ip );

        if ( false !== get_transient( $key ) ) {
            return false;
        }

        set_transient( $key, 1, 5 );
        return true;
    }

    /**
     * Responds to the handshake from the pinger during registration.
     */
    public function handle_verify( \WP_REST_Request $request ): \WP_REST_Response {
        $token  = sanitize_text_field( $request->get_param( 'token' ) );
        $stored = get_option( self::TOKEN_OPTION, '' );

        if ( ! $token || ! $stored || ! hash_equals( $stored, $token ) ) {
            update_option( 'hayfutbol_verify_debug', array(
                'match' => false,
                'time'  => current_time( 'mysql' ),
            ), false );
            return new \WP_REST_Response( array( 'verified' => false ), 403 );
        }

        delete_option( 'hayfutbol_verify_debug' );
        return new \WP_REST_Response( array( 'verified' => true ), 200 );
    }

    public function register(): void {
        $token = get_option( self::TOKEN_OPTION, '' );
        if ( ! $token ) {
            $token = wp_generate_password( 32, false );
            update_option( self::TOKEN_OPTION, $token, false );
        }

        $response = wp_remote_post( self::REGISTER_URL, array(
            'timeout' => 20,
            'body'    => array(
                'site_url' => home_url(),
                'ip'       => $this->get_server_ip(),
                'token'    => $token,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            update_option( self::STATUS_OPTION, '0', false );
            update_option( 'hayfutbol_pinger_last_error', $response->get_error_message(), false );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $ok   = ! empty( $body['status'] ) && 'success' === $body['status'];
        update_option( self::STATUS_OPTION, $ok ? '1' : '0', false );

        if ( $ok ) {
            delete_option( 'hayfutbol_pinger_last_error' );
        } else {
            $detail = $body['details'] ?? $body['error'] ?? "HTTP {$code}: respuesta inesperada";
            update_option( 'hayfutbol_pinger_last_error', $detail, false );
        }
    }

    public function unregister(): void {
        delete_option( self::TOKEN_OPTION );
        delete_option( self::STATUS_OPTION );
        delete_option( 'hayfutbol_pinger_last_error' );
        delete_option( 'hayfutbol_verify_debug' );
    }

    private function get_server_ip(): string {
        if ( ! empty( $_SERVER['SERVER_ADDR'] ) ) {
            return $_SERVER['SERVER_ADDR'];
        }
        $ip = gethostbyname( gethostname() );
        return $ip !== gethostname() ? $ip : '';
    }
}
