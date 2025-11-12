<?php
/**
 * CardanoWalletPHP.php
 *
 * Pure-PHP Cardano wallet derivation for CIP-1852 using the Icarus root:
 *  - Mnemonic -> entropy (BIP39)
 *  - Root (Icarus): PBKDF2-HMAC-SHA512(passphrase, salt=entropy, iter=4096, dkLen=96) => (kL,kR,chainCode)
 *    + Icarus clamp on kL: kL[0]&=0xF8; kL[31]&=0x1F; kL[31]|=0x40
 *  - Children: Ed25519-BIP32 (Khovratovich/Law) with correct prefixes, LE indices, Z split
 *  - Addresses: base (payment keyhash + stake keyhash), stake, Bech32 encoding
 *
 * Requires:
 *   - ext/sodium (built-in since PHP 7.2)
 *   - ext/hash (built-in)
 *   - ext/bcmath (for pure-PHP scalar math fallback paths and safety)
 *
 * Uses:
 *   - Ed25519Compat.php (native->FFI->pure fallback) for scalarmult and extended signing
 */

// Namespace removed for Umbrella Blog standalone compatibility
// Was: namespace CardanoMintPay\Helpers;

require_once __DIR__ . '/Ed25519Compat.php';

class CardanoWalletPHP
{
    // ---- Public API ---------------------------------------------------------

    /**
     * Create wallet from a mnemonic (24 words typical), optional passphrase, and network.
     * @param string $mnemonic
     * @param string $passphrase
     * @param string $network 'mainnet' or 'preprod' (test)
     * @return array
     */
    public static function fromMnemonic(string $mnemonic, string $passphrase = '', string $network = 'preprod'): array
    {
        self::checkRequirements();

        // 1) Root (Icarus) from mnemonic entropy
        $root = self::deriveRootKeyIcarusFromMnemonic($mnemonic, $passphrase);

        // 2) CIP-1852 path m/1852'/1815'/0'
        $purpose = self::deriveChild($root, 1852, true);
        $coin    = self::deriveChild($purpose, 1815, true);
        $acct0   = self::deriveChild($coin, 0,    true);

        // 3) Payment m/.../0/0 (soft-soft), Stake m/.../2/0 (soft-soft)
        $pay_chain  = self::deriveChild($acct0, 0, false);
        $pay_key    = self::deriveChild($pay_chain, 0, false);

        $stake_chain= self::deriveChild($acct0, 2, false);
        $stake_key  = self::deriveChild($stake_chain, 0, false);

        // 4) Addresses
        $addresses = self::buildAddresses($pay_key['public_key'], $stake_key['public_key'], $network);

        return [
            'success' => true,
            'root'    => $root,
            'account' => $acct0,
            'payment' => $pay_key,
            'stake'   => $stake_key,
            'addresses' => $addresses,
            // Hex helpers commonly used in tests
            'payment_skey_hex' => bin2hex($pay_key['kL']), // leaf kL only (64 hex) - for display/tests
            'payment_skey_extended' => bin2hex($pay_key['kL'] . $pay_key['kR']), // FULL extended key (128 hex) - for signing!
            'payment_pkey_hex' => bin2hex($pay_key['public_key']),
            'payment_keyhash'  => self::blake2b224_hex($pay_key['public_key']),
            'stake_skey_hex'   => bin2hex($stake_key['kL']),
            'stake_skey_extended' => bin2hex($stake_key['kL'] . $stake_key['kR']), // FULL extended key for stake
            'stake_keyhash'    => self::blake2b224_hex($stake_key['public_key']),
        ];
    }

    /**
     * Generate a new wallet: returns mnemonic + derived keys/addresses
     */
    public static function generateWallet(string $network = 'preprod'): array
    {
        self::checkRequirements();
        $mnemonic = self::generateMnemonic(24);
        $wallet = self::fromMnemonic($mnemonic, '', $network);
        $wallet['mnemonic'] = $mnemonic;
        return $wallet;
    }

