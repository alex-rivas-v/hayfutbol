<?php
namespace HayFutbol;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AES-256-CBC encryption helper for storing sensitive plugin options.
 *
 * On activation, generate_key() writes HAYFUTBOL_ENCRYPTION_KEY to wp-config.php
 * so the key lives outside the database. Falls back to wp_options if the file
 * is not writable (the key is still unique per installation, just not separated
 * from the ciphertext at the storage layer).
 */
class Encryption {

    const KEY_OPTION = 'hayfutbol_encryption_key';

    private static function key(): string {
        if ( defined( 'HAYFUTBOL_ENCRYPTION_KEY' ) ) {
            return substr( hash( 'sha256', HAYFUTBOL_ENCRYPTION_KEY, true ), 0, 32 );
        }

        $stored = get_option( self::KEY_OPTION, '' );
        if ( $stored ) {
            return base64_decode( $stored );
        }

        // Should not happen after activation; generate and persist so it stays stable.
        $key = base64_encode( random_bytes( 32 ) );
        add_option( self::KEY_OPTION, $key, '', 'no' );
        return base64_decode( $key );
    }

    /**
     * Generates a unique key and writes it to wp-config.php when possible.
     * Falls back to wp_options. Must be called on plugin activation.
     */
    public static function generate_key(): void {
        if ( defined( 'HAYFUTBOL_ENCRYPTION_KEY' ) || get_option( self::KEY_OPTION ) ) {
            return;
        }

        $key = base64_encode( random_bytes( 32 ) );

        if ( ! self::write_to_wpconfig( $key ) ) {
            add_option( self::KEY_OPTION, $key, '', 'no' );
        }
    }

    private static function write_to_wpconfig( string $key ): bool {
        $path = self::find_wpconfig();
        if ( ! $path || ! is_writable( $path ) ) {
            return false;
        }

        $contents = file_get_contents( $path );
        if ( false === $contents ) {
            return false;
        }

        if ( strpos( $contents, 'HAYFUTBOL_ENCRYPTION_KEY' ) !== false ) {
            return true; // Already present.
        }

        $define = "define( 'HAYFUTBOL_ENCRYPTION_KEY', '" . addslashes( $key ) . "' );\n";
        $marker = "/* That's all, stop editing!";
        $pos    = strpos( $contents, $marker );

        $contents = false !== $pos
            ? substr( $contents, 0, $pos ) . $define . "\n" . substr( $contents, $pos )
            : $contents . "\n" . $define;

        return false !== file_put_contents( $path, $contents, LOCK_EX );
    }

    private static function find_wpconfig(): ?string {
        $path = ABSPATH . 'wp-config.php';
        if ( file_exists( $path ) ) {
            return $path;
        }

        // Some setups place wp-config.php one level above the WP root.
        $parent = dirname( ABSPATH ) . '/wp-config.php';
        if ( file_exists( $parent ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
            return $parent;
        }

        return null;
    }

    /**
     * @param  string $value
     * @return string Base64-encoded IV + ciphertext.
     */
    public static function encrypt( string $value ): string {
        $iv        = random_bytes( 16 );
        $encrypted = openssl_encrypt( $value, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $encrypted );
    }

    /**
     * @param  string $value Base64-encoded IV + ciphertext.
     * @return string        Decrypted string, or empty string on failure.
     */
    public static function decrypt( string $value ): string {
        $raw = base64_decode( $value, true );
        if ( false === $raw || strlen( $raw ) < 17 ) {
            return '';
        }
        $iv         = substr( $raw, 0, 16 );
        $ciphertext = substr( $raw, 16 );
        $decrypted  = openssl_decrypt( $ciphertext, 'AES-256-CBC', self::key(), OPENSSL_RAW_DATA, $iv );
        return false !== $decrypted ? $decrypted : '';
    }
}
