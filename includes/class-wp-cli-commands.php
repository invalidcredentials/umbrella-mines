<?php
/**
 * WP-CLI Commands for Umbrella Mines
 *
 * Usage:
 *   wp umbrella-mines start          - Start mining (continuous)
 *   wp umbrella-mines mine-once      - Mine one solution and exit
 *   wp umbrella-mines test-wallet    - Generate and test a wallet
 *   wp umbrella-mines test-challenge - Fetch current challenge
 *   wp umbrella-mines stats          - Show mining statistics
 */

if (!defined('ABSPATH')) {
    exit;
}

class Umbrella_Mines_CLI_Commands {

    /**
     * Start continuous mining
     *
     * ## OPTIONS
     *
     * [--max-attempts=<count>]
     * : Maximum nonce attempts per wallet (default: 100000)
     *
     * [--derive=<path>]
     * : Custom derivation path as account/chain/address (default: 0/0/0)
     *
     * ## EXAMPLES
     *
     *     wp umbrella-mines start
     *     wp umbrella-mines start --max-attempts=500000
     *     wp umbrella-mines start --max-attempts=500000 --derive=0/0/0
     *     wp umbrella-mines start --max-attempts=500000 --derive=5/1/100
     *
     * @when after_wp_load
     */
    public function start($args, $assoc_args) {
        WP_CLI::line('');
        WP_CLI::line('╔════════════════════════════════════════════════╗');
        WP_CLI::line('║       UMBRELLA MINES - STARTING ENGINE        ║');
        WP_CLI::line('╚════════════════════════════════════════════════╝');
        WP_CLI::line('');

        // Load dependencies
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-mining-engine.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-scavenger-api.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-ashmaize-ffi.php';  // Use FFI version!
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoCIP8Signer.php';

        // Get options
        $wallets_per_attempt = isset($assoc_args['wallets']) ? intval($assoc_args['wallets']) : 5;
        $max_attempts = isset($assoc_args['max-attempts']) ? intval($assoc_args['max-attempts']) : 100000;

        // Parse derivation path (optional)
        $use_custom_path = isset($assoc_args['derive']);
        $base_account = 0;
        $base_chain = 0;
        $base_address = 0;

        if ($use_custom_path) {
            $path_str = $assoc_args['derive'];
            $path_parts = explode('/', $path_str);

            if (count($path_parts) !== 3) {
                WP_CLI::error("Invalid path format. Use: account/chain/address (e.g., 0/0/0)");
            }

            $base_account = intval($path_parts[0]);
            $base_chain = intval($path_parts[1]);
            $base_address = intval($path_parts[2]);
        }

        // Get configuration
        global $wpdb;
        $config_table = $wpdb->prefix . 'umbrella_mining_config';
        $api_url = $wpdb->get_var($wpdb->prepare("SELECT config_value FROM {$config_table} WHERE config_key = %s", 'api_url'));
        $network = $wpdb->get_var($wpdb->prepare("SELECT config_value FROM {$config_table} WHERE config_key = %s", 'network'));

        WP_CLI::line("Configuration:");
        WP_CLI::line("  API URL: {$api_url}");
        WP_CLI::line("  Network: {$network}");
        if ($use_custom_path) {
            WP_CLI::line("  Derivation path: m/1852'/1815'/$base_account'/$base_chain/$base_address");
        } else {
            WP_CLI::line("  Derivation path: Standard (m/1852'/1815'/0'/0/0)");
        }
        WP_CLI::line("  Max nonce attempts per wallet: " . number_format($max_attempts));
        WP_CLI::line('');

        // FIXED STRATEGY: Generate ONE wallet and stick with it until solution found
        // This matches Rust miner "Persistent Key Mining" mode
        $total_solutions = 0;
        $wallet_count = 0;

        while (true) {
            // Check for stop flag
            $stop_file = WP_CONTENT_DIR . '/umbrella-mines-stop.flag';
            if (file_exists($stop_file)) {
                @unlink($stop_file);
                WP_CLI::success('Stop signal received. Mining stopped gracefully.');
                exit(0); // Hard exit - stop immediately
            }

            $wallet_count++;

            WP_CLI::line('');
            WP_CLI::line("═══════════════════════════════════════════════");
            WP_CLI::line("  WALLET #{$wallet_count}");
            WP_CLI::line("═══════════════════════════════════════════════");

            try {
                // Fetch current challenge
                WP_CLI::line('→ Fetching challenge...');
                $challenge = Umbrella_Mines_ScavengerAPI::get_challenge($api_url);

                if (!$challenge) {
                    WP_CLI::warning('Failed to fetch challenge. Retrying in 15 seconds...');
                    sleep(15);
                    continue;
                }

                $this->display_challenge($challenge);

                // Generate ONE wallet for this mining session
                WP_CLI::line("→ Generating wallet...");
                if ($use_custom_path) {
                    $wallet = $this->generate_wallet_with_path($network, $base_account, $base_chain, $base_address + $wallet_count - 1);
                } else {
                    $wallet = $this->generate_wallet($network);
                }
                WP_CLI::line("  Path:    {$wallet['derivation_path']}");
                WP_CLI::line("  Address: " . substr($wallet['address'], 0, 30) . "...");

                // Register wallet
                WP_CLI::line("  Registering wallet with API...");
                WP_CLI::line("  API: $api_url");
                WP_CLI::line("  Address: " . $wallet['address']);

                $reg_start = microtime(true);
                $registered = $this->register_wallet($wallet, $api_url);
                $reg_time = microtime(true) - $reg_start;

                if (!$registered || !isset($registered['success'])) {
                    WP_CLI::warning("  ✗ Registration failed after " . number_format($reg_time, 2) . "s");
                    WP_CLI::warning("  Check logs for details. Retrying in 15 seconds...");
                    sleep(15);
                    continue;
                }

                WP_CLI::success("  ✓ Wallet registered in " . number_format($reg_time, 2) . "s!");
                WP_CLI::line("    Signature: " . substr($registered['signature'], 0, 50) . "...");
                WP_CLI::line("    Pubkey: " . $registered['pubkey']);

                // Save wallet to database
                $wallet_id = $this->save_wallet($wallet, $registered);

                // Mine CONTINUOUSLY with this ONE wallet until solution found
                WP_CLI::line("  Mining continuously with this wallet...");
                $attempt_round = 0;
                $solution = null;

                $stopped = false;
                while (!$solution) {
                    // Check for stop flag before each mining round
                    $stop_file = WP_CONTENT_DIR . '/umbrella-mines-stop.flag';
                    if (file_exists($stop_file)) {
                        @unlink($stop_file);
                        WP_CLI::success('Stop signal received. Mining stopped gracefully.');
                        exit(0); // Hard exit - stop immediately
                    }

                    $attempt_round++;
                    WP_CLI::line("  Attempt round #{$attempt_round} ({$max_attempts} nonces)...");

                    $solution = $this->mine_solution($wallet, $challenge, $max_attempts);

                    if (!$solution) {
                        WP_CLI::line("  No solution yet. Continuing with same wallet...");
                    }
                }

                // If stopped, break out of wallet loop too
                if ($stopped) {
                    break;
                }

                WP_CLI::success("  ✨ SOLUTION FOUND! Nonce: {$solution['nonce']}");

                // Save solution
                $this->save_solution($wallet_id, $challenge, $solution);
                $total_solutions++;

                WP_CLI::line('');
                WP_CLI::line("🎉 Total solutions found: {$total_solutions}");
                WP_CLI::line("→ Moving to NEXT wallet (new address)...");

            } catch (Exception $e) {
                WP_CLI::error("Error: " . $e->getMessage());
                WP_CLI::line('Waiting 60 seconds before retry...');
                sleep(15);
            }
        }
    }