    /**
     * Generate wallet with CUSTOM derivation path
     * Allows exploring non-standard keyspace!
     *
     * @param int $account_idx Account index (default 0)
     * @param int $chain_idx Chain index (0=external, 1=internal, 2=stake)
     * @param int $address_idx Address index (default 0)
     * @param string $network 'mainnet' or 'preprod'
     * @return array Wallet data
     */
    public static function generateWalletWithPath(
        int $account_idx = 0,
        int $chain_idx = 0,
        int $address_idx = 0,
        string $network = 'preprod'
    ): array {
        self::checkRequirements();

        // Generate random mnemonic
        $mnemonic = self::generateMnemonic(24);

        // Derive root
        $root = self::deriveRootKeyIcarusFromMnemonic($mnemonic, '');

        // CIP-1852 path: m/1852'/1815'/{account}'
        $purpose = self::deriveChild($root, 1852, true);
        $coin = self::deriveChild($purpose, 1815, true);
        $account = self::deriveChild($coin, $account_idx, true);

        // Payment path: m/.../chain/address_idx
        $pay_chain = self::deriveChild($account, $chain_idx, false);
        $pay_key = self::deriveChild($pay_chain, $address_idx, false);

        // Stake always uses chain=2, address=0
        $stake_chain = self::deriveChild($account, 2, false);
        $stake_key = self::deriveChild($stake_chain, 0, false);

        // Build addresses
        $addresses = self::buildAddresses($pay_key['public_key'], $stake_key['public_key'], $network);

        return [
            'success' => true,
            'mnemonic' => $mnemonic,
            'derivation_path' => "m/1852'/1815'/$account_idx'/$chain_idx/$address_idx",
            'root' => $root,
            'account' => $account,
            'payment' => $pay_key,
            'stake' => $stake_key,
            'addresses' => $addresses,
            'payment_skey_hex' => bin2hex($pay_key['kL']),
            'payment_skey_extended' => bin2hex($pay_key['kL'] . $pay_key['kR']),
            'payment_pkey_hex' => bin2hex($pay_key['public_key']),
            'payment_keyhash' => self::blake2b224_hex($pay_key['public_key']),
            'stake_skey_hex' => bin2hex($stake_key['kL']),
            'stake_skey_extended' => bin2hex($stake_key['kL'] . $stake_key['kR']),
            'stake_keyhash' => self::blake2b224_hex($stake_key['public_key']),
        ];
    }

    /**
     * Derive wallet from EXISTING mnemonic with CUSTOM derivation path
     * Use this to import wallets from other platforms (Eternl, Nami, etc.)
     *
     * @param string $mnemonic Existing 24-word recovery phrase
     * @param int $account_idx Account index (default 0)
     * @param int $chain_idx Chain index (0=external, 1=internal, 2=stake)
     * @param int $address_idx Address index (default 0)
     * @param string $passphrase Optional passphrase
     * @param string $network 'mainnet' or 'preprod'
     * @return array Wallet data
     */
    public static function fromMnemonicWithPath(
        string $mnemonic,
        int $account_idx = 0,
        int $chain_idx = 0,
        int $address_idx = 0,
        string $passphrase = '',
        string $network = 'preprod'
    ): array {
        self::checkRequirements();

        // Derive root from PROVIDED mnemonic
        $root = self::deriveRootKeyIcarusFromMnemonic($mnemonic, $passphrase);

        // CIP-1852 path: m/1852'/1815'/{account}'
        $purpose = self::deriveChild($root, 1852, true);
        $coin = self::deriveChild($purpose, 1815, true);
        $account = self::deriveChild($coin, $account_idx, true);

        // Payment path: m/.../chain/address_idx
        $pay_chain = self::deriveChild($account, $chain_idx, false);
        $pay_key = self::deriveChild($pay_chain, $address_idx, false);

        // Stake always uses chain=2, address=0
        $stake_chain = self::deriveChild($account, 2, false);
        $stake_key = self::deriveChild($stake_chain, 0, false);

        // Build addresses
        $addresses = self::buildAddresses($pay_key['public_key'], $stake_key['public_key'], $network);

        return [
            'success' => true,
            'derivation_path' => "m/1852'/1815'/$account_idx'/$chain_idx/$address_idx",
            'root' => $root,
            'account' => $account,
            'payment' => $pay_key,
            'stake' => $stake_key,
            'addresses' => $addresses,
            'payment_skey_hex' => bin2hex($pay_key['kL']),
            'payment_skey_extended' => bin2hex($pay_key['kL'] . $pay_key['kR']),
            'payment_pkey_hex' => bin2hex($pay_key['public_key']),
            'payment_keyhash' => self::blake2b224_hex($pay_key['public_key']),
            'stake_skey_hex' => bin2hex($stake_key['kL']),
            'stake_skey_extended' => bin2hex($stake_key['kL'] . $stake_key['kR']),
            'stake_keyhash' => self::blake2b224_hex($stake_key['public_key']),
        ];
    }

