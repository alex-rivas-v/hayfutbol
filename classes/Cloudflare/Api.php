<?php
namespace HayFutbol\Cloudflare;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Minimal Cloudflare API v4 client.
 * Requires a token scoped to Zone > DNS > Edit.
 */
class Api {

    const BASE_URL = 'https://api.cloudflare.com/client/v4';

    /** @var string */
    private $token;

    /** @var string */
    private $zone_id;

    public function __construct( string $token, string $zone_id ) {
        $this->token   = $token;
        $this->zone_id = $zone_id;
    }

    /**
     * Enables or disables the proxy (orange cloud) for a DNS record.
     *
     * @param  string $record_id
     * @param  bool   $proxied
     * @return array|\WP_Error
     */
    public function set_proxy( string $record_id, bool $proxied ) {
        return $this->request(
            'PATCH',
            "/zones/{$this->zone_id}/dns_records/{$record_id}",
            array( 'proxied' => $proxied )
        );
    }

    /**
     * @param  string $record_id
     * @return array|\WP_Error
     */
    public function get_dns_record( string $record_id ) {
        return $this->request( 'GET', "/zones/{$this->zone_id}/dns_records/{$record_id}" );
    }

    /**
     * Lists all A records for the zone. No hostname filter is applied so this
     * works regardless of the WordPress home_url (including local environments).
     *
     * @return array|\WP_Error
     */
    public function get_dns_records() {
        return $this->request(
            'GET',
            '/zones/' . $this->zone_id . '/dns_records?' . http_build_query(
                array( 'type' => 'A' )
            )
        );
    }

    private function request( string $method, string $endpoint, array $body = array() ) {
        $args = array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ),
        );

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( self::BASE_URL . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $parsed = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $parsed ) ) {
            return new \WP_Error( 'cf_invalid_response', 'Invalid JSON received from Cloudflare API.' );
        }

        return $parsed;
    }
}
