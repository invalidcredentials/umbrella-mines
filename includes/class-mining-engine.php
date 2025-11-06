<?php
/**
 * Mining Engine
 *
 * Core mining loop that:
 * 1. Fetches active challenge
 * 2. Generates ephemeral wallet
 * 3. Registers wallet with API
 * 4. Brute-forces nonces until solution found
 * 5. Saves solution to database
 *
 * @package NightMinePHP
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-ashmaize-ffi.php';
require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-scavenger-api.php';

class NightMine_MiningEngine {

    private $api_url;
    private $network;
    private $ashmaize = null;
    private $current_challenge = null;
    private $dll_path;

    public function __construct() {
        $this->api_url = $this->get_config('api_url');
        $this->network = $this->get_config('network');
        $this->dll_path = UMBRELLA_MINES_PLUGIN_DIR . 'bin/ashmaize_capi.dll';
    }

    /**
     * Main mining loop
     * Run this in a background process (WP-CLI or daemon)
     */
    public function start_mining() {
        error_log("=== NIGHT MINING ENGINE START ===");

        while ($this->is_mining_enabled()) {
            try {
                // 1. Fetch current challenge
                $challenge = NightMine_ScavengerAPI::get_challenge($this->api_url);

                if (!$challenge || $challenge['code'] !== 'active') {
                    error_log("No active challenge. Waiting 60 seconds...");
                    sleep(60);
                    continue;
                }

                $this->current_challenge = $challenge['challenge'];

                error_log("Active challenge: " . $this->current_challenge['challenge_id']);
                error_log("Difficulty: " . $this->current_challenge['difficulty']);

                // 2. Initialize AshMaize ROM if needed
                if ($this->ashmaize === null || $this->ashmaize_key_changed()) {
                    error_log("Initializing AshMaize FFI ROM (1GB)...");
                    $start_rom = microtime(true);
                    $this->ashmaize = new AshMaizeFFI($this->dll_path, $this->current_challenge['no_pre_mine']);
                    $elapsed = microtime(true) - $start_rom;
                    error_log(sprintf("ROM ready in %.2f seconds!", $elapsed));
                }

                // 3. Generate ephemeral wallet
                $wallet = $this->generate_wallet();
                error_log("Generated wallet: " . $wallet['address']);

                // 4. Register wallet
                $registered = $this->register_wallet($wallet);

                if (!$registered) {
                    error_log("Wallet registration failed. Skipping to next wallet.");
                    continue;
                }

                error_log("Wallet registered successfully!");

                // 5. Mine for solution
                $solution = $this->mine_solution($wallet);

                if ($solution) {
                    error_log("SOLUTION FOUND!");
                    error_log("Nonce: " . $solution['nonce']);
                    error_log("Hash: " . bin2hex($solution['hash']));

                    // 6. Save to database
                    $this->save_solution($wallet, $solution);

                    error_log("Solution saved to database!");
                } else {
                    error_log("No solution found for this wallet. Moving to next...");
                }

            } catch (Exception $e) {
                error_log("Mining error: " . $e->getMessage());
                sleep(60); // Wait before retrying
            }
        }

        error_log("=== NIGHT MINING ENGINE STOPPED ===");
    }

    /**
     * Generate a random ephemeral wallet
     */
    private function generate_wallet() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';

        // Generate completely random wallet (no mnemonic)
        $wallet = CardanoWalletPHP::generateWallet($this->network);

        // Save to database
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'umbrella_mining_wallets',
            array(
                'address' => $wallet['addresses']['payment'],
                'payment_skey_extended' => $wallet['payment_skey_extended'],
                'payment_pkey' => $wallet['payment_pkey_hex'],
                'payment_keyhash' => $wallet['payment_keyhash'],
                'network' => $this->network
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );

        $wallet['wallet_id'] = $wpdb->insert_id;

        return $wallet;
    }

    /**
     * Register wallet with Scavenger Mine API
     */
    private function register_wallet($wallet) {
        // Get T&C
        $tc = NightMine_ScavengerAPI::get_tandc($this->api_url);

        if (!$tc) {
            error_log("Failed to fetch T&C");
            return false;
        }

        // Sign T&C message with CIP-8
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoCIP8Signer.php';

        $signature_data = CardanoCIP8Signer::sign_message(
            $tc['message'],
            $wallet['payment_skey_extended'],
            $wallet['addresses']['payment'],
            $this->network
        );

        // Register with API
        $result = NightMine_ScavengerAPI::register_address(
            $this->api_url,
            $wallet['addresses']['payment'],
            $signature_data['signature'],
            $signature_data['pubkey']
        );

        if ($result) {
            // Update wallet record
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'umbrella_mining_wallets',
                array(
                    'registration_signature' => $signature_data['signature'],
                    'registration_pubkey' => $signature_data['pubkey'],
                    'registered_at' => current_time('mysql')
                ),
                array('id' => $wallet['wallet_id']),
                array('%s', '%s', '%s'),
                array('%d')
            );

            return true;
        }

        return false;
    }

    /**
     * Mine for a solution (brute force nonces)
     */
    private function mine_solution($wallet) {
        $start_time = microtime(true);
        $hashes_done = 0;
        $max_attempts = 1000000; // Try 1 million nonces per wallet

        error_log("Mining with wallet: " . $wallet['addresses']['payment']);
        error_log("Challenge: " . $this->current_challenge['challenge_id']);
        error_log("Difficulty: " . $this->current_challenge['difficulty']);

        for ($i = 0; $i < $max_attempts; $i++) {
            // Generate random nonce
            $nonce_bytes = random_bytes(8);
            $nonce_hex = bin2hex($nonce_bytes);

            // Build preimage
            $preimage = AshMaizeFFI::build_preimage(
                $nonce_hex,
                $wallet['addresses']['payment'],
                $this->current_challenge['challenge_id'],
                $this->current_challenge['difficulty'],
                $this->current_challenge['no_pre_mine'],
                $this->current_challenge['latest_submission'],
                $this->current_challenge['no_pre_mine_hour']
            );

            // Hash with AshMaize FFI
            $hash_hex = $this->ashmaize->hash($preimage);
            $hashes_done++;

            // Check difficulty
            if (AshMaizeFFI::check_difficulty($hash_hex, $this->current_challenge['difficulty'])) {
                $elapsed = microtime(true) - $start_time;
                $hashrate = $hashes_done / $elapsed;

                error_log(sprintf("Solution found after %d attempts in %.2f seconds (%.2f H/s)",
                    $hashes_done, $elapsed, $hashrate));

                return array(
                    'nonce' => $nonce_hex,
                    'preimage' => $preimage,
                    'hash' => $hash_hex
                );
            }

            // Progress update every 10k hashes
            if ($i > 0 && $i % 10000 === 0) {
                $elapsed = microtime(true) - $start_time;
                $hashrate = $hashes_done / $elapsed;
                error_log(sprintf("Mining progress: %d hashes, %.2f H/s", $hashes_done, $hashrate));
            }
        }

        // No solution found
        $elapsed = microtime(true) - $start_time;
        $hashrate = $hashes_done / $elapsed;
        error_log(sprintf("No solution found after %d attempts (%.2f H/s)", $hashes_done, $hashrate));

        return null;
    }

    /**
     * Save solution to database
     */
    private function save_solution($wallet, $solution) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'umbrella_mining_solutions',
            array(
                'wallet_id' => $wallet['wallet_id'],
                'challenge_id' => $this->current_challenge['challenge_id'],
                'nonce' => $solution['nonce'],
                'preimage' => bin2hex($solution['preimage']),
                'hash_result' => bin2hex($solution['hash']),
                'difficulty' => $this->current_challenge['difficulty'],
                'no_pre_mine' => $this->current_challenge['no_pre_mine'],
                'no_pre_mine_hour' => $this->current_challenge['no_pre_mine_hour'],
                'latest_submission' => $this->current_challenge['latest_submission'],
                'submission_status' => 'pending'
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $wpdb->insert_id;
    }

    /**
     * Check if AshMaize key changed (new challenge)
     */
    private function ashmaize_key_changed() {
        if (!$this->ashmaize) {
            return true;
        }

        // Compare hex strings directly (no conversion needed)
        return $this->ashmaize->no_pre_mine_hex !== $this->current_challenge['no_pre_mine'];
    }

    /**
     * Check if mining is enabled
     */
    private function is_mining_enabled() {
        return (bool) $this->get_config('mining_enabled');
    }

    /**
     * Get config value
     */
    private function get_config($key) {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}umbrella_mining_config WHERE config_key = %s",
            $key
        ));

        return $value;
    }
}