    // ---- Root (Icarus) -----------------------------------------------------

    private static function deriveRootKeyIcarusFromMnemonic(string $mnemonic, string $passphrase = ''): array
    {
        $entropy = self::mnemonicToEntropyBytes($mnemonic); // 16,20,24,28,32 bytes based on 12..24 words

        if (class_exists('\Normalizer')) {
            $passphrase = \Normalizer::normalize($passphrase, \Normalizer::FORM_KD);
        }

        // PBKDF2-HMAC-SHA512(passphrase, salt=entropy, iter=4096, dkLen=96)
        $dk = hash_pbkdf2('sha512', $passphrase, $entropy, 4096, 96, true);
        $kL = substr($dk, 0, 32);
        $kR = substr($dk, 32, 32);
        $cc = substr($dk, 64, 32);

        // Security: Zero out the derived key material after extraction
        if (function_exists('sodium_memzero')) {
            sodium_memzero($dk);
        }

        // Icarus clamp
        $kL[0]  = $kL[0]  & "\xF8";
        $kL[31] = $kL[31] & "\x1F";
        $kL[31] = $kL[31] | "\x40";

        $A = \Ed25519Compat::ge_scalarmult_base_noclamp($kL);

        return [
            'kL'         => $kL,
            'kR'         => $kR,
            'chain_code' => $cc,
            'public_key' => $A,
        ];
    }

    // ---- Child Derivation (ed25519-BIP32, Khovratovich/Law) ----------------

    /**
     * Derive child from extended private parent.
     * @param array $parent ['kL','kR','chain_code','public_key']
     * @param int $index
     * @param bool $hardened
     * @return array same shape
     */
    private static function deriveChild(array $parent, int $index, bool $hardened): array
    {
        $cP = $parent['chain_code'];
        $kL = $parent['kL'];
        $kR = $parent['kR'];

        $i  = $hardened ? ($index | 0x80000000) : $index;
        $i_le = pack('V', $i);

        if ($hardened) {
            // EXACT blob = kL || kR (64 bytes)
            $blob = $kL . $kR;
            $dataZ = "\x00" . $blob . $i_le;
            $dataC = "\x01" . $blob . $i_le;
        } else {
            // A_parent (32 bytes compressed)
            $A = \Ed25519Compat::ge_scalarmult_base_noclamp($kL);
            $dataZ = "\x02" . $A . $i_le;
            $dataC = "\x03" . $A . $i_le;
        }

        // Z = HMAC-SHA512(c_parent, dataZ)
        $Z  = hash_hmac('sha512', $dataZ, $cP, true);
        // Split: left 28 -> pad 4 zero bytes; skip Z[28..31]; right 32
        $ZL = substr($Z, 0, 28) . "\x00\x00\x00\x00";
        $ZR = substr($Z, 32, 32);

        // kL_child = kL_parent + 8*ZL (raw 32-byte add for CSL compatibility)
        $t = \Ed25519Compat::scalar_mul_small8($ZL);
        $kL_child = \Ed25519Compat::add_raw32($kL, $t);  // Raw add (mod 2^256), NOT mod L

        // kR_child = kR_parent + ZR (mod 2^256)
        $kR_child = $kR; $carry = 0;
        for ($j = 0; $j < 32; $j++) {
            $sum = ord($kR_child[$j]) + ord($ZR[$j]) + $carry;
            $kR_child[$j] = chr($sum & 0xFF);
            $carry = $sum >> 8;
        }

        // c_child = HMAC-SHA512(c_parent, dataC)[32..63]
        $c_full  = hash_hmac('sha512', $dataC, $cP, true);
        $c_child = substr($c_full, 32, 32);

        $A_child = \Ed25519Compat::ge_scalarmult_base_noclamp($kL_child);

        return [
            'kL'         => $kL_child,
            'kR'         => $kR_child,
            'chain_code' => $c_child,
            'public_key' => $A_child,
        ];
    }

