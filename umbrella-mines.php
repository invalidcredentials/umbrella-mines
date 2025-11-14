<?php
/**
 * Plugin Name: Umbrella Mines
 * Plugin URI: https://umbrella.lol
 * Description: Professional Cardano Midnight Scavenger Mine implementation with AshMaize FFI hashing. Mine NIGHT tokens with high-performance PHP/Rust hybrid miner. Cross-platform: Windows, Linux, macOS.
 * Version: 0.4.20.68
 * Author: Umbrella
 * Author URI: https://umbrella.lol
 * License: MIT
 * Requires at least: 5.0
 * Requires PHP: 8.0
 * Text Domain: umbrella-mines
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('UMBRELLA_MINES_VERSION', '0.4.20.68');
define('UMBRELLA_MINES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UMBRELLA_MINES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UMBRELLA_MINES_DATA_DIR', WP_CONTENT_DIR . '/uploads/umbrella-mines');

/**
 * Main Plugin Class
 */
class Umbrella_Mines {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Handle Start/Stop mining actions EARLY
        add_action('admin_init', array($this, 'handle_mining_actions'));
        add_action('admin_init', array($this, 'handle_payout_wallet_actions'));

        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        // add_action('admin_notices', array($this, 'show_system_requirements_notice')); // DISABLED

        // AJAX hooks
        add_action('wp_ajax_delete_solution', array($this, 'ajax_delete_solution'));
        add_action('wp_ajax_retry_solution', array($this, 'ajax_retry_solution'));
        add_action('wp_ajax_reset_solution_status', array($this, 'ajax_reset_solution_status'));
        add_action('wp_ajax_get_solution_details', array($this, 'ajax_get_solution_details'));
        add_action('wp_ajax_get_wallet_details', array($this, 'ajax_get_wallet_details'));

        // Dashboard AJAX hooks
        add_action('wp_ajax_umbrella_toggle_autosubmit', array($this, 'ajax_toggle_autosubmit'));
        add_action('wp_ajax_umbrella_toggle_public_display', array($this, 'ajax_toggle_public_display'));
        add_action('wp_ajax_umbrella_save_mine_name', array($this, 'ajax_save_mine_name'));
        add_action('wp_ajax_umbrella_start_mining_live', array($this, 'ajax_start_mining_live'));
        add_action('wp_ajax_umbrella_stop_mining_live', array($this, 'ajax_stop_mining_live'));
        add_action('wp_ajax_umbrella_get_mining_status_live', array($this, 'ajax_get_mining_status_live'));
        add_action('wp_ajax_umbrella_get_terminal_output', array($this, 'ajax_get_terminal_output'));
        add_action('wp_ajax_umbrella_send_stop_signal', array($this, 'ajax_send_stop_signal'));
        add_action('wp_ajax_umbrella_check_export_allowed', array($this, 'ajax_check_export_allowed'));
        add_action('wp_ajax_umbrella_export_all_data', array($this, 'ajax_export_all_data'));

        // Merge/Payout wallet AJAX hooks
        add_action('wp_ajax_umbrella_export_payout_mnemonic', array($this, 'ajax_export_payout_mnemonic'));
        add_action('wp_ajax_umbrella_delete_payout_wallet', array($this, 'ajax_delete_payout_wallet'));
        add_action('wp_ajax_umbrella_merge_all_wallets', array($this, 'ajax_merge_all_wallets'));
        add_action('wp_ajax_umbrella_create_merge_session', array($this, 'ajax_create_merge_session'));
        add_action('wp_ajax_umbrella_process_merge_chunk', array($this, 'ajax_process_merge_chunk'));
        add_action('wp_ajax_umbrella_get_merge_session_status', array($this, 'ajax_get_merge_session_status'));
        add_action('wp_ajax_umbrella_merge_single_wallet', array($this, 'ajax_merge_single_wallet'));
        add_action('wp_ajax_umbrella_export_all_merged', array($this, 'ajax_export_all_merged'));
        add_action('wp_ajax_get_merge_details', array($this, 'ajax_get_merge_details'));
        add_action('wp_ajax_umbrella_import_payout_wallet', array($this, 'ajax_import_payout_wallet'));
        add_action('wp_ajax_umbrella_clear_imported_wallet', array($this, 'ajax_clear_imported_wallet'));
        add_action('wp_ajax_umbrella_load_merge_page', array($this, 'ajax_load_merge_page'));

        // Import solutions AJAX hooks
        add_action('wp_ajax_umbrella_parse_import_file', array($this, 'ajax_parse_import_file'));
        add_action('wp_ajax_umbrella_parse_umbrella_json', array($this, 'ajax_parse_umbrella_json'));
        add_action('wp_ajax_umbrella_start_batch_merge', array($this, 'ajax_start_batch_merge'));
        add_action('wp_ajax_umbrella_get_merge_progress', array($this, 'ajax_get_merge_progress'));
        add_action('wp_ajax_umbrella_download_import_receipt', array($this, 'ajax_download_import_receipt'));
        add_action('wp_ajax_umbrella_check_interrupted_sessions', array($this, 'ajax_check_interrupted_sessions'));
        add_action('wp_ajax_umbrella_cancel_import_session', array($this, 'ajax_cancel_import_session'));

        // Public display hooks
        add_action('init', array($this, 'register_public_display_rewrite'));
        add_action('template_redirect', array($this, 'handle_public_display_page'));

        // WP-CLI commands
        if (defined('WP_CLI') && WP_CLI) {
            require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-wp-cli-commands.php';
            WP_CLI::add_command('umbrella-mines', 'Umbrella_Mines_CLI_Commands');
        }

