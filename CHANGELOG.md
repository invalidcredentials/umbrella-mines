# Changelog

All notable changes to Umbrella Mines will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.20.68] - 2025-01-12

### Fixed

#### Accurate NIGHT Estimation from Challenge Data
- **Challenge ID Parsing** - Import processor now extracts day numbers from challenge IDs (e.g., `**D14C24` → Day 14)
- **Day-Specific Rate Fetching** - Fetches work_to_star_rate for each mining day from API endpoint `/work_to_star_rate`
- **Per-Day Calculation** - Calculates NIGHT accurately: `(solutions × work_to_star_rate) / 1,000,000` for each day
- **Breakdown Display** - Import preview shows detailed breakdown: day, solutions, rate, and NIGHT earned per day
- **Correct Formula** - Fixed calculation that was incorrectly dividing by 1,000,000 without using actual daily rates

### Technical Details

**Problem:** Previous NIGHT estimation used current challenge's work_to_star_rate and incorrect formula, showing 0.40 NIGHT instead of 4,320 NIGHT for 397 Day 14 solutions.

**Solution:** Parse `challenge_submissions` object from Night Miner wallet.json to extract day numbers and count solutions per day. Fetch all daily rates from API and calculate accurately.

**Example Calculation:**
- Day 14: 397 solutions × 10,882,519 STAR/solution = 4,320,360,043 STAR ÷ 1,000,000 = 4,320.36 NIGHT ✓

**Files Modified:**
- `includes/class-import-processor.php` - Replaced `calculate_night_estimate()` with `calculate_night_estimate_from_challenges()`
- `admin/merge-addresses.php` - Added breakdown table showing per-day NIGHT calculation

**API Endpoint Used:**
- `GET /work_to_star_rate` - Returns array of daily conversion rates

---

## [0.4.20.67] - 2025-01-12

### Added

#### Night Miner Import Support (Standard Ed25519 Keys)
- **Standard Ed25519 Signing** - Added `sign_standard()` method to support 32-byte seed keys from Night Miner exports
- **Automatic Key Detection** - System now auto-detects 64-char (Night Miner) vs 128-char (extended) keys and uses appropriate signing method
- **RFC 8032 Compliance** - Proper SHA-512 expansion, scalar clamping, and public key derivation for standard Ed25519 keys
- **Import Compatibility** - Drag-and-drop imports from Night Miner now work with correct signature validation

#### Merge Progress Enhancements
- **Real-Time Progress Updates** - Import progress now updates every 2 wallets (small batches) or every 5 wallets (large batches)
- **Merge All Loading State** - Added animated progress bar with live counts when merging wallets
- **UI Lockout** - Prevents accidental page refresh or double-clicks during merge operations
- **Success/Fail Counters** - Live display of successful and failed merge operations

### Changed
- **Auto-Submit Default** - Solution auto-submission now **enabled by default** (previously off)
  - Dashboard checkboxes default to checked if not explicitly disabled
  - WP-CLI mining defaults to auto-submit unless set to '0'
  - Prevents "lost solutions" where pending solutions never get submitted

### Fixed
- **Night Miner Signature Validation** - Public key derivation now matches Night Miner's format, resolving 400 Bad Signature errors
- **Import Progress Jumps** - No more 0% → 100% jumps; smooth progress for all batch sizes
- **Merge Button Feedback** - Clear visual feedback during wallet consolidation operations

### Technical Details

**Files Modified:**
- `includes/vendor/Ed25519Compat.php` - Added sign_standard() for RFC 8032 Ed25519
- `includes/vendor/CardanoCIP8Signer.php` - Auto-detect key type and route to correct signer
- `admin/dashboard-live.php` - Auto-submit checkbox defaults to checked
- `admin/dashboard.php` - Auto-submit checkbox defaults to checked
- `includes/class-wp-cli-commands.php` - Auto-submit default behavior changed
- `includes/class-import-processor.php` - Adaptive progress update frequency
- `admin/merge-addresses.php` - Added merge progress UI container with animations
- `.gitignore` - Excluded test files and backup examples

**Breaking Changes:** None - fully backward compatible

**Migration Notes:** Existing users will see auto-submit automatically enabled on next dashboard load. This prevents the common issue of solutions remaining in "pending" state indefinitely.

---

## [0.4.20.6] - 2025-11-12

### Added

#### BCMath Extension Requirement Documentation
- **README Requirements Update** - Added BCMath PHP extension to requirements with installation instructions
- **Version-Specific Install Commands** - Documented need to install bcmath for Apache's PHP version (e.g., `php8.2-bcmath`)
- **CLI vs Apache PHP Note** - Clarified that CLI and Apache may use different PHP versions requiring separate installs

#### Database Schema Validation
- **Column Status Checker** - Create Tables page now validates critical columns added in v0.4.20
- **Visual Status Indicators** - Green checkmarks for existing columns, red X for missing
- **Mnemonic Field Validation** - Checks `mnemonic_encrypted` columns in wallets, solutions, payout_wallet, and merges tables
- **Anti-Daisy-Chain Validation** - Verifies `used_as_payout` flag exists in wallets table
- **Migration Guidance** - Clear instructions to run "Force Update Schema" if columns missing

#### Merge Page Troubleshooting System
- **Collapsible Troubleshooting Guide** - Expandable section under "How It Works" with comprehensive debugging steps
- **Requirement Checklist** - Lists all 4 requirements: v0.4.20+ wallet, registration, receipts, BCMath extension
- **Quick Diagnostic Steps** - Database checks, wallet mnemonic verification, BCMath installation, clean reinstall instructions
- **Always-Accessible Import** - Import wallet form available even when no payout wallet exists
- **AJAX-Based Import** - Smooth import experience with status messages and automatic page reload on success

### Fixed
- **Payout Wallet Grey-Out** - Auto-selected wallets from mining_wallets table now properly greyed out on Solutions page
- **Import Form Handler** - Changed from POST to AJAX to prevent page refresh issues
- **Emoji Text Overlap** - Fixed "Start Mining First" text overlapping merge icon with proper line-height
- **Dashboard Link** - Corrected "Go to Mining" button to properly link to Dashboard page

### Technical Details

**Files Modified:**
- `umbrella-mines.php` - Version bump to 0.4.20.6
- `README.md` - BCMath requirement documentation
- `admin/solutions.php` - Grey-out logic for auto-selected payout wallets
- `admin/create-table.php` - Column validation UI
- `admin/merge-addresses.php` - Troubleshooting guide and import form improvements

**User Experience Improvements:**
- Users stuck on "No Registered Wallet" screen now have clear troubleshooting path
- Database schema issues can be diagnosed without SSH access
- Import functionality accessible regardless of payout wallet state
- Better error messages guide users to solutions

---

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
