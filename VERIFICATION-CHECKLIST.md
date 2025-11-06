# Umbrella Mines Plugin - Verification Checklist

## ✅ Files Copied from night-mine-php

### Core Files
- [x] `includes/class-ashmaize-ffi.php` - AshMaize FFI hasher
- [x] `includes/class-wp-cli-commands.php` - WP-CLI mining commands
- [x] `includes/vendor/CardanoWalletPHP.php` - Wallet generation
- [x] `includes/vendor/CardanoCIP8Signer.php` - CIP-8 T&C signing
- [x] `includes/vendor/Ed25519Compat.php` - Ed25519 cryptography
- [x] `bin/ashmaize_capi.dll` - Rust FFI library

### Admin Pages
- [x] `admin/solutions.php` - Solutions dashboard
- [x] `admin/wallets.php` - Wallets dashboard
- [x] `admin/manual-submit.php` - Manual submission page

### Plugin Files
- [x] `umbrella-mines.php` - Main plugin file (newly created)
- [x] `README.md` - Documentation (newly created)

---

## ✅ Naming Updates Applied

### Database Tables
- [x] `night_mining_wallets` → `umbrella_mining_wallets`
- [x] `night_mining_solutions` → `umbrella_mining_solutions`
- [x] `night_mining_receipts` → `umbrella_mining_receipts`

**Files Updated:**
- [x] umbrella-mines.php (table creation)
- [x] includes/class-wp-cli-commands.php
- [x] admin/solutions.php
- [x] admin/wallets.php
- [x] admin/manual-submit.php

### Constants
- [x] `NIGHT_MINE_VERSION` → `UMBRELLA_MINES_VERSION`
- [x] `NIGHT_MINE_PLUGIN_DIR` → `UMBRELLA_MINES_PLUGIN_DIR`
- [x] `NIGHT_MINE_PLUGIN_URL` → `UMBRELLA_MINES_PLUGIN_URL`
- [x] `NIGHT_MINE_DATA_DIR` → `UMBRELLA_MINES_DATA_DIR`

**Files Updated:**
- [x] umbrella-mines.php (constant definitions)
- [x] includes/class-wp-cli-commands.php (constant usage)

### Class Names
- [x] `Night_Mine_CLI_Commands` → `Umbrella_Mines_CLI_Commands`
- [x] `NightMine` → `Umbrella_Mines` (main class)

**Files Updated:**
- [x] umbrella-mines.php
- [x] includes/class-wp-cli-commands.php

### WP-CLI Commands
- [x] `wp night-mine` → `wp umbrella-mines`

**Files Updated:**
- [x] umbrella-mines.php (command registration)
- [x] includes/class-wp-cli-commands.php (command examples)

### Admin Page Slugs
- [x] `night-mine-solutions` → `umbrella-mines-solutions`
- [x] `night-mine-wallets` → `umbrella-mines-wallets`
- [x] `night-mine-manual-submit` → `umbrella-mines-manual-submit`

**Files Updated:**
- [x] umbrella-mines.php (menu registration)
- [x] admin/solutions.php (page links)

---

## ✅ Feature Verification

### Core Features Working
- [x] AshMaize FFI hashing (~700-900 H/s)
- [x] Cardano wallet generation (CIP-1852)
- [x] Custom derivation paths (`--derive` flag)
- [x] Wallet registration with T&C signing (CIP-8)
- [x] Solution mining and validation
- [x] Difficulty checking
- [x] Solution submission to API
- [x] Crypto receipt storage

### Admin Dashboard Features
- [x] Solutions list with derivation paths
- [x] Status indicators (pending/submitted/failed)
- [x] Green checkmarks for submitted solutions
- [x] Manual solution submission
- [x] Reset status button
- [x] Delete solution button
- [x] Wallets list
- [x] Statistics dashboard

### Database Features
- [x] Auto-create tables on activation
- [x] Wallet backups to JSON
- [x] Solution backups to JSON
- [x] Receipt storage with signatures
- [x] Derivation path tracking

---

## ✅ Code Quality Checks

### No References to Old Names
- [x] No `NIGHT_MINE_*` constants found
- [x] No `night_mining_*` table names found
- [x] No `night-mine` slugs found
- [x] No `NightMine` class names found

### Proper Path References
- [x] DLL path uses `UMBRELLA_MINES_PLUGIN_DIR . 'bin/ashmaize_capi.dll'`
- [x] Data directory uses `UMBRELLA_MINES_DATA_DIR`
- [x] Admin includes use `UMBRELLA_MINES_PLUGIN_DIR . 'admin/...'`

### WP-CLI Integration
- [x] Command registered in main plugin file
- [x] Class name matches registration
- [x] All command examples use `wp umbrella-mines`

---

## ✅ Files NOT Copied (Intentionally Excluded)

### Test Files (Development Only)
- [ ] test-*.php (all test files)
- [ ] mine-live.php
- [ ] mine-direct.php
- [ ] verify-solution.php
- [ ] check-*.php
- [ ] fix-*.php
- [ ] insert-*.php
- [ ] add-*.php

### Documentation (Not Needed)
- [ ] javascript-reference.txt
- [ ] whitepaper.txt
- [ ] IMPLEMENTATION-SUMMARY.md
- [ ] cli-dump.txt

### Other Excluded
- [ ] /solutions/ directory
- [ ] /backups/ directory
- [ ] /ffi/ directory (development docs)

---

## 🚀 Ready for Deployment

### Pre-Deployment Checklist
- [x] All core files copied
- [x] All naming updated consistently
- [x] Database tables renamed
- [x] WP-CLI commands updated
- [x] Admin pages functional
- [x] FFI binary included
- [x] README documentation complete

### Installation Requirements
- PHP 8.0+ with FFI extension
- WordPress 5.0+
- WP-CLI installed
- 2GB+ RAM per mining process

### Activation Command
```bash
wp plugin activate umbrella-mines
```

### First Mining Command
```bash
wp umbrella-mines start --max-attempts=500000 --derive=0/0/0
```

---

## 📋 Final Notes

- Original `night-mine-php` plugin remains UNTOUCHED as backup
- All fixes from `night-mine-php` have been applied to `umbrella-mines`
- Derivation path feature fully functional
- Solution submission with 180-second timeout configured
- Green checkmark status indicators working
- Plugin ready for production deployment

**Status: ✅ VERIFIED AND READY**
