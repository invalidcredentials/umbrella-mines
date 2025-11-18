# Changelog

All notable changes to Umbrella Mines will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.20.70] - 2025-11-18

### Fixed
- **Critical import bug** - Fixed Umbrella Mines JSON import rejecting 50% of wallets due to missing mnemonic validation
  - Mnemonic is now optional for importing existing solutions (only address and solutions required)
  - Wallets with solutions but no mnemonic can now be imported successfully
  - If mnemonic is present, it will be stored; if not, import proceeds without it
- **Database migrations** - Added missing columns to import_sessions table:
  - `wallet_ids_json` column for storing session wallet data
  - `created_at` and `updated_at` timestamp columns
- **Debug logging** - Added detailed logging for import process to track wallet filtering

### Technical Details
- Removed `empty($wallet['mnemonic'])` validation check from Umbrella JSON import parsing
- Solutions with receipts don't require mnemonics to be consolidated/merged to payout wallet
- Import sessions table now fully compatible with both Night Miner and Umbrella Mines exports

## [0.4.20.69-hotpocket] - 2025-11-15

### Added - NIGHT Tracking Hot-Improvement 🌙

#### Complete NIGHT Value Tracking System
- **Per-wallet NIGHT calculation** - Accurately calculates NIGHT value for each imported wallet based on challenge submissions and day-specific work_to_star rates
- **Database persistence** - Added `night_value` column to merges table with automatic migration for existing installations
- **Smart re-upload detection** - Detects already-merged wallets and updates NIGHT values without re-submitting to API
- **Total NIGHT display** - Payout wallet statistics now show total NIGHT with breakdown: `Mined: X | Imported: Y`
- **Purple notice on re-upload** - Shows when addresses are already merged but missing NIGHT values: "Merge will UPDATE NIGHT totals ONLY"
- **Merge history NIGHT column** - Added purple NIGHT value display to merge history table for complete audit trail
- **Retroactive calculation support** - Re-uploading original wallet.json files fills in missing NIGHT values for old imports

#### Enhanced UI Polish
- **Rounded card corners** - Import sections now have 8px border-radius matching plugin aesthetic
- **Gradient toggle switches** - Beautiful dark gradient toggles with cyan borders and hover glows
- **Active toggle effects** - Green-to-cyan gradient with outer glow when enabled
- **Dashboard Live payout stats** - Replaced "Average Per Wallet" with "Total NIGHT" across all dashboard pages

### Technical Details
- **API rate fetching** - Fetches work_to_star_rate from Scavenger API for accurate day-specific calculations
- **Challenge parsing** - Extracts day from challenge IDs (e.g., `**D14C24` → Day 14) for precise NIGHT calculation
- **Formula**: `NIGHT = (solutions × work_to_star_rate) / 1,000,000` per challenge

## [0.4.20.69] - 2025-01-13

### Added

#### Umbrella Mines JSON Import Support
- **Import from other Umbrella Mines instances** - Import wallets + solutions from other Umbrella Mines installations via JSON export files
- **Automatic payout wallet filtering** - Skips all payout wallets from PAYOUT_WALLET section to prevent daisy-chaining between instances
- **Duplicate detection** - Automatically skips wallets that have already been successfully merged to prevent redundant API calls
- **NIGHT calculation compatibility** - Uses same challenge ID parsing logic as Night Miner imports for accurate NIGHT estimation
- **Import preview UI** - Shows wallet count, skipped duplicates count, and excluded payout wallets before import confirmation

#### Session-Based Chunked Merge Processing
- **20-wallet chunks** - Processes merges in batches of 20 to avoid 60-second server timeout (was timing out after ~60 wallets)
- **Real-time progress tracking** - Displays accurate progress like "Chunk 3/22 complete - 60/435 wallets merged (13.8%)" instead of fake 0% → 90% → 100%
- **Automatic resume capability** - Database-backed sessions automatically recover from server hiccups or timeouts
- **Live success/fail counters** - Real-time display of successful merges, failed merges, and total processed
- **Database session management** - New `umbrella_mining_merge_sessions` table tracks progress, wallet IDs, and status

#### Enhanced Export System for Instance Transfer
- **Complete payout wallet export** - Exports ALL payout wallets (active, imported, historical) in dedicated PAYOUT_WALLET section
- **Clearly marked payout addresses** - Payout wallets flagged with status (ACTIVE/IMPORTED/HISTORICAL) to prevent accidental import
- **Import compatibility** - JSON structure designed for seamless import into other Umbrella Mines instances
- **Anti-daisy-chain protection** - Import system uses PAYOUT_WALLET section to filter out payout addresses

