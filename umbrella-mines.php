<?php
/**
 * Plugin Name: Umbrella Mines
 * Plugin URI: https://umbrella.lol
 * Description: Professional Cardano Midnight Scavenger Mine implementation with AshMaize FFI hashing. Mine NIGHT tokens with high-performance PHP/Rust hybrid miner. Cross-platform: Windows, Linux, macOS.
 * Version: 0.3.4
 * Author: Umbrella
 * Author URI: https://umbrella.lol
 * License: MIT
 * Requires at least: 5.0
 * Requires PHP: 8.3
 * Text Domain: umbrella-mines
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('UMBRELLA_MINES_VERSION', '0.3.4');
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
        add_action('wp_ajax_umbrella_merge_single_wallet', array($this, 'ajax_merge_single_wallet'));

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
        $solution_id = isset($_POST['solution_id']) ? intval($_POST['solution_id']) : 0;

        if (!$solution_id) {
            echo 'Invalid solution ID';
            wp_die();
        }

        global $wpdb;

        $solution = $wpdb->get_row($wpdb->prepare("
            SELECT s.*, w.address, w.derivation_path
            FROM {$wpdb->prefix}umbrella_mining_solutions s
            JOIN {$wpdb->prefix}umbrella_mining_wallets w ON s.wallet_id = w.id
            WHERE s.id = %d
        ", $solution_id));

        if (!$solution) {
            echo 'Solution not found';
            wp_die();
        }

        echo '<table class="widefat">';
        echo '<tr><th>ID</th><td>' . esc_html($solution->id) . '</td></tr>';
        echo '<tr><th>Challenge</th><td><code>' . esc_html($solution->challenge_id) . '</code></td></tr>';
        echo '<tr><th>Nonce</th><td><code>' . esc_html($solution->nonce) . '</code></td></tr>';
        echo '<tr><th>Address</th><td><code style="font-size: 10px;">' . esc_html($solution->address) . '</code></td></tr>';
        echo '<tr><th>Derivation Path</th><td><code>' . esc_html($solution->derivation_path ?: 'N/A') . '</code></td></tr>';
        echo '<tr><th>Hash</th><td><code style="font-size: 10px; word-break: break-all;">' . esc_html($solution->hash_result) . '</code></td></tr>';
        echo '<tr><th>Difficulty</th><td><code>' . esc_html($solution->difficulty) . '</code></td></tr>';
        echo '<tr><th>Found At</th><td>' . esc_html($solution->found_at) . '</td></tr>';
        echo '<tr><th>Status</th><td><strong>' . esc_html(strtoupper($solution->submission_status)) . '</strong></td></tr>';

        if ($solution->submission_error) {
            echo '<tr><th>Error</th><td><pre>' . esc_html($solution->submission_error) . '</pre></td></tr>';
        }

        // Check for receipt
        $receipt = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}umbrella_mining_receipts
            WHERE solution_id = %d
        ", $solution_id));

        if ($receipt) {
            echo '<tr><th>Receipt</th><td><pre>' . esc_html($receipt->crypto_receipt) . '</pre></td></tr>';
            echo '<tr><th>Signature</th><td><code style="word-break: break-all;">' . esc_html($receipt->signature) . '</code></td></tr>';
        }

        echo '</table>';

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

        // Build wallet-centric export structure (ONLY wallets with submitted/confirmed solutions)
        $export_data = array(
            'export_date' => current_time('mysql'),
            'export_version' => '1.0',
            'plugin_version' => UMBRELLA_MINES_VERSION,
            'export_note' => 'This export only includes wallets with submitted or confirmed solutions',
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

            // Build wallet export object
            $wallet_export = array(
                'wallet_id' => (int)$wallet['id'],
                'address' => $wallet['address'],
                'derivation_path' => $wallet['derivation_path'],
                'payment_skey_extended' => $wallet['payment_skey_extended'], // 🔐 CRITICAL
                'payment_pkey' => $wallet['payment_pkey'],
                'payment_keyhash' => $wallet['payment_keyhash'],
                'network' => $wallet['network'],
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
                )
            );

            $export_data['wallets'][] = $wallet_export;
        }

        // Add summary stats
        $export_data['total_wallets'] = count($wallets);
        $export_data['total_solutions'] = $total_solutions;
        $export_data['total_receipts'] = $total_receipts;
        $export_data['summary'] = array(
            'wallets_with_solutions' => $wallets_with_solutions,
            'wallets_without_solutions' => count($wallets) - $wallets_with_solutions,
            'solutions_with_receipts' => $total_receipts,
            'solutions_pending_receipt' => $total_solutions - $total_receipts
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

        // Use the correct payout address
        $correct_payout_address = 'addr1qyxzax7ncz2gmdsl3jrcpjtdceqfjuhgjprn2fpjsx5hhqkjm5fmh9vdhzn5k2uemdjjdehe67tljygwx2zp329eh46s33jlqa';

        error_log("AJAX MERGE: Using payout address: " . $correct_payout_address);

        $wallet_id = intval($_POST['wallet_id']);
        $result = Umbrella_Mines_Merge_Processor::merge_wallet($wallet_id, $correct_payout_address);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error']);
        }
    }
}

// Initialize plugin
function umbrella_mines() {
    return Umbrella_Mines::instance();
}

umbrella_mines();