    /**
     * Mine one solution and exit
     *
     * @when after_wp_load
     */
    public function mine_once($args, $assoc_args) {
        WP_CLI::line('Mining single solution...');

        // TODO: Implement single mining attempt
        WP_CLI::error('Not yet implemented');
    }

    /**
     * Test wallet generation
     *
     * @when after_wp_load
     */
    public function test_wallet($args, $assoc_args) {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';

        WP_CLI::line('Generating test wallet...');

        // Get raw wallet first to debug
        $raw_wallet = CardanoWalletPHP::generateWallet('mainnet');

        WP_CLI::line('');
        WP_CLI::line('RAW WALLET KEYS:');
        WP_CLI::line('  Keys available: ' . implode(', ', array_keys($raw_wallet)));
        if (isset($raw_wallet['addresses'])) {
            WP_CLI::line('  Addresses: ' . print_r($raw_wallet['addresses'], true));
        }
        if (isset($raw_wallet['payment'])) {
            WP_CLI::line('  Payment keys: ' . implode(', ', array_keys($raw_wallet['payment'])));
        }
        WP_CLI::line('');

        $wallet = $this->generate_wallet('mainnet');

        WP_CLI::line('');
        WP_CLI::success('Wallet generated!');
        WP_CLI::line('');
        WP_CLI::line('Address:         ' . ($wallet['address'] ?? 'N/A'));
        WP_CLI::line('Payment PubKey:  ' . ($wallet['payment_pkey'] ?? 'N/A'));
        WP_CLI::line('Payment KeyHash: ' . ($wallet['payment_keyhash'] ?? 'N/A'));
        WP_CLI::line('Payment SKey:    ' . substr($wallet['payment_skey_extended'] ?? '', 0, 32) . '...');
        WP_CLI::line('Network:         ' . ($wallet['network'] ?? 'N/A'));
        if (isset($wallet['mnemonic'])) {
            WP_CLI::line('Mnemonic:        ' . substr($wallet['mnemonic'], 0, 50) . '...');
        }
        WP_CLI::line('');
    }