#### Payout Wallet Statistics Dashboard
- **Lifetime merge statistics** - Added comprehensive stats display to both Dashboard and Merge Addresses pages
- **Per-wallet breakdown** - Shows total merged wallets, total merged solutions, average per wallet, last merge timestamp
- **Collapsible historical wallets** - Primary payout wallet shown by default, others hidden in expandable section
- **Beautiful gradient UI** - Matrix-themed cards with green/cyan gradients matching plugin design
- **Database query optimization** - New `get_payout_wallet_stats()` method queries merge history efficiently

### Improved

#### Merge Addresses Page UX
- **Fixed View button on AJAX pages** - View button now works on pages 2, 3, etc. (was only working on page 1 after refresh)
- **Delegated event binding** - Changed from direct `.on('click')` to `$(document).on('click')` for dynamically-loaded rows
- **Smooth AJAX pagination** - All merge row interactions work consistently across paginated results

#### Solutions Page Responsive Design
- **Action button stacking** - Buttons stack vertically on screens < 1600px instead of overflowing horizontally
- **Compact actions column** - Actions column shrinks to 100px width when buttons are stacked (was 350px)
- **Address truncation with ellipsis** - Long addresses show "addr1q8h3as..." with full address on hover popup
- **Compact payout wallet indicator** - Grey "PAYOUT WALLET" box uses smaller padding and font on small screens
- **No more vertical text** - Table headers stay horizontal at all screen sizes with `white-space: nowrap`
- **Responsive breakpoints** - Optimized for 13", 15" laptops and tablets without layout breakage

### Fixed
- **Server timeout on bulk merge** - Chunked processing prevents 60-second PHP execution timeout when merging hundreds of wallets
- **Lost merge progress** - Session system preserves progress and allows resumption after timeout or server hiccup
- **Inaccurate progress bar** - Replaced fake animated progress with real chunk-based tracking
- **View button AJAX issue** - Fixed event binding to work on dynamically-loaded pagination pages
- **Address vertical explosion** - Fixed addresses going character-by-character vertically on small screens
- **Grey box overflow** - Payout wallet indicator now scales properly on all screen sizes

### Technical Details

**New Database Tables:**
- `wp_umbrella_mining_merge_sessions` - Tracks chunked merge progress (session_key, payout_address, total_wallets, processed_wallets, successful_count, wallet_ids_json, status)

**New Methods:**
- `Umbrella_Mines_Merge_Processor::create_merge_session()` - Creates database session for chunked merge
- `Umbrella_Mines_Merge_Processor::process_merge_chunk()` - Processes 20 wallets per chunk via AJAX
- `Umbrella_Mines_Merge_Processor::get_payout_wallet_stats()` - Queries lifetime merge statistics per payout address
- `ajax_parse_umbrella_json()` - Parses Umbrella Mines JSON exports with payout filtering and duplicate detection

**New AJAX Endpoints:**
- `umbrella_create_merge_session` - Initializes chunked merge operation
- `umbrella_process_merge_chunk` - Processes next 20 wallets in merge queue
- `umbrella_get_merge_session_status` - Retrieves current merge progress
- `umbrella_parse_umbrella_json` - Validates and parses Umbrella Mines JSON imports

**Files Modified:**
- `umbrella-mines.php` - Version bump to 0.4.20.69, AJAX handler registration, merge sessions table schema
- `includes/class-merge-processor.php` - Chunked merge methods, stats query, improved error handling
- `includes/class-import-processor.php` - Made `calculate_night_estimate_from_challenges()` public for JSON import
- `admin/merge-addresses.php` - Chunked merge UI, import preview enhancements, payout stats display, View button fix
- `admin/dashboard-live.php` - Payout wallet statistics with collapsible section for historical wallets
- `admin/solutions.php` - Responsive CSS for action buttons, address truncation, compact payout indicator
- `README.md` - Version badge updated to 0.4.20.69

**Performance Improvements:**
- Merge operations complete in <10 seconds per chunk instead of timing out after 60 seconds
- Database sessions enable resumption without re-processing completed wallets
- Statistics queries use indexed columns for fast retrieval

**User Experience Wins:**
- "it even froze (server hiccup) and restarted perfectly by design" - User feedback on resume capability
- "its smooth af now" - User feedback on AJAX pagination fix
- "the status bar works GREAT nice work on that" - User feedback on real progress tracking
- "THIS IS FUCKING FIRE" - User feedback on collapsible payout wallet stats

---

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
