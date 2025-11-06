<?php
/**
 * Cardano CIP-8 Message Signer
 *
 * Signs arbitrary messages using COSE_Sign1 structure (CIP-8 standard).
 * Used for off-chain authentication like wallet registration.
 *
 * @package NightMinePHP
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/Ed25519Compat.php';

class CardanoCIP8Signer {

    /**
     * Sign a message using CIP-8 (COSE_Sign1)
     *
     * @param string $message Message to sign
     * @param string $extended_key_hex Extended key (128 hex chars = kL||kR)
     * @param string $address Cardano address (bech32)
     * @param string $network 'mainnet' or 'preprod'
     * @return array ['signature' => hex, 'pubkey' => hex]
     */
    public static function sign_message($message, $extended_key_hex, $address, $network = 'mainnet') {
        // Decode extended key
        if (strlen($extended_key_hex) !== 128) {
            throw new Exception('Extended key must be 128 hex chars (64 bytes)');
        }

        $kL = hex2bin(substr($extended_key_hex, 0, 64));
        $kR = hex2bin(substr($extended_key_hex, 64, 64));

        // Derive public key (no-clamp)
        $pubkey = Ed25519Compat::ge_scalarmult_base_noclamp($kL);

        // Decode address to bytes
        $address_bytes = self::bech32_decode($address);

        // Build COSE protected header
        $prot_header = self::encode_protected_header($address_bytes);

        // Build Sig_structure (what we actually sign)
        $sig_structure = self::encode_sig_structure($prot_header, $message);

        // Sign using extended key
        $signature = Ed25519Compat::sign_extended($sig_structure, $kL, $kR);

        // Build COSE_Sign1 structure
        $cose_sign1 = self::encode_cose_sign1($prot_header, $message, $signature);

        return array(
            'signature' => bin2hex($cose_sign1),
            'pubkey' => bin2hex($pubkey), // ✓ CORRECT: Actual public key (32 bytes)
            'pubkey_kL' => substr($extended_key_hex, 0, 64), // kL (private key half - DO NOT USE for API!)
            'signature_raw' => bin2hex($signature),
            'pubkey_bytes' => bin2hex($pubkey) // Same as pubkey - kept for backwards compat
        );
    }

    /**
     * Encode COSE protected header
     * Map: {1: -8, "address": <address_bytes>}
     */
    private static function encode_protected_header($address_bytes) {
        $map = array(
            1 => -8,  // Algorithm: EdDSA
            'address' => $address_bytes
        );

        return self::encode_cbor_map($map);
    }

    /**
     * Encode Sig_structure for signing
     * Array: ["Signature1", protected_header, "", message_bytes]
     */
    private static function encode_sig_structure($prot_header, $message) {
        $array = array(
            'Signature1',
            $prot_header,
            '',  // External AAD (empty)
            $message
        );

        return self::encode_cbor_array($array);
    }

    /**
     * Encode COSE_Sign1 structure
     * Array: [protected_header, {hashed: false}, message_bytes, signature]
     */
    private static function encode_cose_sign1($prot_header, $message, $signature) {
        $unprotected = array('hashed' => false);

        $array = array(
            $prot_header,
            $unprotected,
            $message,
            $signature
        );

        return self::encode_cbor_array($array);
    }

    /**
     * Simple CBOR encoder for CIP-8 structures
     */
    private static function encode_cbor_map($map) {
        $cbor = chr(0xA0 | count($map)); // Map with N pairs

        foreach ($map as $key => $value) {
            // Encode key
            if (is_int($key)) {
                if ($key >= 0 && $key <= 23) {
                    $cbor .= chr($key);
                } elseif ($key < 0 && $key >= -24) {
                    $cbor .= chr(0x20 | (-1 - $key));
                } else {
                    throw new Exception('Unsupported integer key');
                }
            } elseif (is_string($key)) {
                $cbor .= self::encode_cbor_text($key);
            }

            // Encode value
            if (is_int($value)) {
                if ($value >= 0 && $value <= 23) {
                    $cbor .= chr($value);
                } elseif ($value < 0 && $value >= -24) {
                    $cbor .= chr(0x20 | (-1 - $value));
                } else {
                    throw new Exception('Unsupported integer value');
                }
            } elseif (is_string($value)) {
                $cbor .= self::encode_cbor_bytes($value);
            } elseif (is_bool($value)) {
                $cbor .= chr($value ? 0xF5 : 0xF4);
            }
        }

        return $cbor;
    }

    private static function encode_cbor_array($array) {
        $cbor = chr(0x80 | count($array)); // Array with N elements

        foreach ($array as $value) {
            if (is_string($value)) {
                if (ctype_print($value) || $value === 'Signature1') {
                    $cbor .= self::encode_cbor_text($value);
                } else {
                    $cbor .= self::encode_cbor_bytes($value);
                }
            } elseif (is_array($value)) {
                $cbor .= self::encode_cbor_map($value);
            } elseif (is_int($value)) {
                if ($value >= 0 && $value <= 23) {
                    $cbor .= chr($value);
                } else {
                    throw new Exception('Unsupported integer in array');
                }
            }
        }

        return $cbor;
    }

    private static function encode_cbor_text($text) {
        $len = strlen($text);

        if ($len <= 23) {
            return chr(0x60 | $len) . $text;
        } elseif ($len <= 255) {
            return chr(0x78) . chr($len) . $text;
        } else {
            return chr(0x79) . pack('n', $len) . $text;
        }
    }

    private static function encode_cbor_bytes($bytes) {
        $len = strlen($bytes);

        if ($len <= 23) {
            return chr(0x40 | $len) . $bytes;
        } elseif ($len <= 255) {
            return chr(0x58) . chr($len) . $bytes;
        } else {
            return chr(0x59) . pack('n', $len) . $bytes;
        }
    }

    /**
     * Decode Bech32 address to raw bytes
     */
    private static function bech32_decode($address) {
        // Simple bech32 decoder
        $parts = explode('1', $address);

        if (count($parts) !== 2) {
            throw new Exception('Invalid bech32 address');
        }

        list($hrp, $data_part) = $parts;

        // Bech32 charset
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $decoded = array();

        for ($i = 0; $i < strlen($data_part) - 6; $i++) { // Skip checksum (last 6 chars)
            $char = $data_part[$i];
            $pos = strpos($charset, $char);

            if ($pos === false) {
                throw new Exception('Invalid bech32 character');
            }

            $decoded[] = $pos;
        }

        // Convert from 5-bit to 8-bit
        $bytes = '';
        $acc = 0;
        $bits = 0;

        foreach ($decoded as $value) {
            $acc = ($acc << 5) | $value;
            $bits += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($acc >> $bits) & 0xFF);
                $acc &= (1 << $bits) - 1;
            }
        }

        return $bytes;
    }
}
