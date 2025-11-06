<?php
/**
 * AshMaize FFI Hasher
 *
 * High-performance hasher using PHP FFI to call compiled Rust library.
 * Based on ce-ashmaize via FFI C-ABI wrapper.
 *
 * @package NightMinePHP
 */

// ABSPATH check removed for CLI testing

class AshMaizeFFI {

    private FFI $ffi;
    private $ctx = null;  // ash_ctx_t* pointer
    public string $no_pre_mine_hex;  // Public so mining engine can check if key changed

    /**
     * Initialize FFI hasher with no_pre_mine key
     *
     * @param string $dll_path Path to ashmaize_capi.dll
     * @param string $no_pre_mine_hex Hex-encoded no_pre_mine from challenge
     * @throws Exception if FFI not available, DLL not found, or ROM init fails
     */
    public function __construct(string $dll_path, string $no_pre_mine_hex) {
        // Enable FFI at runtime (required for web context)
        @ini_set('ffi.enable', '1');

        // Check FFI availability
        if (!extension_loaded('ffi')) {
            throw new Exception('PHP FFI extension not loaded. Enable in php.ini: extension=ffi');
        }

        if (!class_exists('FFI')) {
            throw new Exception('FFI class not available. Check php.ini: ffi.enable=true or ini_set failed');
        }

        // Check DLL exists
        if (!file_exists($dll_path)) {
            throw new Exception("AshMaize DLL not found: {$dll_path}");
        }

        // Load C header
        $header_path = dirname(__FILE__) . '/../ffi/ashmaize_capi.h';
        if (!file_exists($header_path)) {
            throw new Exception("C header not found: {$header_path}");
        }

        $header = file_get_contents($header_path);
        if ($header === false) {
            throw new Exception("Failed to read C header");
        }

        // Initialize FFI
        try {
            $this->ffi = FFI::cdef($header, $dll_path);
        } catch (FFI\Exception $e) {
            throw new Exception("FFI initialization failed: " . $e->getMessage());
        }

        // Initialize ROM with no_pre_mine
        // CRITICAL: JavaScript uses TextEncoder.encode(no_pre_mine) which encodes
        // the HEX STRING ITSELF as UTF-8 bytes, NOT the decoded binary!
        // Example: "fd65" becomes [0x66,0x64,0x36,0x35] (ASCII "fd65")
        // NOT [0xfd,0x65] (binary representation)
        $this->no_pre_mine_hex = $no_pre_mine_hex;
        $seed = $no_pre_mine_hex;  // Use the hex string directly as UTF-8!

        error_log("AshMaizeFFI: Initializing ROM (this takes ~10-30 seconds for 1GB ROM)...");
        $start = microtime(true);

        $seed_buf = FFI::new("uint8_t[" . strlen($seed) . "]", false);
  	FFI::memcpy($seed_buf, $seed, strlen($seed));
  	$this->ctx = $this->ffi->ash_new($seed_buf, strlen($seed));

        $elapsed = microtime(true) - $start;
        error_log(sprintf("AshMaizeFFI: ROM initialized in %.2f seconds", $elapsed));

        if (FFI::isNull($this->ctx)) {
            throw new Exception("Failed to initialize AshMaize ROM");
        }
    }

    /**
     * Compute 64-byte AshMaize hash
     *
     * @param string $preimage Binary preimage data
     * @return string 128-character lowercase hex string (64 bytes)
     */
    public function hash(string $preimage): string {
        if (FFI::isNull($this->ctx)) {
            throw new Exception("ROM not initialized");
        }

        // Allocate output buffer
        $out = FFI::new("uint8_t[64]");

        // Call Rust hasher
        $input_buf = FFI::new("uint8_t[" . strlen($preimage) . "]", false);
  	FFI::memcpy($input_buf, $preimage, strlen($preimage));
  	$this->ffi->ash_hash($this->ctx, $input_buf, strlen($preimage), $out);

        // Convert to hex
        $hex = '';
        for ($i = 0; $i < 64; $i++) {
            $hex .= str_pad(dechex($out[$i]), 2, '0', STR_PAD_LEFT);
        }

        return strtolower($hex);
    }

    /**
     * Cleanup: free ROM
     */
    public function __destruct() {
        if ($this->ctx && !FFI::isNull($this->ctx)) {
            error_log("AshMaizeFFI: Freeing ROM");
            $this->ffi->ash_free($this->ctx);
            $this->ctx = null;
        }
    }