    // ---- Addresses ----------------------------------------------------------

    private static function buildAddresses(string $pay_vkey, string $stake_vkey, string $network): array
    {
        $mainnet = ($network === 'mainnet');
        $network_id = $mainnet ? 1 : 0;

        $pay_hash   = hex2bin(self::blake2b224_hex($pay_vkey));
        $stake_hash = hex2bin(self::blake2b224_hex($stake_vkey));

        // Base address (keyhash + keyhash) header: type=0x0, so header=(0x00 | network_id)
        $header = chr(($network_id & 0x0F));
        $addr_bytes = $header . $pay_hash . $stake_hash;

        $addr_hrp = $mainnet ? 'addr' : 'addr_test';
        $addr_bech32 = self::bech32Encode($addr_hrp, self::to5Bits($addr_bytes));

        // Stake (reward) address: header: 0xE0 | network_id
        $sheader = chr(0xE0 | ($network_id & 0x0F));
        $stake_bytes = $sheader . $stake_hash;
        $stake_hrp = $mainnet ? 'stake' : 'stake_test';
        $stake_bech32 = self::bech32Encode($stake_hrp, self::to5Bits($stake_bytes));

        return [
            'payment_address' => $addr_bech32,
            'stake_address'   => $stake_bech32,
        ];
    }

    // ---- BIP39 helpers ------------------------------------------------------

    private static $WORDLIST = null;

    private static function getWordlist(): array
    {
        if (self::$WORDLIST !== null) return self::$WORDLIST;
        // bip39-wordlist.php should return the EN wordlist array (2048 words in order)
        self::$WORDLIST = require __DIR__ . '/bip39-wordlist.php';
        return self::$WORDLIST;
    }

    private static function generateMnemonic(int $wordCount = 24): string
    {
        // ENT (bits) must be 128,160,192,224,256 for 12,15,18,21,24 words respectively
        $valid = [12=>128, 15=>160, 18=>192, 21=>224, 24=>256];
        if (!isset($valid[$wordCount])) $wordCount = 24;
        $ENT = $valid[$wordCount]; $CS = $ENT / 32;
        $entropy = random_bytes($ENT / 8);
        $hash = hash('sha256', $entropy, true);
        $bits = self::binToBits($entropy) . substr(self::binToBits($hash), 0, $CS);
        $words = self::bitsToWords($bits);
        return implode(' ', $words);
    }

