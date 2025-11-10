<?php
/**
 * Payout Wallet Manager
 * Handles custodial wallet for merging mining rewards
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/UmbrellaMines_EncryptionHelper.php';
require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';

class Umbrella_Mines_Payout_Wallet {

    /**
     * Get active payout wallet for network
     *
     * @param string $network Network (mainnet/preprod)
     * @return object|null Wallet data or null
     */
    public static function get_active_wallet($network = 'mainnet') {
        global $wpdb;
        $table = $wpdb->prefix . 'umbrella_mining_payout_wallet';

        $wallet = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE network = %s AND is_active = 1 ORDER BY created_at DESC LIMIT 1",
            $network
        ));

        return $wallet;
    }

    /**
     * Create new payout wallet (generate)
     *
     * @param string $wallet_name Friendly name
     * @param string $network Network (mainnet/preprod)
     * @return array|WP_Error Result with wallet_id and mnemonic, or error
     */
    public static function create_wallet($wallet_name, $network = 'mainnet') {
        global $wpdb;

        // Generate wallet using existing CardanoWalletPHP
        $wallet_data = CardanoWalletPHP::generateWallet($network);

        if (!$wallet_data || !isset($wallet_data['addresses'])) {
            return new WP_Error('wallet_generation_failed', 'Failed to generate Cardano wallet');
        }

        // Extract address from addresses array
        $address = $wallet_data['addresses']['payment_address'] ?? null;
        if (!$address) {
            return new WP_Error('wallet_generation_failed', 'No payment address in generated wallet');
        }

        // Encrypt sensitive data
        $mnemonic_encrypted = UmbrellaMines_EncryptionHelper::encrypt($wallet_data['mnemonic']);
        $skey_encrypted = UmbrellaMines_EncryptionHelper::encrypt($wallet_data['payment_skey_extended']);

        if (empty($mnemonic_encrypted) || empty($skey_encrypted)) {
            return new WP_Error('encryption_failed', 'Failed to encrypt wallet data');
        }

        // Deactivate any existing active wallet for this network
        $table = $wpdb->prefix . 'umbrella_mining_payout_wallet';
        $wpdb->update(
            $table,
            ['is_active' => 0],
            ['network' => $network, 'is_active' => 1]
        );

        // Insert new active wallet
        $result = $wpdb->insert(
            $table,
            [
                'wallet_name' => sanitize_text_field($wallet_name),
                'address' => $address,
                'mnemonic_encrypted' => $mnemonic_encrypted,
                'payment_skey_extended_encrypted' => $skey_encrypted,
                'payment_pkey' => $wallet_data['payment_pkey_hex'],
                'payment_keyhash' => $wallet_data['payment_keyhash'],
                'network' => $network,
                'is_active' => 1,
                'created_at' => current_time('mysql')
            ]
        );

        if ($result === false) {
            return new WP_Error('database_error', 'Failed to save wallet to database: ' . $wpdb->last_error);
        }

        $wallet_id = $wpdb->insert_id;

        // Return wallet ID and mnemonic (for one-time display)
        return [
            'success' => true,
            'wallet_id' => $wallet_id,
            'mnemonic' => $wallet_data['mnemonic'], // Plaintext for one-time display
            'address' => $address
        ];
    }

    /**
     * Import existing wallet from mnemonic
     *
     * @param string $wallet_name Friendly name
     * @param string $mnemonic 24-word mnemonic phrase
     * @param string $network Network (mainnet/preprod)
     * @return array|WP_Error Result with wallet_id, or error
     */
    public static function import_wallet($wallet_name, $mnemonic, $network = 'mainnet') {
        global $wpdb;

        // Validate mnemonic format
        $mnemonic = trim($mnemonic);
        $words = preg_split('/\s+/', $mnemonic);

        if (count($words) !== 24) {
            return new WP_Error('invalid_mnemonic', 'Mnemonic must be exactly 24 words');
        }

        // Generate wallet from mnemonic using CardanoWalletPHP::fromMnemonic()
        // Uses default derivation path: m/1852'/1815'/0'/0/0
        $wallet_data = CardanoWalletPHP::fromMnemonic($mnemonic, '', $network);

        if (!$wallet_data || !isset($wallet_data['addresses'])) {
            return new WP_Error('wallet_import_failed', 'Failed to import wallet from mnemonic');
        }

        // Extract address from addresses array
        $address = $wallet_data['addresses']['payment_address'] ?? null;
        if (!$address) {
            return new WP_Error('wallet_import_failed', 'No payment address in imported wallet');
        }

        // Encrypt sensitive data
        $mnemonic_encrypted = UmbrellaMines_EncryptionHelper::encrypt($mnemonic);
        $skey_encrypted = UmbrellaMines_EncryptionHelper::encrypt($wallet_data['payment_skey_extended']);

        if (empty($mnemonic_encrypted) || empty($skey_encrypted)) {
            return new WP_Error('encryption_failed', 'Failed to encrypt wallet data');
        }

        // Deactivate any existing active wallet for this network
        $table = $wpdb->prefix . 'umbrella_mining_payout_wallet';
        $wpdb->update(
            $table,
            ['is_active' => 0],
            ['network' => $network, 'is_active' => 1]
        );

        // Insert new active wallet
        $result = $wpdb->insert(
            $table,
            [
                'wallet_name' => sanitize_text_field($wallet_name),
                'address' => $address,
                'mnemonic_encrypted' => $mnemonic_encrypted,
                'payment_skey_extended_encrypted' => $skey_encrypted,
                'payment_pkey' => $wallet_data['payment_pkey_hex'],
                'payment_keyhash' => $wallet_data['payment_keyhash'],
                'network' => $network,
                'is_active' => 1,
                'created_at' => current_time('mysql')
            ]
        );

        if ($result === false) {
            return new WP_Error('database_error', 'Failed to save wallet to database: ' . $wpdb->last_error);
        }

        $wallet_id = $wpdb->insert_id;

        return [
            'success' => true,
            'wallet_id' => $wallet_id,
            'address' => $address
        ];
    }

    /**
     * Get decrypted mnemonic for export
     *
     * @param int $wallet_id Wallet ID
     * @return string|false Decrypted mnemonic or false
     */
    public static function get_mnemonic($wallet_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'umbrella_mining_payout_wallet';

        $encrypted = $wpdb->get_var($wpdb->prepare(
            "SELECT mnemonic_encrypted FROM $table WHERE id = %d",
            $wallet_id
        ));

        if (!$encrypted) {
            return false;
        }

        return UmbrellaMines_EncryptionHelper::decrypt($encrypted);
    }

    /**
     * Get decrypted extended signing key
     *
     * @param int $wallet_id Wallet ID
     * @return string|false Decrypted skey or false
     */
    public static function get_skey($wallet_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'umbrella_mining_payout_wallet';

        $encrypted = $wpdb->get_var($wpdb->prepare(
            "SELECT payment_skey_extended_encrypted FROM $table WHERE id = %d",
            $wallet_id
        ));

        if (!$encrypted) {
            return false;
        }

        return UmbrellaMines_EncryptionHelper::decrypt($encrypted);
    }

    /**
     * Delete payout wallet
     *
     * @param int $wallet_id Wallet ID
     * @return bool Success
     */
    public static function delete_wallet($wallet_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'umbrella_mining_payout_wallet';

        $result = $wpdb->delete($table, ['id' => $wallet_id], ['%d']);

        return $result !== false;
    }

    /**
     * Export wallet data (decrypted for user)
     *
     * @param int $wallet_id Wallet ID
     * @return array|false Wallet data array or false
     */
    public static function export_wallet($wallet_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'umbrella_mining_payout_wallet';

        $wallet = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $wallet_id
        ));

        if (!$wallet) {
            return false;
        }

        // Decrypt sensitive data
        $mnemonic = UmbrellaMines_EncryptionHelper::decrypt($wallet->mnemonic_encrypted);
        $skey = UmbrellaMines_EncryptionHelper::decrypt($wallet->payment_skey_extended_encrypted);

        return [
            'wallet_name' => $wallet->wallet_name,
            'address' => $wallet->address,
            'mnemonic' => $mnemonic,
            'payment_skey_extended' => $skey,
            'payment_pkey' => $wallet->payment_pkey,
            'payment_keyhash' => $wallet->payment_keyhash,
            'network' => $wallet->network,
            'created_at' => $wallet->created_at
        ];
    }
}
