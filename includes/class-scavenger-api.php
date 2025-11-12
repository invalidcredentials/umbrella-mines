<?php
/**
 * Scavenger Mine API Client
 *
 * Handles all communication with the Midnight Scavenger Mine API:
 * - Fetch T&C
 * - Register addresses
 * - Get challenges
 * - Submit solutions (NOTE: Done via browser worker, not directly!)
 *
 * @package NightMinePHP
 */

if (!defined('ABSPATH')) {
    exit;
}

class Umbrella_Mines_ScavengerAPI {

    /**
     * Fetch Terms and Conditions
     *
     * @param string $api_url API base URL
     * @return array|false T&C response or false on failure
     */
    public static function get_tandc($api_url) {
        $url = $api_url . '/TandC/1-0';

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => false  // Disable SSL verification (Windows cert issue)
        ));

        if (is_wp_error($response)) {
            error_log("T&C fetch failed: " . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['message'])) {
            error_log("Invalid T&C response");
            return false;
        }

        return $data;
    }

    /**
     * Register wallet address
     *
     * @param string $api_url API base URL
     * @param string $address Cardano address
     * @param string $signature CIP-8 signature (hex) - full COSE_Sign1 structure
     * @param string $pubkey Public key (hex) - 64 chars (32 bytes) SHORT FORM kL
     * @return bool Success status
     */
    public static function register_address($api_url, $address, $signature, $pubkey) {
        // API format: POST /register/{address}/{signature}/{pubkey}
        // pubkey MUST be SHORT form (64 hex chars = 32 bytes) - the kL value, NOT pubkey_bytes!

        $log_prefix = "[REGISTRATION]";

        // Validate pubkey length
        if (strlen($pubkey) !== 64) {
            $msg = "Invalid pubkey length: " . strlen($pubkey) . " chars (expected 64)";
            error_log("$log_prefix ERROR: $msg");
            error_log("$log_prefix Pubkey: $pubkey");
            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::warning("  $msg");
            }
            return false;
        }

        $url = sprintf(
            '%s/register/%s/%s/%s',
            $api_url,
            $address,
            $signature,
            $pubkey
        );

        error_log("$log_prefix Starting registration...");
        error_log("$log_prefix Address: " . $address);
        error_log("$log_prefix Pubkey: " . $pubkey);
        error_log("$log_prefix Signature length: " . strlen($signature) . " chars");
        error_log("$log_prefix URL: " . $url);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line("    → Sending registration request...");
            \WP_CLI::line("    → Timeout: 30s");
        }

        $start_time = microtime(true);
        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'sslverify' => false,  // Disable SSL verification (Windows cert issue)
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8'
            ),
            'body' => '{}'
        ));
        $elapsed = microtime(true) - $start_time;

        error_log("$log_prefix Request completed in " . number_format($elapsed, 2) . "s");

        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            error_log("$log_prefix ERROR: $error_msg");
            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::error("    Registration request failed: $error_msg", false);
            }
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        error_log("$log_prefix HTTP Status: $status_code");
        error_log("$log_prefix Response body: " . substr($body, 0, 500));

        if ($status_code !== 200 && $status_code !== 201) {
            $error_msg = "Registration failed with status {$status_code}: " . $body;
            error_log($error_msg);
            echo "    API Response [{$status_code}]: " . substr($body, 0, 200) . "\n";
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['registrationReceipt'])) {
            error_log("$log_prefix ERROR: Invalid response structure");
            error_log("$log_prefix Response: $body");
            if (defined('WP_CLI') && WP_CLI) {
                \WP_CLI::error("    Invalid registration response from server", false);
                \WP_CLI::line("    Status: $status_code");
                \WP_CLI::line("    Body: " . substr($body, 0, 200));
            }
            return false;
        }

        error_log("$log_prefix ✓ SUCCESS!");
        error_log("$log_prefix Receipt timestamp: " . ($data['registrationReceipt']['timestamp'] ?? 'N/A'));

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::line("    ✓ Server accepted registration");
        }

        return true;
    }

    /**
     * Get current challenge
     *
     * @param string $api_url API base URL
     * @return array|false Challenge data or false on failure
     */
    public static function get_challenge($api_url) {
        $url = $api_url . '/challenge';

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => false  // Disable SSL verification (Windows cert issue)
        ));

        if (is_wp_error($response)) {
            error_log("Challenge fetch failed: " . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);

        error_log("Challenge response code: " . $http_code);
        error_log("Challenge response body: " . substr($body, 0, 500));

        $data = json_decode($body, true);

        if (!$data) {
            error_log("Invalid challenge response - JSON decode failed");
            error_log("Raw body: " . $body);
            return false;
        }

        // Cache challenge in database and flatten structure
        if (isset($data['challenge'])) {
            $challenge = $data['challenge'];
            $challenge['mining_period_ends'] = $data['mining_period_ends'] ?? null;
            self::cache_challenge($challenge);
            return $challenge;  // Return flattened challenge
        }

        return $data;
    }

    /**
     * Cache challenge data in database
     */
    private static function cache_challenge($challenge) {
        global $wpdb;

        $wpdb->replace(
            $wpdb->prefix . 'umbrella_mining_challenges',
            array(
                'challenge_id' => $challenge['challenge_id'],
                'day' => $challenge['day'],
                'challenge_number' => $challenge['challenge_number'],
                'difficulty' => $challenge['difficulty'],
                'no_pre_mine' => $challenge['no_pre_mine'],
                'no_pre_mine_hour' => $challenge['no_pre_mine_hour'],
                'latest_submission' => $challenge['latest_submission'],
                'issued_at' => $challenge['issued_at'],
                'mining_period_ends' => isset($challenge['mining_period_ends']) ? $challenge['mining_period_ends'] : null
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Get cached challenge (for fallback)
     */
    public static function get_cached_challenge($challenge_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}umbrella_mining_challenges WHERE challenge_id = %s",
            $challenge_id
        ), ARRAY_A);
    }

    /**
     * Submit solution (NOTE: This should be called from browser JavaScript!)
     *
     * This method is provided for testing, but actual submissions should go through
     * the browser worker to avoid API blocks.
     *
     * @param string $api_url API base URL
     * @param string $address Wallet address
     * @param string $challenge_id Challenge ID
     * @param string $nonce Nonce (hex)
     * @return array|false Solution receipt or false
     */
    public static function submit_solution($api_url, $address, $challenge_id, $nonce) {
        $url = sprintf(
            '%s/solution/%s/%s/%s',
            $api_url,
            $address,
            $challenge_id,
            $nonce
        );

        error_log("WARNING: Submitting solution directly (should use browser worker!)");
        error_log("Submission URL: " . $url);
        error_log("Submitting: address={$address}, challenge={$challenge_id}, nonce={$nonce}");

        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'sslverify' => false,  // Disable SSL verification (Windows cert issue)
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8'
            ),
            'body' => '{}'
        ));

        if (is_wp_error($response)) {
            error_log("Solution submission failed: " . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200 && $status_code !== 201) {
            error_log("Solution submission failed with status {$status_code}: " . $body);
            return false;
        }

        $data = json_decode($body, true);

        if (!$data || !isset($data['crypto_receipt'])) {
            error_log("Invalid solution response");
            return false;
        }

        return $data;
    }

    /**
     * Get statistics for an address
     */
    public static function get_statistics($api_url, $address) {
        $url = sprintf('%s/statistics/%s', $api_url, $address);

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => false  // Disable SSL verification (Windows cert issue)
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    /**
     * Merge rewards to payout address (donate_to endpoint)
     *
     * @param string $api_url API base URL
     * @param string $destination_address Payout address to receive merged rewards
     * @param string $original_address Mining wallet address to merge from
     * @param string $signature CIP-8 signature (hex) - signed by original_address
     * @return array|false API response or false on failure
     */
    public static function donate_to($api_url, $destination_address, $original_address, $signature) {
        // API format: POST /donate_to/{destination_address}/{original_address}/{signature}
        // Message signed: "Assign accumulated Scavenger rights to: " + destination_address

        $log_prefix = "[MERGE]";

        // Use the CORRECT whitepaper method: signature in URL path
        $url = sprintf('%s/donate_to/%s/%s/%s', $api_url, $destination_address, $original_address, $signature);

        error_log("$log_prefix Full URL (length): " . strlen($url));
        error_log("$log_prefix Destination: " . $destination_address);
        error_log("$log_prefix Original: " . $original_address);
        error_log("$log_prefix Signature (first 50): " . substr($signature, 0, 50) . "...");
        error_log("$log_prefix Signature (length): " . strlen($signature));

        $response = wp_remote_post($url, array(
            'timeout' => 60,
            'sslverify' => false,
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array())
        ));

        if (is_wp_error($response)) {
            $msg = "Merge request failed: " . $response->get_error_message();
            error_log("$log_prefix ERROR: $msg");
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        error_log("$log_prefix HTTP $code");
        error_log("$log_prefix Response: " . $body);

        // Check for success
        if ($code === 200 && $data && isset($data['status']) && $data['status'] === 'success') {
            error_log("$log_prefix SUCCESS: Merged {$original_address} → {$destination_address}");
            return $data;
        }

        // Handle errors
        $error_msg = isset($data['message']) ? $data['message'] : 'Unknown error';
        error_log("$log_prefix FAILED: $error_msg");

        return [
            'success' => false,
            'error' => $error_msg,
            'status_code' => $code
        ];
    }
}