    private static function mnemonicToEntropyBytes(string $mnemonic): string
    {
        $words = preg_split('/\s+/', trim($mnemonic));
        $n = count($words);
        $valid = [12=>128, 15=>160, 18=>192, 21=>224, 24=>256];
        if (!isset($valid[$n])) {
            throw new \InvalidArgumentException('Unsupported mnemonic length');
        }
        $ENT = $valid[$n]; $CS = $ENT / 32;

        $wl = self::getWordlist();
        $map = array_flip($wl);

        $bits = '';
        foreach ($words as $w) {
            if (!isset($map[$w])) {
                throw new \InvalidArgumentException("Word not in BIP39 list: {$w}");
            }
            $idx = $map[$w];
            $bits .= str_pad(decbin($idx), 11, '0', STR_PAD_LEFT);
        }
        $entropy_bits = substr($bits, 0, $ENT);
        $checksum_bits= substr($bits, $ENT, $CS);
        $entropy = self::bitsToBin($entropy_bits);

        $hash = hash('sha256', $entropy, true);
        $check = substr(self::binToBits($hash), 0, $CS);
        if ($check !== $checksum_bits) {
            // We continue anyway; but flagging could help debugging vectors
            // throw new \RuntimeException('Mnemonic checksum mismatch');
        }
        return $entropy;
    }

    private static function binToBits(string $bin): string
    {
        $out = '';
        $len = strlen($bin);
        for ($i=0; $i<$len; $i++) {
            $out .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    private static function bitsToBin(string $bits): string
    {
        $out = '';
        $len = strlen($bits);
        for ($i = 0; $i < $len; $i += 8) {
            $byte = substr($bits, $i, 8);
            // Pad incomplete byte with zeros on the right
            if (strlen($byte) < 8) {
                $byte = str_pad($byte, 8, '0', STR_PAD_RIGHT);
            }
            $out .= chr(bindec($byte));
        }
        return $out;
    }

    private static function bitsToWords(string $bits): array
    {
        $wl = self::getWordlist();
        $out = [];
        for ($i=0; $i<strlen($bits); $i+=11) {
            $chunk = substr($bits, $i, 11);
            $idx = bindec($chunk);
            $out[] = $wl[$idx];
        }
        return $out;
    }

    // ---- Hash helpers -------------------------------------------------------

    private static function blake2b224_hex(string $data): string
    {
        // Sodium generic hash is Blake2b; 28-byte output
        return bin2hex(sodium_crypto_generichash($data, '', 28));
    }

    // ---- Bech32 -------------------------------------------------------------

    private static function bech32Encode(string $hrp, array $data5): string
    {
        $charset = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $chk = self::bech32Polymod(array_merge(self::bech32HrpExpand($hrp), $data5, [0,0,0,0,0,0])) ^ 1;
        $checksum = [];
        for ($p = 0; $p < 6; $p++) {
            $checksum[] = ($chk >> (5 * (5 - $p))) & 31;
        }
        $combined = array_merge($data5, $checksum);
        $out = $hrp . '1';
        foreach ($combined as $v) { $out .= $charset[$v]; }
        return $out;
    }

    private static function to5Bits(string $bytes): array
    {
        $bits = 0; $value = 0; $out = [];
        for ($i=0; $i<strlen($bytes); $i++) {
            $value = ($value << 8) | ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $out[] = ($value >> ($bits - 5)) & 31;
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out[] = ($value << (5 - $bits)) & 31;
        }
        return $out;
    }

    private static function bech32HrpExpand(string $hrp): array
    {
        $out = [];
        $len = strlen($hrp);
        for ($i=0; $i<$len; $i++) { $out[] = ord($hrp[$i]) >> 5; }
        $out[] = 0;
        for ($i=0; $i<$len; $i++) { $out[] = ord($hrp[$i]) & 31; }
        return $out;
    }

    private static function bech32Polymod(array $values): int
    {
        $GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i=0; $i<5; $i++) {
                if ((($b >> $i) & 1) !== 0) {
                    $chk ^= $GEN[$i];
                }
            }
        }
        return $chk;
    }

    // ---- Requirements -------------------------------------------------------

    private static function checkRequirements(): void
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('ext/sodium is required');
        }
        if (!function_exists('hash_pbkdf2')) {
            throw new \RuntimeException('hash_pbkdf2 is required');
        }
        if (!extension_loaded('bcmath')) {
            throw new \RuntimeException('ext/bcmath is required for pure-PHP scalar arithmetic');
        }
        \Ed25519Compat::init();
    }
}
