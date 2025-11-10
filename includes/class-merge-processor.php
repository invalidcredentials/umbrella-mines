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
            ORDER BY w.created_at DESC
        ", $payout_wallet_id));

        return $wallets;
    }

    /**
     * Merge single wallet to payout address
     *
     * @param int $wallet_id Wallet ID
     * @param string $payout_address Destination payout address
     * @return array Result with success status and message
     */
    public static function merge_wallet($wallet_id, $payout_address) {
        global $wpdb;

        error_log("=== MERGE WALLET START ===");
        error_log("Wallet ID: $wallet_id");
        error_log("Payout Address: $payout_address");

        $api_url = get_option('umbrella_mines_api_url', 'https://scavenger.prod.gd.midnighttge.io');
        error_log("API URL: $api_url");

        // Get wallet data
        $wallets_table = $wpdb->prefix . 'umbrella_mining_wallets';
        $wallet = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wallets_table} WHERE id = %d",
            $wallet_id
        ));

        if (!$wallet) {
            error_log("ERROR: Wallet not found");
            return ['success' => false, 'error' => 'Wallet not found'];
        }

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

        if (!$api_response || (isset($api_response['success']) && !$api_response['success'])) {
            $error_msg = isset($api_response['error']) ? $api_response['error'] : 'API request failed';
            error_log("ERROR: Merge failed - $error_msg");

            // Save failed merge attempt
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
                    'error_message' => $error_msg,
                    'merged_at' => current_time('mysql')
                ]
            );

            return ['success' => false, 'error' => $error_msg];
        }

        // Save successful merge
        $solutions_consolidated = isset($api_response['solutions_consolidated']) ? (int) $api_response['solutions_consolidated'] : 0;
        error_log("SUCCESS: Merge completed!");
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
                'merged_at' => current_time('mysql')
            ]
        );

        return [
            'success' => true,
            'solutions_consolidated' => $solutions_consolidated,
            'receipt' => $api_response
        ];
    }

    /**
     * Merge all eligible wallets to payout address (batch operation)
     *
     * @param string $network Network
     * @return array Results summary
     */
    public static function merge_all($network = 'mainnet') {
        // Get payout wallet (first registered wallet)
        $payout_wallet = self::get_registered_payout_wallet($network);

        if (!$payout_wallet) {
            return [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'errors' => [['error' => 'No payout wallet available']]
            ];
        }

        $payout_address = $payout_wallet->address;
        $eligible_wallets = self::get_eligible_wallets($network);

        $results = [
            'total' => count($eligible_wallets),
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($eligible_wallets as $wallet) {
            $result = self::merge_wallet($wallet->id, $payout_address);

            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'wallet_id' => $wallet->id,
                    'address' => $wallet->address,
                    'error' => $result['error']
                ];
            }

            // Small delay to avoid rate limiting
            usleep(500000); // 0.5 seconds
        }

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

        $wallets_table = $wpdb->prefix . 'umbrella_mining_wallets';
        $solutions_table = $wpdb->prefix . 'umbrella_mining_solutions';

        // Get first wallet with submitted solutions
        // submitted = confirmed/accepted by API, which means wallet is registered
        $wallet = $wpdb->get_row($wpdb->prepare("
            SELECT w.*,
                   COUNT(s.id) as submitted_count
            FROM {$wallets_table} w
            INNER JOIN {$solutions_table} s ON w.id = s.wallet_id
            WHERE s.submission_status = 'submitted'
            AND w.network = %s
            AND w.registered_at IS NOT NULL
            GROUP BY w.id
            ORDER BY w.created_at ASC
            LIMIT 1
        ", $network));

        return $wallet;
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
