<?php
/**
 * Background Mining Worker
 *
 * Processes mining jobs in chunks with real-time progress updates
 */

if (!defined('ABSPATH')) {
    exit;
}

class Umbrella_Mines_Background_Miner {

    private $job_id;
    private $job;
    private $wallet;
    private $challenge;
    private $ashmaize;

    /**
     * Process a mining job in chunks
     *
     * @param int $job_id Job ID to process
     * @param int $chunk_size Number of nonces to try per execution (default: 5000)
     */
    public function process_job($job_id, $chunk_size = 5000) {
        global $wpdb;

        error_log("[MINER] Processing job #$job_id (chunk_size: $chunk_size)");

        $this->job_id = $job_id;

        // Get job
        $this->job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}umbrella_mining_jobs WHERE id = %d",
            $job_id
        ), ARRAY_A);

        if (!$this->job) {
            error_log("[MINER] ERROR: Job #$job_id not found in database");
            return false;
        }

        if ($this->job['status'] === 'stopped') {
            error_log("[MINER] Job #$job_id is stopped, skipping");
            return false;
        }

        error_log("[MINER] Job #$job_id status: {$this->job['status']}, attempts: {$this->job['attempts_done']}/{$this->job['max_attempts']}");

        // Mark as running if just started
        if ($this->job['status'] === 'pending') {
            $wpdb->update(
                $wpdb->prefix . 'umbrella_mining_jobs',
                array(
                    'status' => 'running',
                    'started_at' => current_time('mysql')
                ),
                array('id' => $job_id),
                array('%s', '%s'),
                array('%d')
            );
            $this->log_progress('Starting mining job...', 0, 0, 0);
        }

        // Get or create wallet
        if (!$this->job['wallet_id']) {
            $this->log_progress('⚙️ Generating wallet...', 0, 0, 0);

            $this->wallet = $this->generate_wallet();
            if (!$this->wallet) {
                $this->log_progress('❌ Failed to generate wallet', 0, 0, 0);
                return false;
            }

            // Update job with wallet
            $wpdb->update(
                $wpdb->prefix . 'umbrella_mining_jobs',
                array('wallet_id' => $this->wallet['id']),
                array('id' => $job_id),
                array('%d'),
                array('%d')
            );

            $this->log_progress('✓ Wallet generated: ' . substr($this->wallet['address'], 0, 30) . '...', 0, 0, 0);
            $this->log_progress('  Path: ' . $this->wallet['derivation_path'], 0, 0, 0);
        } else {
            // Load existing wallet
            $this->wallet = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}umbrella_mining_wallets WHERE id = %d",
                $this->job['wallet_id']
            ), ARRAY_A);
        }

        // Get challenge
        $this->log_progress('⚙️ Fetching challenge from API...', 0, 0, 0);
        $this->challenge = $this->get_challenge();
        if (!$this->challenge) {
            $this->log_progress('❌ Failed to fetch challenge', 0, 0, 0);
            return false;
        }
        $this->log_progress('✓ Challenge fetched: ' . $this->challenge['challenge_id'], 0, 0, 0);
        $this->log_progress('  Difficulty: ' . $this->challenge['difficulty'], 0, 0, 0);

        // Register wallet if needed
        if (!$this->wallet['registered_at']) {
            $this->log_progress('⚙️ Registering wallet with API (may take 15-30s)...', 0, 0, 0);
            $registered = $this->register_wallet();
            if (!$registered) {
                $this->log_progress('❌ Wallet registration failed, will retry on next run', 0, 0, 0);
                return false;
            }
            $this->log_progress('✓ Wallet registered successfully!', 0, 0, 0);
        }

        // Initialize AshMaize
        if (!$this->ashmaize) {
            $this->log_progress('⚙️ Initializing AshMaize ROM (1GB, takes ~2 seconds)...', 0, 0, 0);
            $this->init_ashmaize();
            $this->log_progress('✓ AshMaize ROM ready!', 0, 0, 0);
        }

        // Mine a chunk of nonces
        $result = $this->mine_chunk($chunk_size);

        return $result;
    }

    /**
     * Mine a chunk of nonces
     */
    private function mine_chunk($chunk_size) {
        global $wpdb;

        $start_time = microtime(true);
        $attempts_done = (int)$this->job['attempts_done'];
        $max_attempts = (int)$this->job['max_attempts'];

        for ($i = 0; $i < $chunk_size; $i++) {
            if ($attempts_done >= $max_attempts) {
                // Job complete - no solution found
                $wpdb->update(
                    $wpdb->prefix . 'umbrella_mining_jobs',
                    array(
                        'status' => 'completed',
                        'completed_at' => current_time('mysql')
                    ),
                    array('id' => $this->job_id),
                    array('%s', '%s'),
                    array('%d')
                );
                $this->log_progress('Completed - no solution found', $attempts_done, 0, 100);
                return 'completed';
            }

            // Generate random nonce
            $nonce_bytes = random_bytes(8);
            $nonce_hex = bin2hex($nonce_bytes);

            // Build preimage
            require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-ashmaize-ffi.php';
            $preimage = AshMaizeFFI::build_preimage(
                $nonce_hex,
                $this->wallet['address'],
                $this->challenge['challenge_id'],
                $this->challenge['difficulty'],
                $this->challenge['no_pre_mine'],
                $this->challenge['latest_submission'],
                $this->challenge['no_pre_mine_hour']
            );

            // Hash with AshMaize
            $hash_hex = $this->ashmaize->hash($preimage);
            $attempts_done++;

            // Check difficulty
            if (AshMaizeFFI::check_difficulty($hash_hex, $this->challenge['difficulty'])) {
                // SOLUTION FOUND!
                $this->save_solution($nonce_hex, $preimage, $hash_hex);

                $elapsed = microtime(true) - $start_time;
                $hashrate = ($i + 1) / $elapsed;

                $wpdb->update(
                    $wpdb->prefix . 'umbrella_mining_jobs',
                    array(
                        'status' => 'completed',
                        'attempts_done' => $attempts_done,
                        'completed_at' => current_time('mysql')
                    ),
                    array('id' => $this->job_id),
                    array('%s', '%d', '%s'),
                    array('%d')
                );

                $this->log_progress('SOLUTION FOUND! Nonce: ' . $nonce_hex, $attempts_done, $hashrate, 100);
                return 'solution_found';
            }

            // Log progress every 100 hashes (more frequent updates!)
            if (($i + 1) % 100 === 0) {
                $elapsed = microtime(true) - $start_time;
                $hashrate = ($i + 1) / $elapsed;
                $progress_percent = ($attempts_done / $max_attempts) * 100;

                $wpdb->update(
                    $wpdb->prefix . 'umbrella_mining_jobs',
                    array(
                        'attempts_done' => $attempts_done,
                        'current_nonce' => $nonce_hex
                    ),
                    array('id' => $this->job_id),
                    array('%d', '%s'),
                    array('%d')
                );

                $this->log_progress(
                    sprintf('⛏️ Mining: %s hashes | %.2f H/s | %.2f%% complete',
                        number_format($attempts_done),
                        $hashrate,
                        $progress_percent
                    ),
                    $attempts_done,
                    $hashrate,
                    $progress_percent
                );
            }
        }

        // Update job with progress
        $elapsed = microtime(true) - $start_time;
        $hashrate = $chunk_size / $elapsed;
        $progress_percent = ($attempts_done / $max_attempts) * 100;

        $wpdb->update(
            $wpdb->prefix . 'umbrella_mining_jobs',
            array('attempts_done' => $attempts_done),
            array('id' => $this->job_id),
            array('%d'),
            array('%d')
        );

        return 'continue';
    }

    /**
     * Generate wallet
     */
    private function generate_wallet() {
        global $wpdb;

        try {
            require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';

            // Parse derivation path
            $parts = explode('/', $this->job['derivation_path']);
            $account = isset($parts[0]) ? (int)$parts[0] : 0;
            $chain = isset($parts[1]) ? (int)$parts[1] : 0;
            $address_index = isset($parts[2]) ? (int)$parts[2] : 0;

            error_log("[WALLET GEN] Generating wallet for path: {$this->job['derivation_path']} (account=$account, chain=$chain, index=$address_index)");

            $network = $this->get_config('network') ?: 'mainnet';
            error_log("[WALLET GEN] Network: $network");

            $wallet = CardanoWalletPHP::generateWalletWithPath($account, $chain, $address_index, $network);

            if (!$wallet) {
                error_log("[WALLET GEN] ERROR: CardanoWalletPHP::generateWalletWithPath returned null/false");
                $this->log_progress('❌ Wallet generation returned null', 0, 0, 0);
                return false;
            }

            if (!isset($wallet['addresses']['payment_address'])) {
                error_log("[WALLET GEN] ERROR: No payment address in wallet response. Keys: " . implode(', ', array_keys($wallet)));
                error_log("[WALLET GEN] Addresses keys: " . (isset($wallet['addresses']) ? implode(', ', array_keys($wallet['addresses'])) : 'N/A'));
                $this->log_progress('❌ No payment address in wallet', 0, 0, 0);
                return false;
            }

            error_log("[WALLET GEN] Wallet generated successfully: " . $wallet['addresses']['payment_address']);

            // Save to database
            $result = $wpdb->insert(
                $wpdb->prefix . 'umbrella_mining_wallets',
                array(
                    'address' => $wallet['addresses']['payment_address'],
                    'derivation_path' => $this->job['derivation_path'],
                    'payment_skey_extended' => $wallet['payment_skey_extended'],
                    'payment_pkey' => $wallet['payment_pkey_hex'],
                    'payment_keyhash' => $wallet['payment_keyhash'],
                    'network' => $network
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s')
            );

            if ($result === false) {
                error_log("[WALLET GEN] ERROR: Failed to insert wallet into database. Last error: " . $wpdb->last_error);
                $this->log_progress('❌ Failed to save wallet to database: ' . $wpdb->last_error, 0, 0, 0);
                return false;
            }

            error_log("[WALLET GEN] Wallet saved to database with ID: " . $wpdb->insert_id);

            return array(
                'id' => $wpdb->insert_id,
                'address' => $wallet['addresses']['payment_address'],
                'payment_skey_extended' => $wallet['payment_skey_extended'],
                'derivation_path' => $this->job['derivation_path']
            );

        } catch (Throwable $e) {
            error_log("[WALLET GEN] EXCEPTION: " . $e->getMessage());
            error_log("[WALLET GEN] Stack trace: " . $e->getTraceAsString());
            $this->log_progress('❌ Exception in wallet generation: ' . $e->getMessage(), 0, 0, 0);
            return false;
        }
    }

    /**
     * Register wallet with API
     */
    private function register_wallet() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-scavenger-api.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoCIP8Signer.php';

        $api_url = $this->get_config('api_url');
        $network = $this->get_config('network') ?: 'mainnet';

        // Get T&C
        $tc = Umbrella_Mines_ScavengerAPI::get_tandc($api_url);
        if (!$tc) {
            return false;
        }

        // Sign T&C
        $signature_data = CardanoCIP8Signer::sign_message(
            $tc['message'],
            $this->wallet['payment_skey_extended'],
            $this->wallet['address'],
            $network
        );

        // Register
        $result = Umbrella_Mines_ScavengerAPI::register_address(
            $api_url,
            $this->wallet['address'],
            $signature_data['signature'],
            $signature_data['pubkey']
        );

        if ($result) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'umbrella_mining_wallets',
                array(
                    'registration_signature' => $signature_data['signature'],
                    'registration_pubkey' => $signature_data['pubkey'],
                    'registered_at' => current_time('mysql')
                ),
                array('id' => $this->wallet['id']),
                array('%s', '%s', '%s'),
                array('%d')
            );
        }

        return $result;
    }

    /**
     * Get challenge
     */
    private function get_challenge() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-scavenger-api.php';

        $api_url = $this->get_config('api_url');
        return Umbrella_Mines_ScavengerAPI::get_challenge($api_url);
    }

    /**
     * Initialize AshMaize
     */
    private function init_ashmaize() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-ashmaize-ffi.php';

        $dll_path = UMBRELLA_MINES_PLUGIN_DIR . 'bin/ashmaize_capi.dll';
        $this->ashmaize = new AshMaizeFFI($dll_path, $this->challenge['no_pre_mine']);
    }

    /**
     * Save solution
     */
    private function save_solution($nonce_hex, $preimage, $hash_hex) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'umbrella_mining_solutions',
            array(
                'wallet_id' => $this->wallet['id'],
                'challenge_id' => $this->challenge['challenge_id'],
                'nonce' => $nonce_hex,
                'preimage' => bin2hex($preimage),
                'hash_result' => $hash_hex,
                'difficulty' => $this->challenge['difficulty'],
                'no_pre_mine' => $this->challenge['no_pre_mine'],
                'no_pre_mine_hour' => $this->challenge['no_pre_mine_hour'],
                'latest_submission' => $this->challenge['latest_submission'],
                'submission_status' => 'pending'
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Log progress
     */
    private function log_progress($message, $attempts, $hashrate, $progress_percent) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'umbrella_mining_progress',
            array(
                'job_id' => $this->job_id,
                'message' => $message,
                'attempts' => $attempts,
                'hashrate' => $hashrate,
                'progress_percent' => $progress_percent
            ),
            array('%d', '%s', '%d', '%f', '%f')
        );
    }

    /**
     * Get config value
     */
    private function get_config($key) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}umbrella_mining_config WHERE config_key = %s",
            $key
        ));
    }
}
