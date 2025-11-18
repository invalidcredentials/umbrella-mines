<?php
/**
 * Import Processor
 * Handles parsing and batch importing of wallet exports from other miners (Night Miner, etc.)
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';
require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-scavenger-api.php';
require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoCIP8Signer.php';

class Umbrella_Mines_Import_Processor {

    /**
     * Parse uploaded Night Miner export ZIP file
     *
     * @param string $zip_path Path to uploaded ZIP file
     * @param string $network Network (mainnet/preprod)
     * @return array|WP_Error Result with parsed wallet data or error
     */
    public static function parse_night_miner_export($zip_path, $network = 'mainnet') {
        error_log("=== PARSE NIGHT MINER EXPORT ===");
        error_log("ZIP Path: $zip_path");
        error_log("Network: $network");

        // Validate ZIP file exists
        if (!file_exists($zip_path)) {
            return new WP_Error('file_not_found', 'ZIP file not found');
        }

        // Create temp extraction directory
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/umbrella-mines/temp-imports/' . uniqid('import_', true);

        if (!wp_mkdir_p($temp_dir)) {
            return new WP_Error('mkdir_failed', 'Failed to create temp directory');
        }

        error_log("Temp directory: $temp_dir");

        // Extract ZIP
        $zip = new ZipArchive();
        $res = $zip->open($zip_path);

        if ($res !== true) {
            return new WP_Error('zip_open_failed', 'Failed to open ZIP file: ' . $res);
        }

        $zip->extractTo($temp_dir);
        $zip->close();

        error_log("ZIP extracted successfully");

        // Find wallet.json (may be in subfolder)
        $wallet_json_path = self::find_file_recursive($temp_dir, 'wallet.json');

        if (!$wallet_json_path) {
            self::cleanup_temp_dir($temp_dir);
            return new WP_Error('wallet_json_not_found', 'wallet.json not found in ZIP. Is this a valid Night Miner export?');
        }

        error_log("Found wallet.json at: $wallet_json_path");

        // Parse wallet.json
        $wallet_json_content = file_get_contents($wallet_json_path);
        $wallet_data = json_decode($wallet_json_content, true);

        if (!$wallet_data || !isset($wallet_data['addresses'])) {
            self::cleanup_temp_dir($temp_dir);
            return new WP_Error('invalid_wallet_json', 'Invalid wallet.json format');
        }

        $addresses = $wallet_data['addresses'];
        $challenge_submissions = $wallet_data['challenge_submissions'] ?? [];

        error_log("Found " . count($addresses) . " addresses in wallet.json");

        // Get the directory containing wallet.json (where skey files are located)
        $wallet_dir = dirname($wallet_json_path);

        // Parse each wallet
        $wallets = [];
        $invalid_count = 0;

        foreach ($addresses as $index => $addr_data) {
            $address = $addr_data['address'];
            $verification_key = $addr_data['verification_key'];

            // Load private key from addr-{N}.skey
            $skey_path = $wallet_dir . '/addr-' . $index . '.skey';

            if (!file_exists($skey_path)) {
                error_log("WARNING: Missing skey file for addr-$index");
                $invalid_count++;
                continue;
            }

            $skey_content = file_get_contents($skey_path);
            $skey_data = json_decode($skey_content, true);

            if (!$skey_data || !isset($skey_data['cborHex'])) {
                error_log("WARNING: Invalid skey format for addr-$index");
                $invalid_count++;
                continue;
            }

            // Extract private key from CBOR format
            $cbor_hex = $skey_data['cborHex'];

            // Strip CBOR prefix "5820" (major type 2, length 32)
            if (substr($cbor_hex, 0, 4) === '5820') {
                $private_key_hex = substr($cbor_hex, 4);
            } else {
                $private_key_hex = $cbor_hex;
            }

            // Validate private key format
            // Accept both regular keys (64 chars = 32 bytes) and extended keys (128 chars = 64 bytes)
            // Night Miner uses 64-char keys, our system uses 128-char extended keys
            // For CIP-8 signing, we only need the first 32 bytes (64 chars) anyway
            $key_length = strlen($private_key_hex);
            if (($key_length !== 64 && $key_length !== 128) || !ctype_xdigit($private_key_hex)) {
                error_log("WARNING: Invalid private key format for addr-$index (length: $key_length, expected 64 or 128)");
                $invalid_count++;
                continue;
            }

            // CIP-8 signer now accepts both 64-char and 128-char keys
            // No need to pad - pass the key as-is
            error_log("Parsed addr-$index: key_length=$key_length (will be passed directly to signer)");

            // Validate address network
            $expected_prefix = $network === 'mainnet' ? 'addr1' : 'addr_test1';
            if (substr($address, 0, strlen($expected_prefix)) !== $expected_prefix) {
                error_log("WARNING: Address network mismatch for addr-$index (expected $expected_prefix)");
                $invalid_count++;
                continue;
            }

            // Check if this wallet has solutions (from challenge_submissions)
            $has_solutions = false;
            $solution_count = 0;

            foreach ($challenge_submissions as $challenge_id => $indices) {
                if (in_array($index, $indices)) {
                    $has_solutions = true;
                    $solution_count++;
                }
            }

            $wallets[] = [
                'index' => $index,
                'address' => $address,
                'verification_key' => $verification_key,
                'private_key_hex' => $private_key_hex, // 64 or 128 chars - CIP-8 signer handles both
                'has_solutions' => $has_solutions,
                'solution_count' => $solution_count
            ];
        }

        // Cleanup temp directory
        self::cleanup_temp_dir($temp_dir);

        error_log("Parsed " . count($wallets) . " valid wallets");
        error_log("Invalid wallets skipped: $invalid_count");

        // Calculate totals
        $total_wallets = count($wallets);
        $wallets_with_solutions = count(array_filter($wallets, fn($w) => $w['has_solutions']));
        $total_solutions = array_sum(array_column($wallets, 'solution_count'));

        // Estimate NIGHT value (using challenge_submissions data for accurate calculation)
        $night_estimate = self::calculate_night_estimate_from_challenges($challenge_submissions);

        // Calculate NIGHT per wallet for storage during merge
        $wallets = self::add_night_values_to_wallets($wallets, $challenge_submissions);

        // Check how many wallets are already merged
        global $wpdb;
        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';
        $wallet_addresses = array_column($wallets, 'address');
        $already_merged_count = 0;
        $already_merged_missing_night = 0;

        error_log("=== CHECKING FOR ALREADY MERGED WALLETS ===");
        error_log("Total wallets to check: " . count($wallet_addresses));
        error_log("First few addresses: " . implode(', ', array_slice($wallet_addresses, 0, 3)));

        if (!empty($wallet_addresses)) {
            $placeholders = implode(',', array_fill(0, count($wallet_addresses), '%s'));
            $already_merged = $wpdb->get_results($wpdb->prepare(
                "SELECT original_address, night_value FROM {$merges_table} WHERE original_address IN ($placeholders) AND status = 'success'",
                ...$wallet_addresses
            ));

            $already_merged_count = count($already_merged);
            error_log("Found already merged: $already_merged_count");

            foreach ($already_merged as $merged) {
                error_log("  - {$merged->original_address}: night_value = " . ($merged->night_value ?? 'NULL'));
                if ($merged->night_value === null || $merged->night_value == 0) {
                    $already_merged_missing_night++;
                }
            }

            error_log("Already merged missing NIGHT: $already_merged_missing_night");
        }

        return [
            'success' => true,
            'wallets' => $wallets,
            'wallet_count' => $total_wallets,
            'wallets_with_solutions' => $wallets_with_solutions,
            'total_solutions' => $total_solutions,
            'invalid_wallets' => $invalid_count,
            'night_estimate' => $night_estimate,
            'night_breakdown' => $night_estimate['breakdown'] ?? [],
            'network' => $network,
            'already_merged_count' => $already_merged_count,
            'already_merged_missing_night' => $already_merged_missing_night
        ];
    }

    /**
     * Calculate estimated NIGHT value from challenge_submissions data
     *
     * Uses actual day-specific work_to_star_rate values from API for accurate calculation
     *
     * @param array $challenge_submissions Challenge submissions from wallet.json
     * @return array NIGHT estimate with breakdown by day
     */
    public static function calculate_night_estimate_from_challenges($challenge_submissions) {
        if (empty($challenge_submissions)) {
            return [
                'total' => '0 NIGHT',
                'total_numeric' => 0,
                'breakdown' => []
            ];
        }

        // Fetch work_to_star_rate values from API
        $api_url = get_option('umbrella_mines_api_url', 'https://scavenger.prod.gd.midnighttge.io');
        $rates_response = wp_remote_get($api_url . '/work_to_star_rate', array(
            'timeout' => 10,
            'sslverify' => false
        ));

        $day_rates = [];
        if (!is_wp_error($rates_response)) {
            $body = wp_remote_retrieve_body($rates_response);
            $rates_data = json_decode($body, true);

            // API returns array indexed by day (0-based or 1-based, need to test)
            if (is_array($rates_data)) {
                $day_rates = $rates_data;
                error_log("Fetched " . count($day_rates) . " day rates from API");
            }
        } else {
            error_log("Failed to fetch work_to_star_rate: " . $rates_response->get_error_message());
        }

        // Parse challenge_submissions to group solutions by day
        $solutions_by_day = [];

        foreach ($challenge_submissions as $challenge_id => $wallet_indices) {
            // Parse challenge ID format: **D06C19 → Day 6, Challenge 19
            // Extract day number from challenge ID
            if (preg_match('/\*\*D(\d+)C\d+/', $challenge_id, $matches)) {
                $day = (int) $matches[1];
                $solution_count = count($wallet_indices);

                if (!isset($solutions_by_day[$day])) {
                    $solutions_by_day[$day] = 0;
                }
                $solutions_by_day[$day] += $solution_count;

                error_log("Challenge $challenge_id → Day $day: $solution_count solutions");
            } else {
                error_log("WARNING: Could not parse challenge ID: $challenge_id");
            }
        }

        // Calculate NIGHT per day
        $total_night = 0;
        $breakdown = [];

        foreach ($solutions_by_day as $day => $solution_count) {
            // Try both 0-based and 1-based indexing for API rates
            $work_to_star_rate = $day_rates[$day] ?? $day_rates[$day - 1] ?? null;

            if ($work_to_star_rate === null) {
                error_log("WARNING: No work_to_star_rate found for day $day");
                $breakdown[$day] = [
                    'day' => $day,
                    'solutions' => $solution_count,
                    'rate' => 'Unknown',
                    'night' => 'Unknown'
                ];
                continue;
            }

            // Calculate: NIGHT = (solutions * work_to_star_rate) / 1,000,000
            $star_earned = $solution_count * (int) $work_to_star_rate;
            $night_earned = $star_earned / 1000000;
            $total_night += $night_earned;

            $breakdown[$day] = [
                'day' => $day,
                'solutions' => $solution_count,
                'rate' => number_format($work_to_star_rate),
                'night' => number_format($night_earned, 2)
            ];

            error_log(sprintf(
                "Day %d: %d solutions × %s STAR/solution = %s NIGHT",
                $day,
                $solution_count,
                number_format($work_to_star_rate),
                number_format($night_earned, 2)
            ));
        }

        return [
            'total' => number_format($total_night, 2) . ' NIGHT',
            'total_numeric' => $total_night,
            'breakdown' => $breakdown
        ];
    }

    /**
     * Add NIGHT value to each wallet based on their challenge submissions
     *
     * @param array $wallets Array of wallet data
     * @param array $challenge_submissions Challenge submissions data
     * @return array Wallets with night_value added
     */
    private static function add_night_values_to_wallets($wallets, $challenge_submissions) {
        // Fetch work_to_star_rate values from API
        $api_url = get_option('umbrella_mines_api_url', 'https://scavenger.prod.gd.midnighttge.io');
        $rates_response = wp_remote_get($api_url . '/work_to_star_rate', array(
            'timeout' => 10,
            'sslverify' => false
        ));

        $day_rates = [];
        if (!is_wp_error($rates_response)) {
            $body = wp_remote_retrieve_body($rates_response);
            $rates_data = json_decode($body, true);
            if (is_array($rates_data)) {
                $day_rates = $rates_data;
            }
        }

        // Calculate NIGHT for each wallet
        foreach ($wallets as &$wallet) {
            $wallet_index = $wallet['index'];
            $wallet_night = 0;

            // Find all challenges this wallet participated in
            foreach ($challenge_submissions as $challenge_id => $indices) {
                if (in_array($wallet_index, $indices)) {
                    // Parse day from challenge ID (e.g., **D06C19 → Day 6)
                    if (preg_match('/\*\*D(\d+)C\d+/', $challenge_id, $matches)) {
                        $day = (int) $matches[1];

                        // Get work_to_star_rate for this day
                        $work_to_star_rate = $day_rates[$day] ?? $day_rates[$day - 1] ?? null;

                        if ($work_to_star_rate !== null) {
                            // Each solution = 1 × work_to_star_rate STAR
                            $star_earned = (int) $work_to_star_rate;
                            $night_earned = $star_earned / 1000000;
                            $wallet_night += $night_earned;
                        }
                    }
                }
            }

            // Store the calculated NIGHT value
            $wallet['night_value'] = $wallet_night;
        }

        return $wallets;
    }

    /**
     * Recursively find a file in directory tree
     *
     * @param string $dir Directory to search
     * @param string $filename Filename to find
     * @return string|false Full path if found, false otherwise
     */
    private static function find_file_recursive($dir, $filename) {
        $files = scandir($dir);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;

            if ($file === $filename) {
                return $path;
            }

            if (is_dir($path)) {
                $result = self::find_file_recursive($path, $filename);
                if ($result) {
                    return $result;
                }
            }
        }

        return false;
    }

    /**
     * Recursively delete directory and contents
     *
     * @param string $dir Directory to delete
     */
    private static function cleanup_temp_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? self::cleanup_temp_dir($path) : unlink($path);
        }

        rmdir($dir);
        error_log("Cleaned up temp directory: $dir");
    }

    /**
     * Create or resume import session
     *
     * @param array $wallets Parsed wallet data
     * @param string $payout_address Destination address
     * @param string $network Network
     * @return string Session key
     */
    public static function create_import_session($wallets, $payout_address, $network = 'mainnet') {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'umbrella_mining_import_sessions';

        $session_key = uniqid('import_', true);
        $user_id = get_current_user_id();

        $wpdb->insert(
            $sessions_table,
            [
                'user_id' => $user_id,
                'session_key' => $session_key,
                'import_type' => 'night_miner',
                'payout_address' => $payout_address,
                'total_wallets' => count($wallets),
                'status' => 'pending',
                'wallets_data' => json_encode($wallets),
                'processed_addresses' => json_encode([]),
                'error_log' => json_encode([]),
                'started_at' => current_time('mysql'),
                'last_activity' => current_time('mysql')
            ]
        );

        error_log("Created import session: $session_key");

        return $session_key;
    }

    /**
     * Check for interrupted sessions for current user
     *
     * @return array|false Session data if found, false otherwise
     */
    public static function get_interrupted_session() {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'umbrella_mining_import_sessions';
        $user_id = get_current_user_id();

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sessions_table}
             WHERE user_id = %d
             AND status IN ('pending', 'processing', 'interrupted')
             ORDER BY started_at DESC
             LIMIT 1",
            $user_id
        ));

        return $session ? (array) $session : false;
    }

    /**
     * Batch merge imported wallets with resume capability
     *
     * @param string $session_key Session identifier
     * @param float $rate_limit_seconds Delay between API calls
     * @return array Result summary
     */
    public static function batch_merge_with_resume($session_key, $rate_limit_seconds = 0.5) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'umbrella_mining_import_sessions';
        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';

        error_log("=== BATCH MERGE WITH RESUME ===");
        error_log("Session Key: $session_key");

        // Get session
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sessions_table} WHERE session_key = %s",
            $session_key
        ));

        if (!$session) {
            return ['success' => false, 'error' => 'Session not found'];
        }

        $wallets = json_decode($session->wallet_ids_json, true) ?: [];
        $processed_addresses = json_decode($session->processed_addresses, true) ?: [];
        $error_log = json_decode($session->error_log, true) ?: [];
        $payout_address = $session->payout_address;

        // Filter to unprocessed wallets
        $wallets_to_process = array_filter($wallets, function($w) use ($processed_addresses) {
            return !in_array($w['address'], $processed_addresses);
        });

        $successful = $session->successful_count;
        $failed = $session->failed_count;

        error_log("Total wallets: " . count($wallets));
        error_log("Already processed: " . count($processed_addresses));
        error_log("Remaining: " . count($wallets_to_process));

        // Update status to processing
        $wpdb->update(
            $sessions_table,
            ['status' => 'processing'],
            ['session_key' => $session_key]
        );

        $start_time = microtime(true);
        $network = $session->payout_address[0] === 'a' && $session->payout_address[1] === 'd' && $session->payout_address[4] === '1' ? 'mainnet' : 'preprod';

        // Adaptive rate limiting
        $current_rate_limit = $rate_limit_seconds;
        $min_delay = 0.2;
        $max_delay = 2.0;

        foreach ($wallets_to_process as $i => $wallet) {
            error_log("Processing wallet " . ($i + 1) . "/" . count($wallets_to_process) . ": " . $wallet['address']);

            // Merge this wallet
            $api_start = microtime(true);
            $result = self::merge_wallet_from_import($wallet, $payout_address, $network);
            $api_duration = microtime(true) - $api_start;

            if ($result['success']) {
                $successful++;

                // Log NIGHT value updates separately
                if (isset($result['night_value_updated']) && $result['night_value_updated']) {
                    error_log("  → NIGHT value updated: " . ($result['night_value'] ?? 'N/A'));
                }
            } else {
                $failed++;
                $error_log[] = [
                    'address' => $wallet['address'],
                    'error' => $result['error'] ?? 'Unknown error',
                    'timestamp' => current_time('mysql')
                ];
            }

            $processed_addresses[] = $wallet['address'];

            // Adaptive rate limiting
            if ($api_duration < 0.2) {
                $current_rate_limit = max($min_delay, $current_rate_limit * 0.9);
            } elseif ($api_duration > 1.0) {
                $current_rate_limit = min($max_delay, $current_rate_limit * 1.2);
            }

            // On error, back off
            if (!$result['success'] && ($result['should_retry'] ?? false)) {
                $current_rate_limit = min($max_delay, $current_rate_limit * 2);
            }

            // Update session frequently for UI progress (every 2 wallets for small batches, every 5 for large)
            $update_frequency = count($wallets_to_process) < 20 ? 2 : 5;
            if ($i % $update_frequency === 0 || $i === count($wallets_to_process) - 1) {
                $wpdb->update(
                    $sessions_table,
                    [
                        'processed_wallets' => count($processed_addresses),
                        'successful_count' => $successful,
                        'failed_count' => $failed,
                        'processed_addresses' => json_encode($processed_addresses),
                        'error_log' => json_encode($error_log),
                        'last_activity' => current_time('mysql')
                    ],
                    ['session_key' => $session_key]
                );
            }

            // Check for connection abort
            if (connection_aborted()) {
                error_log("Connection aborted - marking session as interrupted");
                $wpdb->update(
                    $sessions_table,
                    ['status' => 'interrupted'],
                    ['session_key' => $session_key]
                );

                return [
                    'success' => false,
                    'interrupted' => true,
                    'processed' => count($processed_addresses),
                    'remaining' => count($wallets) - count($processed_addresses)
                ];
            }

            // Rate limiting
            usleep($current_rate_limit * 1000000);
        }

        $duration = round(microtime(true) - $start_time, 2);

        // Mark complete
        $wpdb->update(
            $sessions_table,
            [
                'status' => 'completed',
                'completed_at' => current_time('mysql')
            ],
            ['session_key' => $session_key]
        );

        error_log("Batch merge complete - Duration: {$duration}s, Successful: $successful, Failed: $failed");

        return [
            'success' => true,
            'total' => count($wallets),
            'successful' => $successful,
            'failed' => $failed,
            'duration_seconds' => $duration
        ];
    }

    /**
     * Merge a single imported wallet
     *
     * @param array $wallet Wallet data (address, private_key_hex, night_value)
     * @param string $payout_address Destination address
     * @param string $network Network
     * @return array Result
     */
    private static function merge_wallet_from_import($wallet, $payout_address, $network = 'mainnet') {
        global $wpdb;
        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';

        $address = $wallet['address'];
        $private_key_hex = $wallet['private_key_hex'];
        $night_value = isset($wallet['night_value']) ? $wallet['night_value'] : null;

        error_log("Merging imported wallet: $address");

        // Check if already merged
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$merges_table} WHERE original_address = %s AND status = 'success'",
            $address
        ));

        if ($existing) {
            // Wallet already merged - check if we need to update night_value
            if ($night_value !== null && ($existing->night_value === null || $existing->night_value == 0)) {
                error_log("Wallet already merged but missing NIGHT value - updating with: $night_value");

                $wpdb->update(
                    $merges_table,
                    ['night_value' => $night_value],
                    ['id' => $existing->id],
                    ['%f'],
                    ['%d']
                );

                return [
                    'success' => true,
                    'already_merged' => true,
                    'night_value_updated' => true,
                    'night_value' => $night_value
                ];
            }

            error_log("Wallet already merged - skipping");
            return ['success' => true, 'already_merged' => true];
        }

        // Construct message
        $message = "Assign accumulated Scavenger rights to: " . $payout_address;

        // Sign with CIP-8
        try {
            $signature_data = CardanoCIP8Signer::sign_message($message, $private_key_hex, $address, $network);

            if (!$signature_data || !isset($signature_data['signature'])) {
                return ['success' => false, 'error' => 'Failed to sign message'];
            }

            $signature = $signature_data['signature'];

        } catch (Exception $e) {
            error_log("Signing error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Signing error: ' . $e->getMessage()];
        }

        // Call API
        $api_url = get_option('umbrella_mines_api_url', 'https://scavenger.prod.gd.midnighttge.io');
        $api_response = Umbrella_Mines_ScavengerAPI::donate_to(
            $api_url,
            $payout_address,
            $address,
            $signature
        );

        if (!$api_response) {
            return ['success' => false, 'error' => 'API call failed', 'should_retry' => true];
        }

        $status_code = $api_response['status_code'] ?? 0;
        $is_success = isset($api_response['status']) && $api_response['status'] === 'success';

        // Handle 409 (already assigned) as success
        if ($status_code === 409) {
            $wpdb->insert(
                $merges_table,
                [
                    'original_address' => $address,
                    'payout_address' => $payout_address,
                    'original_wallet_id' => 0, // External wallet
                    'merge_signature' => $signature,
                    'merge_receipt' => json_encode($api_response),
                    'solutions_consolidated' => 0,
                    'night_value' => $night_value,
                    'status' => 'success',
                    'error_message' => 'Already assigned (409)',
                    'merged_at' => current_time('mysql')
                ]
            );

            return ['success' => true, 'already_assigned' => true];
        }

        // Handle success
        if ($is_success) {
            $solutions_consolidated = $api_response['solutions_consolidated'] ?? 0;

            $wpdb->insert(
                $merges_table,
                [
                    'original_address' => $address,
                    'payout_address' => $payout_address,
                    'original_wallet_id' => 0, // External wallet
                    'merge_signature' => $signature,
                    'merge_receipt' => json_encode($api_response),
                    'solutions_consolidated' => $solutions_consolidated,
                    'night_value' => $night_value,
                    'status' => 'success',
                    'merged_at' => current_time('mysql')
                ]
            );

            return ['success' => true, 'solutions_consolidated' => $solutions_consolidated];
        }

        // Handle errors
        $error_msg = $api_response['error'] ?? $api_response['message'] ?? 'Unknown error';

        // 400 = bad signature (don't retry)
        if ($status_code === 400) {
            $wpdb->insert(
                $merges_table,
                [
                    'original_address' => $address,
                    'payout_address' => $payout_address,
                    'original_wallet_id' => 0,
                    'merge_signature' => $signature,
                    'merge_receipt' => json_encode($api_response),
                    'status' => 'failed',
                    'error_message' => $error_msg,
                    'merged_at' => current_time('mysql')
                ]
            );

            return ['success' => false, 'error' => $error_msg, 'should_retry' => false];
        }

        // Other errors (network, timeout, etc.) - retryable
        return ['success' => false, 'error' => $error_msg, 'should_retry' => true];
    }

    /**
     * Generate downloadable receipt for import session
     *
     * @param string $session_key Session identifier
     * @return array Receipt data
     */
    public static function generate_import_receipt($session_key) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'umbrella_mining_import_sessions';
        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sessions_table} WHERE session_key = %s",
            $session_key
        ));

        if (!$session) {
            return ['success' => false, 'error' => 'Session not found'];
        }

        // Get all merges for this payout address from the session timeframe
        $merges = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$merges_table}
             WHERE payout_address = %s
             AND merged_at >= %s
             ORDER BY merged_at ASC",
            $session->payout_address,
            $session->started_at
        ));

        $receipt = [
            'import_type' => 'night_miner',
            'session_key' => $session_key,
            'imported_at' => $session->started_at,
            'completed_at' => $session->completed_at,
            'payout_address' => $session->payout_address,
            'total_wallets' => $session->total_wallets,
            'processed' => $session->processed_wallets,
            'successful' => $session->successful_count,
            'failed' => $session->failed_count,
            'wallets' => array_map(function($merge) {
                return [
                    'original_address' => $merge->original_address,
                    'signature' => $merge->merge_signature,
                    'solutions_consolidated' => $merge->solutions_consolidated,
                    'status' => $merge->status,
                    'error' => $merge->error_message,
                    'receipt' => json_decode($merge->merge_receipt, true)
                ];
            }, $merges)
        ];

        return $receipt;
    }
}
