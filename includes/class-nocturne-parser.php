<?php
/**
 * Nocturne Log Parser
 *
 * Parses Nocturne miner logs and converts to Umbrella Mines import format
 */

class Umbrella_Mines_Nocturne_Parser {

    /**
     * Parse Nocturne log file
     *
     * @param string $log_content Contents of Nocturne log file
     * @param string $network Network (mainnet/preprod)
     * @return array|WP_Error Parsed wallet data or error
     */
    public static function parse_nocturne_log($log_content, $network = 'mainnet') {
        error_log("=== PARSE NOCTURNE LOG ===");

        // Extract mnemonic from JSON config at top of log
        $mnemonic = self::extract_mnemonic($log_content);
        if (!$mnemonic) {
            return new WP_Error('no_mnemonic', 'Could not find mnemonic in log file');
        }

        error_log("Found mnemonic: " . substr($mnemonic, 0, 50) . "...");

        // Extract successful submissions
        $submissions = self::extract_submissions($log_content);
        if (empty($submissions)) {
            return new WP_Error('no_submissions', 'No successful submissions found in log file');
        }

        error_log("Found " . count($submissions) . " successful submissions");

        // Group submissions by wallet index
        $wallets_data = self::group_by_wallet($submissions);
        error_log("Submissions grouped into " . count($wallets_data) . " wallets");

        // Debug: Log challenge IDs found
        $challenge_ids = array_unique(array_column($submissions, 'challenge_id'));
        error_log("Challenge IDs found: " . implode(', ', $challenge_ids));

        // Derive wallets from mnemonic
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';

        $wallets = [];
        $challenge_submissions = [];
        $total_solutions = 0;

        foreach ($wallets_data as $wallet_index => $wallet_submissions) {
            // Derive wallet at this index
            $wallet_data = CardanoWalletPHP::fromMnemonicWithPath(
                $mnemonic,
                0,              // account_idx
                0,              // chain_idx (0=external)
                $wallet_index,  // address_idx
                '',             // passphrase
                $network        // network
            );

            if (!$wallet_data || !isset($wallet_data['addresses'])) {
                error_log("WARNING: Failed to derive wallet at index $wallet_index");
                continue;
            }

            $address = $wallet_data['addresses']['payment_address'];
            $private_key_hex = $wallet_data['payment_skey_hex']; // 64-char kL only (32 bytes) for CIP-8

            // Build solutions array
            $solutions = [];
            foreach ($wallet_submissions as $submission) {
                $solutions[] = [
                    'challenge_id' => $submission['challenge_id'],
                    'nonce' => $submission['nonce'],
                    'difficulty' => $submission['difficulty']
                ];

                // Track for NIGHT calculation
                $challenge_id = $submission['challenge_id'];
                if (!isset($challenge_submissions[$challenge_id])) {
                    $challenge_submissions[$challenge_id] = [];
                }
                $challenge_submissions[$challenge_id][] = $wallet_index;
                $total_solutions++;
            }

            error_log("Wallet #$wallet_index ($address): " . count($solutions) . " solutions");

            $wallets[] = [
                'index' => $wallet_index,
                'address' => $address,
                'verification_key' => $wallet_data['payment_pkey_hex'],
                'private_key_hex' => $private_key_hex, // 64-char kL only for CIP-8 signing
                'solutions' => $solutions,
                'has_solutions' => true,
                'solution_count' => count($solutions)
            ];
        }

        if (empty($wallets)) {
            return new WP_Error('no_valid_wallets', 'No valid wallets could be derived');
        }

        // Calculate NIGHT estimate
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-import-processor.php';

        // Debug: Log challenge_submissions structure
        error_log("Challenge submissions for NIGHT calc: " . json_encode($challenge_submissions));

        $night_estimate = Umbrella_Mines_Import_Processor::calculate_night_estimate_from_challenges($challenge_submissions);

        // Add NIGHT values to wallets
        $wallets = Umbrella_Mines_Import_Processor::add_night_values_to_wallets($wallets, $challenge_submissions);

        error_log("Parsed " . count($wallets) . " valid wallets with $total_solutions total solutions");

        return [
            'success' => true,
            'wallets' => $wallets,
            'wallet_count' => count($wallets),
            'wallets_with_solutions' => count($wallets),
            'total_solutions' => $total_solutions,
            'invalid_wallets' => 0,
            'night_estimate' => $night_estimate,
            'network' => $network,
            'already_merged_count' => 0,
            'already_merged_missing_night' => 0
        ];
    }

    /**
     * Extract mnemonic from Nocturne config JSON
     */
    private static function extract_mnemonic($log_content) {
        // Look for "mnemonic": "word1 word2 word3..."
        if (preg_match('/"mnemonic":\s*"([^"]+)"/', $log_content, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Extract successful submissions from log
     * Returns array of ['wallet_index' => N, 'challenge_id' => '**D19C24', 'nonce' => 'abc123', 'difficulty' => '0ADA8800']
     */
    private static function extract_submissions($log_content) {
        $submissions = [];

        // Split into lines
        $lines = explode("\n", $log_content);

        $current_nonce = null;
        $current_challenge = null;
        $current_difficulty = null;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];

            // Look for nonce
            if (preg_match('/nonce:\s*([0-9a-f]+)/', $line, $matches)) {
                $current_nonce = $matches[1];
            }

            // Look for challenge with Index
            if (preg_match('/(\*\*D\d+C\d+)\s*\|\s*Index\s*#(\d+)\s*\|\s*Difficulty:\s*([0-9A-F]+)/', $line, $matches)) {
                $current_challenge = $matches[1];
                $index = (int)$matches[2];
                $current_difficulty = $matches[3];
            }

            // Look for "Submitted" line
            if (preg_match('/Submitted/', $line)) {
                // Next line should have "Submission #N: wallet #M"
                if (isset($lines[$i + 1]) && preg_match('/Submission #\d+: wallet #(\d+)/', $lines[$i + 1], $matches)) {
                    $wallet_index = (int)$matches[1];

                    if ($current_nonce && $current_challenge && $current_difficulty) {
                        $submissions[] = [
                            'wallet_index' => $wallet_index,
                            'challenge_id' => $current_challenge,
                            'nonce' => $current_nonce,
                            'difficulty' => $current_difficulty
                        ];

                        error_log("Recorded submission: wallet #$wallet_index -> $current_challenge (nonce: $current_nonce)");

                        // Reset for next submission
                        $current_nonce = null;
                        $current_challenge = null;
                        $current_difficulty = null;
                    }
                }
            }
        }

        return $submissions;
    }

    /**
     * Group submissions by wallet index
     * Returns array indexed by wallet_index containing arrays of submissions
     */
    private static function group_by_wallet($submissions) {
        $grouped = [];

        foreach ($submissions as $submission) {
            $wallet_index = $submission['wallet_index'];
            if (!isset($grouped[$wallet_index])) {
                $grouped[$wallet_index] = [];
            }
            $grouped[$wallet_index][] = $submission;
        }

        // Sort by wallet index for consistency
        ksort($grouped);

        return $grouped;
    }
}
