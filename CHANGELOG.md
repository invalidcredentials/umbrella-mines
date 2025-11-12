# Changelog

All notable changes to Umbrella Mines will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.20] - 2025-11-12

### Added

#### Merge Address Management System
- **Complete merge flow implementation** - Full workflow for consolidating mining rewards from multiple wallets into a single payout address
- **Merge statistics dashboard** - Real-time overview of total wallets, eligible wallets, merged wallets, and pending consolidations
- **Recent merge history** - View last 10 merge operations with status, timestamp, and solution counts
- **Bulk merge operations** - Merge all eligible wallets in a single operation
- **Export merged data** - Export all successfully merged wallet data for record-keeping

#### Custom Wallet Import Functionality
- **Import from external wallets** - Bring existing Cardano wallets from Eternl, Nami, Typhon, or any BIP39-compatible wallet
- **Custom derivation path support** - Specify exact derivation path (account/chain/address format) for imported wallets
- **Automatic path detection** - System scans common derivation paths (0/0/0 through 0/0/9) to find registered wallets in database
- **Registration verification** - Validates imported wallet is registered with Midnight Scavenger Mine API before acceptance
- **Smart path fallback** - If wallet not found in database, uses user-provided derivation path with warning
- **`fromMnemonicWithPath()` method** - New CardanoWalletPHP method for deriving wallets from existing mnemonics with custom paths

#### Encrypted Mnemonic Storage
- **Database encryption** - All mnemonics and extended private keys encrypted before storage using `UmbrellaMines_EncryptionHelper`
- **Secure key derivation** - Encrypted keys enable wallet recovery without exposing sensitive data in database
- **Export with recovery data** - Exported wallets include encrypted mnemonics for full wallet restoration
- **Merge signature generation** - Encrypted storage enables signing merge transactions from payout wallet

#### Auto Wallet Selection
- **Smart payout wallet selection** - Automatically selects best payout wallet based on priority system:
  1. User-imported payout wallet (highest priority)
  2. Auto-selected mining wallet with submitted solutions (fallback)
- **Prevents selection errors** - System ensures payout wallet exists before merge operations
- **Status indicators** - Clear visual feedback showing which wallet is active for payouts

#### Anti-Daisy-Chain Protection
- **Wallet lock mechanism** - Wallets used as payout addresses cannot be merged again
- **Rules compliance** - Prevents daisy-chaining merge operations as per Midnight Scavenger Mine rules
- **Database flags** - `used_as_payout` flag tracks wallet usage to enforce restrictions
- **Automatic validation** - System checks wallet eligibility before allowing merge operations

### Improved

#### Enhanced Export System
- **Wallet recovery data** - Exports now include encrypted mnemonics for full wallet restoration capability
- **Complete solution records** - All submitted/confirmed solutions exported with crypto receipts
- **Merge operation history** - Export includes merge signatures, receipts, and status for audit trail
- **Structured JSON format** - Wallet-centric export format with nested solutions and receipts for easy parsing

#### Merge Flow Validation
- **Registration status checks** - API validation accepts 200/201/409 status codes (idempotent registration)
- **Error handling improvements** - Clear error messages for unregistered wallets, failed merges, and API issues
- **Transaction receipts** - Merge operations return crypto receipts as proof of consolidation

### Fixed
- **Derivation path handling** - Fixed wallet import to use provided mnemonic instead of generating random addresses
- **Registration API compliance** - Updated to accept 201 status code (API's idempotent registration behavior)
- **Wallet selection edge cases** - Resolved issues with empty payout wallet scenarios

### Technical Details

**New Database Fields:**
- `wp_umbrella_mining_payout_wallet.mnemonic_encrypted` - Encrypted 24-word recovery phrase
- `wp_umbrella_mining_payout_wallet.payment_skey_extended_encrypted` - Encrypted extended private key
- `wp_umbrella_mining_wallets.used_as_payout` - Flag preventing daisy-chain merges

**New Classes/Methods:**
- `CardanoWalletPHP::fromMnemonicWithPath()` - Derive wallet from existing mnemonic with custom path
- `Umbrella_Mines_Merge_Processor` - Handles merge operations and API communication
- `UmbrellaMines_EncryptionHelper::encrypt()` / `decrypt()` - Symmetric encryption for sensitive data

**API Endpoints Used:**
- `GET /merge/{original_address}/{payout_address}/{signature}` - Submit merge consolidation request

---

## [0.3.0] - 2025-11-04

### Added
- **Smart Export System** - One-click export of all mining data with completion validation
- **System Requirements Checker** - Automatic validation of dependencies on dashboard load
- **Enhanced UI/UX** - Matrix-themed design with improved visual feedback
- **Export warnings** - Alerts for pending solutions requiring submission before export
- **Collapsible requirements panel** - Detailed status for PHP version, FFI, libraries, permissions

### Improved
- **Dashboard interface** - Better spacing, color matching, and visual hierarchy
- **Export functionality** - Identical export on both Wallets and Solutions pages
- **Warning modals** - Styled modals for better user experience

---

## [0.2.0] - 2025-10-28

### Added
- **Auto-submit toggle** - Automatically submit solutions to Midnight API when found
- **Live terminal output** - Real-time mining progress with cyberpunk-styled terminal
- **Graceful stop mechanism** - Three-checkpoint stop system for clean process termination
- **JSON backups** - Redundant file-based backups for solutions and wallets

### Improved
- **Process management** - Better handling of background mining processes
- **Database indexing** - Optimized queries for faster wallet and solution lookups

---

## [0.1.0] - 2025-10-15

### Added
- **Initial release** - One-click NIGHT token mining on WordPress
- **PHP FFI integration** - Near-native hashing performance with compiled Rust library
- **Cardano HD wallet generation** - Full BIP39/BIP32 implementation
- **CIP-8 message signing** - Proper Cardano address registration
- **Midnight Scavenger API** - Complete implementation of all 5 required endpoints
- **Database management** - Tables for wallets, solutions, receipts, configuration
- **WordPress admin interface** - Dashboard, Solutions, Wallets, Manual Submit, Create Table pages

---

## Version Numbering

- **Major version (X.0.0)** - Breaking changes, major architectural updates
- **Minor version (0.X.0)** - New features, enhancements, non-breaking changes
- **Patch version (0.0.X)** - Bug fixes, minor improvements
