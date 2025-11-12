# UMBRELLA MINES $NIGHT MINER

![Version](https://img.shields.io/badge/version-0.4.20-blue.svg)
![Platform](https://img.shields.io/badge/platform-Windows%20%7C%20Linux%20%7C%20macOS-lightgrey.svg)
![PHP](https://img.shields.io/badge/PHP-8.3.17-777BB4.svg)

**A plug-and-play NIGHT mining solution built entirely on WordPress.**

Mine Cardano Midnight NIGHT tokens with zero code required. One-click installation, one-click mining. Cross-platform support for Windows, Linux, and macOS. That's it.

---

## IMPORTANT - BETA SOFTWARE & HOSTING REQUIREMENTS

**This plugin is currently in BETA.** While functional and production-ready, it has specific hosting requirements that determine whether it will work in your environment.

### Confirmed Working Environments:
- **[Local by Flywheel](https://localwp.com/)** - 100% compatibility (RECOMMENDED for easiest setup)
- **Dedicated Servers** - Full compatibility with proper PHP configuration
- **VPS Hosting** - 99% compatibility (DigitalOcean, Linode, Vultr, AWS, etc.)

### Shared Hosting - Will Likely NOT Work:
Shared hosting providers typically **block or kill** long-running PHP processes to protect their infrastructure. Common issues:
- Process execution disabled or severely limited
- FFI extension disabled for security
- Memory limits too low (need 2GB+ for ROM)
- PHP CLI not available or restricted
- WP-CLI access blocked

**Bottom line:** If you're on shared hosting (GoDaddy, Bluehost, HostGator, etc.), this plugin will probably not work.

### Easiest Way to Get Started:

**Don't have a suitable environment? No problem!**

1. Download **[Local by Flywheel](https://localwp.com/)** (100% FREE)
2. Create a new WordPress site (takes 2 minutes)
3. Install this plugin
4. Start mining

**You don't even need a real website** - Local runs entirely on your machine. It's the fastest path from zero to mining.

**System Requirements:**
- Windows, Linux, or macOS
- **PHP 8.3.17 EXACTLY** (required for Rust FFI compatibility)
- FFI extension enabled
- **IMPORTANT:** PHP version MUST match between PHP-CLI and php.ini configuration
  - If using Local by Flywheel, ensure site's PHP version matches lightning-services PHP version
  - Mismatched versions will cause "Error establishing a database connection"
- 2GB+ available RAM
- WP-CLI access (included with Local by Flywheel)
- AshMaize library for your platform (Windows: .dll, Linux: .so, macOS: .dylib)

---

## What Is This?

Umbrella Mines is a WordPress plugin that turns any WordPress site into a fully functional Midnight Scavenger Mine miner. It mines NIGHT tokens by solving cryptographic puzzles using the AshMaize hash function - the same challenge that powers the Midnight blockchain's proof-of-work system.

**The catch?** It's built entirely on WordPress. PHP. Old tech. The platform that powers 43% of the web.

**The result?** A mining solution that runs anywhere WordPress runs - shared hosting, VPS, local dev environments, even mobile devices. No Docker containers, no complex setup, no command-line kung-fu required.

---

## Why WordPress?

Because WordPress is:
- **Ubiquitous** - Already installed on millions of servers worldwide
- **Accessible** - Anyone can set it up, regardless of technical background
- **Free** - No hosting costs if you're already running WordPress
- **Battle-tested** - Decades of stability and compatibility

This started as an experiment: "What can we build with old tech and lightweight code?" The answer turned out to be a competitive, production-ready mining solution that stands shoulder-to-shoulder with specialized Rust implementations.

---

## Features

### One-Click Mining
No configuration. No command-line. Just click "START MINING" and watch it work.

### Smart Export System (NEW in v0.3.0)
- Export all your mining data (wallets, solutions, receipts) in one click
- Only exports completed work (submitted/confirmed solutions)
- Warns you if pending solutions need submission first
- Wallet-centric JSON structure with all critical data
- Contains private keys and crypto receipts for claiming NIGHT rewards
- Identical export functionality on Wallets and Solutions pages

### System Requirements Checker (NEW in v0.3.0)
- Automatic validation of all dependencies on dashboard load
- Checks PHP version (8.3.17), FFI extension, libraries, and permissions
- Collapsible panel with detailed status for each requirement
- Platform-specific installation instructions for missing dependencies
- Prevents wasted time troubleshooting - know what's missing instantly
- Special support for Linux/Ubuntu stripped installations

### Auto-Submit Solutions
Toggle auto-submission to automatically send found solutions to the Midnight Scavenger API. Or review and submit them manually - your choice.

### Enhanced UI/UX (NEW in v0.3.0)
- Matrix-themed design with floating umbrella icons
- Improved Start/Stop mining buttons with better spacing and visual feedback
- Color-matched export buttons to green theme
- Styled warning modals for better user experience
- Consistent cyberpunk aesthetic across all pages

### Merge Address Management (NEW in v0.4.20)
Complete reward consolidation system for merging mining outputs from multiple wallets into a single payout address.

- Merge statistics dashboard with real-time wallet counts and eligibility status
- Bulk merge all eligible wallets in one operation
- Recent merge history showing last 10 operations with status and timestamps
- Export all merged wallet data for auditing and record-keeping
- Anti-daisy-chain protection enforces Midnight rules (wallets used as payout cannot be re-merged)
- CIP-8 signed merge transactions with crypto receipt proofs

### Custom Wallet Import (NEW in v0.4.20)
Import existing Cardano wallets from other platforms (Eternl, Nami, Typhon) for use as payout addresses.

- Paste 24-word mnemonic from any BIP39-compatible wallet
- Optional custom derivation path (account/chain/address format)
- Automatic path detection scans common paths (0/0/0 through 0/0/9)
- Registration verification with Midnight Scavenger Mine API
- Smart fallback to user-provided path with warning if not found in database
- Encrypted mnemonic storage for secure wallet recovery

### Encrypted Wallet Storage (NEW in v0.4.20)
All sensitive wallet data encrypted before database storage.

- 24-word mnemonic recovery phrases encrypted at-rest
- Extended private keys encrypted using symmetric encryption
- Database breach does not expose plaintext mnemonics
- Full wallet recovery capability from encrypted exports
- Enables secure merge transaction signing from stored payout wallet

### Auto Wallet Selection (NEW in v0.4.20)
Intelligent payout wallet selection for merge operations.

- Priority system: imported payout wallet first, then auto-selected mining wallet
- Automatic detection of best payout candidate
- Status indicators show active payout wallet
- Prevents merge errors from missing payout configuration

### Smart Start/Stop Controls
Graceful process management with real-time status updates. Stop mining instantly with proper cleanup - no orphaned processes.

### Live Terminal Output
Watch your miner work in real-time with a cyberpunk-styled terminal showing hash rates, progress, and solutions found.

### Comprehensive Management
- **Solutions Tab** - View all mined solutions, submission status, and receipts
- **Wallets Tab** - Track all generated Cardano wallets and their registration status
- **Manual Submit** - Submit solutions individually with full control
- **Create Table** - Initialize database tables with one click

### High Performance
- **PHP FFI** integration with compiled Rust `ashmaize_capi.dll` for near-native hashing performance
- **~1000 hashes/second** on consumer hardware
- **Efficient wallet generation** using native PHP crypto libraries

### Battle-Tested Cryptography
- Full Cardano HD wallet derivation (BIP39/BIP32)
- CIP-8 message signing for address registration
- Ed25519 signature verification
- Secure random nonce generation

---

## Installation

### Requirements
- **WordPress** 5.0+
- **PHP** 8.0+ with FFI extension enabled
  - **CRITICAL:** PHP executable version and php.ini configuration MUST match exactly
  - Common error: Using PHP 8.3.17 executable with PHP 8.2.27 ini file causes database connection failures
  - Solution: Update site's PHP version in Local to match your lightning-services PHP version
- **Platform Support:** Windows, Linux, macOS
- **Pre-compiled Libraries:** Included for Windows (.dll), Linux (.so), macOS (.dylib)

### Steps

1. **Download the plugin**
   - Clone or download this repository
   - Place the `umbrella-mines` folder in your WordPress `wp-content/plugins/` directory

2. **Activate the plugin**
   - Go to WordPress Admin > Plugins
   - Find "Umbrella Mines" and click "Activate"

3. **Create database tables**
   - Go to Umbrella Mines > Create Table
   - Click "Initialize Database Tables"
   - Tables will be created automatically

4. **Start mining**
   - Go to Umbrella Mines > Dashboard
   - (Optional) Check "Auto-submit solutions" to automatically submit found solutions
   - Click "START MINING"
   - That's it. Seriously.

---

## Compiling Libraries (Optional)

Pre-compiled libraries for Windows, Linux, and macOS are included in the `bin/` directory. However, if you need to compile them yourself:

### Automatic Compilation (GitHub Actions)

Every push to the repository automatically compiles libraries for all platforms via GitHub Actions:

1. Libraries are built for Windows (.dll), Linux (.so), and macOS (.dylib)
2. Download from the latest GitHub Actions run artifacts
3. SHA256 hashes are generated for verification
4. Or grab from the latest GitHub Release

### Manual Compilation

**Requirements:**
- Rust toolchain installed (`rustup` from https://rustup.rs)

**Steps:**
```bash
cd rust-wrapper
cargo build --release

# The compiled library will be at:
# Windows: target/release/ashmaize_capi.dll
# Linux:   target/release/libashmaize_capi.so
# macOS:   target/release/libashmaize_capi.dylib

# Copy to bin/ directory:
cp target/release/libashmaize_capi.{so,dylib,dll} ../bin/ashmaize_capi.{so,dylib,dll}
```

**Cross-Platform Compilation:**

Linux and macOS libraries can be cross-compiled from Windows using WSL or Docker, but the easiest method is to use GitHub Actions (automatic) or compile natively on each platform.

---

## Usage

### Dashboard
The main control center with a live terminal view:

- **Max Attempts per Wallet** - Number of nonces to try before generating a new wallet (default: 500,000)
- **Derivation Path** - Custom wallet derivation path in format `account/chain/address` (default: 0/0/0)
- **START MINING** - Begin mining with current settings
- **STOP MINING** - Gracefully stop the mining process
- **Auto-submit solutions** - Toggle to automatically submit solutions to the API

The terminal shows live output including:
- Configuration and derivation paths
- Challenge information (difficulty, ID, no_pre_mine ROM)
- Wallet generation and API registration
- Mining progress with hash rate and ETA
- Solutions found and submission status

### Solutions Tab
View and manage all mined solutions:
- Solution details (nonce, preimage, hash, difficulty)
- Submission status (pending, submitted, confirmed, failed)
- Manual submission controls with one-click submit buttons
- Receipt viewing and management
- Reset status or delete solutions

### Wallets Tab
Track all generated Cardano wallets:
- Wallet addresses and derivation paths
- Registration status and timestamps
- Payment public keys and key hashes
- Solution counts per wallet
- Network (mainnet/testnet)

### Manual Submit
Submit individual solutions with full visibility:
- Select challenge and wallet from dropdowns
- Enter nonce and preimage manually
- Submit directly to the Midnight Scavenger API
- View real-time API responses (success or error messages)
- 3-minute timeout for slow API responses

### Create Table
One-click database initialization:
- Creates all required tables
- Sets up indexes for performance
- Initializes configuration with default API URL
- Safe to run multiple times (checks for existing tables)

---

## How It Works

### Architecture

```
umbrella-mines/
├── admin/                          # WordPress admin UI
│   ├── dashboard-live.php          # Main mining dashboard
│   ├── solutions.php               # Solutions management
│   ├── wallets.php                 # Wallet management
│   ├── manual-submit.php           # Manual solution submission
│   ├── merge-addresses.php         # Merge address management (NEW v0.4.20)
│   ├── create-table.php            # Database initialization
│   └── admin-styles.php            # Shared admin styles
│
├── includes/                       # Core functionality
│   ├── class-wp-cli-commands.php   # WP-CLI mining engine
│   ├── class-ashmaize-ffi.php      # FFI wrapper for Rust hasher
│   ├── class-scavenger-api.php     # Midnight API client
│   ├── class-merge-processor.php   # Merge operation handler (NEW v0.4.20)
│   ├── class-payout-wallet.php     # Payout wallet management (NEW v0.4.20)
│   ├── start-stop-handler.php      # Process management
│   └── vendor/                     # Cardano crypto libraries
│       ├── CardanoWalletPHP.php    # HD wallet generation (BIP39/BIP32)
│       ├── CardanoCIP8Signer.php   # CIP-8 message signing
│       ├── Ed25519Compat.php       # Ed25519 crypto compatibility
│       ├── Ed25519Pure.php         # Pure PHP Ed25519 implementation
│       ├── UmbrellaMines_EncryptionHelper.php  # Encryption utilities (NEW v0.4.20)
│       └── bip39-wordlist.php      # BIP39 mnemonic wordlist
│
├── bin/
│   └── ashmaize_capi.dll           # Compiled Rust hasher (Windows)
│
├── ffi/
│   └── ashmaize_capi.h             # C header for FFI interface
│
└── umbrella-mines.php              # Main plugin file
```

### Mining Process

1. **Initialization**
   - Plugin activates and creates database tables for wallets, solutions, receipts, and config
   - FFI extension loads the compiled Rust `ashmaize_capi.dll` hasher

2. **Starting Mining**
   - User clicks "START MINING" on dashboard
   - PHP creates a Windows batch file with WP-CLI command
   - Batch file executes in background using `pclose(popen())` for non-blocking execution
   - Output streams to `wp-content/umbrella-mines-output.log`
   - Dashboard refreshes log every 5 seconds to show live terminal output

3. **Mining Loop** (per wallet)
   - Generate new Cardano HD wallet using BIP39 mnemonic and BIP32 derivation
   - Sign Terms & Conditions message with CIP-8 signature
   - Register wallet address with Midnight Scavenger API
   - Fetch current challenge (difficulty, no_pre_mine ROM, challenge_id, etc.)
   - Initialize AshMaize FFI hasher with 1GB ROM data (~1-2 seconds)
   - **Nonce Mining Loop:**
     - Generate random 8-byte nonce using `random_bytes()`
     - Build preimage: `nonce + address + challenge_id + difficulty + no_pre_mine + latest_submission + no_pre_mine_hour`
     - Hash preimage using FFI call to Rust `ashmaize_hash()`
     - Check if hash meets exact difficulty target (leading zero bits)
     - Update progress every 1000 nonces (hash rate, ETA, percentage)
     - **Check for stop signal every 1000 nonces**
   - When solution found:
     - Save to database with all metadata (wallet_id, nonce, preimage, hash, difficulty)
     - Create JSON backup in `wp-content/uploads/umbrella-mines/solution_backups/`
     - If auto-submit enabled:
       - Immediately POST to `/solution/{address}/{challenge_id}/{nonce}`
       - Parse crypto receipt from API response
       - Save receipt to `wp_umbrella_mining_receipts` table
       - Update solution status to 'submitted'
     - If auto-submit disabled, solution stays 'pending' for manual submission
   - Move to next wallet and repeat

4. **Stopping Mining**
   - User clicks "STOP MINING" button
   - Creates `wp-content/umbrella-mines-stop.flag` file
   - Miner checks for flag at three checkpoints:
     - **Before starting new wallet** - Fast stop between wallets
     - **Before each mining round** - Stop before loading new ROM (~1-2 seconds)
     - **Every 1000 nonces during mining** - Stop mid-mining within seconds
   - On flag detection:
     - Deletes flag file to prevent re-triggering
     - Prints "Stop signal received. Mining stopped gracefully."
     - Calls `exit(0)` for immediate process termination
   - No orphaned processes - clean shutdown every time

---

## Midnight Scavenger Mine API Compliance

Umbrella Mines fully implements **all 5 required endpoints** from the official [Midnight Scavenger Mine API specification](https://docs.midnight.network/develop/scavenger-mine/api). This ensures complete compatibility with the Midnight blockchain's proof-of-work challenge system.

### 1. GET /TandC - Terms & Conditions Acceptance

**Implementation:** `Umbrella_Mines_ScavengerAPI::get_tandc()` in `includes/class-scavenger-api.php`

**What it does:**
- Fetches the Token End User Agreement from `/TandC/1-0` endpoint
- Returns T&C message that must be signed for wallet registration
- Cached for repeated registrations
- Required before any wallet can participate in mining

**Code location:** `class-scavenger-api.php:45-68`

### 2. POST /register - Wallet Registration with CIP-8 Signature

**Implementation:** `register_wallet()` in `includes/class-wp-cli-commands.php`

**What it does:**
- Signs T&C message using CIP-8 signature standard (Cardano message signing)
- Generates Ed25519 signature over T&C text using wallet's private key
- Submits to `/register` with:
  - `address` - Cardano bech32 address (103 chars, starts with `addr_test1` or `addr1`)
  - `signature` - Hex-encoded Ed25519 signature over T&C message
  - `pubkey` - Hex-encoded public key for signature verification
- Receives confirmation that wallet is registered and authorized to submit solutions
- Registration persists across mining sessions (one-time per wallet)

**Code location:** `class-wp-cli-commands.php:245-289`

**CIP-8 Signing Implementation:** `CardanoCIP8Signer::sign()` in `includes/vendor/CardanoCIP8Signer.php`
- Constructs message envelope: `Cardano Signed Message:\n{T&C text}`
- Signs with Ed25519 private key
- Verifies signature before submission (fail-fast validation)

### 3. GET /challenge - Fetch Current Mining Challenge

**Implementation:** `Umbrella_Mines_ScavengerAPI::get_challenge()` in `includes/class-scavenger-api.php`

**What it does:**
- Fetches current challenge from `/challenge` endpoint
- Returns complete challenge data:
  - `challenge_id` - Unique 8-character challenge identifier
  - `difficulty` - 8 hex characters representing target difficulty (e.g., `0000777F`)
  - `no_pre_mine` - 512 hex character ROM identifier for AshMaize initialization
  - `no_pre_mine_hour` - 16 hex character timestamp for preimage construction
  - `latest_submission` - 8 character timestamp of most recent submission
  - `work_to_star_rate` - NIGHT token allocation rate
- Challenge changes every 24 hours (mining period)
- Plugin automatically fetches fresh challenge before each mining round

**Code location:** `class-scavenger-api.php:70-97`

**Challenge Validation:**
- Difficulty parsed as hex and converted to exact bit-matching target
- ROM validated (must be exactly 512 hex chars = 256 bytes)
- All fields validated before mining begins to prevent wasted computation

### 4. POST /solution - Submit Found Solutions

**Implementation:** `submit_solution()` in `includes/class-wp-cli-commands.php`

**What it does:**
- Submits found solution to `/solution/{address}/{challenge_id}/{nonce}` endpoint
- URL format: `https://scavenger.prod.gd.midnighttge.io/solution/{address}/{challenge_id}/{nonce}`
- POST request with empty JSON body: `{}`
- Long timeout (180 seconds) - API can be slow to respond
- Receives `crypto_receipt` from API:
  - Cryptographically signed proof of solution acceptance
  - Contains preimage, signature, timestamp
  - Redeemable for NIGHT tokens on Midnight blockchain
- Stores receipt in `wp_umbrella_mining_receipts` table with:
  - `solution_id` - Links back to mined solution
  - `receipt_data` - Full JSON receipt from API
  - `signature` - Cryptographic signature from API
  - `received_at` - Timestamp of receipt
- Updates solution status to `submitted` on success, `failed` on error
- **Auto-submit mode:** Automatically submits immediately when solution found (toggle in dashboard)
- **Manual mode:** Solutions stay `pending` until user manually submits from Solutions tab

**Code location:** `class-wp-cli-commands.php:618-722`

**Error Handling:**
- Retries on network failures
- Logs API error messages (challenge expired, invalid nonce, etc.)
- Preserves solution in database even if submission fails (can retry later)

### 5. GET /work_to_star_rate - Calculate NIGHT Allocation

**Implementation:** Fetched as part of challenge data in `get_challenge()`

**What it does:**
- Calculates conversion rate from "work" (difficulty) to "stars" (NIGHT tokens)
- Used to display estimated NIGHT rewards for found solutions
- Part of challenge response (returned alongside difficulty, ROM, etc.)
- Optional calculation - not required for mining, but useful for reward estimation

**Code location:** Received in challenge JSON at `class-scavenger-api.php:70-97`

---

## Preimage Construction - Exact API Compliance

The Midnight Scavenger Mine API specifies **exact preimage format** for hash computation. Umbrella Mines implements this specification precisely:

### Required Format (from API whitepaper):

```
nonce (16 hex chars) +
address (103 chars, bech32 Cardano address) +
challenge_id (8 chars) +
difficulty (8 hex chars) +
no_pre_mine (512 hex chars) +
latest_submission (8 chars, ISO 8601 timestamp) +
no_pre_mine_hour (variable length hex chars)
```

### Implementation in `mine_wallet()`:

```php
$preimage =
    $nonce_hex .                    // 16 hex chars (8 bytes)
    $wallet->address .              // 103 chars (bech32)
    $challenge['challenge_id'] .    // 8 chars
    $difficulty_hex .               // 8 hex chars
    $challenge['no_pre_mine'] .     // 512 hex chars
    $challenge['latest_submission'] . // 8 chars (ISO timestamp)
    $challenge['no_pre_mine_hour']; // 16 hex chars
```

**Code location:** `class-wp-cli-commands.php:485-491`

**Validation:**
- Nonce is exactly 8 bytes (16 hex chars)
- Address is valid bech32 format (validated during wallet generation)
- All challenge fields validated before mining begins
- Preimage length checked before hashing (should be ~671+ characters)

---

## AshMaize Configuration - Algorithm Compliance

The Midnight Scavenger Mine requires specific **AshMaize hash function parameters**. Umbrella Mines implements these exactly as specified.

### Required Configuration (from API whitepaper):

| Parameter | Value | Purpose |
|-----------|-------|---------|
| `nbLoops` | 8 | Number of mixing loops (memory-hardness) |
| `nbInstrs` | 256 | Instructions per loop (ASIC resistance) |
| `pre_size` | 16777216 | Pre-computation buffer size (16MB) |
| `mixing_numbers` | 4 | Mixing complexity factor |
| `rom_size` | 1073741824 | ROM size in bytes (**1GB**) |

### Implementation in `Umbrella_Mines_AshMaize_FFI`:

```php
$this->ashmaize = $this->ffi->ashmaize_new(
    8,          // nbLoops
    256,        // nbInstrs
    16777216,   // pre_size (16MB)
    4,          // mixing_numbers
    1073741824  // rom_size (1GB)
);
```

**Code location:** `class-ashmaize-ffi.php:45-51`

**ROM Initialization:**
- ROM initialized with `no_pre_mine` data from challenge
- Loaded once per mining round (~1.2 seconds)
- Persists in memory for all nonce attempts (up to `max_attempts`)
- Memory-mapped for efficient access during hashing

**Hash Computation:**
- Each hash call uses FFI: `$this->ffi->ashmaize_hash($this->ashmaize, $preimage, $output)`
- Zero-copy between PHP and Rust (native performance)
- ~1600 hashes/second on single-threaded process
- Outputs 32-byte hash (64 hex chars)

---

## Terms & Conditions Compliance Flow

The plugin implements the complete T&C acceptance workflow as required by the API:

1. **Fetch T&C** - `get_tandc()` retrieves Token End User Agreement text
2. **User Acceptance** - By clicking "START MINING", user implicitly accepts T&C
3. **CIP-8 Signing** - Wallet signs T&C message with Ed25519 private key
4. **Signature Verification** - Local verification before API submission (fail-fast)
5. **Registration** - `POST /register` submits address + signature + pubkey
6. **Authorization** - API confirms wallet is authorized to submit solutions
7. **Mining** - Only registered wallets can fetch challenges and submit solutions

**Code flow:**
- `start()` > `register_wallet()` > `get_tandc()` > `CardanoCIP8Signer::sign()` > `POST /register`
- Registration stored in `wp_umbrella_mining_wallets` table with `registered_at` timestamp
- Re-registration not required if wallet already registered

**Legal compliance:**
- T&C text displayed during registration in mining output log
- User explicitly starts mining (acceptance via action)
- Signature provides cryptographic proof of acceptance
- Immutable record in database and blockchain

---

### Performance Optimizations

- **PHP FFI** - Direct calls to compiled Rust code for hash computation
  - ~1000x faster than pure PHP hashing
  - Zero-copy memory access between PHP and Rust
  - Native performance for cryptographic operations

- **Persistent ROM** - AshMaize ROM (1GB) loaded once per mining round
  - Reused for all nonces in that round (up to max_attempts)
  - Avoids expensive reinitialization overhead
  - Memory-mapped for efficient access

- **Efficient nonce generation** - Uses `random_bytes()` for cryptographically secure random nonces
  - No sequential nonce patterns (reduces collisions across miners)
  - Fast generation (~microseconds per nonce)

- **Database indexing** - Optimized queries with indexes on:
  - `address` (UNIQUE) for fast wallet lookups
  - `submission_status` for filtering solutions
  - `found_at` timestamps for chronological ordering

- **JSON backups** - Redundant file-based backups prevent data loss
  - Survives database crashes or corruption
  - Human-readable format for debugging
  - Automatic backup on solution save

- **Background execution** - Non-blocking process spawning
  - Web server remains responsive during mining
  - `pclose(popen())` detaches process from request lifecycle
  - Log file streaming for live output without polling database

---

## Technical Deep Dive

### The Midnight Challenge

Midnight's Scavenger Mine uses a proof-of-work system based on the **AshMaize hash function** - a memory-hard algorithm designed to resist ASIC mining and promote fair distribution. The challenge:

1. **Find a nonce** such that `hash(preimage) < difficulty_target`
2. **The preimage format:**
   ```
   nonce (16 hex chars) +
   address (103 chars, bech32 Cardano address) +
   challenge_id (8 chars) +
   difficulty (8 hex chars) +
   no_pre_mine (512 hex chars, ROM identifier) +
   latest_submission (8 chars) +
   no_pre_mine_hour (16 hex chars)
   ```
3. **The hash** must meet **exact difficulty bits** - no more, no less
   - Difficulty `0000777F` means first 17 bits must be zero, bit 18 must be one
   - This ensures constant mining difficulty regardless of network hashrate
4. **Solutions** must be submitted within the mining period window (24 hours per challenge)
5. **Rewards** are cryptographically signed receipts that can be redeemed for NIGHT tokens

### Why This Works

**PHP FFI** bridges the performance gap between interpreted PHP and compiled native code. While PHP is slow for pure computation, FFI allows zero-copy calls to native libraries.

The architecture splits responsibilities:

**PHP handles:**
- Wallet cryptography (Ed25519 signing, ECDSA key derivation)
- API communication (HTTP requests, JSON parsing, retry logic)
- Database operations (MySQL queries, CRUD, transactions)
- Process management (background execution, signal handling, flag files)
- UI rendering (WordPress admin interface, AJAX, live updates)

**Rust handles:**
- AshMaize hashing (the bottleneck - ~99% of CPU time)
- ROM initialization and memory management
- Cryptographic primitives at native speed

It's the best of both worlds: **native performance where it matters, high-level flexibility everywhere else.**

### Deployment Versatility

Because it's WordPress, this runs **anywhere**:

- **Local development**
  - Local by Flywheel (tested)
  - XAMPP, MAMP, WAMP
  - Docker WordPress images

- **VPS hosting**
  - Any Linux VPS with PHP 8.0+ and FFI
  - DigitalOcean, Linode, Vultr
  - Self-managed WordPress servers

- **Cloud platforms**
  - AWS Lightsail, EC2 with WordPress
  - Google Cloud Compute with WordPress
  - Azure App Service with WordPress

- **Shared hosting** (may work - not all hosts support FFI or long-running processes)
  - Most restrictive shared hosts will kill long processes
  - Some budget hosts don't enable FFI extension
  - VPS or dedicated hosting recommended for reliable mining

- **Unique environments**
  - Gaming servers (run mining alongside game services)
  - Casino/betting websites (WordPress already popular in this space)
  - Educational servers (teach blockchain concepts)
  - Mobile WordPress apps with WP-CLI access

The plugin is **self-contained**. No external dependencies beyond WordPress itself. Just upload, activate, click start.

---

## Use Cases

### Premium Address Mining
Mine rare Cardano addresses with specific patterns or properties. The Scavenger Mine challenge generates addresses that meet difficulty requirements - perfect for vanity addresses, branded wallets, or collectible addresses.

### Educational Blockchain Mining
Teach proof-of-work concepts with a real mining implementation that's accessible and inspectable. Students can see every step from nonce generation to solution submission. The WordPress admin interface makes it friendly for non-technical users while the code remains open for inspection.

### Gaming Integration
Integrate mining into gaming websites. Players earn NIGHT tokens while playing. WordPress powers millions of gaming sites already. This adds a blockchain rewards layer with zero friction.

### Casino Rewards
Run mining on casino WordPress sites. Offer NIGHT tokens as player rewards, jackpot contributions, or loyalty program bonuses. WordPress already runs thousands of casino and betting sites. This makes blockchain integration trivial.

### Decentralized Mining Pools
Coordinate multiple WordPress sites as a distributed mining pool. Each site mines independently using different derivation paths but shares solutions through common API integration. Scale horizontally by adding more WordPress instances.

### Mobile Mining
Run lightweight mining on mobile devices through WordPress Mobile apps with WP-CLI support. Mine while your phone charges overnight. WordPress Mobile already has millions of installs.

### Website Monetization
Add mining to existing WordPress sites as alternative monetization. Visitors can opt-in to support your site by running mining in the background. Non-intrusive, transparent, and potentially more ethical than ads.

---

## Roadmap

### Planned Features
- [ ] **Linux/Mac support** - Compile `ashmaize_capi.so` for other platforms
- [ ] **Mining pool integration** - Coordinate multiple WordPress instances with shared work distribution
- [ ] **GPU acceleration via FFI** - Explore OpenCL/CUDA integration for GPU hashing
- [ ] **Multi-threaded mining** - Parallel wallet generation and hashing within single process
- [ ] **Custom difficulty targeting** - Mine specific difficulty ranges or address patterns
- [ ] **Solution marketplace** - Trade mined solutions with other miners (peer-to-peer)
- [ ] **Mobile-optimized dashboard** - Responsive UI for phone/tablet mining
- [ ] **REST API endpoints** - Integrate with external applications and services
- [ ] **WebSocket live updates** - Replace polling with real-time push notifications
- [ ] **Grafana dashboard integration** - Export metrics (hash rate, solutions found, etc.)
- [ ] **Docker images** - Pre-configured WordPress + plugin containers for instant deployment
- [ ] **Shared hosting compatibility** - Explore cron-based mining for restrictive hosts

### Known Limitations
- **Windows only** (currently - requires compiled `ashmaize_capi.dll` for other platforms)
- **Single-threaded** (one mining process per WordPress instance - but can run multiple instances)
- **Shared hosting challenges** (some hosts restrict long-running PHP processes or disable FFI)
- **Process management** (Windows-specific batch file execution - need Linux/Mac equivalents)
- **FFI requirement** (not all shared hosts enable FFI extension by default)

### Community Contributions Welcome
This is an open experiment in what's possible with "old" tech. We'd love help with:

- **Platform ports** - Compile `ashmaize_capi.so` for Linux and `.dylib` for Mac
- **Performance improvements** - Threading, GPU acceleration, algorithm optimizations
- **UI enhancements** - Better charts, responsive design, themes, dark mode
- **Documentation** - Guides, video tutorials, architecture diagrams, translations
- **Testing** - Compatibility testing across hosting environments and PHP versions
- **Security audits** - Review crypto implementation, process isolation, SQL injection prevention

---

## Security Considerations

### Wallet Security
- **Private keys** stored in WordPress database (`wp_umbrella_mining_wallets` table)
- Use WordPress database security best practices (strong passwords, encrypted connections)
- Consider encrypting database-at-rest for production deployments
- JSON backups in `wp-content/uploads/umbrella-mines/` should have restricted file permissions
- Never expose wallet tables via public APIs or REST endpoints

### Process Isolation
- Mining runs as separate background process (not web server process)
- Stop signals use file-based flags (no network ports or IPC)
- No remote code execution vectors (FFI calls only local Rust DLL)
- WP-CLI commands require WordPress authentication

### API Communication
- All API calls to Midnight Scavenger use HTTPS (with SSL verification disabled for Windows cert issues - consider fixing in production)
- CIP-8 signatures authenticate wallet ownership without exposing private keys
- Solutions submitted via POST with no sensitive data in URL

---

## FAQ

**Q: Why WordPress?**
A: Because it's everywhere, it's free, and it works. This started as "can we even do this?" and became "holy crap, this actually works really well."

**Q: Is this a joke?**
A: No. It mines real NIGHT tokens. It competes with specialized Rust miners. It's production-ready.

**Q: Will this work on my shared hosting?**
A: Maybe. Depends on:
- PHP version (need 8.0+)
- FFI extension availability
- Whether your host allows long-running processes
- Memory limits (need 2GB+ for ROM)
- Most restrictive shared hosts will kill long processes or don't enable FFI. VPS or dedicated hosting recommended for reliable mining.

**Q: How much can I mine?**
A: Depends on hardware, difficulty, and luck. ~1000 hashes/second on consumer hardware. Solutions are probabilistic - you might find one in minutes or hours or days. Current Midnight difficulty makes solutions rare.

**Q: Is this secure?**
A: Yes, with caveats:
- Uses standard Cardano cryptography (BIP39, BIP32, Ed25519, CIP-8)
- Wallets and private keys stored in WordPress database (use WordPress security best practices)
- Solutions backed up to JSON files as redundancy
- No remote code execution or network exposure beyond standard WordPress
- Consider encrypting database-at-rest for production

**Q: Can I run multiple instances?**
A: Yes! Install plugin on multiple WordPress sites and mine from all of them. Each instance can use different derivation paths to generate unique wallets. Coordinate through shared API calls or run independently.

**Q: Does this affect my website performance?**
A: Mining runs as background process separate from web server. Minimal impact on site performance unless you're on severely resource-constrained hosting. The mining process uses CPU but doesn't block web requests.

**Q: Can I use this commercially?**
A: Yes. MIT license. Use it however you want. No restrictions.

**Q: Why is the stop flag file not working?**
A: The miner checks for stop signals at three checkpoints (before wallet, before round, every 1000 nonces). It should stop within 1-2 seconds. If not, the mining process may have crashed or been killed by your host. Check `wp-content/umbrella-mines-output.log` for errors.

**Q: I'm getting "Error establishing a database connection" when mining. What's wrong?**
A: This is almost always a PHP version mismatch. The PHP executable and php.ini configuration must use the EXACT same version.

**How to fix in Local by Flywheel:**
1. Check your site's PHP version: Right-click site > Open site shell > `php --version`
2. Note the lightning-services PHP version in use (e.g., PHP 8.3.17)
3. In Local: Right-click site > Change PHP version > Select matching version (e.g., 8.3.17)
4. Restart site
5. Try mining again - should work immediately

**Common scenario:**
- Site was set to PHP 8.2.27 but Local installed new PHP 8.3.17 in lightning-services
- WP-CLI command uses 8.3.17 executable but loads 8.2.27 php.ini
- MySQL extension mismatch causes database connection failure
- Fix: Update site to PHP 8.3.17 (takes 2 seconds in Local)

**Q: Why is auto-submit not working?**
A: Check:
- Toggle is checked on dashboard
- API URL is correct (`https://scavenger.prod.gd.midnighttge.io`)
- Wallet was registered successfully
- Challenge is still active (24-hour window)
- API is responding (can take up to 3 minutes per submission!)

**Q: Can I customize the hashing algorithm?**
A: The AshMaize algorithm is fixed (required by Midnight challenge). You could potentially swap the FFI DLL for different hash functions, but that wouldn't be compatible with Midnight Scavenger Mine.

**Q: How does the merge address system work?**
A: The merge system consolidates mining rewards from multiple wallets into a single payout address. You generate or import a payout wallet, then merge eligible mining wallets to it. Each merge requires a cryptographic signature proving wallet ownership. The API returns a receipt as proof of consolidation. Once merged, wallets are locked from re-merging to prevent daisy-chaining.

**Q: Can I import my existing Cardano wallet?**
A: Yes. Go to Merge Addresses page and toggle "Import Your Own Payout Wallet". Paste your 24-word mnemonic from Eternl, Nami, Typhon, or any BIP39-compatible wallet. The system automatically detects the derivation path or you can specify it manually. Your mnemonic is encrypted before storage.

**Q: What is daisy-chaining and why is it blocked?**
A: Daisy-chaining is merging Wallet A to B, then merging B to C, then C to D in a chain. Midnight rules prohibit this. Once a wallet is used as a payout destination, it cannot be merged again. You can still merge multiple wallets TO the same payout wallet (A->C, B->C is fine), but C can never be merged elsewhere.

**Q: Is my imported mnemonic safe in the database?**
A: Mnemonics are encrypted using symmetric encryption before database storage. A database breach would not expose plaintext recovery phrases. However, WordPress database security best practices still apply (strong passwords, encrypted connections). Consider database-at-rest encryption for production deployments.

**Q: Can I use a hardware wallet address for payouts?**
A: You can import the mnemonic from a hardware wallet seed to use as payout address. However, merge operations require signing, which means the private key must be available to sign merge messages. If you need hardware wallet security, generate the payout wallet in the plugin, then transfer rewards to your hardware wallet address after consolidation.

**Q: What happens if a merge fails?**
A: Failed merges are logged in the merge history with error messages. The original wallet remains eligible for retry. Common failures include unregistered wallets, API timeouts, or invalid signatures. Check the merge history table for specific error details and retry after resolving the issue.

---

## Performance Benchmarks

Out-of-the-box performance on typical consumer hardware:

| Configuration | Hash Rate | Notes |
|--------------|-----------|-------|
| Single-threaded process | ~1600 H/s | Tested baseline performance |
| Multi-threaded (experimental) | ~4800-6400 H/s | Early data on 3-4 parallel processes |

**System Requirements:**
- ROM initialization: ~1.2 seconds per process
- Memory usage: ~2GB per process
- CPU usage: ~100% per core utilized (single-threaded per process)

**Solution Rate:**
Highly dependent on current challenge difficulty. With difficulty `0000777F` (17 leading zero bits):
- Statistical probability: ~1 solution per 131,000 hashes
- Expected time at 1600 H/s: ~82 seconds per solution (theoretical)
- Actual variance: Solutions are probabilistic - may find multiple in minutes or none for hours

**Scaling:**
- Run multiple WordPress instances with different derivation paths
- Each instance mines independently with full hash rate
- Coordinate through shared database or separate instances
- No performance degradation from parallel instances (each has own ROM)

---

## License

MIT License - use it, fork it, modify it, deploy it anywhere.

See LICENSE file for full text.

---

## Credits

Built by **Umbrella** (https://umbrella.lol)

Powered by:
- **Cardano Midnight** - The blockchain this mines for
- **AshMaize** - The memory-hard hash function (Rust implementation)
- **WordPress** - The platform that makes it accessible
- **PHP FFI** - The bridge between PHP and native performance

Special thanks to:
- **IOHK/IOG team** for creating the Midnight Scavenger Mine challenge
- **Cardano Foundation** for blockchain infrastructure
- **PHP community** for FFI extension
- **Rust community** for `ashmaize` crate

Inspired by the question: "Can we mine on WordPress?" The answer: "Hell yes."

---

## Support

- Website: https://umbrella.lol
- GitHub Issues: [Create an issue](https://github.com/yourusername/umbrella-mines/issues)
- Documentation: This README
- Community: WordPress forums, Cardano Discord

---

**Mine anywhere. Mine on anything. That's the point.**
