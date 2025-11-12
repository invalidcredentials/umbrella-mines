<?php
/**
 * Merge Processor
 * Handles batch processing of wallet merges to payout address
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-payout-wallet.php';
require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-scavenger-api.php';
require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoCIP8Signer.php';
require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/UmbrellaMines_EncryptionHelper.php';

class Umbrella_Mines_Merge_Processor {

    /**
     * Get merge statistics
     *
     * @param string $network Network
     * @return array Statistics
     */
    public static function get_statistics($network = 'mainnet') {
        global $wpdb;

        $wallets_table = $wpdb->prefix . 'umbrella_mining_wallets';
        $solutions_table = $wpdb->prefix . 'umbrella_mining_solutions';
        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';

        error_log("[MERGE STATS] Network: $network");

        // Check how many total solutions exist
        $total_solutions = $wpdb->get_var("SELECT COUNT(*) FROM {$solutions_table}");
        error_log("[MERGE STATS] Total solutions in DB: $total_solutions");

        // Check how many wallets exist for this network
        $total_network_wallets = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wallets_table} WHERE network = %s", $network));
        error_log("[MERGE STATS] Total wallets for network '$network': $total_network_wallets");

        // First, let's see what statuses exist
        $statuses = $wpdb->get_col("SELECT DISTINCT submission_status FROM {$solutions_table}");
        error_log("[MERGE STATS] Available statuses: " . implode(', ', $statuses));

        // Total wallets with submitted solutions (submitted = confirmed/accepted by API)
        $total_wallets = $wpdb->get_var("
            SELECT COUNT(DISTINCT w.id)
            FROM {$wallets_table} w
            INNER JOIN {$solutions_table} s ON w.id = s.wallet_id
            WHERE s.submission_status = 'submitted'
        ");

        error_log("[MERGE STATS] Total wallets with submitted solutions: $total_wallets");

        // Get payout wallet ID to exclude it
        $payout_wallet = self::get_registered_payout_wallet($network);
        $payout_wallet_id = $payout_wallet ? $payout_wallet->id : 0;

        // Eligible wallets (submitted solutions + not yet merged + not the payout wallet)
        $eligible_wallets = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT w.id)
            FROM {$wallets_table} w
            INNER JOIN {$solutions_table} s ON w.id = s.wallet_id
            LEFT JOIN {$merges_table} m ON w.id = m.original_wallet_id AND m.status = 'success'
            WHERE s.submission_status = 'submitted'
            AND m.id IS NULL
            AND w.id != %d
        ", $payout_wallet_id));

        // Already merged wallets
        $merged_wallets = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT original_wallet_id)
            FROM {$merges_table}
            WHERE status = 'success'
        "));

        // Merge history (recent 50)
        $merge_history = $wpdb->get_results("
            SELECT m.*, w.address as original_addr
            FROM {$merges_table} m
            LEFT JOIN {$wallets_table} w ON m.original_wallet_id = w.id
            ORDER BY m.merged_at DESC
            LIMIT 50
        ");

        return [
            'total_wallets' => (int) $total_wallets,
            'eligible_wallets' => (int) $eligible_wallets,
            'merged_wallets' => (int) $merged_wallets,
            'merge_history' => $merge_history
        ];
    }

    /**
     * Get eligible wallets for merging
     *
     * @param string $network Network
     * @return array Eligible wallets
     */
    public static function get_eligible_wallets($network = 'mainnet') {
        global $wpdb;

        $wallets_table = $wpdb->prefix . 'umbrella_mining_wallets';
        $solutions_table = $wpdb->prefix . 'umbrella_mining_solutions';
        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';

        // Get the payout wallet ID (first registered wallet)
        $payout_wallet = self::get_registered_payout_wallet($network);
        $payout_wallet_id = $payout_wallet ? $payout_wallet->id : 0;

        // Get wallets with submitted solutions that haven't been merged yet
        // Exclude the payout wallet itself
        // Process OLDEST wallets first (ASC) to maintain chronological order
        $wallets = $wpdb->get_results($wpdb->prepare("
            SELECT
                w.*,
                COUNT(s.id) as submitted_solutions_count
            FROM {$wallets_table} w
            INNER JOIN {$solutions_table} s ON w.id = s.wallet_id
            LEFT JOIN {$merges_table} m ON w.id = m.original_wallet_id AND m.status = 'success'
            WHERE s.submission_status = 'submitted'
            AND m.id IS NULL
            AND w.id != %d
            GROUP BY w.id
            ORDER BY w.created_at ASC
        ", $payout_wallet_id));

        return $wallets;
    }

    /**
     * Merge single wallet to payout address with retry logic
     *
     * @param int $wallet_id Wallet ID
     * @param string $payout_address Destination payout address
     * @param int $max_retries Maximum retry attempts (default 3)
     * @return array Result with success status, message, and detailed attempt log
     */
    public static function merge_wallet($wallet_id, $payout_address, $max_retries = 3) {
        global $wpdb;

        $attempt_log = [];
        $attempt_number = 0;

        error_log("=== MERGE WALLET START ===");
        error_log("Wallet ID: $wallet_id");
        error_log("Payout Address: $payout_address");
        error_log("Max Retries: $max_retries");

        $api_url = get_option('umbrella_mines_api_url', 'https://scavenger.prod.gd.midnighttge.io');
        error_log("API URL: $api_url");

        // Get wallet data (including encrypted mnemonic)
        $wallets_table = $wpdb->prefix . 'umbrella_mining_wallets';
        $wallet = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wallets_table} WHERE id = %d",
            $wallet_id
        ));

        if (!$wallet) {
            error_log("ERROR: Wallet not found");
            return ['success' => false, 'error' => 'Wallet not found'];
        }

        // Store mnemonic for merge record
        $mnemonic_encrypted = $wallet->mnemonic_encrypted ?? '';

        error_log("Wallet found: " . $wallet->address);
        error_log("Network: " . $wallet->network);
        error_log("Registration signature: " . ($wallet->registration_signature ? 'EXISTS' : 'NULL'));
        error_log("Registration pubkey: " . ($wallet->registration_pubkey ? 'EXISTS' : 'NULL'));
        error_log("Registered at: " . ($wallet->registered_at ?? 'NULL'));

        // Check if already merged
        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';
        $existing_merge = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$merges_table} WHERE original_wallet_id = %d AND status = 'success'",
            $wallet_id
        ));

        if ($existing_merge) {
            error_log("ERROR: Wallet already merged");
            return ['success' => false, 'error' => 'Wallet already merged'];
        }

        // Get wallet's private key from database
        // Mining wallets store keys as plaintext hex (128 chars)
        // Payout wallets store keys encrypted (much longer base64)
        $skey = $wallet->payment_skey_extended;
        error_log("Raw payment_skey_extended from DB (length: " . strlen($skey) . ")");

        // Check if it's encrypted (base64 is much longer than 128) or plaintext hex
        if (strlen($skey) > 128) {
            // This is encrypted - decrypt it
            error_log("Key appears encrypted (length > 128), attempting to decrypt...");
            $decrypted = UmbrellaMines_EncryptionHelper::decrypt($skey);
            if (!$decrypted) {
                error_log("ERROR: Failed to decrypt wallet private key");
                return ['success' => false, 'error' => 'Failed to decrypt wallet private key'];
            }
            $skey = $decrypted;
            error_log("Successfully decrypted key (length: " . strlen($skey) . ")");
        } else {
            // This is plaintext hex (mining wallets)
            error_log("Key is plaintext hex (length: " . strlen($skey) . ")");
        }

        // Validate it's the expected 128 hex chars (64 bytes)
        if (strlen($skey) != 128 || !ctype_xdigit($skey)) {
            error_log("ERROR: Invalid key format - expected 128 hex chars, got length: " . strlen($skey));
            return ['success' => false, 'error' => 'Invalid wallet private key format'];
        }

        error_log("Private key validated successfully (128 hex chars)");

        // Construct message to sign
        $message = "Assign accumulated Scavenger rights to: " . $payout_address;
        error_log("Message to sign: $message");

        // Load CIP-8 signing function
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoCIP8Signer.php';

        // Sign with CIP-8
        error_log("Signing message with CIP-8...");
        try {
            $signature_data = CardanoCIP8Signer::sign_message($message, $skey, $wallet->address, $wallet->network);

            if (!$signature_data || !isset($signature_data['signature'])) {
                error_log("ERROR: Failed to sign message - no signature in response");
                return ['success' => false, 'error' => 'Failed to sign message'];
            }

            $signature = $signature_data['signature'];
            error_log("Signature generated (length: " . strlen($signature) . ")");

        } catch (Exception $e) {
            error_log("ERROR: Signing exception: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return ['success' => false, 'error' => 'Signing error: ' . $e->getMessage()];
        }

        // RETRY LOOP with intelligent error handling
        while ($attempt_number < $max_retries) {
            $attempt_number++;
            error_log("Attempt $attempt_number of $max_retries");

            // Call API
            error_log("Calling donate_to API...");
            error_log("  Destination: $payout_address");
            error_log("  Original: " . $wallet->address);
            error_log("  Signature: " . substr($signature, 0, 50) . "...");

            $api_response = Umbrella_Mines_ScavengerAPI::donate_to(
                $api_url,
                $payout_address,
                $wallet->address,
                $signature
            );

            error_log("API Response: " . print_r($api_response, true));

            // Log this attempt
            $attempt_log[] = [
                'attempt' => $attempt_number,
                'response' => $api_response,
                'timestamp' => current_time('mysql')
            ];

            // CLASSIFY THE RESPONSE
            $status_code = isset($api_response['status_code']) ? (int)$api_response['status_code'] : 0;
            $is_success = isset($api_response['status']) && $api_response['status'] === 'success';

            // SUCCESS CASE
            if ($is_success) {
                $solutions_consolidated = isset($api_response['solutions_consolidated']) ? (int)$api_response['solutions_consolidated'] : 0;
                error_log("SUCCESS: Merge completed on attempt $attempt_number!");
                error_log("Solutions consolidated: $solutions_consolidated");

                $wpdb->insert(
                    $merges_table,
                    [
                        'original_address' => $wallet->address,
                        'payout_address' => $payout_address,
                        'original_wallet_id' => $wallet_id,
                        'merge_signature' => $signature,
                        'merge_receipt' => json_encode($api_response),
                        'solutions_consolidated' => $solutions_consolidated,
                        'status' => 'success',
                        'error_message' => null,
                        'mnemonic_encrypted' => $mnemonic_encrypted,
                        'merged_at' => current_time('mysql')
                    ]
                );

                return [
                    'success' => true,
                    'solutions_consolidated' => $solutions_consolidated,
                    'receipt' => $api_response,
                    'attempts' => $attempt_number,
                    'attempt_log' => $attempt_log
                ];
            }

            // ERROR CLASSIFICATION
            $error_msg = isset($api_response['error']) ? $api_response['error'] : (isset($api_response['message']) ? $api_response['message'] : 'Unknown error');

            // 409 CONFLICT - Already assigned (treat as success!)
            if ($status_code === 409) {
                error_log("409 CONFLICT: Wallet already assigned - treating as success");

                $wpdb->insert(
                    $merges_table,
                    [
                        'original_address' => $wallet->address,
                        'payout_address' => $payout_address,
                        'original_wallet_id' => $wallet_id,
                        'merge_signature' => $signature,
                        'merge_receipt' => json_encode($api_response),
                        'solutions_consolidated' => 0,
                        'status' => 'success',
                        'error_message' => 'Already assigned (409)',
                        'mnemonic_encrypted' => $mnemonic_encrypted,
                        'merged_at' => current_time('mysql')
                    ]
                );

                return [
                    'success' => true,
                    'already_assigned' => true,
                    'solutions_consolidated' => 0,
                    'receipt' => $api_response,
                    'attempts' => $attempt_number,
                    'attempt_log' => $attempt_log,
                    'note' => 'Wallet already had active assignment'
                ];
            }

            // 400 BAD REQUEST - Invalid signature, don't retry
            if ($status_code === 400) {
                error_log("400 BAD REQUEST: Invalid signature - NOT retrying");

                $wpdb->insert(
                    $merges_table,
                    [
                        'original_address' => $wallet->address,
                        'payout_address' => $payout_address,
                        'original_wallet_id' => $wallet_id,
                        'merge_signature' => $signature,
                        'merge_receipt' => json_encode($api_response),
                        'solutions_consolidated' => 0,
                        'status' => 'failed',
                        'error_message' => '400 Bad Request: ' . $error_msg,
                        'mnemonic_encrypted' => $mnemonic_encrypted,
                        'merged_at' => current_time('mysql')
                    ]
                );

                return [
                    'success' => false,
                    'error' => '400 Bad Request: ' . $error_msg,
                    'error_type' => 'client_error',
                    'retryable' => false,
                    'attempts' => $attempt_number,
                    'attempt_log' => $attempt_log
                ];
            }

            // 404 NOT FOUND - Wallet not registered, don't retry
            if ($status_code === 404) {
                error_log("404 NOT FOUND: Wallet not registered - NOT retrying");

                $wpdb->insert(
                    $merges_table,
                    [
                        'original_address' => $wallet->address,
                        'payout_address' => $payout_address,
                        'original_wallet_id' => $wallet_id,
                        'merge_signature' => $signature,
                        'merge_receipt' => json_encode($api_response),
                        'solutions_consolidated' => 0,
                        'status' => 'failed',
                        'error_message' => '404 Not Found: ' . $error_msg,
                        'mnemonic_encrypted' => $mnemonic_encrypted,
                        'merged_at' => current_time('mysql')
                    ]
                );

                return [
                    'success' => false,
                    'error' => '404 Not Found: ' . $error_msg,
                    'error_type' => 'not_registered',
                    'retryable' => false,
                    'attempts' => $attempt_number,
                    'attempt_log' => $attempt_log
                ];
            }

            // NETWORK/SERVER ERRORS (5xx or no response) - RETRY
            $should_retry = (!$api_response || $status_code >= 500 || $status_code === 0);

            if ($should_retry && $attempt_number < $max_retries) {
                // Exponential backoff: 0.5s, 1s, 2s
                $backoff = pow(2, $attempt_number - 1) * 0.5;
                error_log("Retryable error - waiting {$backoff}s before retry...");
                usleep($backoff * 1000000);
                continue;
            }

            // MAX RETRIES REACHED or other error
            break;
        }

        // ALL RETRIES FAILED
        error_log("ERROR: All $attempt_number attempts failed");

        $wpdb->insert(
            $merges_table,
            [
                'original_address' => $wallet->address,
                'payout_address' => $payout_address,
                'original_wallet_id' => $wallet_id,
                'merge_signature' => $signature,
                'merge_receipt' => json_encode($api_response ?? []),
                'solutions_consolidated' => 0,
                'status' => 'failed',
                'error_message' => $error_msg ?? 'Unknown error after ' . $attempt_number . ' attempts',
                'mnemonic_encrypted' => $mnemonic_encrypted,
                'merged_at' => current_time('mysql')
            ]
        );

        return [
            'success' => false,
            'error' => $error_msg ?? 'Unknown error',
            'error_type' => 'max_retries_exceeded',
            'retryable' => true,
            'attempts' => $attempt_number,
            'attempt_log' => $attempt_log
        ];
    }

    /**
     * Merge all eligible wallets to payout address (batch operation)
     * Returns detailed log with all receipts and errors
     *
     * @param string $network Network
     * @return array Comprehensive results with detailed logs
     */
    public static function merge_all($network = 'mainnet') {
        $session_start = microtime(true);
        $session_id = uniqid('merge_', true);

        // Get payout wallet (first registered wallet)
        $payout_wallet = self::get_registered_payout_wallet($network);

        if (!$payout_wallet) {
            return [
                'session_id' => $session_id,
                'started_at' => current_time('mysql'),
                'completed_at' => current_time('mysql'),
                'duration_seconds' => 0,
                'total_wallets' => 0,
                'successful' => 0,
                'already_assigned' => 0,
                'failed' => 0,
                'details' => [],
                'summary_error' => 'No payout wallet available'
            ];
        }

        $payout_address = $payout_wallet->address;
        $eligible_wallets = self::get_eligible_wallets($network);

        $results = [
            'session_id' => $session_id,
            'started_at' => current_time('mysql'),
            'payout_address' => $payout_address,
            'total_wallets' => count($eligible_wallets),
            'successful' => 0,
            'already_assigned' => 0,
            'failed' => 0,
            'details' => []
        ];

        error_log("=== MERGE ALL SESSION $session_id ===");
        error_log("Total eligible wallets: " . count($eligible_wallets));
        error_log("Payout address: $payout_address");

        foreach ($eligible_wallets as $wallet) {
            $wallet_start = microtime(true);

            error_log("Processing wallet {$wallet->id}: {$wallet->address}");

            $result = self::merge_wallet($wallet->id, $payout_address);

            $wallet_duration = microtime(true) - $wallet_start;

            $detail = [
                'wallet_id' => (int)$wallet->id,
                'address' => $wallet->address,
                'duration_seconds' => round($wallet_duration, 2)
            ];

            if ($result['success']) {
                if (isset($result['already_assigned']) && $result['already_assigned']) {
                    // 409 Conflict - already assigned
                    $results['already_assigned']++;
                    $detail['status'] = 'already_assigned';
                    $detail['note'] = $result['note'] ?? 'Wallet already had active assignment';
                } else {
                    // True success
                    $results['successful']++;
                    $detail['status'] = 'success';
                    $detail['solutions_consolidated'] = $result['solutions_consolidated'] ?? 0;
                    if (isset($result['receipt']['donation_id'])) {
                        $detail['donation_id'] = $result['receipt']['donation_id'];
                    }
                }
                $detail['receipt'] = $result['receipt'] ?? null;
            } else {
                // Failed
                $results['failed']++;
                $detail['status'] = 'failed';
                $detail['error'] = $result['error'] ?? 'Unknown error';
                $detail['error_type'] = $result['error_type'] ?? 'unknown';
                $detail['retryable'] = $result['retryable'] ?? false;
            }

            $detail['attempts'] = $result['attempts'] ?? 1;
            $detail['attempt_log'] = $result['attempt_log'] ?? [];

            $results['details'][] = $detail;

            error_log("Wallet {$wallet->id} result: {$detail['status']} in {$wallet_duration}s");

            // Delay between merges (0.5s)
            usleep(500000);
        }

        $session_duration = microtime(true) - $session_start;
        $results['completed_at'] = current_time('mysql');
        $results['duration_seconds'] = round($session_duration, 2);

        error_log("=== MERGE ALL SESSION COMPLETE ===");
        error_log("Total: {$results['total_wallets']} | Success: {$results['successful']} | Already Assigned: {$results['already_assigned']} | Failed: {$results['failed']}");
        error_log("Duration: {$results['duration_seconds']}s");

        return $results;
    }

    /**
     * Check if wallet can be merged
     *
     * @param int $wallet_id Wallet ID
     * @return bool True if eligible
     */
    public static function is_wallet_eligible($wallet_id) {
        global $wpdb;

        $solutions_table = $wpdb->prefix . 'umbrella_mining_solutions';
        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';

        // Has confirmed solutions?
        $has_confirmed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$solutions_table} WHERE wallet_id = %d AND submission_status = 'confirmed'",
            $wallet_id
        ));

        if (!$has_confirmed) {
            return false;
        }

        // Already merged?
        $already_merged = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$merges_table} WHERE original_wallet_id = %d AND status = 'success'",
            $wallet_id
        ));

        return ($already_merged == 0);
    }

    /**
     * Get first registered wallet with submitted solutions
     * This wallet will be used as the payout destination for merging
     *
     * @param string $network Network
     * @return object|null Wallet object or null
     */
    public static function get_registered_payout_wallet($network = 'mainnet') {
        global $wpdb;

        $payout_table = $wpdb->prefix . 'umbrella_mining_payout_wallet';
        $wallets_table = $wpdb->prefix . 'umbrella_mining_wallets';
        $solutions_table = $wpdb->prefix . 'umbrella_mining_solutions';
        $receipts_table = $wpdb->prefix . 'umbrella_mining_receipts';
        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';

        error_log("=== GET PAYOUT WALLET ===");
        error_log("Network: $network");

        // PRIORITY 1: Check for active imported payout wallet (must have mnemonic!)
        $imported_wallet = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$payout_table}
            WHERE is_active = 1
            AND network = %s
            AND mnemonic_encrypted IS NOT NULL
            AND mnemonic_encrypted != ''
            LIMIT 1
        ", $network));

        error_log("Imported wallet query result: " . ($imported_wallet ? "Found ID " . $imported_wallet->id . " - " . $imported_wallet->address : "None found"));

        if ($imported_wallet) {
            error_log("✅ Using imported payout wallet: " . $imported_wallet->address);
            return $imported_wallet;
        }

        // PRIORITY 2: Auto-select from mining wallets that meet ALL criteria:
        // 1. Has at least one confirmed solution (crypto receipt exists)
        // 2. Has mnemonic stored (we can control it)
        // 3. Does NOT have merge receipt (hasn't been merged to another wallet - no daisy-chaining)
        // 4. Is registered (registered_at IS NOT NULL)

        error_log("Running PRIORITY 2: Auto-select from mining_wallets...");

        $wallet = $wpdb->get_row($wpdb->prepare("
            SELECT w.*,
                   COUNT(DISTINCT s.id) as submitted_count,
                   COUNT(DISTINCT r.id) as receipt_count
            FROM {$wallets_table} w
            INNER JOIN {$solutions_table} s ON w.id = s.wallet_id
            INNER JOIN {$receipts_table} r ON s.id = r.solution_id
            LEFT JOIN {$merges_table} m ON w.id = m.original_wallet_id AND m.status = 'success'
            WHERE s.submission_status = 'submitted'
            AND w.network = %s
            AND w.registered_at IS NOT NULL
            AND w.mnemonic_encrypted IS NOT NULL
            AND w.mnemonic_encrypted != ''
            AND m.id IS NULL
            GROUP BY w.id
            HAVING receipt_count > 0
            ORDER BY w.created_at ASC
            LIMIT 1
        ", $network));

        if ($wallet) {
            error_log("✅ Using auto-selected mining wallet: ID " . $wallet->id . " - " . $wallet->address);
            error_log("Wallet details: submitted_count=" . $wallet->submitted_count . ", receipt_count=" . $wallet->receipt_count . ", has_mnemonic=" . (!empty($wallet->mnemonic_encrypted) ? 'YES' : 'NO'));
        } else {
            error_log("❌ No auto-selected wallet found!");
        }

        return $wallet;
    }

    /**
     * Verify if a wallet is registered with the Scavenger Mine API
     * Tests the /register endpoint - if wallet is already registered, API returns 409 error
     *
     * @param string $address Wallet address
     * @param string $signature CIP-8 signature
     * @param string $pubkey Public key
     * @return array ['is_registered' => bool, 'response' => array, 'error' => string]
     */
    public static function verify_wallet_registration($address, $signature, $pubkey) {
        $api_url = get_option('umbrella_mines_api_url', 'https://scavenger.prod.gd.midnighttge.io');
        $endpoint = "{$api_url}/register/{$address}/{$signature}/{$pubkey}";

        error_log("=== VERIFY WALLET REGISTRATION ===");
        error_log("Endpoint: $endpoint");

        $response = wp_remote_post($endpoint, [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json']
        ]);

        if (is_wp_error($response)) {
            return [
                'is_registered' => false,
                'response' => null,
                'error' => $response->get_error_message()
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        error_log("Status Code: $status_code");
        error_log("Response Body: $body");

        // 409 Conflict = Already registered (this is what we want!)
        if ($status_code === 409) {
            return [
                'is_registered' => true,
                'response' => $data,
                'error' => null
            ];
        }

        // 200 = Successfully registered (wallet wasn't registered before)
        if ($status_code === 200) {
            return [
                'is_registered' => true, // Now it is!
                'response' => $data,
                'error' => null
            ];
        }

        // Any other status = Error or not registered
        return [
            'is_registered' => false,
            'response' => $data,
            'error' => "HTTP $status_code: " . ($data['error'] ?? 'Unknown error')
        ];
    }

    /**
     * Get decrypted mnemonic for a mining wallet
     *
     * @param int $wallet_id Wallet ID
     * @return string|false Decrypted mnemonic or false
     */
    public static function get_wallet_mnemonic($wallet_id) {
        global $wpdb;

        error_log("=== GET WALLET MNEMONIC ===");
        error_log("Wallet ID: $wallet_id");

        $wallets_table = $wpdb->prefix . 'umbrella_mining_wallets';

        // First, get the entire wallet row to see what we have
        $wallet = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wallets_table} WHERE id = %d",
            $wallet_id
        ), ARRAY_A);

        error_log("Wallet data columns: " . print_r(array_keys($wallet ?: []), true));
        error_log("Wallet data (keys only): " . implode(', ', array_keys($wallet ?: [])));

        if (!$wallet) {
            error_log("ERROR: Wallet not found in database");
            return false;
        }

        // Check if mnemonic column exists
        if (!isset($wallet['mnemonic'])) {
            error_log("ERROR: 'mnemonic' column does not exist in wallet data");
            error_log("Available columns: " . print_r(array_keys($wallet), true));
            return false;
        }

        $mnemonic_encrypted = $wallet['mnemonic'];
        error_log("Mnemonic encrypted value length: " . strlen($mnemonic_encrypted ?: ''));
        error_log("Mnemonic encrypted value (first 50 chars): " . substr($mnemonic_encrypted ?: '', 0, 50));

        if (empty($mnemonic_encrypted)) {
            error_log("ERROR: Mnemonic is empty or null");
            return false;
        }

        // Mining wallets store mnemonic encrypted
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/UmbrellaMines_EncryptionHelper.php';

        error_log("Attempting to decrypt mnemonic...");
        $decrypted = UmbrellaMines_EncryptionHelper::decrypt($mnemonic_encrypted);

        if ($decrypted) {
            error_log("SUCCESS: Mnemonic decrypted successfully (length: " . strlen($decrypted) . ")");
            $word_count = count(explode(' ', trim($decrypted)));
            error_log("Word count: $word_count");
        } else {
            error_log("ERROR: Failed to decrypt mnemonic");
        }

        return $decrypted;
    }
}