        // Create data directories
        if (!file_exists(UMBRELLA_MINES_DATA_DIR)) {
            wp_mkdir_p(UMBRELLA_MINES_DATA_DIR);
            wp_mkdir_p(UMBRELLA_MINES_DATA_DIR . '/wallet_backups');
            wp_mkdir_p(UMBRELLA_MINES_DATA_DIR . '/solution_backups');
        }
    }

    /**
     * Plugin activation - create database tables
     */
    public function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Table 1: Mining wallets
        $table_wallets = $wpdb->prefix . 'umbrella_mining_wallets';
        $sql_wallets = "CREATE TABLE {$table_wallets} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            address varchar(255) NOT NULL,
            derivation_path varchar(100) DEFAULT NULL,
            payment_skey_extended text NOT NULL,
            payment_pkey varchar(64) NOT NULL,
            payment_keyhash varchar(56) NOT NULL,
            network varchar(20) DEFAULT 'mainnet',
            registration_signature text,
            registration_pubkey varchar(64),
            registered_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY address (address),
            KEY idx_registered (registered_at)
        ) $charset_collate;";

        // Table 2: Solutions found
        $table_solutions = $wpdb->prefix . 'umbrella_mining_solutions';
        $sql_solutions = "CREATE TABLE {$table_solutions} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wallet_id bigint(20) NOT NULL,
            challenge_id varchar(50) NOT NULL,
            nonce varchar(16) NOT NULL,
            preimage text NOT NULL,
            hash_result varchar(128) NOT NULL,
            difficulty varchar(8) NOT NULL,
            no_pre_mine varchar(128) NOT NULL,
            no_pre_mine_hour varchar(20) NOT NULL,
            latest_submission datetime NOT NULL,
            found_at datetime DEFAULT CURRENT_TIMESTAMP,
            submitted_at datetime,
            submission_status enum('pending','queued','submitted','confirmed','failed') DEFAULT 'pending',
            submission_error text,
            PRIMARY KEY (id),
            KEY idx_status (submission_status),
            KEY idx_challenge (challenge_id),
            KEY idx_wallet (wallet_id),
            KEY idx_found_at (found_at)
        ) $charset_collate;";

        // Table 3: Receipts from server (proofs of acceptance)
        $table_receipts = $wpdb->prefix . 'umbrella_mining_receipts';
        $sql_receipts = "CREATE TABLE {$table_receipts} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            solution_id bigint(20) NOT NULL,
            crypto_receipt longtext NOT NULL,
            preimage text NOT NULL,
            signature text NOT NULL,
            timestamp datetime NOT NULL,
            received_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY solution_id (solution_id),
            KEY idx_timestamp (timestamp)
        ) $charset_collate;";

        // Table 4: Challenges cache
        $table_challenges = $wpdb->prefix . 'umbrella_mining_challenges';
        $sql_challenges = "CREATE TABLE {$table_challenges} (
            challenge_id varchar(50) NOT NULL,
            day int NOT NULL,
            challenge_number int NOT NULL,
            difficulty varchar(8) NOT NULL,
            no_pre_mine varchar(128) NOT NULL,
            no_pre_mine_hour varchar(20) NOT NULL,
            latest_submission datetime NOT NULL,
            issued_at datetime NOT NULL,
            mining_period_ends datetime,
            fetched_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (challenge_id),
            KEY idx_day (day)
        ) $charset_collate;";

        // Table 5: Mining stats/config
        $table_config = $wpdb->prefix . 'umbrella_mining_config';
        $sql_config = "CREATE TABLE {$table_config} (
            config_key varchar(100) NOT NULL,
            config_value longtext NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (config_key)
        ) $charset_collate;";

        // Table 6: Mining jobs (queue)
        $table_jobs = $wpdb->prefix . 'umbrella_mining_jobs';
        $sql_jobs = "CREATE TABLE {$table_jobs} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            derivation_path varchar(100),
            max_attempts int,
            attempts_done int DEFAULT 0,
            status enum('pending','running','paused','completed','stopped') DEFAULT 'pending',
            wallet_id bigint(20),
            current_nonce varchar(32),
            started_at datetime,
            completed_at datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status)
        ) $charset_collate;";

        // Table 7: Mining progress logs (for live updates)
        $table_progress = $wpdb->prefix . 'umbrella_mining_progress';
        $sql_progress = "CREATE TABLE {$table_progress} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            job_id bigint(20) NOT NULL,
            message text,
            attempts int,
            hashrate decimal(10,2),
            progress_percent decimal(5,2),
            logged_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_job (job_id),
            KEY idx_logged (logged_at)
        ) $charset_collate;";

        // Table 8: Mining processes (track PIDs for proper cleanup)
        $table_processes = $wpdb->prefix . 'umbrella_mining_processes';
        $sql_processes = "CREATE TABLE {$table_processes} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            pid int NOT NULL,
            derivation_path varchar(100),
            max_attempts int,
            command_line text,
            status enum('running','stopped','completed') DEFAULT 'running',
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            stopped_at datetime,
            PRIMARY KEY (id),
            KEY idx_pid (pid),
            KEY idx_status (status),
            KEY idx_started (started_at)
        ) $charset_collate;";

        // Table 9: NIGHT rates by day (cached from API)
        $table_night_rates = $wpdb->prefix . 'umbrella_night_rates';
        $sql_night_rates = "CREATE TABLE {$table_night_rates} (
            day int NOT NULL,
            star_per_receipt bigint(20) NOT NULL,
            fetched_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (day)
        ) $charset_collate;";

        // Table 10: Payout wallet (custodial wallet for merging rewards)
        $table_payout_wallet = $wpdb->prefix . 'umbrella_mining_payout_wallet';
        $sql_payout_wallet = "CREATE TABLE {$table_payout_wallet} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wallet_name varchar(255) NOT NULL,
            address varchar(255) NOT NULL,
            mnemonic_encrypted text NOT NULL,
            payment_skey_extended_encrypted text NOT NULL,
            payment_pkey varchar(64) NOT NULL,
            payment_keyhash varchar(56) NOT NULL,
            network varchar(20) DEFAULT 'mainnet',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY address (address)
        ) $charset_collate;";

        // Table 11: Merge operations (donate_to API tracking)
        $table_merges = $wpdb->prefix . 'umbrella_mining_merges';
        $sql_merges = "CREATE TABLE {$table_merges} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            original_address varchar(255) NOT NULL,
            payout_address varchar(255) NOT NULL,
            original_wallet_id bigint(20) NOT NULL,
            merge_signature text NOT NULL,
            merge_receipt longtext,
            solutions_consolidated int DEFAULT 0,
            status enum('pending','processing','success','failed') DEFAULT 'pending',
            error_message text,
            merged_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_original (original_address),
            KEY idx_payout (payout_address),
            KEY idx_status (status),
            KEY idx_wallet (original_wallet_id)
        ) $charset_collate;";

        // Table 12: Import sessions (for Night Miner imports)
        $table_import_sessions = $wpdb->prefix . 'umbrella_mining_import_sessions';
        $sql_import_sessions = "CREATE TABLE {$table_import_sessions} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_key varchar(100) NOT NULL,
            payout_address varchar(255) NOT NULL,
            total_wallets int NOT NULL,
            processed_wallets int DEFAULT 0,
            successful_count int DEFAULT 0,
            failed_count int DEFAULT 0,
            wallet_ids_json longtext NOT NULL,
            status enum('pending','processing','completed','cancelled') DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at datetime,
            PRIMARY KEY (id),
            UNIQUE KEY session_key (session_key),
            KEY idx_status (status)
        ) $charset_collate;";

        // Table 13: Merge sessions (for chunked DB wallet merging)
        $table_merge_sessions = $wpdb->prefix . 'umbrella_mining_merge_sessions';
        $sql_merge_sessions = "CREATE TABLE {$table_merge_sessions} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            session_key varchar(100) NOT NULL,
            payout_address varchar(255) NOT NULL,
            total_wallets int NOT NULL,
            processed_wallets int DEFAULT 0,
            successful_count int DEFAULT 0,
            failed_count int DEFAULT 0,
            already_assigned_count int DEFAULT 0,
            wallet_ids_json longtext NOT NULL,
            status enum('pending','processing','completed','cancelled') DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at datetime,
            PRIMARY KEY (id),
            UNIQUE KEY session_key (session_key),
            KEY idx_status (status)
        ) $charset_collate;";

        // Create tables
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_wallets);
        dbDelta($sql_solutions);
        dbDelta($sql_receipts);
        dbDelta($sql_challenges);
        dbDelta($sql_config);
        dbDelta($sql_jobs);
        dbDelta($sql_progress);
        dbDelta($sql_processes);
        dbDelta($sql_night_rates);
        dbDelta($sql_payout_wallet);
        dbDelta($sql_merges);
        dbDelta($sql_import_sessions);
        dbDelta($sql_merge_sessions);

        // Set default config
        $this->set_default_config();
    }

    /**
     * Set default configuration
     */
    private function set_default_config() {
        global $wpdb;
        $table = $wpdb->prefix . 'umbrella_mining_config';

        $defaults = array(
            'api_url' => 'https://scavenger.prod.gd.midnighttge.io',
            'network' => 'mainnet',
            'mining_enabled' => '0',
            'submission_enabled' => '0',
            'wallets_per_solution' => '1',
            'mining_threads' => '4',
            'submission_interval_seconds' => '5',
            'tc_message' => '',
            'tc_content' => '',
            'tc_version' => ''
        );

        foreach ($defaults as $key => $value) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE config_key = %s",
                $key
            ));

            if (!$exists) {
                $wpdb->insert($table, array(
                    'config_key' => $key,
                    'config_value' => $value
                ), array('%s', '%s'));
            }
        }

        // Register rewrite rules and flush
        $this->register_public_display_rewrite();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up rewrite rules
        flush_rewrite_rules();
        // Clear cron
        wp_clear_scheduled_hook('umbrella_mines_process_jobs');
    }

    /**
     * Add custom cron interval (every 30 seconds for fast processing)
     */
    public function add_cron_interval($schedules) {
        $schedules['umbrella_mines_interval'] = array(
            'interval' => 30,
            'display'  => 'Every 30 seconds'
        );
        return $schedules;
    }

    /**
     * Background job processor (triggered by WP Cron)
     *
     * Uses WP-CLI to run mining in CLI context where FFI is enabled
     */
    public function process_mining_jobs() {
        error_log("[CRON] process_mining_jobs triggered at " . date('Y-m-d H:i:s'));

        global $wpdb;

        // Get pending or running jobs
        $jobs = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}umbrella_mining_jobs
            WHERE status IN ('pending', 'running')
            ORDER BY created_at ASC
            LIMIT 1
        ", ARRAY_A);

        if (empty($jobs)) {
            error_log("[CRON] No pending or running jobs found");
            return;
        }

        error_log("[CRON] Found job #{$jobs[0]['id']}, launching CLI miner...");

        // Run via WP-CLI where FFI is enabled
        $wp_cli = $this->find_wp_cli();
        $php_cli = $this->find_php();

        if (!$wp_cli) {
            error_log("[CRON] ERROR: WP-CLI not found, cannot run mining");
            return;
        }

        // Build WP-CLI command
        $site_path = ABSPATH;
        $job_id = $jobs[0]['id'];

        // Log output to a file we can check
        $log_file = WP_CONTENT_DIR . '/umbrella-mines-cli.log';

        $cmd = sprintf(
            'cd %s && %s %s umbrella-mines process-chunk --job-id=%d >> %s 2>&1',
            escapeshellarg($site_path),
            escapeshellarg($php_cli),
            escapeshellarg($wp_cli),
            $job_id,
            escapeshellarg($log_file)
        );

        error_log("[CRON] Running: $cmd");

        // Run command in background
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen("start /B " . $cmd, "r"));
        } else {
            exec($cmd . " &");
        }

        error_log("[CRON] CLI miner launched for job #$job_id, output logged to: $log_file");
    }

    /**
     * Handle Start/Stop mining actions
     */
    public function handle_mining_actions() {
        if (isset($_POST['action']) && isset($_POST['umbrella_mining_nonce']) && wp_verify_nonce($_POST['umbrella_mining_nonce'], 'umbrella_mining')) {
            $action = sanitize_text_field($_POST['action']);
            $log_file = WP_CONTENT_DIR . '/umbrella-mines-output.log';

            require_once(UMBRELLA_MINES_PLUGIN_DIR . 'includes/start-stop-handler.php');
            umbrella_mines_handle_action($action, $log_file);
            exit;
        }
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_menu_page(
            'Umbrella Mines',
            'Umbrella Mines',
            'manage_options',
            'umbrella-mines',
            array($this, 'admin_dashboard'),
            'dashicons-shield',
            30
        );

        add_submenu_page(
            'umbrella-mines',
            'Solutions',
            'Solutions',
            'manage_options',
            'umbrella-mines-solutions',
            array($this, 'admin_solutions')
        );

        add_submenu_page(
            'umbrella-mines',
            'Wallets',
            'Wallets',
            'manage_options',
            'umbrella-mines-wallets',
            array($this, 'admin_wallets')
        );

        add_submenu_page(
            'umbrella-mines',
            'Manual Submit',
            'Manual Submit',
            'manage_options',
            'umbrella-mines-manual-submit',
            array($this, 'admin_manual_submit')
        );

        add_submenu_page(
            'umbrella-mines',
            'Merge Addresses',
            'Merge Addresses',
            'manage_options',
            'umbrella-mines-merge-addresses',
            array($this, 'admin_merge_addresses')
        );

        add_submenu_page(
            'umbrella-mines',
            'Create Table',
            'Create Table',
            'manage_options',
            'umbrella-mines-create-table',
            array($this, 'admin_create_table')
        );
    }

    /**
     * Admin dashboard page
     */
    public function admin_dashboard() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'admin/dashboard-live.php';
    }

    /**
     * Solutions admin page
     */
    public function admin_solutions() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'admin/solutions.php';
    }

    /**
     * Wallets admin page
     */
    public function admin_wallets() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'admin/wallets.php';
    }

    /**
     * Manual submit admin page
     */
    public function admin_manual_submit() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'admin/manual-submit.php';
    }

    /**
     * Create table admin page
     */
    public function admin_create_table() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'admin/create-table.php';
    }

    /**
     * Handle payout wallet form submissions (runs on admin_init BEFORE any output)
     */
    public function handle_payout_wallet_actions() {
        if (!isset($_POST['payout_action'])) {
            return;
        }

        if (!check_admin_referer('umbrella_payout_wallet', 'payout_wallet_nonce')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-payout-wallet.php';

        $action = sanitize_text_field($_POST['payout_action']);

        if ($action === 'generate') {
            error_log('=== UMBRELLA MINES PAYOUT WALLET GENERATION START ===');

            $wallet_name = sanitize_text_field($_POST['wallet_name']);
            $network = get_option('umbrella_mines_network', 'mainnet');

            error_log('Wallet name: ' . $wallet_name);
            error_log('Network: ' . $network);

            try {
                $result = Umbrella_Mines_Payout_Wallet::create_wallet($wallet_name, $network);

                error_log('create_wallet returned: ' . print_r($result, true));

                if (is_wp_error($result)) {
                    error_log('ERROR: ' . $result->get_error_message());
                    set_transient('umbrella_mines_admin_error_' . get_current_user_id(), $result->get_error_message(), 30);
                    wp_redirect(admin_url('admin.php?page=umbrella-mines-merge-addresses&error=1'));
                    exit;
                } else {
                    error_log('SUCCESS: Wallet created with ID ' . $result['wallet_id']);
                    error_log('Mnemonic length: ' . strlen($result['mnemonic']));
                    error_log('Address: ' . $result['address']);

                    set_transient('umbrella_mines_payout_mnemonic_' . get_current_user_id(), $result['mnemonic'], 300);
                    error_log('Transient set, redirecting to created=1');
                    wp_redirect(admin_url('admin.php?page=umbrella-mines-merge-addresses&created=1'));
                    exit;
                }
            } catch (Exception $e) {
                error_log('=== WALLET GENERATION EXCEPTION ===');
                error_log('Error: ' . $e->getMessage());
                error_log('File: ' . $e->getFile() . ':' . $e->getLine());
                error_log('Stack trace: ' . $e->getTraceAsString());

                set_transient('umbrella_mines_admin_error_' . get_current_user_id(), 'Exception: ' . $e->getMessage(), 30);
                wp_redirect(admin_url('admin.php?page=umbrella-mines-merge-addresses&error=1'));
                exit;
            }

        } elseif ($action === 'import') {
            $wallet_name = sanitize_text_field($_POST['wallet_name']);
            $mnemonic = sanitize_textarea_field($_POST['mnemonic']);
            $network = get_option('umbrella_mines_network', 'mainnet');

            $result = Umbrella_Mines_Payout_Wallet::import_wallet($wallet_name, $mnemonic, $network);

            if (is_wp_error($result)) {
                set_transient('umbrella_mines_admin_error_' . get_current_user_id(), $result->get_error_message(), 30);
                wp_redirect(admin_url('admin.php?page=umbrella-mines-merge-addresses&error=1'));
                exit;
            } else {
                set_transient('umbrella_mines_admin_success_' . get_current_user_id(), 'Payout wallet imported successfully!', 30);
                wp_redirect(admin_url('admin.php?page=umbrella-mines-merge-addresses&success=1'));
                exit;
            }
        }
    }

    public function admin_merge_addresses() {
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'admin/merge-addresses.php';
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Add admin styles/scripts if needed
    }

    /**
     * AJAX: Reset solution status to pending
     */
    public function ajax_reset_solution_status() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'reset_solution_status')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $solution_id = isset($_POST['solution_id']) ? intval($_POST['solution_id']) : 0;

        if (!$solution_id) {
            wp_send_json_error('Invalid solution ID');
            return;
        }

        global $wpdb;

        // CRITICAL: Check if crypto receipt exists - cannot reset if receipt is stored
        $receipt_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}umbrella_mining_receipts WHERE solution_id = %d",
            $solution_id
        ));

        if ($receipt_exists) {
            wp_send_json_error('Cannot reset - crypto receipt exists. Resetting would permanently delete proof of submission.');
            return;
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'umbrella_mining_solutions',
            array(
                'submission_status' => 'pending',
                'submission_error' => null,
                'submitted_at' => null
            ),
            array('id' => $solution_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($updated !== false) {
            wp_send_json_success('Solution status reset to pending');
        } else {
            wp_send_json_error('Failed to reset solution status');
        }
    }

    /**
     * AJAX: Retry a failed solution
     */
    public function ajax_retry_solution() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'retry_solution')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $solution_id = isset($_POST['solution_id']) ? intval($_POST['solution_id']) : 0;

        if (!$solution_id) {
            wp_send_json_error('Invalid solution ID');
            return;
        }

        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'umbrella_mining_solutions',
            array(
                'submission_status' => 'pending',
                'submission_error' => null,
                'submitted_at' => null
            ),
            array('id' => $solution_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if ($updated) {
            wp_send_json_success('Solution reset to pending');
        } else {
            wp_send_json_error('Failed to reset solution');
        }
    }

    /**
     * AJAX: Delete a solution
     */
    public function ajax_delete_solution() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'delete_solution')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }

        $solution_id = isset($_POST['solution_id']) ? intval($_POST['solution_id']) : 0;

        if (!$solution_id) {
            wp_send_json_error('Invalid solution ID');
            return;
        }

        global $wpdb;

        $deleted = $wpdb->delete(
            $wpdb->prefix . 'umbrella_mining_solutions',
            array('id' => $solution_id),
            array('%d')
        );

        if ($deleted) {
            wp_send_json_success('Solution deleted');
        } else {
            wp_send_json_error('Failed to delete solution');
        }
    }

    /**
     * AJAX: Get solution details
     */
    public function ajax_get_solution_details() {
        $solution_id = isset($_REQUEST['solution_id']) ? intval($_REQUEST['solution_id']) : 0;

        if (!$solution_id) {
            echo 'Invalid solution ID';
            wp_die();
        }

        global $wpdb;

        // Get full solution + wallet data
        $solution = $wpdb->get_row($wpdb->prepare("
            SELECT s.*, w.*
            FROM {$wpdb->prefix}umbrella_mining_solutions s
            JOIN {$wpdb->prefix}umbrella_mining_wallets w ON s.wallet_id = w.id
            WHERE s.id = %d
        ", $solution_id));

        if (!$solution) {
            echo 'Solution not found';
            wp_die();
        }

        // Get receipt if exists
        $receipt = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}umbrella_mining_receipts
            WHERE solution_id = %d
        ", $solution_id));

        // Get merge data for this wallet (if merged)
        $merge = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}umbrella_mining_merges
            WHERE original_wallet_id = %d
            AND status = 'success'
            ORDER BY merged_at DESC
            LIMIT 1
        ", $solution->wallet_id));

        // Format status color
        $status_colors = array(
            'pending' => '#999',
            'queued' => '#0073aa',
            'submitted' => '#46b450',
            'confirmed' => '#46b450',
            'failed' => '#dc3232'
        );
        $status_color = $status_colors[$solution->submission_status] ?? '#666';

        ?>
        <style>
        .solution-details-section {
            background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
            border-left: 4px solid #00ff41;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .solution-details-section h3 {
            color: #00ff41;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 0 0 15px 0;
        }
        .solution-details-table {
            width: 100%;
            border-collapse: collapse;
        }
        .solution-details-table tr {
            border-bottom: 1px solid #2a3f5f;
        }
        .solution-details-table tr:last-child {
            border-bottom: none;
        }
        .solution-details-table th {
            color: #00ff41;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 15px 10px 0;
            text-align: left;
            vertical-align: top;
            width: 200px;
        }
        .solution-details-table td {
            color: #e0e0e0;
            font-size: 13px;
            padding: 10px 0;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 600px;
        }
        .solution-details-table code {
            background: rgba(0, 255, 65, 0.1);
            color: #00ff41;
            padding: 4px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            word-break: break-word;
            overflow-wrap: break-word;
            white-space: pre-wrap;
            display: inline-block;
            max-width: 100%;
        }
        .solution-details-table pre {
            background: rgba(0, 255, 65, 0.05);
            border: 1px solid rgba(0, 255, 65, 0.2);
            color: #00ff41;
            padding: 12px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }
        .status-badge-details {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .receipt-section {
            background: rgba(0, 255, 65, 0.05);
            border-left: 4px solid #00d4ff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .receipt-section h3 {
            color: #00d4ff;
        }
        </style>

        <!-- Wallet Info -->
        <div class="solution-details-section">
            <h3>💼 Wallet Information</h3>
            <table class="solution-details-table">
                <tr>
                    <th>Wallet ID</th>
                    <td><code><?php echo esc_html($solution->wallet_id); ?></code></td>
                </tr>
                <tr>
                    <th>Address</th>
                    <td><code><?php echo esc_html($solution->address); ?></code></td>
                </tr>
                <tr>
                    <th>Derivation Path</th>
                    <td><code><?php echo esc_html($solution->derivation_path ?: 'N/A'); ?></code></td>
                </tr>
                <tr>
                    <th>Network</th>
                    <td><code><?php echo esc_html($solution->network ?? 'mainnet'); ?></code></td>
                </tr>
                <tr>
                    <th>Payment PKey</th>
                    <td><code><?php echo esc_html($solution->payment_pkey ?? 'N/A'); ?></code></td>
                </tr>
                <tr>
                    <th>Payment KeyHash</th>
                    <td><code><?php echo esc_html($solution->payment_keyhash ?? 'N/A'); ?></code></td>
                </tr>
                <?php if (!empty($solution->registration_signature)): ?>
                <tr>
                    <th>Registered At</th>
                    <td><?php echo esc_html($solution->registered_at); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Created At</th>
                    <td><?php echo esc_html($solution->created_at); ?></td>
                </tr>
            </table>
        </div>

        <!-- Solution Info -->
        <div class="solution-details-section">
            <h3>⛏️ Solution Details</h3>
            <table class="solution-details-table">
                <tr>
                    <th>Solution ID</th>
                    <td><code><?php echo esc_html($solution->id); ?></code></td>
                </tr>
                <tr>
                    <th>Challenge ID</th>
                    <td><code><?php echo esc_html($solution->challenge_id); ?></code></td>
                </tr>
                <tr>
                    <th>Nonce</th>
                    <td><code><?php echo esc_html($solution->nonce); ?></code></td>
                </tr>
                <tr>
                    <th>Hash Result</th>
                    <td><code><?php echo esc_html($solution->hash_result); ?></code></td>
                </tr>
                <tr>
                    <th>Difficulty</th>
                    <td><code><?php echo esc_html($solution->difficulty); ?></code></td>
                </tr>
                <tr>
                    <th>No Pre-Mine</th>
                    <td><code><?php echo esc_html($solution->no_pre_mine); ?></code></td>
                </tr>
                <tr>
                    <th>No Pre-Mine Hour</th>
                    <td><code><?php echo esc_html($solution->no_pre_mine_hour ?? 'N/A'); ?></code></td>
                </tr>
                <tr>
                    <th>Latest Submission</th>
                    <td><?php echo esc_html($solution->latest_submission); ?></td>
                </tr>
                <tr>
                    <th>Found At</th>
                    <td><?php echo esc_html($solution->found_at); ?></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><span class="status-badge-details" style="background: <?php echo $status_color; ?>; color: #fff;"><?php echo esc_html(strtoupper($solution->submission_status)); ?></span></td>
                </tr>
                <?php if ($solution->submitted_at): ?>
                <tr>
                    <th>Submitted At</th>
                    <td><?php echo esc_html($solution->submitted_at); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($solution->submission_error): ?>
                <tr>
                    <th>Error</th>
                    <td><pre><?php echo esc_html($solution->submission_error); ?></pre></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <?php if (!empty($solution->registration_signature)): ?>
        <!-- Registration Info -->
        <div class="solution-details-section">
            <h3>📝 Registration</h3>
            <table class="solution-details-table">
                <tr>
                    <th>Signature</th>
                    <td><code><?php echo esc_html($solution->registration_signature); ?></code></td>
                </tr>
                <tr>
                    <th>Public Key</th>
                    <td><code><?php echo esc_html($solution->registration_pubkey ?? 'N/A'); ?></code></td>
                </tr>
                <tr>
                    <th>Registered At</th>
                    <td><?php echo esc_html($solution->registered_at); ?></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($receipt): ?>
        <!-- Crypto Receipt -->
        <div class="receipt-section">
            <h3>🧾 Crypto Receipt (Proof of Submission)</h3>
            <table class="solution-details-table">
                <tr>
                    <th>Receipt ID</th>
                    <td><code><?php echo esc_html($receipt->id); ?></code></td>
                </tr>
                <tr>
                    <th>Full Receipt</th>
                    <td><pre><?php echo esc_html($receipt->crypto_receipt); ?></pre></td>
                </tr>
                <tr>
                    <th>Preimage</th>
                    <td><code><?php echo esc_html($receipt->preimage); ?></code></td>
                </tr>
                <tr>
                    <th>Signature</th>
                    <td><code><?php echo esc_html($receipt->signature); ?></code></td>
                </tr>
                <tr>
                    <th>Timestamp</th>
                    <td><?php echo esc_html($receipt->timestamp); ?></td>
                </tr>
            </table>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 40px; color: #666; font-style: italic;">
            No crypto receipt yet - submit this solution to receive API confirmation
        </div>
        <?php endif; ?>

        <?php if ($merge): ?>
        <!-- Merge Receipt -->
        <div class="receipt-section" style="border-left-color: #ffaa00;">
            <h3 style="color: #ffaa00;">🔀 Wallet Merge Receipt</h3>
            <table class="solution-details-table">
                <tr>
                    <th>Merge Status</th>
                    <td><span class="status-badge-details" style="background: #46b450; color: #fff;">✅ MERGED</span></td>
                </tr>
                <?php
                // Decode merge receipt JSON
                $merge_data = json_decode($merge->merge_receipt, true);
                if ($merge_data && isset($merge_data['donation_id'])): ?>
                <tr>
                    <th>Donation ID</th>
                    <td><code><?php echo esc_html($merge_data['donation_id']); ?></code></td>
                </tr>
                <tr>
                    <th>Message</th>
                    <td><?php echo esc_html($merge_data['message'] ?? 'N/A'); ?></td>
                </tr>
                <tr>
                    <th>From Address</th>
                    <td><code><?php echo esc_html($merge_data['original_address'] ?? $merge->original_address); ?></code></td>
                </tr>
                <tr>
                    <th>To Address</th>
                    <td><code><?php echo esc_html($merge_data['destination_address'] ?? $merge->payout_address); ?></code></td>
                </tr>
                <tr>
                    <th>Solutions Consolidated</th>
                    <td><strong><?php echo esc_html($merge_data['solutions_consolidated'] ?? $merge->solutions_consolidated); ?></strong> solution(s)</td>
                </tr>
                <tr>
                    <th>Merge Timestamp</th>
                    <td><?php echo esc_html($merge_data['timestamp'] ?? $merge->merged_at); ?></td>
                </tr>
                <tr>
                    <th>Merge Signature</th>
                    <td><code><?php echo esc_html(substr($merge->merge_signature, 0, 100)); ?>...</code></td>
                </tr>
                <tr>
                    <th>Full Merge Receipt</th>
                    <td><pre><?php echo esc_html(json_encode($merge_data, JSON_PRETTY_PRINT)); ?></pre></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>
        <?php endif; ?>
        <?php

        wp_die();
    }

    /**
     * AJAX: Get wallet details with solutions
     */
    public function ajax_get_wallet_details() {
        $wallet_id = isset($_REQUEST['wallet_id']) ? intval($_REQUEST['wallet_id']) : 0;

        if (!$wallet_id) {
            echo 'Invalid wallet ID';
            wp_die();
        }

        global $wpdb;

        // Get wallet data
        $wallet = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}umbrella_mining_wallets WHERE id = %d
        ", $wallet_id));

        if (!$wallet) {
            echo 'Wallet not found';
            wp_die();
        }

        // Get solutions for this wallet
        $solutions = $wpdb->get_results($wpdb->prepare("
            SELECT s.*, r.id as receipt_id
            FROM {$wpdb->prefix}umbrella_mining_solutions s
            LEFT JOIN {$wpdb->prefix}umbrella_mining_receipts r ON s.id = r.solution_id
            WHERE s.wallet_id = %d
            ORDER BY s.found_at DESC
        ", $wallet_id));

        $solution_count = count($solutions);

        // Format status colors
        $status_colors = array(
            'pending' => '#999',
            'queued' => '#0073aa',
            'submitted' => '#46b450',
            'confirmed' => '#46b450',
            'failed' => '#dc3232'
        );

        ?>
        <style>
        .wallet-details-section {
            background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
            border-left: 4px solid #00ff41;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .wallet-details-section h3 {
            color: #00ff41;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 0 0 15px 0;
        }
        .wallet-details-table {
            width: 100%;
            border-collapse: collapse;
        }
        .wallet-details-table tr {
            border-bottom: 1px solid #2a3f5f;
        }
        .wallet-details-table tr:last-child {
            border-bottom: none;
        }
        .wallet-details-table th {
            color: #00ff41;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 15px 10px 0;
            text-align: left;
            vertical-align: top;
            width: 200px;
        }
        .wallet-details-table td {
            color: #e0e0e0;
            font-size: 13px;
            padding: 10px 0;
            word-break: break-all;
        }
        .wallet-details-table code {
            background: rgba(0, 255, 65, 0.1);
            color: #00ff41;
            padding: 4px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
        }
        .solutions-list {
            background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
            border-left: 4px solid #00d4ff;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .solutions-list h3 {
            color: #00d4ff;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 0 0 15px 0;
        }
        .solution-item {
            background: rgba(0, 212, 255, 0.05);
            border: 1px solid rgba(0, 212, 255, 0.2);
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 6px;
        }
        .solution-item:last-child {
            margin-bottom: 0;
        }
        .solution-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .solution-item-id {
            color: #00d4ff;
            font-weight: 700;
            font-size: 13px;
        }
        .solution-item-status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .solution-item-details {
            font-size: 12px;
            color: #999;
        }
        .solution-item-details code {
            background: rgba(0, 212, 255, 0.1);
            color: #00d4ff;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
        }
        </style>

        <!-- Wallet Info -->
        <div class="wallet-details-section">
            <h3>💼 Wallet Information</h3>
            <table class="wallet-details-table">
                <tr>
                    <th>Wallet ID</th>
                    <td><code><?php echo esc_html($wallet->id); ?></code></td>
                </tr>
                <tr>
                    <th>Address</th>
                    <td><code><?php echo esc_html($wallet->address); ?></code></td>
                </tr>
                <tr>
                    <th>Derivation Path</th>
                    <td><code><?php echo esc_html($wallet->derivation_path ?: 'N/A'); ?></code></td>
                </tr>
                <tr>
                    <th>Network</th>
                    <td><code><?php echo esc_html($wallet->network ?? 'mainnet'); ?></code></td>
                </tr>
                <tr>
                    <th>Payment PKey</th>
                    <td><code><?php echo esc_html($wallet->payment_pkey ?? 'N/A'); ?></code></td>
                </tr>
                <tr>
                    <th>Payment KeyHash</th>
                    <td><code><?php echo esc_html($wallet->payment_keyhash ?? 'N/A'); ?></code></td>
                </tr>
                <?php if (!empty($wallet->registration_signature)): ?>
                <tr>
                    <th>Registration Signature</th>
                    <td><code><?php echo esc_html($wallet->registration_signature); ?></code></td>
                </tr>
                <tr>
                    <th>Registration PubKey</th>
                    <td><code><?php echo esc_html($wallet->registration_pubkey ?? 'N/A'); ?></code></td>
                </tr>
                <tr>
                    <th>Registered At</th>
                    <td><?php echo esc_html($wallet->registered_at); ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Created At</th>
                    <td><?php echo esc_html($wallet->created_at); ?></td>
                </tr>
            </table>
        </div>

        <!-- Solutions -->
        <?php if ($solution_count > 0): ?>
        <div class="solutions-list">
            <h3>⛏️ Solutions (<?php echo $solution_count; ?>)</h3>
            <?php foreach ($solutions as $solution):
                $status_color = $status_colors[$solution->submission_status] ?? '#666';
            ?>
            <div class="solution-item">
                <div class="solution-item-header">
                    <span class="solution-item-id">Solution #<?php echo esc_html($solution->id); ?></span>
                    <span class="solution-item-status" style="background: <?php echo $status_color; ?>; color: #fff;">
                        <?php echo esc_html(strtoupper($solution->submission_status)); ?>
                        <?php if ($solution->receipt_id): ?>🔒<?php endif; ?>
                    </span>
                </div>
                <div class="solution-item-details">
                    <div style="margin-bottom: 5px;">
                        <strong>Challenge:</strong> <code><?php echo esc_html($solution->challenge_id); ?></code>
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        <strong>Nonce:</strong> <code><?php echo esc_html($solution->nonce); ?></code>
                    </div>
                    <div style="margin-bottom: 5px;">
                        <strong>Difficulty:</strong> <code><?php echo esc_html($solution->difficulty); ?></code>
                        &nbsp;&nbsp;|&nbsp;&nbsp;
                        <strong>Found:</strong> <?php echo esc_html($solution->found_at); ?>
                    </div>
                    <?php if ($solution->submitted_at): ?>
                    <div>
                        <strong>Submitted:</strong> <?php echo esc_html($solution->submitted_at); ?>
                        <?php if ($solution->receipt_id): ?>
                        <span style="color: #00ff41; font-weight: 700;">✓ Has Crypto Receipt</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 40px; color: #666; font-style: italic;">
            No solutions found for this wallet yet
        </div>
        <?php endif; ?>
        <?php

        wp_die();
    }

    /**
     * AJAX: Toggle auto-submit setting
     */
    public function ajax_toggle_autosubmit() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $enabled = isset($_POST['enabled']) ? $_POST['enabled'] : '0';

        $wpdb->update(
            $wpdb->prefix . 'umbrella_mining_config',
            array('config_value' => $enabled),
            array('config_key' => 'submission_enabled'),
            array('%s'),
            array('%s')
        );

        wp_send_json_success(array('enabled' => $enabled));
    }

    /**
     * AJAX: Toggle public display
     */
    public function ajax_toggle_public_display() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $enabled = isset($_POST['enabled']) ? $_POST['enabled'] : '0';
        update_option('umbrella_public_display_enabled', $enabled);

        // Flush rewrite rules to activate/deactivate the endpoint
        flush_rewrite_rules();

        wp_send_json_success(array('enabled' => $enabled));
    }

    /**
     * AJAX: Save mine name
     */
    public function ajax_save_mine_name() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $mine_name = isset($_POST['mine_name']) ? sanitize_text_field($_POST['mine_name']) : '';

        // Default to "Umbrella Mines" if empty
        if (empty($mine_name)) {
            $mine_name = 'Umbrella Mines';
        }

        update_option('umbrella_mine_name', $mine_name);

        wp_send_json_success(array('mine_name' => $mine_name));
    }

    /**
     * Register URL rewrite for /umbrella-mines
     */
    public function register_public_display_rewrite() {
        add_rewrite_rule('^umbrella-mines/?$', 'index.php?umbrella_mines_public=1', 'top');
        add_rewrite_tag('%umbrella_mines_public%', '1');
    }

    /**
     * Handle public display page
     */
    public function handle_public_display_page() {
        if (get_query_var('umbrella_mines_public') === '1') {
            // Check if public display is enabled
            if (get_option('umbrella_public_display_enabled', '0') !== '1') {
                wp_die('Public display is not enabled.', 'Umbrella Mines - Disabled', array('response' => 403));
            }

            // Load the public template
            include UMBRELLA_MINES_PLUGIN_DIR . 'public/mining-display.php';
            exit;
        }
    }

    /**
     * AJAX: Start mining process (creates job in queue)
     */
    public function ajax_start_mining() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $max_attempts = isset($_POST['max_attempts']) ? (int)$_POST['max_attempts'] : 500000;
        $derivation_path = isset($_POST['derivation_path']) ? sanitize_text_field($_POST['derivation_path']) : '0/0/0';

        global $wpdb;

        // Create mining job
        $wpdb->insert(
            $wpdb->prefix . 'umbrella_mining_jobs',
            array(
                'derivation_path' => $derivation_path,
                'max_attempts' => $max_attempts,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%d', '%s', '%s')
        );

        $job_id = $wpdb->insert_id;

        // Trigger immediate cron execution (don't wait for schedule)
        spawn_cron();

        wp_send_json_success(array(
            'message' => 'Mining job created and started!',
            'job_id' => $job_id,
            'derivation_path' => $derivation_path
        ));
    }

    /**
     * Find PHP CLI executable path (not CGI!)
     */
    private function find_php() {
        // For Local by Flywheel, look in lightning-services
        $username = getenv('USERNAME') ?: getenv('USER') ?: get_current_user();
        $lightning_dir = 'C:/Users/' . $username . '/AppData/Local/Programs/Local/lightning-services';

        if (is_dir($lightning_dir)) {
            // Find all PHP versions and get the newest
            $dirs = glob($lightning_dir . '/php-*/bin/win64/php.exe');
            if (!empty($dirs)) {
                // Sort by version (newest first)
                rsort($dirs);
                return $dirs[0];
            }
        }

        // Check if PHP_BINARY is CLI (not CGI) - but replace php-cgi.exe with php.exe
        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            // If it's php-cgi.exe, try php.exe in same directory
            if (strpos(PHP_BINARY, 'php-cgi.exe') !== false) {
                $php_cli = str_replace('php-cgi.exe', 'php.exe', PHP_BINARY);
                if (file_exists($php_cli)) {
                    return $php_cli;
                }
            } else {
                return PHP_BINARY;
            }
        }

        // Common standalone PHP CLI paths
        $possible_paths = array(
            'C:/php/php.exe',
            'C:/Program Files/php/php.exe',
            '/usr/bin/php',
            '/usr/local/bin/php',
        );

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Try which command (Unix/Linux)
        $which = trim(shell_exec('which php 2>/dev/null'));
        if ($which) {
            return $which;
        }

        // Last resort - just use 'php' and hope it works
        return 'php';
    }

    /**
     * Find WP-CLI executable path
     */
    private function find_wp_cli() {
        // Common paths
        $possible_paths = array(
            'C:/Users/' . get_current_user() . '/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar',
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            'wp'
        );

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Try which command
        $which = trim(shell_exec('which wp 2>/dev/null'));
        if ($which) {
            return $which;
        }

        return false;
    }

    /**
     * Get OS type (windows or unix)
     */
    private function get_os_type() {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'windows' : 'unix';
    }

    /**
     * Get dynamic library extension for current OS
     */
    private function get_lib_extension() {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return 'dll';
        }
        if (PHP_OS === 'Darwin') {
            return 'dylib';
        }
        return 'so';
    }

    /**
     * AJAX: Stop all mining jobs
     */
    public function ajax_stop_mining() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        // Stop all active jobs
        $stopped = $wpdb->update(
            $wpdb->prefix . 'umbrella_mining_jobs',
            array('status' => 'stopped'),
            array('status' => 'running'),
            array('%s'),
            array('%s')
        );

        // Also stop pending jobs
        $wpdb->update(
            $wpdb->prefix . 'umbrella_mining_jobs',
            array('status' => 'stopped'),
            array('status' => 'pending'),
            array('%s'),
            array('%s')
        );

        wp_send_json_success(array(
            'message' => 'All mining jobs stopped'
        ));
    }

    /**
     * AJAX: Get mining status with real-time progress
     */
    public function ajax_get_mining_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        // Get active jobs
        $jobs = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}umbrella_mining_jobs
            WHERE status IN ('pending', 'running')
            ORDER BY created_at DESC
            LIMIT 5
        ", ARRAY_A);

        if (empty($jobs)) {
            wp_send_json_success(array(
                'jobs' => array(),
                'output' => "No active mining jobs.\n\nClick \"Start Mining\" to begin.",
                'progress' => array()
            ));
            return;
        }

        // Get recent progress for all active jobs
        $job_ids = array_column($jobs, 'id');
        $placeholders = implode(',', array_fill(0, count($job_ids), '%d'));

        $progress_logs = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}umbrella_mining_progress
            WHERE job_id IN ($placeholders)
            ORDER BY logged_at DESC
            LIMIT 50
        ", ...$job_ids), ARRAY_A);

        // Build output
        $output = '';
        foreach ($jobs as $job) {
            $output .= "╔════════════════════════════════════════════════╗\n";
            $output .= sprintf("║  JOB #%d - Path: %s\n", $job['id'], $job['derivation_path']);
            $output .= "╚════════════════════════════════════════════════╝\n\n";

            $output .= sprintf("Status: %s\n", strtoupper($job['status']));
            $output .= sprintf("Progress: %s / %s attempts (%.1f%%)\n",
                number_format($job['attempts_done']),
                number_format($job['max_attempts']),
                ($job['attempts_done'] / $job['max_attempts']) * 100
            );

            // Get latest progress for this job
            $latest = null;
            foreach ($progress_logs as $log) {
                if ($log['job_id'] == $job['id']) {
                    $latest = $log;
                    break;
                }
            }

            if ($latest) {
                $output .= sprintf("Hashrate: %.2f H/s\n", $latest['hashrate']);
                $output .= sprintf("Last update: %s\n", $latest['logged_at']);
                $output .= sprintf("Message: %s\n", $latest['message']);
            }

            $output .= "\n";

            // Show recent messages for this job
            $output .= "Recent activity:\n";
            $output .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $shown = 0;
            foreach ($progress_logs as $log) {
                if ($log['job_id'] == $job['id'] && $shown < 10) {
                    $output .= sprintf("[%s] %s\n",
                        date('H:i:s', strtotime($log['logged_at'])),
                        $log['message']
                    );
                    $shown++;
                }
            }
            $output .= "\n\n";
        }

        wp_send_json_success(array(
            'jobs' => $jobs,
            'output' => $output,
            'progress' => $progress_logs
        ));
    }

    /**
     * AJAX: Force run cron now (manual trigger)
     */
    public function ajax_force_run() {
        error_log("[AJAX] ajax_force_run called");

        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log("[AJAX] ajax_force_run - unauthorized user");
            wp_send_json_error('Unauthorized');
        }

        error_log("[AJAX] ajax_force_run - calling process_mining_jobs()");

        // Manually trigger the cron job processor
        $this->process_mining_jobs();

        error_log("[AJAX] ajax_force_run - completed successfully");

        wp_send_json_success(array(
            'message' => 'Cron job executed manually'
        ));
    }

    /**
     * Start mining process and capture PID
     */
    public function start_mining_with_pid($max_attempts, $derivation_path) {
        global $wpdb;

        $log_file = WP_CONTENT_DIR . '/umbrella-mines-output.log';

        // Clear old log
        $start_log = "=== MINING START REQUESTED at " . date('Y-m-d H:i:s') . " ===\n";
        $start_log .= "Max attempts: {$max_attempts}\n";
        $start_log .= "Derivation path: {$derivation_path}\n\n";
        file_put_contents($log_file, $start_log);

        // Find PHP CLI
        $php_cli = $this->find_php();

        // Find WP-CLI
        $wp_cli = $this->find_wp_cli();

        if (!$wp_cli) {
            return array('success' => false, 'error' => 'WP-CLI not found');
        }

        // Find php.ini
        $php_ini = '';
        $username = getenv('USERNAME') ?: getenv('USER') ?: get_current_user();
        $site_id_dirs = glob('C:/Users/' . $username . '/AppData/Roaming/Local/run/*', GLOB_ONLYDIR);
        foreach ($site_id_dirs as $dir) {
            if (file_exists($dir . '/conf/php/php.ini')) {
                $php_ini = $dir . '/conf/php/php.ini';
                break;
            }
        }

        // Build command
        $cmd = "cd \"" . ABSPATH . "\" && \"" . $php_cli . "\"";
        if ($php_ini) {
            $cmd .= " -c \"" . $php_ini . "\"";
        }
        $cmd .= " \"" . $wp_cli . "\" umbrella-mines start --max-attempts=" . $max_attempts . " --derive=" . $derivation_path . " --path=\"" . ABSPATH . "\"";

        // Just use the old exec method - it works!
        exec($cmd . ' >> "' . $log_file . '" 2>&1 &', $output);

        $exec_log = "Process started in background\n";
        file_put_contents($log_file, $exec_log, FILE_APPEND);

        // Store with PID 0 (we'll find it later with wmic when stopping)
        $wpdb->insert(
            $wpdb->prefix . 'umbrella_mining_processes',
            array(
                'pid' => 0,
                'derivation_path' => $derivation_path,
                'max_attempts' => $max_attempts,
                'command_line' => $cmd,
                'status' => 'running'
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );

        return array('success' => true, 'pid' => 0);
    }

    /**
     * Stop all running mining processes
     */
    public function stop_all_mining_processes() {
        global $wpdb;

        $log_file = WP_CONTENT_DIR . '/umbrella-mines-output.log';
        $stop_log = "\n\n=== MINING STOP REQUESTED at " . date('Y-m-d H:i:s') . " ===\n";

        // Get all running processes from database
        $processes = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}umbrella_mining_processes WHERE status = 'running'",
            ARRAY_A
        );

        if (empty($processes)) {
            $stop_log .= "No running processes found in database\n";
            file_put_contents($log_file, $stop_log, FILE_APPEND);
            touch($log_file, time() - 120);
            return array('success' => true, 'killed' => 0);
        }

        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        $killed = 0;

        foreach ($processes as $process) {
            $pid = $process['pid'];

            if ($pid > 0) {
                // Kill process with OS-appropriate command
                $kill_output = array();
                if ($is_windows) {
                    exec("taskkill /F /PID {$pid} /T 2>&1", $kill_output, $kill_return);
                } else {
                    exec("kill -9 {$pid} 2>&1", $kill_output, $kill_return);
                }

                $stop_log .= "Killed PID {$pid}: " . implode(' ', $kill_output) . "\n";
                $killed++;
            } else {
                // Fallback: PID not tracked, search for processes
                $stop_log .= "PID not tracked, searching for umbrella-mines processes...\n";

                if ($is_windows) {
                    $cmd = 'wmic process where "commandline like \'%umbrella-mines%\'" get processid 2>nul';
                } else {
                    $cmd = "ps aux | grep '[u]mbrella-mines' | awk '{print \$2}'";
                }

                exec($cmd, $pids, $return_code);

                foreach ($pids as $line) {
                    $found_pid = trim($line);
                    if (is_numeric($found_pid) && $found_pid > 0) {
                        $output = array();
                        if ($is_windows) {
                            $kill_cmd = "taskkill /F /PID {$found_pid} /T 2>&1";
                        } else {
                            $kill_cmd = "kill -9 {$found_pid} 2>&1";
                        }
                        exec($kill_cmd, $output, $kill_return);
                        $stop_log .= "Killed PID {$found_pid}: " . implode(' ', $output) . "\n";
                        $killed++;
                    }
                }
            }

            // Mark as stopped in database
            $wpdb->update(
                $wpdb->prefix . 'umbrella_mining_processes',
                array('status' => 'stopped', 'stopped_at' => current_time('mysql')),
                array('id' => $process['id']),
                array('%s', '%s'),
                array('%d')
            );
        }

        $stop_log .= "Total processes killed: {$killed}\n";
        $stop_log .= "=== STOP COMPLETE ===\n\n";

        file_put_contents($log_file, $stop_log, FILE_APPEND);
        touch($log_file, time() - 120);

        return array('success' => true, 'killed' => $killed);
    }

    /**
     * AJAX: Start mining (live dashboard)
     */
    public function ajax_start_mining_live() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $max_attempts = isset($_POST['max_attempts']) ? intval($_POST['max_attempts']) : 500000;
        $derivation_path = isset($_POST['derivation_path']) ? sanitize_text_field($_POST['derivation_path']) : '0/0/0';

        $result = $this->start_mining_with_pid($max_attempts, $derivation_path);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'Mining started successfully!',
                'pid' => $result['pid']
            ));
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * AJAX: Stop mining (live dashboard)
     */
    public function ajax_stop_mining_live() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $result = $this->stop_all_mining_processes();

        wp_send_json_success(array(
            'message' => 'Mining stopped',
            'killed' => $result['killed']
        ));
    }

    /**
     * AJAX: Check if export is allowed (check for pending solutions)
     */
    public function ajax_check_export_allowed() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        // Check for pending/non-submitted solutions
        $pending_count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}umbrella_mining_solutions
            WHERE submission_status NOT IN ('submitted', 'confirmed')
        ");

        if ($pending_count > 0) {
            $pending_solutions = $wpdb->get_results("
                SELECT s.id, s.challenge_id, s.nonce, s.submission_status, s.found_at, w.address
                FROM {$wpdb->prefix}umbrella_mining_solutions s
                JOIN {$wpdb->prefix}umbrella_mining_wallets w ON s.wallet_id = w.id
                WHERE s.submission_status NOT IN ('submitted', 'confirmed')
                ORDER BY s.found_at DESC
                LIMIT 10
            ", ARRAY_A);

            wp_send_json_error(array(
                'message' => 'Cannot export: You have ' . $pending_count . ' solution(s) that are not submitted yet!',
                'warning' => 'Please submit all solutions before exporting.',
                'pending_count' => (int)$pending_count,
                'pending_solutions' => array_map(function($s) {
                    return array(
                        'solution_id' => (int)$s['id'],
                        'challenge_id' => $s['challenge_id'],
                        'nonce' => $s['nonce'],
                        'status' => $s['submission_status'],
                        'found_at' => $s['found_at']
                    );
                }, $pending_solutions)
            ));
        }

        wp_send_json_success(array('allowed' => true));
    }

    /**
     * AJAX: Export all mining data (wallet-centric structure)
     */
    public function ajax_export_all_data() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        // Load encryption helper for mnemonic decryption
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/UmbrellaMines_EncryptionHelper.php';

        // Check for pending/non-submitted solutions
        $pending_count = $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->prefix}umbrella_mining_solutions
            WHERE submission_status NOT IN ('submitted', 'confirmed')
        ");

        // If there are pending solutions, return warning instead of exporting
        if ($pending_count > 0) {
            $pending_solutions = $wpdb->get_results("
                SELECT s.id, s.challenge_id, s.nonce, s.submission_status, s.found_at, w.address
                FROM {$wpdb->prefix}umbrella_mining_solutions s
                JOIN {$wpdb->prefix}umbrella_mining_wallets w ON s.wallet_id = w.id
                WHERE s.submission_status NOT IN ('submitted', 'confirmed')
                ORDER BY s.found_at DESC
            ", ARRAY_A);

            wp_send_json_error(array(
                'message' => 'Cannot export: You have ' . $pending_count . ' solution(s) that are not submitted yet!',
                'warning' => 'Please submit all solutions before exporting. Only submitted/confirmed solutions can be exported.',
                'pending_count' => (int)$pending_count,
                'pending_solutions' => array_map(function($s) {
                    return array(
                        'solution_id' => (int)$s['id'],
                        'challenge_id' => $s['challenge_id'],
                        'nonce' => $s['nonce'],
                        'status' => $s['submission_status'],
                        'found_at' => $s['found_at'],
                        'address' => substr($s['address'], 0, 20) . '...'
                    );
                }, $pending_solutions)
            ));
        }

        // Get ALL payout wallets (current + imported + historical from merges)
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';
        $network = get_option('umbrella_mines_network', 'mainnet');
        $payout_wallet = Umbrella_Mines_Merge_Processor::get_registered_payout_wallet($network);

        // Collect all payout wallets
        $all_payout_wallets = array();
        $payout_addresses_seen = array();

        // 1. Current active payout wallet
        if ($payout_wallet) {
            $payout_mnemonic = '';
            if (!empty($payout_wallet->mnemonic_encrypted)) {
                $payout_mnemonic = UmbrellaMines_EncryptionHelper::decrypt($payout_wallet->mnemonic_encrypted);
                if ($payout_mnemonic === false) {
                    $payout_mnemonic = '[DECRYPTION_FAILED]';
                }
            }

            $is_imported = property_exists($payout_wallet, 'wallet_name');

            $all_payout_wallets[] = array(
                'status' => 'ACTIVE',
                'wallet_type' => $is_imported ? 'IMPORTED (User-provided)' : 'AUTO-SELECTED (From mining wallets)',
                'address' => $payout_wallet->address,
                'mnemonic' => $payout_mnemonic,
                'payment_skey_extended' => property_exists($payout_wallet, 'payment_skey_extended') ? $payout_wallet->payment_skey_extended : (property_exists($payout_wallet, 'payment_skey_extended_encrypted') ? '[ENCRYPTED - Use mnemonic to recover]' : null),
                'payment_pkey' => $payout_wallet->payment_pkey,
                'payment_keyhash' => $payout_wallet->payment_keyhash,
                'network' => $payout_wallet->network,
                'created_at' => $payout_wallet->created_at,
                'wallet_name' => $is_imported && property_exists($payout_wallet, 'wallet_name') ? $payout_wallet->wallet_name : null,
                'cardanoscan_link' => $network === 'mainnet'
                    ? 'https://cardanoscan.io/address/' . $payout_wallet->address
                    : 'https://preprod.cardanoscan.io/address/' . $payout_wallet->address
            );

            $payout_addresses_seen[$payout_wallet->address] = true;
        }

        // 2. All imported payout wallets from payout_wallet table
        $imported_payouts = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}umbrella_mining_payout_wallet
            ORDER BY created_at DESC
        ");

        foreach ($imported_payouts as $imported) {
            if (isset($payout_addresses_seen[$imported->address])) {
                continue; // Skip if already added
            }

            $imported_mnemonic = '';
            if (!empty($imported->mnemonic_encrypted)) {
                $imported_mnemonic = UmbrellaMines_EncryptionHelper::decrypt($imported->mnemonic_encrypted);
                if ($imported_mnemonic === false) {
                    $imported_mnemonic = '[DECRYPTION_FAILED]';
                }
            }

            $all_payout_wallets[] = array(
                'status' => $imported->is_active ? 'ACTIVE' : 'INACTIVE',
                'wallet_type' => 'IMPORTED (User-provided)',
                'address' => $imported->address,
                'mnemonic' => $imported_mnemonic,
                'payment_skey_extended' => '[ENCRYPTED - Use mnemonic to recover]',
                'payment_pkey' => $imported->payment_pkey,
                'payment_keyhash' => $imported->payment_keyhash,
                'network' => $imported->network,
                'created_at' => $imported->created_at,
                'wallet_name' => $imported->wallet_name,
                'cardanoscan_link' => $network === 'mainnet'
                    ? 'https://cardanoscan.io/address/' . $imported->address
                    : 'https://preprod.cardanoscan.io/address/' . $imported->address
            );

            $payout_addresses_seen[$imported->address] = true;
        }

        // 3. All unique payout addresses from merge history
        $historical_payout_addresses = $wpdb->get_col("
            SELECT DISTINCT payout_address
            FROM {$wpdb->prefix}umbrella_mining_merges
            WHERE payout_address IS NOT NULL AND payout_address != ''
        ");

        foreach ($historical_payout_addresses as $hist_address) {
            if (isset($payout_addresses_seen[$hist_address])) {
                continue; // Skip if already added
            }

            $all_payout_wallets[] = array(
                'status' => 'HISTORICAL (Used in merges)',
                'wallet_type' => 'Unknown - Address only',
                'address' => $hist_address,
                'mnemonic' => '[NOT AVAILABLE - Historical reference only]',
                'payment_skey_extended' => null,
                'payment_pkey' => null,
                'payment_keyhash' => null,
                'network' => $network,
                'created_at' => null,
                'wallet_name' => null,
                'cardanoscan_link' => $network === 'mainnet'
                    ? 'https://cardanoscan.io/address/' . $hist_address
                    : 'https://preprod.cardanoscan.io/address/' . $hist_address
            );

            $payout_addresses_seen[$hist_address] = true;
        }

        // Build payout wallets export structure
        $payout_wallet_export = array(
            '⭐_IMPORTANT' => '🔑 THESE ARE YOUR PAYOUT WALLETS - Do not import these as mining wallets to prevent daisy-chaining',
            'total_payout_wallets' => count($all_payout_wallets),
            'payout_wallets' => $all_payout_wallets
        );

        error_log('=== EXPORT: Including ' . count($all_payout_wallets) . ' payout wallet(s) for reference ===');

        // Build wallet-centric export structure (ONLY wallets with submitted/confirmed solutions)
        $export_data = array(
            'export_date' => current_time('mysql'),
            'export_version' => '1.0',
            'plugin_version' => UMBRELLA_MINES_VERSION,
            'export_note' => 'This export only includes wallets with submitted or confirmed solutions',
            'PAYOUT_WALLET' => $payout_wallet_export, // 🎯 PAYOUT DESTINATION - Listed first for easy access
            'wallets' => array()
        );

        // Get ONLY wallets that have submitted/confirmed solutions
        $wallets = $wpdb->get_results("
            SELECT DISTINCT w.*
            FROM {$wpdb->prefix}umbrella_mining_wallets w
            INNER JOIN {$wpdb->prefix}umbrella_mining_solutions s ON w.id = s.wallet_id
            WHERE s.submission_status IN ('submitted', 'confirmed')
            ORDER BY w.id ASC
        ", ARRAY_A);

        $total_solutions = 0;
        $total_receipts = 0;
        $wallets_with_solutions = count($wallets);

        foreach ($wallets as $wallet) {
            $wallet_id = $wallet['id'];

            // Get ONLY submitted/confirmed solutions for this wallet
            $solutions = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}umbrella_mining_solutions
                WHERE wallet_id = %d
                AND submission_status IN ('submitted', 'confirmed')
                ORDER BY found_at ASC
            ", $wallet_id), ARRAY_A);

            $wallet_solutions = array();
            $confirmed_count = 0;
            $receipt_count = 0;

            foreach ($solutions as $solution) {
                $solution_id = $solution['id'];

                // Get receipt for this solution (if exists)
                $receipt = $wpdb->get_row($wpdb->prepare("
                    SELECT * FROM {$wpdb->prefix}umbrella_mining_receipts
                    WHERE solution_id = %d
                    LIMIT 1
                ", $solution_id), ARRAY_A);

                // Add receipt to solution
                $solution['receipt'] = $receipt;

                if ($receipt) {
                    $receipt_count++;
                    $total_receipts++;
                }

                if ($solution['submission_status'] === 'confirmed') {
                    $confirmed_count++;
                }

                $wallet_solutions[] = $solution;
            }

            $total_solutions += count($solutions);
            if (count($solutions) > 0) {
                $wallets_with_solutions++;
            }

            // Get merge data for this wallet (if merged)
            $merge = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}umbrella_mining_merges
                WHERE original_wallet_id = %d
                AND status = 'success'
                ORDER BY merged_at DESC
                LIMIT 1
            ", $wallet_id), ARRAY_A);

            // Decrypt mnemonic for export
            $mnemonic_decrypted = '';
            if (!empty($wallet['mnemonic_encrypted'])) {
                $mnemonic_decrypted = UmbrellaMines_EncryptionHelper::decrypt($wallet['mnemonic_encrypted']);
                if ($mnemonic_decrypted === false) {
                    $mnemonic_decrypted = '[DECRYPTION_FAILED]';
                }
            }

            // Build wallet export object
            $wallet_export = array(
                'wallet_id' => (int)$wallet['id'],
                'address' => $wallet['address'],
                'derivation_path' => $wallet['derivation_path'],
                'payment_skey_extended' => $wallet['payment_skey_extended'], // 🔐 CRITICAL
                'payment_pkey' => $wallet['payment_pkey'],
                'payment_keyhash' => $wallet['payment_keyhash'],
                'network' => $wallet['network'],
                'mnemonic' => $mnemonic_decrypted, // 🔐 CRITICAL - Decrypted mnemonic phrase
                'registration' => array(
                    'signature' => $wallet['registration_signature'],
                    'pubkey' => $wallet['registration_pubkey'],
                    'registered_at' => $wallet['registered_at']
                ),
                'created_at' => $wallet['created_at'],
                'solutions' => $wallet_solutions,
                'stats' => array(
                    'total_solutions' => count($solutions),
                    'confirmed_solutions' => $confirmed_count,
                    'pending_solutions' => count($solutions) - $confirmed_count,
                    'total_receipts' => $receipt_count
                ),
                'merge' => $merge ? (function($merge) {
                    // Decrypt merge mnemonic
                    $merge_mnemonic = '';
                    if (!empty($merge['mnemonic_encrypted'])) {
                        $merge_mnemonic = UmbrellaMines_EncryptionHelper::decrypt($merge['mnemonic_encrypted']);
                        if ($merge_mnemonic === false) {
                            $merge_mnemonic = '[DECRYPTION_FAILED]';
                        }
                    }

                    return array(
                        'merge_id' => (int)$merge['id'],
                        'payout_address' => $merge['payout_address'],
                        'merge_signature' => $merge['merge_signature'],
                        'merge_receipt' => json_decode($merge['merge_receipt'], true),
                        'solutions_consolidated' => (int)$merge['solutions_consolidated'],
                        'status' => $merge['status'],
                        'mnemonic' => $merge_mnemonic, // 🔐 CRITICAL - Decrypted mnemonic
                        'merged_at' => $merge['merged_at']
                    );
                })($merge) : null
            );

            $export_data['wallets'][] = $wallet_export;
        }

        // Add summary stats
        $export_data['total_wallets'] = count($wallets);
        $export_data['total_solutions'] = $total_solutions;
        $export_data['total_receipts'] = $total_receipts;

        // Count merged wallets
        $merged_wallets = $wpdb->get_var("
            SELECT COUNT(DISTINCT original_wallet_id)
            FROM {$wpdb->prefix}umbrella_mining_merges
            WHERE status = 'success'
        ");

        $export_data['summary'] = array(
            'wallets_with_solutions' => $wallets_with_solutions,
            'wallets_without_solutions' => count($wallets) - $wallets_with_solutions,
            'solutions_with_receipts' => $total_receipts,
            'solutions_pending_receipt' => $total_solutions - $total_receipts,
            'merged_wallets' => (int)$merged_wallets,
            'unmerged_wallets' => count($wallets) - (int)$merged_wallets
        );

        // Generate filename with timestamp
        $filename = 'umbrella-mines-export-' . date('Y-m-d-His') . '.json';

        // Send JSON file
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');

        echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * AJAX: Get mining status (live dashboard)
     */
    public function ajax_get_mining_status_live() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;

        $log_file = WP_CONTENT_DIR . '/umbrella-mines-output.log';

        // Check if mining is active
        $is_mining = false;
        $last_modified = 0;
        if (file_exists($log_file)) {
            $last_modified = filemtime($log_file);
            $is_mining = (time() - $last_modified) < 60;
        }

        // Get active processes
        $processes = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}umbrella_mining_processes WHERE status = 'running' ORDER BY started_at DESC",
            ARRAY_A
        );

        // Get log output
        $mining_output = '';
        if (file_exists($log_file) && filesize($log_file) > 0) {
            $lines = file($log_file);
            if ($lines !== false) {
                $total_lines = count($lines);
                if ($total_lines > 100) {
                    $mining_output = "... [showing last 100 lines of {$total_lines} total]\n\n";
                    $mining_output .= implode('', array_slice($lines, -100));
                } else {
                    $mining_output .= implode('', $lines);
                }
            }
        }

        $seconds_since_update = $is_mining ? (time() - $last_modified) : 60;

        wp_send_json_success(array(
            'is_mining' => $is_mining,
            'processes' => $processes,
            'output' => $mining_output,
            'last_modified' => $last_modified,
            'seconds_since_update' => $seconds_since_update
        ));
    }

    /**
     * AJAX handler to get just terminal output (lightweight)
     */
    public function ajax_get_terminal_output() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $log_file = WP_CONTENT_DIR . '/umbrella-mines-output.log';
        $output = '';
        $is_mining = false;

        if (file_exists($log_file) && filesize($log_file) > 0) {
            $last_modified = filemtime($log_file);
            $time_since_modified = time() - $last_modified;

            // Skip reading if file was modified in last 0.5 seconds (heavy write activity)
            // This prevents blocking when solution is found or wallet is switching
            if ($time_since_modified < 0.5) {
                // File is being actively written to - skip this read, return empty to use cached content
                wp_send_json_success(array(
                    'output' => '',
                    'is_mining' => true,
                    'status' => 'ACTIVE',
                    'skipped' => true // Let client know we skipped
                ));
                return;
            }

            // Read last 100 lines efficiently without loading entire file
            $lines = $this->read_last_lines($log_file, 100);

            if (!empty($lines)) {
                $output = implode('', $lines);

                // Check for stop signal in recent content
                $recent_content = implode('', array_slice($lines, -50));
                if (strpos($recent_content, 'Stop signal received. Mining stopped gracefully') !== false) {
                    $is_mining = false;
                } else {
                    $is_mining = $time_since_modified < 60;
                }
            }
        }

        wp_send_json_success(array(
            'output' => esc_html($output),
            'is_mining' => $is_mining,
            'status' => $is_mining ? 'ACTIVE' : 'IDLE'
        ));
    }

    /**
     * Read last N lines from file without loading entire file into memory
     * Works on all OS (Windows, Linux, macOS)
     */
    public function read_last_lines($file, $lines = 100) {
        $buffer_size = 4096;
        $output = array();

        $fp = fopen($file, 'rb');
        if (!$fp) {
            return $output;
        }

        // Get file size
        fseek($fp, 0, SEEK_END);
        $file_size = ftell($fp);

        // Start from end of file
        $pos = $file_size;
        $buffer = '';
        $line_count = 0;

        // Read backwards in chunks
        while ($pos > 0 && $line_count < $lines) {
            // Calculate how much to read
            $read_size = min($buffer_size, $pos);
            $pos -= $read_size;

            // Read chunk
            fseek($fp, $pos, SEEK_SET);
            $chunk = fread($fp, $read_size);

            // Prepend to buffer
            $buffer = $chunk . $buffer;

            // Count lines in buffer
            $buffer_lines = explode("\n", $buffer);
            $line_count = count($buffer_lines) - 1; // -1 because last element might be incomplete
        }

        fclose($fp);

        // Split buffer into lines and get last N
        $all_lines = explode("\n", $buffer);
        $output = array_slice($all_lines, -$lines);

        // Re-add newlines
        $output = array_map(function($line) { return $line . "\n"; }, $output);

        return $output;
    }

    /**
     * AJAX handler to send graceful stop signal to mining process
     */
    public function ajax_send_stop_signal() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Create the stop flag file
        $stop_file = WP_CONTENT_DIR . '/umbrella-mines-stop.flag';
        $result = file_put_contents($stop_file, '1');

        if ($result !== false) {
            wp_send_json_success('Stop signal sent');
        } else {
            wp_send_json_error('Failed to create stop signal file');
        }
    }

    /**
     * AJAX: Export payout wallet mnemonic
     */
    public function ajax_export_payout_mnemonic() {
        check_ajax_referer('umbrella_payout_wallet', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-payout-wallet.php';

        $network = get_option('umbrella_mines_network', 'mainnet');
        $wallet = Umbrella_Mines_Payout_Wallet::get_active_wallet($network);

        if (!$wallet) {
            wp_send_json_error('No payout wallet found');
        }

        $mnemonic = Umbrella_Mines_Payout_Wallet::get_mnemonic($wallet->id);

        if (!$mnemonic) {
            wp_send_json_error('Failed to decrypt mnemonic');
        }

        wp_send_json_success(['mnemonic' => $mnemonic]);
    }

    /**
     * AJAX: Delete payout wallet
     */
    public function ajax_delete_payout_wallet() {
        check_ajax_referer('umbrella_payout_wallet', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-payout-wallet.php';

        $network = get_option('umbrella_mines_network', 'mainnet');
        $wallet = Umbrella_Mines_Payout_Wallet::get_active_wallet($network);

        if (!$wallet) {
            wp_send_json_error('No payout wallet found');
        }

        $result = Umbrella_Mines_Payout_Wallet::delete_wallet($wallet->id);

        if ($result) {
            wp_send_json_success('Payout wallet deleted');
        } else {
            wp_send_json_error('Failed to delete wallet');
        }
    }

    /**
     * AJAX: Merge all eligible wallets to payout address
     */
    public function ajax_merge_all_wallets() {
        check_ajax_referer('umbrella_merge_wallets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-payout-wallet.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';

        $network = get_option('umbrella_mines_network', 'mainnet');

        // merge_all() now automatically uses the registered payout wallet
        $result = Umbrella_Mines_Merge_Processor::merge_all($network);

        wp_send_json_success($result);
    }

    /**
     * AJAX: Merge single wallet to payout address
     */
    public function ajax_merge_single_wallet() {
        check_ajax_referer('umbrella_merge_wallets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!isset($_POST['wallet_id'])) {
            wp_send_json_error('Wallet ID required');
        }

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-payout-wallet.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';

        $network = get_option('umbrella_mines_network', 'mainnet');

        // Get dynamically selected payout wallet (must have: receipt, mnemonic, not merged, registered)
        $payout_wallet = Umbrella_Mines_Merge_Processor::get_registered_payout_wallet($network);

        if (!$payout_wallet) {
            wp_send_json_error('No eligible payout wallet found. Please ensure you have at least one wallet with confirmed solutions and mnemonic.');
            return;
        }

        $payout_address = $payout_wallet->address;
        error_log("AJAX MERGE: Using dynamically selected payout address: " . $payout_address);

        $wallet_id = intval($_POST['wallet_id']);
        $result = Umbrella_Mines_Merge_Processor::merge_wallet($wallet_id, $payout_address);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * AJAX: Export all merged wallets data
     */
    public function ajax_export_all_merged() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        // Load encryption helper for mnemonic decryption
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/UmbrellaMines_EncryptionHelper.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';

        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';
        $wallets_table = $wpdb->prefix . 'umbrella_mining_wallets';

        // Get active payout wallet
        $network = get_option('umbrella_mines_network', 'mainnet');
        $payout_wallet = Umbrella_Mines_Merge_Processor::get_registered_payout_wallet($network);

        // Prepare payout wallet info for export
        $payout_wallet_export = null;
        if ($payout_wallet) {
            // Decrypt mnemonic
            $payout_mnemonic = '';
            if (!empty($payout_wallet->mnemonic_encrypted)) {
                $payout_mnemonic = UmbrellaMines_EncryptionHelper::decrypt($payout_wallet->mnemonic_encrypted);
                if ($payout_mnemonic === false) {
                    $payout_mnemonic = '[DECRYPTION_FAILED]';
                }
            }

            // Check if it's imported (has wallet_name) or auto-selected
            $is_imported = property_exists($payout_wallet, 'wallet_name');

            $payout_wallet_export = array(
                '⭐_IMPORTANT' => '🔑 THIS IS YOUR PAYOUT WALLET - All merged rewards go here',
                'wallet_type' => $is_imported ? 'IMPORTED (User-provided)' : 'AUTO-SELECTED (From mining wallets)',
                'address' => $payout_wallet->address,
                'mnemonic' => $payout_mnemonic, // 🔐 CRITICAL - Full 24-word phrase
                'payment_skey_extended' => property_exists($payout_wallet, 'payment_skey_extended') ? $payout_wallet->payment_skey_extended : (property_exists($payout_wallet, 'payment_skey_extended_encrypted') ? '[ENCRYPTED - Use mnemonic to recover]' : null),
                'payment_pkey' => $payout_wallet->payment_pkey,
                'payment_keyhash' => $payout_wallet->payment_keyhash,
                'network' => $payout_wallet->network,
                'created_at' => $payout_wallet->created_at,
                'cardanoscan_link' => $network === 'mainnet'
                    ? 'https://cardanoscan.io/address/' . $payout_wallet->address
                    : 'https://preprod.cardanoscan.io/address/' . $payout_wallet->address
            );

            if ($is_imported && property_exists($payout_wallet, 'wallet_name')) {
                $payout_wallet_export['wallet_name'] = $payout_wallet->wallet_name;
            }
        }

        // Get all successful merges with wallet data
        $merges = $wpdb->get_results("
            SELECT m.*, w.address as original_address, w.derivation_path, w.created_at as wallet_created_at
            FROM {$merges_table} m
            LEFT JOIN {$wallets_table} w ON m.original_wallet_id = w.id
            WHERE m.status = 'success'
            ORDER BY m.merged_at DESC
        ", ARRAY_A);

        $export_data = [
            'export_date' => current_time('mysql'),
            'export_type' => 'merged_wallets',
            'PAYOUT_WALLET' => $payout_wallet_export, // 🎯 PAYOUT DESTINATION - Listed first for easy access
            'plugin_version' => UMBRELLA_MINES_VERSION,
            'total_merged' => count($merges),
            'merges' => []
        ];

        foreach ($merges as $merge) {
            // Decode the merge_receipt JSON
            $receipt = json_decode($merge['merge_receipt'], true);

            // Decrypt mnemonic for export
            $mnemonic_decrypted = '';
            if (!empty($merge['mnemonic_encrypted'])) {
                $mnemonic_decrypted = UmbrellaMines_EncryptionHelper::decrypt($merge['mnemonic_encrypted']);
                if ($mnemonic_decrypted === false) {
                    $mnemonic_decrypted = '[DECRYPTION_FAILED]';
                }
            }

            $export_data['merges'][] = [
                'merge_id' => (int)$merge['id'],
                'wallet_id' => (int)$merge['original_wallet_id'],
                'original_address' => $merge['original_address'] ?? $merge['original_addr'],
                'payout_address' => $merge['payout_address'],
                'merge_signature' => $merge['merge_signature'],
                'solutions_consolidated' => (int)$merge['solutions_consolidated'],
                'merged_at' => $merge['merged_at'],
                'wallet_created_at' => $merge['wallet_created_at'],
                'derivation_path' => $merge['derivation_path'],
                'mnemonic' => $mnemonic_decrypted, // 🔐 CRITICAL - Decrypted mnemonic phrase
                'receipt' => $receipt,
                'donation_id' => $receipt['donation_id'] ?? null,
                'api_timestamp' => $receipt['timestamp'] ?? null
            ];
        }

        // Summary stats
        $total_solutions = array_sum(array_column($export_data['merges'], 'solutions_consolidated'));
        $export_data['summary'] = [
            'total_wallets_merged' => count($merges),
            'total_solutions_consolidated' => $total_solutions,
            'average_solutions_per_wallet' => count($merges) > 0 ? round($total_solutions / count($merges), 2) : 0
        ];

        // Generate filename
        $filename = 'umbrella-mines-merged-wallets-' . date('Y-m-d-His') . '.json';

        // Send JSON file
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');

        echo json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * AJAX: Get merge details for viewing
     */
    public function ajax_get_merge_details() {
        $merge_id = isset($_REQUEST['merge_id']) ? intval($_REQUEST['merge_id']) : 0;

        if (!$merge_id) {
            echo 'Invalid merge ID';
            wp_die();
        }

        global $wpdb;

        $merges_table = $wpdb->prefix . 'umbrella_mining_merges';
        $wallets_table = $wpdb->prefix . 'umbrella_mining_wallets';

        // Get merge with wallet data
        $merge = $wpdb->get_row($wpdb->prepare("
            SELECT m.*, w.address as wallet_address, w.derivation_path, w.network, w.created_at as wallet_created_at
            FROM {$merges_table} m
            LEFT JOIN {$wallets_table} w ON m.original_wallet_id = w.id
            WHERE m.id = %d
        ", $merge_id));

        if (!$merge) {
            echo 'Merge not found';
            wp_die();
        }

        // Decode receipt
        $receipt = json_decode($merge->merge_receipt, true);

        // Status color
        $status_colors = [
            'success' => '#46b450',
            'failed' => '#dc3232',
            'pending' => '#999',
            'processing' => '#0073aa'
        ];
        $status_color = $status_colors[$merge->status] ?? '#666';

        ?>
        <style>
        .merge-details-section {
            background: linear-gradient(145deg, #1a1f3a 0%, #0f1429 100%);
            border-left: 4px solid #00ff41;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .merge-details-section h3 {
            color: #00ff41;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin: 0 0 15px 0;
        }
        .merge-details-table {
            width: 100%;
            border-collapse: collapse;
        }
        .merge-details-table tr {
            border-bottom: 1px solid #2a3f5f;
        }
        .merge-details-table tr:last-child {
            border-bottom: none;
        }
        .merge-details-table th {
            color: #00ff41;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 15px 10px 0;
            text-align: left;
            vertical-align: top;
            width: 200px;
        }
        .merge-details-table td {
            color: #e0e0e0;
            font-size: 13px;
            padding: 10px 0;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 600px;
        }
        .merge-details-table code {
            background: rgba(0, 255, 65, 0.1);
            color: #00ff41;
            padding: 4px 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            word-break: break-word;
            overflow-wrap: break-word;
            white-space: pre-wrap;
            display: inline-block;
            max-width: 100%;
        }
        .merge-details-table pre {
            background: rgba(0, 255, 65, 0.05);
            border: 1px solid rgba(0, 255, 65, 0.2);
            color: #00ff41;
            padding: 12px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            overflow-wrap: break-word;
            max-width: 100%;
        }
        .status-badge-details {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        </style>

        <!-- Merge Summary -->
        <div class="merge-details-section">
            <h3>🔀 Merge Summary</h3>
            <table class="merge-details-table">
                <tr>
                    <th>Merge ID</th>
                    <td><code><?php echo esc_html($merge->id); ?></code></td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td><span class="status-badge-details" style="background: <?php echo $status_color; ?>; color: #fff;"><?php echo esc_html(strtoupper($merge->status)); ?></span></td>
                </tr>
                <tr>
                    <th>Merged At</th>
                    <td><?php echo esc_html($merge->merged_at); ?></td>
                </tr>
                <tr>
                    <th>Solutions Consolidated</th>
                    <td><strong><?php echo esc_html($merge->solutions_consolidated); ?></strong> solution(s)</td>
                </tr>
                <?php if (isset($receipt['donation_id'])): ?>
                <tr>
                    <th>Donation ID</th>
                    <td><code><?php echo esc_html($receipt['donation_id']); ?></code></td>
                </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Wallet Information -->
        <div class="merge-details-section">
            <h3>💼 Wallet Information</h3>
            <table class="merge-details-table">
                <tr>
                    <th>Wallet ID</th>
                    <td><code><?php echo esc_html($merge->original_wallet_id); ?></code></td>
                </tr>
                <tr>
                    <th>Original Address</th>
                    <td><code><?php echo esc_html($merge->wallet_address ?? $merge->original_address); ?></code></td>
                </tr>
                <tr>
                    <th>Payout Address</th>
                    <td><code><?php echo esc_html($merge->payout_address); ?></code></td>
                </tr>
                <tr>
                    <th>Derivation Path</th>
                    <td><code><?php echo esc_html($merge->derivation_path ?: 'N/A'); ?></code></td>
                </tr>
                <tr>
                    <th>Network</th>
                    <td><code><?php echo esc_html($merge->network ?? 'mainnet'); ?></code></td>
                </tr>
                <tr>
                    <th>Wallet Created</th>
                    <td><?php echo esc_html($merge->wallet_created_at ?? 'N/A'); ?></td>
                </tr>
            </table>
        </div>

        <!-- Merge Signature -->
        <div class="merge-details-section">
            <h3>🔐 Cryptographic Proof</h3>
            <table class="merge-details-table">
                <tr>
                    <th>Merge Signature</th>
                    <td><code><?php echo esc_html($merge->merge_signature); ?></code></td>
                </tr>
            </table>
        </div>

        <!-- API Receipt -->
        <div class="merge-details-section" style="border-left-color: #00d4ff;">
            <h3 style="color: #00d4ff;">🧾 API Receipt</h3>
            <table class="merge-details-table">
                <?php if ($receipt): ?>
                    <?php if (isset($receipt['message'])): ?>
                    <tr>
                        <th>Message</th>
                        <td><?php echo esc_html($receipt['message']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (isset($receipt['timestamp'])): ?>
                    <tr>
                        <th>API Timestamp</th>
                        <td><?php echo esc_html($receipt['timestamp']); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th>Full Receipt</th>
                        <td><pre><?php echo esc_html(json_encode($receipt, JSON_PRETTY_PRINT)); ?></pre></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="2" style="text-align: center; color: #666;">No receipt data available</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <?php if ($merge->error_message): ?>
        <!-- Error Details -->
        <div class="merge-details-section" style="border-left-color: #dc3232;">
            <h3 style="color: #dc3232;">⚠️ Error Details</h3>
            <table class="merge-details-table">
                <tr>
                    <th>Error Message</th>
                    <td><pre><?php echo esc_html($merge->error_message); ?></pre></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        <?php

        wp_die();
    }

    /**
     * AJAX: Import custom payout wallet from mnemonic
     */
    public function ajax_import_payout_wallet() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $mnemonic = isset($_POST['mnemonic']) ? trim($_POST['mnemonic']) : '';

        if (empty($mnemonic)) {
            wp_send_json_error('Mnemonic phrase is required');
        }

        // Validate: must be 24 words
        $words = preg_split('/\s+/', $mnemonic);
        $words = array_filter($words, function($w) { return strlen($w) > 0; });

        if (count($words) !== 24) {
            wp_send_json_error('Mnemonic must be exactly 24 words (found ' . count($words) . ')');
        }

        $network = get_option('umbrella_mines_network', 'mainnet');
        $user_derivation_path = isset($_POST['derivation_path']) ? sanitize_text_field($_POST['derivation_path']) : '0/0/0';

        // Derive wallet from mnemonic
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoWalletPHP.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/UmbrellaMines_EncryptionHelper.php';
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';

        try {
            global $wpdb;

            // Try to find matching wallet in database across common derivation paths
            $found_match = false;
            $wallet = null;
            $address = null;
            $used_path = null;

            error_log("Searching for matching wallet in database...");

            for ($i = 0; $i < 10; $i++) {
                $test_wallet = CardanoWalletPHP::fromMnemonicWithPath($mnemonic, 0, 0, $i, '', $network);
                $test_address = $test_wallet['addresses']['payment_address'];

                // Check if this address exists in database
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT address, derivation_path, registered_at FROM {$wpdb->prefix}umbrella_mining_wallets WHERE address = %s",
                    $test_address
                ));

                if ($existing && $existing->registered_at) {
                    error_log("✅ Found matching registered wallet at path m/1852'/1815'/0'/0/$i - Address: $test_address");
                    $wallet = $test_wallet;
                    $address = $test_address;
                    $used_path = "0/0/$i";
                    $found_match = true;
                    break;
                }
            }

            // If not found in database, use user-provided derivation path
            if (!$found_match) {
                error_log("⚠️ No matching wallet found in database, using user-provided path: $user_derivation_path");

                // Parse user derivation path
                $path_parts = explode('/', $user_derivation_path);
                if (count($path_parts) !== 3) {
                    wp_send_json_error('Invalid derivation path format. Use: 0/0/0');
                }

                $account = intval($path_parts[0]);
                $chain = intval($path_parts[1]);
                $addr_index = intval($path_parts[2]);

                $wallet = CardanoWalletPHP::fromMnemonicWithPath($mnemonic, $account, $chain, $addr_index, '', $network);
                $address = $wallet['addresses']['payment_address'];
                $used_path = $user_derivation_path;
            }

            error_log("=== IMPORT PAYOUT WALLET ===");
            error_log("Address: $address");
            error_log("Network: $network");

            // Get T&C to get the registration message
            $api_url = get_option('umbrella_mines_api_url', 'https://scavenger.prod.gd.midnighttge.io');
            require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-scavenger-api.php';
            $tandc = Umbrella_Mines_ScavengerAPI::get_tandc($api_url);

            if (!$tandc || !isset($tandc['message'])) {
                wp_send_json_error('Failed to fetch Terms & Conditions from API');
            }

            error_log("T&C message: " . $tandc['message']);

            // Sign T&C message (the 'message' field, not 'content')
            require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/vendor/CardanoCIP8Signer.php';
            $signature_result = CardanoCIP8Signer::sign_message(
                $tandc['message'],
                $wallet['payment_skey_extended'],
                $address,
                $network
            );
            $signature_hex = $signature_result['signature'];
            $pubkey = $signature_result['pubkey'];

            error_log("Testing registration with signature: " . substr($signature_hex, 0, 50) . "...");

            // Call /register endpoint to TEST if wallet is already registered
            $endpoint = "{$api_url}/register/{$address}/{$signature_hex}/{$pubkey}";

            $response = wp_remote_post($endpoint, [
                'timeout' => 15,
                'headers' => ['Content-Type' => 'application/json']
            ]);

            global $wpdb;

            if (is_wp_error($response)) {
                error_log("API Error: " . $response->get_error_message());
                wp_send_json_error('Failed to verify wallet registration: ' . $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            error_log("Registration test - Status: $status_code");
            error_log("Registration test - Body: $body");

            $is_registered = false;

            // 200, 201, or 409 = Successfully registered (API is idempotent, always returns 201)
            if ($status_code === 200 || $status_code === 201 || $status_code === 409) {
                $is_registered = true;
                error_log("✅ Wallet IS registered (status $status_code)");
            }
            // "already registered" message in error = Also valid
            else if (isset($data['error']) && stripos($data['error'], 'already registered') !== false) {
                $is_registered = true;
                error_log("✅ Wallet IS registered ('already registered' message)");
            }
            // Any other error
            else {
                error_log("❌ Registration test failed with status $status_code");
                wp_send_json_error('Failed to verify wallet: ' . ($data['error'] ?? 'Unknown error'));
            }

            // Encrypt mnemonic before storing
            $mnemonic_encrypted = UmbrellaMines_EncryptionHelper::encrypt($mnemonic);

            // Store in payout_wallet table
            $payout_table = $wpdb->prefix . 'umbrella_mining_payout_wallet';

            // Check if we already have an active imported payout wallet
            $existing_payout = $wpdb->get_row("SELECT * FROM {$payout_table} WHERE is_active = 1 LIMIT 1");

            if ($existing_payout) {
                // Deactivate old one
                $wpdb->update(
                    $payout_table,
                    array('is_active' => 0),
                    array('id' => $existing_payout->id),
                    array('%d'),
                    array('%d')
                );
            }

            // Insert new imported wallet
            $inserted = $wpdb->insert(
                $payout_table,
                array(
                    'wallet_name' => 'Imported Payout Wallet',
                    'address' => $address,
                    'mnemonic_encrypted' => $mnemonic_encrypted,
                    'payment_skey_extended_encrypted' => UmbrellaMines_EncryptionHelper::encrypt($wallet['payment_skey_extended']),
                    'payment_pkey' => $wallet['payment_pkey_hex'],
                    'payment_keyhash' => $wallet['payment_keyhash'],
                    'network' => $network,
                    'is_active' => 1,
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
            );

            if ($inserted) {
                $message = 'Wallet imported successfully!';
                if (!$found_match) {
                    $message .= " ⚠️ Warning: Using custom derivation path $used_path (not found in database). Verify this is correct.";
                }

                wp_send_json_success(array(
                    'message' => $message,
                    'address' => $address,
                    'network' => $network,
                    'is_registered' => $is_registered,
                    'derivation_path' => $used_path,
                    'found_in_database' => $found_match
                ));
            } else {
                error_log("Failed to insert payout wallet: " . $wpdb->last_error);
                wp_send_json_error('Failed to save wallet to database: ' . $wpdb->last_error);
            }

        } catch (Exception $e) {
            error_log("Error deriving wallet: " . $e->getMessage());
            wp_send_json_error('Failed to derive wallet: ' . $e->getMessage());
        }
    }

    /**
     * AJAX: Clear/deactivate imported payout wallet
     */
    public function ajax_clear_imported_wallet() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $payout_table = $wpdb->prefix . 'umbrella_mining_payout_wallet';

        // Deactivate all imported wallets
        $updated = $wpdb->query("UPDATE {$payout_table} SET is_active = 0 WHERE is_active = 1");

        if ($updated !== false) {
            error_log("=== CLEARED IMPORTED WALLET ===");
            error_log("Deactivated $updated imported wallet(s)");
            wp_send_json_success(array(
                'message' => 'Imported wallet removed successfully',
                'deactivated_count' => $updated
            ));
        } else {
            error_log("Failed to clear imported wallet: " . $wpdb->last_error);
            wp_send_json_error('Failed to deactivate imported wallet: ' . $wpdb->last_error);
        }
    }

    /**
     * AJAX: Load merge history page
     */
    public function ajax_load_merge_page() {
        check_ajax_referer('umbrella_merge_pagination', 'nonce');

        $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
        $network = get_option('umbrella_mines_network', 'mainnet');

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';

        // Get merge history for this page
        $stats = Umbrella_Mines_Merge_Processor::get_statistics($network, $page, 10);

        // Format the data for JavaScript
        $merges = array();
        foreach ($stats['merge_history'] as $merge) {
            $merges[] = array(
                'id' => $merge->id,
                'original_address' => $merge->original_address,
                'solutions_consolidated' => (int) $merge->solutions_consolidated,
                'status' => $merge->status,
                'time_ago' => human_time_diff(strtotime($merge->merged_at), current_time('timestamp'))
            );
        }

        wp_send_json_success(array(
            'merges' => $merges
        ));
    }

    /**
     * AJAX: Create merge session for chunked processing
     */
    public function ajax_create_merge_session() {
        check_ajax_referer('umbrella_merge_wallets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';

        $network = get_option('umbrella_mines_network', 'mainnet');
        $result = Umbrella_Mines_Merge_Processor::create_merge_session($network);

        if (!$result['success']) {
            wp_send_json_error($result['error']);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Process chunk of merge session
     */
    public function ajax_process_merge_chunk() {
        check_ajax_referer('umbrella_merge_wallets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $session_key = isset($_POST['session_key']) ? sanitize_text_field($_POST['session_key']) : '';
        $chunk_size = isset($_POST['chunk_size']) ? intval($_POST['chunk_size']) : 20;

        if (empty($session_key)) {
            wp_send_json_error('Session key required');
        }

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';

        $result = Umbrella_Mines_Merge_Processor::process_merge_chunk($session_key, $chunk_size);

        if (!$result['success']) {
            wp_send_json_error($result['error']);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Get merge session status
     */
    public function ajax_get_merge_session_status() {
        check_ajax_referer('umbrella_merge_wallets', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $session_key = isset($_POST['session_key']) ? sanitize_text_field($_POST['session_key']) : '';

        if (empty($session_key)) {
            wp_send_json_error('Session key required');
        }

        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';

        $session = Umbrella_Mines_Merge_Processor::get_merge_session($session_key);

        if (!$session) {
            wp_send_json_error('Session not found');
        }

        wp_send_json_success($session);
    }

    /**
     * AJAX: Parse uploaded Night Miner export ZIP
     */
    public function ajax_parse_import_file() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Check for uploaded file
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('No file uploaded or upload error');
        }

        $file = $_FILES['import_file'];

        // Validate file type
        if (!in_array($file['type'], ['application/zip', 'application/x-zip-compressed'])) {
            wp_send_json_error('Invalid file type. Please upload a ZIP file.');
        }

        // Validate file size (50MB max)
        if ($file['size'] > 50 * 1024 * 1024) {
            wp_send_json_error('File too large. Maximum size is 50MB.');
        }

        $network = get_option('umbrella_mines_network', 'mainnet');

        // Load import processor
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-import-processor.php';

        // Parse the file
        $result = Umbrella_Mines_Import_Processor::parse_night_miner_export($file['tmp_name'], $network);

        if (is_wp_error($result)) {
            error_log("Parse error: " . $result->get_error_message());
            wp_send_json_error($result->get_error_message());
        }

        // Debug: Log network and result
        error_log("Network setting: $network");
        error_log("Parse result - Valid: " . $result['wallet_count'] . ", Invalid: " . $result['invalid_wallets']);

        // Get payout address for session creation
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';
        $payout_wallet = Umbrella_Mines_Merge_Processor::get_registered_payout_wallet($network);

        if (!$payout_wallet) {
            wp_send_json_error('You must configure a payout wallet before importing solutions.');
        }

        // Create import session
        $session_key = Umbrella_Mines_Import_Processor::create_import_session(
            $result['wallets'],
            $payout_wallet->address,
            $network
        );

        wp_send_json_success(array(
            'wallet_count' => $result['wallet_count'],
            'wallets_with_solutions' => $result['wallets_with_solutions'],
            'total_solutions' => $result['total_solutions'],
            'invalid_wallets' => $result['invalid_wallets'],
            'night_estimate' => $result['night_estimate'],
            'network' => $result['network'],
            'payout_address' => $payout_wallet->address,
            'session_key' => $session_key
        ));
    }

    /**
     * AJAX: Start batch merge process
     */
    public function ajax_start_batch_merge() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $session_key = isset($_POST['session_key']) ? sanitize_text_field($_POST['session_key']) : '';

        if (empty($session_key)) {
            wp_send_json_error('Session key required');
        }

        // Load import processor
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-import-processor.php';

        // Start batch merge
        $result = Umbrella_Mines_Import_Processor::batch_merge_with_resume($session_key);

        if (!$result['success']) {
            wp_send_json_error($result['error'] ?? 'Merge failed');
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Get merge progress for session
     */
    public function ajax_get_merge_progress() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $session_key = isset($_POST['session_key']) ? sanitize_text_field($_POST['session_key']) : '';

        if (empty($session_key)) {
            wp_send_json_error('Session key required');
        }

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'umbrella_mining_import_sessions';

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sessions_table} WHERE session_key = %s",
            $session_key
        ));

        if (!$session) {
            wp_send_json_error('Session not found');
        }

        wp_send_json_success(array(
            'status' => $session->status,
            'total' => $session->total_wallets,
            'processed' => $session->processed_wallets,
            'successful' => $session->successful_count,
            'failed' => $session->failed_count,
            'complete' => in_array($session->status, ['completed', 'failed'])
        ));
    }

    /**
     * AJAX: Download import receipt
     */
    public function ajax_download_import_receipt() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $session_key = isset($_POST['session_key']) ? sanitize_text_field($_POST['session_key']) : '';

        if (empty($session_key)) {
            wp_send_json_error('Session key required');
        }

        // Load import processor
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-import-processor.php';

        $receipt = Umbrella_Mines_Import_Processor::generate_import_receipt($session_key);

        if (!$receipt || isset($receipt['error'])) {
            wp_send_json_error($receipt['error'] ?? 'Failed to generate receipt');
        }

        // Return receipt as JSON for download
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="umbrella-import-receipt-' . date('Y-m-d-His') . '.json"');
        echo json_encode($receipt, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * AJAX: Check for interrupted sessions
     */
    public function ajax_check_interrupted_sessions() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        // Load import processor
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-import-processor.php';

        $session = Umbrella_Mines_Import_Processor::get_interrupted_session();

        if ($session) {
            wp_send_json_success(array('interrupted_session' => $session));
        } else {
            wp_send_json_success(array('interrupted_session' => false));
        }
    }

    /**
     * AJAX: Cancel import session
     */
    public function ajax_cancel_import_session() {
        check_ajax_referer('umbrella_mining', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $session_key = isset($_POST['session_key']) ? sanitize_text_field($_POST['session_key']) : '';

        if (empty($session_key)) {
            wp_send_json_error('Session key required');
        }

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'umbrella_mining_import_sessions';

        $updated = $wpdb->update(
            $sessions_table,
            ['status' => 'cancelled'],
            ['session_key' => $session_key]
        );

        if ($updated !== false) {
            wp_send_json_success(array('message' => 'Session cancelled'));
        } else {
            wp_send_json_error('Failed to cancel session');
        }
    }

    /**
     * Parse uploaded Umbrella Mines JSON export file
     */
    public function ajax_parse_umbrella_json() {
        try {
            check_ajax_referer('umbrella_mining', 'nonce');

            if (!current_user_can('manage_options')) {
                wp_send_json_error('Unauthorized');
            }

            // Check for uploaded file
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                $error_code = isset($_FILES['import_file']['error']) ? $_FILES['import_file']['error'] : 'unknown';
                wp_send_json_error('No file uploaded or upload error (code: ' . $error_code . ')');
            }

            $file = $_FILES['import_file'];

        // Validate file type (check extension - MIME types vary by browser/OS)
        $filename_lower = strtolower($file['name']);
        if (substr($filename_lower, -5) !== '.json') {
            wp_send_json_error('Invalid file type. Please upload a JSON file.');
        }

        // Validate file size (50MB max)
        if ($file['size'] > 50 * 1024 * 1024) {
            wp_send_json_error('File too large. Maximum size is 50MB.');
        }

        // Read and parse JSON
        $json_content = file_get_contents($file['tmp_name']);
        if ($json_content === false) {
            wp_send_json_error('Failed to read uploaded file');
        }

        $import_data = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error('Invalid JSON format: ' . json_last_error_msg());
        }

        // Validate JSON structure
        if (!isset($import_data['wallets']) || !is_array($import_data['wallets'])) {
            wp_send_json_error('Invalid Umbrella Mines export format: Missing wallets array');
        }


        // Extract all payout addresses to exclude
        $payout_addresses_to_skip = array();

        if (isset($import_data['PAYOUT_WALLET'])) {
            // Handle both old format (single wallet) and new format (multiple wallets)
            if (isset($import_data['PAYOUT_WALLET']['payout_wallets'])) {
                // New format with multiple payout wallets
                foreach ($import_data['PAYOUT_WALLET']['payout_wallets'] as $payout) {
                    if (isset($payout['address'])) {
                        $payout_addresses_to_skip[$payout['address']] = true;
                    }
                }
            } elseif (isset($import_data['PAYOUT_WALLET']['address'])) {
                // Old format with single wallet
                $payout_addresses_to_skip[$import_data['PAYOUT_WALLET']['address']] = true;
            }
        }


        // Get current payout wallet
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-merge-processor.php';
        $network = get_option('umbrella_mines_network', 'mainnet');
        $payout_wallet = Umbrella_Mines_Merge_Processor::get_registered_payout_wallet($network);

        if (!$payout_wallet) {
            wp_send_json_error('No payout wallet configured. Please set up a payout wallet first.');
        }

        // Process wallets and filter out payout wallets
        require_once UMBRELLA_MINES_PLUGIN_DIR . 'includes/class-import-processor.php';

        global $wpdb;

        $valid_wallets = array();
        $skipped_payout_count = 0;
        $skipped_already_merged_count = 0;
        $total_solutions = 0;
        $challenge_submissions = array();

        foreach ($import_data['wallets'] as $wallet) {
            // Skip if this wallet is a payout wallet
            if (isset($payout_addresses_to_skip[$wallet['address']])) {
                $skipped_payout_count++;
                continue;
            }

            // Skip if this wallet has already been successfully merged
            $already_merged = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->prefix}umbrella_mining_merges
                WHERE original_address = %s AND status = 'success'
            ", $wallet['address']));

            if ($already_merged > 0) {
                $skipped_already_merged_count++;
                continue;
            }

            // Validate wallet structure
            if (empty($wallet['address']) || empty($wallet['mnemonic']) || !isset($wallet['solutions'])) {
                continue;
            }

            // Count solutions and collect challenge IDs for NIGHT calculation
            $wallet_solution_count = 0;
            if (is_array($wallet['solutions'])) {
                $wallet_solution_count = count($wallet['solutions']);
                $total_solutions += $wallet_solution_count;

                // Collect challenge submissions for NIGHT calculation
                foreach ($wallet['solutions'] as $solution) {
                    if (isset($solution['challenge_id'])) {
                        $challenge_id = $solution['challenge_id'];
                        if (!isset($challenge_submissions[$challenge_id])) {
                            $challenge_submissions[$challenge_id] = array();
                        }
                        $challenge_submissions[$challenge_id][] = $wallet['address'];
                    }
                }
            }

            $valid_wallets[] = $wallet;
        }


        if (count($valid_wallets) === 0) {
            $error_msg = 'No valid wallets found in import file.';
            if ($skipped_payout_count > 0) {
                $error_msg .= ' ' . $skipped_payout_count . ' payout wallet(s) were skipped.';
            }
            if ($skipped_already_merged_count > 0) {
                $error_msg .= ' ' . $skipped_already_merged_count . ' wallet(s) already merged (skipped).';
            }
            wp_send_json_error($error_msg);
        }

        // Calculate NIGHT estimate using same logic as Night Miner import
        $night_estimate = Umbrella_Mines_Import_Processor::calculate_night_estimate_from_challenges($challenge_submissions);

        // Create import session
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'umbrella_mining_import_sessions';

        $session_key = uniqid('umbrella_json_', true);

        // Store wallet data in session
        $wallet_ids_json = json_encode($valid_wallets);

        $wpdb->insert($sessions_table, array(
            'session_key' => $session_key,
            'payout_address' => $payout_wallet->address,
            'total_wallets' => count($valid_wallets),
            'processed_wallets' => 0,
            'successful_count' => 0,
            'failed_count' => 0,
            'wallet_ids_json' => $wallet_ids_json,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ));


        // Return preview data
        wp_send_json_success(array(
            'session_key' => $session_key,
            'wallet_count' => count($valid_wallets),
            'wallets_with_solutions' => count(array_filter($valid_wallets, function($w) {
                return isset($w['solutions']) && count($w['solutions']) > 0;
            })),
            'total_solutions' => $total_solutions,
            'night_estimate' => $night_estimate,
            'payout_address' => $payout_wallet->address,
            'skipped_payout_wallets' => $skipped_payout_count,
            'skipped_already_merged' => $skipped_already_merged_count,
            'invalid_wallets' => 0,
            'source_file' => basename($file['name']),
            'import_type' => 'umbrella_json'
        ));
        } catch (Exception $e) {
            wp_send_json_error('PHP Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
        } catch (Error $e) {
            wp_send_json_error('PHP Fatal Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
        }
    }
}

// Initialize plugin
function umbrella_mines() {
    return Umbrella_Mines::instance();
}

umbrella_mines();
