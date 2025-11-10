<?php
/**
 * Encryption Helper for Umbrella Mines
 * Uses WordPress salts for encryption key derivation
 *
 * Adapted from umbrella-blog plugin
 */
class UmbrellaMines_EncryptionHelper {

    /**
     * Encrypt sensitive data (mnemonic, skey)
     *
     * @param string $plaintext Data to encrypt
     * @return string Base64-encoded encrypted data with IV prepended
     */
    public static function encrypt($plaintext) {
        if (empty($plaintext)) {
            error_log('UmbrellaMines_EncryptionHelper: encrypt() called with empty plaintext');
            return '';
        }

        // Derive encryption key from WordPress salts
        $key = self::deriveKey();

        // Generate random IV (16 bytes for AES-256-CBC)
        $iv = openssl_random_pseudo_bytes(16);

        // Encrypt using AES-256-CBC
        $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            error_log('UmbrellaMines_EncryptionHelper: openssl_encrypt() returned FALSE');
            error_log('UmbrellaMines_EncryptionHelper: OpenSSL error: ' . openssl_error_string());
            return '';
        }

        // Prepend IV to encrypted data and encode as base64
        $result = base64_encode($iv . $encrypted);

        return $result;
    }

    /**
     * Decrypt sensitive data
     *
     * @param string $ciphertext Base64-encoded encrypted data with IV
     * @return string|false Decrypted plaintext or false on failure
     */
    public static function decrypt($ciphertext) {
        if (empty($ciphertext)) {
            return false;
        }

        // Derive encryption key from WordPress salts
        $key = self::deriveKey();

        // Decode from base64
        $data = base64_decode($ciphertext, true);

        if ($data === false || strlen($data) < 17) {
            error_log('UmbrellaMines_EncryptionHelper: Invalid ciphertext format');
            return false;
        }

        // Extract IV (first 16 bytes) and encrypted data
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        // Decrypt using AES-256-CBC
        $plaintext = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            error_log('UmbrellaMines_EncryptionHelper: Decryption failed');
            return false;
        }

        return $plaintext;
    }

    /**
     * Derive encryption key from WordPress salts
     *
     * @return string 32-byte encryption key
     */
    private static function deriveKey() {
        // Combine WordPress security salts
        // These are unique per installation and defined in wp-config.php

        // Check if WordPress salts are defined
        if (!defined('AUTH_KEY') || !defined('SECURE_AUTH_KEY') ||
            !defined('LOGGED_IN_KEY') || !defined('NONCE_KEY')) {
            error_log('UmbrellaMines_EncryptionHelper: WordPress salts not defined!');
        }

        $salt_data = AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY . NONCE_KEY;

        // Derive a 32-byte key using SHA-256
        return hash('sha256', $salt_data, true);
    }

    /**
     * Test encryption/decryption (for debugging)
     *
     * @return bool True if encryption is working
     */
    public static function test() {
        $test_data = 'test_encryption_' . time();
        $encrypted = self::encrypt($test_data);
        $decrypted = self::decrypt($encrypted);

        $success = ($decrypted === $test_data);

        if (!$success) {
            error_log('UmbrellaMines_EncryptionHelper test failed!');
            error_log('Original: ' . $test_data);
            error_log('Encrypted: ' . $encrypted);
            error_log('Decrypted: ' . ($decrypted ?: 'FALSE'));
        }

        return $success;
    }
}