    /**
     * Fetch and display current challenge
     *
     * @when after_wp_load
     */
    public function test_challenge($args, $assoc_args) {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-scavenger-api.php';

        global $wpdb;
        $config_table = $wpdb->prefix . 'umbrella_mining_config';
        $api_url = $wpdb->get_var($wpdb->prepare("SELECT config_value FROM {$config_table} WHERE config_key = %s", 'api_url'));

        WP_CLI::line("Fetching challenge from: {$api_url}");

        $challenge = Umbrella_Mines_ScavengerAPI::get_challenge($api_url);

        if ($challenge) {
            $this->display_challenge($challenge);
        } else {
            WP_CLI::error('Failed to fetch challenge');
        }
    }

    /**
     * Show mining statistics
     *
     * @when after_wp_load
     */
    public function stats($args, $assoc_args) {
        global $wpdb;

        WP_CLI::line('');
        WP_CLI::line('╔════════════════════════════════════════════════╗');
        WP_CLI::line('║          NIGHT MINING STATISTICS              ║');
        WP_CLI::line('╚════════════════════════════════════════════════╝');
        WP_CLI::line('');

        $stats = array(
            'total_wallets' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_wallets"),
            'registered_wallets' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_wallets WHERE registered_at IS NOT NULL"),
            'total_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions"),
            'pending_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE submission_status IN ('pending', 'queued')"),
            'confirmed_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE submission_status = 'confirmed'"),
            'failed_solutions' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_solutions WHERE submission_status = 'failed'"),
            'total_receipts' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_receipts"),
        );

        WP_CLI::line('Wallets:');
        WP_CLI::line('  Total:      ' . number_format($stats['total_wallets']));
        WP_CLI::line('  Registered: ' . number_format($stats['registered_wallets']));
        WP_CLI::line('');

        WP_CLI::line('Solutions:');
        WP_CLI::line('  Total:     ' . number_format($stats['total_solutions']));
        WP_CLI::line('  Pending:   ' . number_format($stats['pending_solutions']));
        WP_CLI::line('  Confirmed: ' . number_format($stats['confirmed_solutions']));
        WP_CLI::line('  Failed:    ' . number_format($stats['failed_solutions']));
        WP_CLI::line('');

        WP_CLI::line('Receipts:    ' . number_format($stats['total_receipts']));
        WP_CLI::line('');

        // Show recent solutions
        $recent = $wpdb->get_results("
            SELECT s.*, w.address
            FROM {$wpdb->prefix}umbrella_mining_solutions s
            JOIN {$wpdb->prefix}umbrella_mining_wallets w ON s.wallet_id = w.id
            ORDER BY s.found_at DESC
            LIMIT 5
        ");

        if ($recent) {
            WP_CLI::line('Recent Solutions:');
            foreach ($recent as $solution) {
                WP_CLI::line(sprintf(
                    '  %s | %s | %s | %s',
                    $solution->found_at,
                    substr($solution->address, 0, 20) . '...',
                    $solution->nonce,
                    strtoupper($solution->submission_status)
                ));
            }
            WP_CLI::line('');
        }
    }

    /**
     * Display challenge information
     */
    private function display_challenge($data) {
        // Handle nested challenge structure from API
        $challenge = isset($data['challenge']) ? $data['challenge'] : $data;

        WP_CLI::line('');
        WP_CLI::line('Challenge Information:');
        WP_CLI::line('  ID:         ' . ($challenge['challenge_id'] ?? 'N/A'));
        WP_CLI::line('  Day:        ' . ($challenge['day'] ?? 'N/A'));
        WP_CLI::line('  Number:     ' . ($challenge['challenge_number'] ?? 'N/A'));
        WP_CLI::line('  Difficulty: ' . ($challenge['difficulty'] ?? 'N/A'));
        WP_CLI::line('  No PreMine: ' . substr($challenge['no_pre_mine'] ?? '', 0, 16) . '...');
        WP_CLI::line('  Ends:       ' . ($data['mining_period_ends'] ?? $challenge['mining_period_ends'] ?? 'N/A'));
        WP_CLI::line('');
    }

    /**
     * Generate ephemeral wallet (standard path)
     */
    private function generate_wallet($network) {
        $raw_wallet = CardanoWalletPHP::generateWallet($network);

        return array(
            'address' => $raw_wallet['addresses']['payment_address'] ?? null,
            'stake_address' => $raw_wallet['addresses']['stake_address'] ?? null,
            'payment_skey_extended' => $raw_wallet['payment_skey_extended'] ?? null,
            'payment_pkey' => $raw_wallet['payment_pkey_hex'] ?? null,
            'payment_keyhash' => $raw_wallet['payment_keyhash'] ?? null,
            'network' => $network,
            'mnemonic' => $raw_wallet['mnemonic'] ?? null,
            'derivation_path' => "m/1852'/1815'/0'/0/0"  // Standard path
        );
    }

    /**
     * Generate ephemeral wallet with custom derivation path
     */
    private function generate_wallet_with_path($network, $account, $chain, $address) {
        $raw_wallet = CardanoWalletPHP::generateWalletWithPath($account, $chain, $address, $network);

        return array(
            'address' => $raw_wallet['addresses']['payment_address'] ?? null,
            'stake_address' => $raw_wallet['addresses']['stake_address'] ?? null,
            'payment_skey_extended' => $raw_wallet['payment_skey_extended'] ?? null,
            'payment_pkey' => $raw_wallet['payment_pkey_hex'] ?? null,
            'payment_keyhash' => $raw_wallet['payment_keyhash'] ?? null,
            'network' => $network,
            'mnemonic' => $raw_wallet['mnemonic'] ?? null,
            'derivation_path' => $raw_wallet['derivation_path'] ?? "m/1852'/1815'/$account'/$chain/$address"
        );
    }

    /**
     * Register wallet with T&C signing
     */
    private function register_wallet($wallet, $api_url) {
        // Get T&C
        $tandc = Umbrella_Mines_ScavengerAPI::get_tandc($api_url);

        if (!$tandc) {
            return false;
        }

        // Sign T&C message (the 'message' field, not 'content')
        $signature = CardanoCIP8Signer::sign_message(
            $tandc['message'],
            $wallet['payment_skey_extended'],
            $wallet['address'],
            $wallet['network']
        );

        // Register with API
        // CRITICAL: Must use 'pubkey' (Ed25519 public key - 64 hex chars = 32 bytes)
        $result = Umbrella_Mines_ScavengerAPI::register_address(
            $api_url,
            $wallet['address'],
            $signature['signature'],
            $signature['pubkey']
        );

        // Return both the API result AND the signature data for saving
        if ($result) {
            return [
                'success' => true,
                'signature' => $signature['signature'],
                'pubkey' => $signature['pubkey']
            ];
        }

        return false;
    }

    /**
     * Save wallet to database AND JSON backup
     */
    private function save_wallet($wallet, $registration) {
        global $wpdb;

        // Encrypt mnemonic before saving
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/UmbrellaMines_EncryptionHelper.php';
        $mnemonic_encrypted = '';
        if (isset($wallet['mnemonic']) && !empty($wallet['mnemonic'])) {
            $mnemonic_encrypted = UmbrellaMines_EncryptionHelper::encrypt($wallet['mnemonic']);
        }

        $wpdb->insert(
            $wpdb->prefix . 'umbrella_mining_wallets',
            array(
                'address' => $wallet['address'],
                'derivation_path' => $wallet['derivation_path'] ?? null,
                'payment_skey_extended' => $wallet['payment_skey_extended'],
                'payment_pkey' => $wallet['payment_pkey'],
                'payment_keyhash' => $wallet['payment_keyhash'],
                'network' => $wallet['network'],
                'mnemonic_encrypted' => $mnemonic_encrypted,
                'registration_signature' => $registration['signature'] ?? null,
                'registration_pubkey' => $registration['pubkey'] ?? null,
                'registered_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        $wallet_id = $wpdb->insert_id;

        // BACKUP: Export wallet to JSON file
        $backup_dir = UMBRELLA_MINES_DATA_DIR . '/wallet_backups';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        $backup_data = array(
            'wallet_id' => $wallet_id,
            'address' => $wallet['address'],
            'payment_skey_extended' => $wallet['payment_skey_extended'],
            'payment_pkey' => $wallet['payment_pkey'],
            'payment_keyhash' => $wallet['payment_keyhash'],
            'network' => $wallet['network'],
            'registration_signature' => $registration['signature'] ?? null,
            'registration_pubkey' => $registration['pubkey'] ?? null,
            'registered_at' => current_time('mysql'),
            'exported_at' => current_time('mysql')
        );

        $backup_file = $backup_dir . '/wallet_' . $wallet_id . '_' . substr($wallet['address'], 0, 16) . '.json';
        file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT));

        return $wallet_id;
    }

    /**
     * Mine a solution
     */
    private function mine_solution($wallet, $challenge, $max_attempts) {
        // Initialize AshMaize FFI with ROM
        $start_time = microtime(true);

        // Detect OS and load correct library
        $lib_ext = 'dll'; // Default Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $lib_ext = (PHP_OS === 'Darwin') ? 'dylib' : 'so';
        }
        $dll_path = UMBRELLA_MINES_PLUGIN_DIR . 'bin/ashmaize_capi.' . $lib_ext;

        // Verify library exists
        if (!file_exists($dll_path)) {
            WP_CLI::error("AshMaize library not found for your OS: {$dll_path}");
            WP_CLI::line("Expected file: ashmaize_capi.{$lib_ext}");
            WP_CLI::line("Please compile the library for your platform.");
            return null;
        }

        $ashmaize = new AshMaizeFFI($dll_path, $challenge['no_pre_mine']);
        $init_time = microtime(true) - $start_time;

        WP_CLI::line("      🚀 ROM loaded via FFI in " . number_format($init_time, 2) . "s");

        // Try random nonces (simple and works)
        for ($i = 0; $i < $max_attempts; $i++) {
            // Check for stop flag every 1000 nonces
            if ($i > 0 && $i % 1000 === 0) {
                $stop_file = WP_CONTENT_DIR . '/umbrella-mines-stop.flag';
                if (file_exists($stop_file)) {
                    @unlink($stop_file);
                    WP_CLI::success('Stop signal received. Mining stopped gracefully.');
                    exit(0); // Hard exit - stop immediately
                }

                // Progress indicator
                $elapsed = microtime(true) - $start_time;
                $rate = $i / $elapsed;
                $percent = ($i / $max_attempts) * 100;
                $eta = ($max_attempts - $i) / $rate;

                WP_CLI::line(sprintf(
                    "      [%6.2f%%] Attempt %s/%s | %.1f hash/sec | ETA: %s",
                    $percent,
                    number_format($i),
                    number_format($max_attempts),
                    $rate,
                    gmdate('H:i:s', $eta)
                ));
            }

            // Random nonce
            $nonce = bin2hex(random_bytes(8));

            // Build preimage
            $preimage = AshMaizeFFI::build_preimage(
                $nonce,
                $wallet['address'],
                $challenge['challenge_id'],
                $challenge['difficulty'],
                $challenge['no_pre_mine'],
                $challenge['latest_submission'],
                $challenge['no_pre_mine_hour']
            );

            // Hash it (FFI returns hex string directly)
            $hash_hex = $ashmaize->hash($preimage);

            // Check difficulty (exact match - no extra bits)
            if (AshMaizeFFI::check_difficulty($hash_hex, $challenge['difficulty'], 0)) {
                return array(
                    'nonce' => $nonce,
                    'preimage' => $preimage,
                    'hash' => $hash_hex
                );
            }
        }

        return null;
    }