    /**
     * Build preimage string for AshMaize hashing
     *
     * Concatenates challenge parameters in exact order per API spec.
     *
     * @param string $nonce_hex 16-char hex (64-bit)
     * @param string $address Cardano address
     * @param string $challenge_id e.g. "**D07C10"
     * @param string $difficulty_hex 8-char hex (e.g. "000FFFFF")
     * @param string $no_pre_mine_hex Hex string
     * @param string $latest_submission ISO 8601 (e.g. "2025-10-19T08:59:59.000Z")
     * @param string $no_pre_mine_hour String number
     * @return string Binary preimage
     */
    public static function build_preimage(
        string $nonce_hex,
        string $address,
        string $challenge_id,
        string $difficulty_hex,
        string $no_pre_mine_hex,
        string $latest_submission,
        string $no_pre_mine_hour
    ): string {
        // Validate inputs
        if (!preg_match('/^[0-9a-f]{16}$/i', $nonce_hex)) {
            throw new InvalidArgumentException("nonce must be 64-bit hex (16 hex chars)");
        }
        if (!preg_match('/^[0-9A-Fa-f]{8}$/', $difficulty_hex)) {
            throw new InvalidArgumentException("difficulty must be 8 hex chars");
        }
        if (!preg_match('/^[0-9A-Fa-f]+$/', $no_pre_mine_hex)) {
            throw new InvalidArgumentException("no_pre_mine must be hex");
        }

        // Build preimage (exact concatenation, no separators)
        return $nonce_hex
            . $address
            . $challenge_id
            . strtoupper($difficulty_hex)
            . strtolower($no_pre_mine_hex)
            . $latest_submission
            . $no_pre_mine_hour;
    }

    /**
     * Check if hash meets difficulty requirement
     *
     * Per API spec: "Each zero bit of the mask corresponds to a zero prefix bit
     * of a successful AshMaize hash."
     *
     * @param string $hash_hex 128-char hex hash
     * @param string $difficulty_hex 8-char hex difficulty mask
     * @param int $extra_bits Additional zero bits required beyond mask (default 0)
     * @return bool True if hash meets difficulty
     */
    public static function check_difficulty(string $hash_hex, string $difficulty_hex, int $extra_bits = 0): bool {
        // Extract first 4 bytes of hash (big-endian)
        $hash_bytes = hex2bin($hash_hex);
        if ($hash_bytes === false || strlen($hash_bytes) < 4) {
            throw new InvalidArgumentException("Invalid hash hex");
        }

        $first_4 = substr($hash_bytes, 0, 4);
        $hash32 = unpack('N', $first_4)[1];  // big-endian unsigned 32-bit

        // Difficulty mask
        if (strlen($difficulty_hex) !== 8) {
            throw new InvalidArgumentException("Difficulty must be 8 hex chars");
        }
        $diff32 = hexdec($difficulty_hex);

        // Zero mask: where difficulty has zeros, hash must also have zeros
        $zero_mask = (~$diff32) & 0xFFFFFFFF;

        // Check if hash meets base difficulty
        if (($hash32 & $zero_mask) !== 0) {
            return false;
        }

        // If extra bits required, count leading zeros
        if ($extra_bits > 0) {
            $required_zeros = self::required_zero_bits(hex2bin($difficulty_hex));
            $actual_zeros = self::count_leading_zero_bits($first_4);

            return $actual_zeros >= ($required_zeros + $extra_bits);
        }

        return true;
    }

    /**
     * Count required zero bits from difficulty mask
     */
    public static function required_zero_bits(string $difficulty_bin): int {
        $zeros = 0;
        for ($i = 0; $i < 4; $i++) {
            $byte = ord($difficulty_bin[$i]);
            if ($byte === 0) {
                $zeros += 8;
            } else {
                // Count leading zeros in this byte
                $zeros += 8 - strlen(decbin($byte));
                break;
            }
        }
        return $zeros;
    }

    /**
     * Count leading zero bits in binary data
     */
    public static function count_leading_zero_bits(string $data): int {
        $zeros = 0;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($data[$i]);
            if ($byte === 0) {
                $zeros += 8;
            } else {
                // Count leading zeros in this byte
                for ($bit = 7; $bit >= 0; $bit--) {
                    if (($byte & (1 << $bit)) === 0) {
                        $zeros++;
                    } else {
                        return $zeros;
                    }
                }
            }
        }

        return $zeros;
    }
}