    /**
     * Save solution to database AND JSON backup
     */
    private function save_solution($wallet_id, $challenge, $solution) {
        global $wpdb;

        // Get encrypted mnemonic from wallet
        $wallet_data = $wpdb->get_row($wpdb->prepare(
            "SELECT mnemonic_encrypted FROM {$wpdb->prefix}umbrella_mining_wallets WHERE id = %d",
            $wallet_id
        ));
        $mnemonic_encrypted = $wallet_data->mnemonic_encrypted ?? '';

        $wpdb->insert(
            $wpdb->prefix . 'umbrella_mining_solutions',
            array(
                'wallet_id' => $wallet_id,
                'challenge_id' => $challenge['challenge_id'],
                'nonce' => $solution['nonce'],
                'preimage' => $solution['preimage'],
                'hash_result' => $solution['hash'],
                'difficulty' => $challenge['difficulty'],
                'no_pre_mine' => $challenge['no_pre_mine'],
                'no_pre_mine_hour' => $challenge['no_pre_mine_hour'],
                'latest_submission' => $challenge['latest_submission'],
                'submission_status' => 'pending',
                'mnemonic_encrypted' => $mnemonic_encrypted
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        $solution_id = $wpdb->insert_id;

        // BACKUP: Export solution to JSON file
        $backup_dir = UMBRELLA_MINES_DATA_DIR . '/solution_backups';
        if (!file_exists($backup_dir)) {
            wp_mkdir_p($backup_dir);
        }

        // Get wallet address
        $wallet = $wpdb->get_row($wpdb->prepare(
            "SELECT address FROM {$wpdb->prefix}umbrella_mining_wallets WHERE id = %d",
            $wallet_id
        ));

        $backup_data = array(
            'solution_id' => $solution_id,
            'wallet_id' => $wallet_id,
            'wallet_address' => $wallet->address ?? 'unknown',
            'challenge_id' => $challenge['challenge_id'],
            'nonce' => $solution['nonce'],
            'preimage' => $solution['preimage'],
            'hash_result' => $solution['hash'],
            'difficulty' => $challenge['difficulty'],
            'no_pre_mine' => $challenge['no_pre_mine'],
            'no_pre_mine_hour' => $challenge['no_pre_mine_hour'],
            'latest_submission' => $challenge['latest_submission'],
            'found_at' => current_time('mysql')
        );

        $backup_file = $backup_dir . '/solution_' . $solution_id . '_' . $challenge['challenge_id'] . '_' . $solution['nonce'] . '.json';
        file_put_contents($backup_file, json_encode($backup_data, JSON_PRETTY_PRINT));

        WP_CLI::line('    💾 Solution backed up to: ' . basename($backup_file));

        // Check if auto-submit is enabled
        $config_table = $wpdb->prefix . 'umbrella_mining_config';
        $auto_submit = $wpdb->get_var($wpdb->prepare(
            "SELECT config_value FROM {$config_table} WHERE config_key = %s",
            'submission_enabled'
        ));

        if ($auto_submit === '1') {
            WP_CLI::line('    🚀 Auto-submit enabled, submitting solution...');
            $this->submit_solution($solution_id, $wallet->address, $challenge['challenge_id'], $solution['nonce']);
        }
    }

    /**
     * Submit solution to API
     */
    private function submit_solution($solution_id, $address, $challenge_id, $nonce) {
        global $wpdb;

        // Get API URL from config
        $config_table = $wpdb->prefix . 'umbrella_mining_config';
        $api_url = $wpdb->get_var($wpdb->prepare(
            "SELECT config_value FROM {$config_table} WHERE config_key = %s",
            'api_url'
        ));

        if (!$api_url) {
            WP_CLI::error('API URL not configured');
            return false;
        }

        $url = "{$api_url}/solution/{$address}/{$challenge_id}/{$nonce}";
        WP_CLI::line("    → Submitting to: {$url}");

        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8'
            ),
            'body' => '{}',
            'timeout' => 180,  // 3 minutes - API is slow!
            'sslverify' => false
        ));

        if (is_wp_error($response)) {
            $error = $response->get_error_message();
            WP_CLI::warning("    ✗ Submission failed: {$error}");

            $wpdb->update(
                $wpdb->prefix . 'umbrella_mining_solutions',
                array(
                    'submission_status' => 'failed',
                    'submission_error' => $error
                ),
                array('id' => $solution_id),
                array('%s', '%s'),
                array('%d')
            );

            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code == 200 || $status_code == 201) {
            // Success - parse the receipt
            $response_data = json_decode($body, true);

            // Update solution status
            $wpdb->update(
                $wpdb->prefix . 'umbrella_mining_solutions',
                array(
                    'submission_status' => 'submitted',
                    'submitted_at' => current_time('mysql')
                ),
                array('id' => $solution_id),
                array('%s', '%s'),
                array('%d')
            );

            // Save the crypto receipt
            if (isset($response_data['crypto_receipt'])) {
                $receipt = $response_data['crypto_receipt'];
                $wpdb->insert(
                    $wpdb->prefix . 'umbrella_mining_receipts',
                    array(
                        'solution_id' => $solution_id,
                        'crypto_receipt' => wp_json_encode($receipt),
                        'preimage' => $receipt['preimage'] ?? '',
                        'signature' => $receipt['signature'] ?? '',
                        'timestamp' => $receipt['timestamp'] ?? current_time('mysql')
                    ),
                    array('%d', '%s', '%s', '%s', '%s')
                );
            }

            WP_CLI::success("    ✓ Solution submitted successfully! (HTTP {$status_code})");
            return true;
        } else {
            // Failed
            $wpdb->update(
                $wpdb->prefix . 'umbrella_mining_solutions',
                array(
                    'submission_status' => 'failed',
                    'submission_error' => $body
                ),
                array('id' => $solution_id),
                array('%s', '%s'),
                array('%d')
            );

            WP_CLI::warning("    ✗ API rejected solution (HTTP {$status_code})");
            WP_CLI::line("    Response: " . substr($body, 0, 200));

            return false;
        }
    }

    /**
     * Process one chunk of mining for a job (called by cron)
     *
     * ## OPTIONS
     *
     * --job-id=<id>
     * : Job ID to process
     *
     * @when after_wp_load
     */
    public function process_chunk($args, $assoc_args) {
        $job_id = $assoc_args['job-id'] ?? 0;

        if (!$job_id) {
            WP_CLI::error('Job ID required: --job-id=<id>');
        }

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-background-miner.php';

        $miner = new Umbrella_Mines_Background_Miner();
        $result = $miner->process_job($job_id, 5000);

        WP_CLI::line("Process chunk result: " . ($result ?: 'false'));
    }
}

// Note: Command registration happens in main plugin file (umbrella-mines.php)
