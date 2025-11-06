<?php
/**
 * Plugin Name: Umbrella Mines
 * Plugin URI: https://umbrella.lol
 * Description: Professional Cardano Midnight Scavenger Mine implementation with AshMaize FFI hashing. Mine NIGHT tokens with high-performance PHP/Rust hybrid miner.
 * Version: 0.1.1
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
define('UMBRELLA_MINES_VERSION', '0.1.1');
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

        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX hooks
        add_action('wp_ajax_delete_solution', array($this, 'ajax_delete_solution'));
        add_action('wp_ajax_retry_solution', array($this, 'ajax_retry_solution'));
        add_action('wp_ajax_reset_solution_status', array($this, 'ajax_reset_solution_status'));
        add_action('wp_ajax_get_solution_details', array($this, 'ajax_get_solution_details'));

        // Dashboard AJAX hooks
        add_action('wp_ajax_umbrella_toggle_autosubmit', array($this, 'ajax_toggle_autosubmit'));
        add_action('wp_ajax_umbrella_start_mining_live', array($this, 'ajax_start_mining_live'));
        add_action('wp_ajax_umbrella_stop_mining_live', array($this, 'ajax_stop_mining_live'));
        add_action('wp_ajax_umbrella_get_mining_status_live', array($this, 'ajax_get_mining_status_live'));

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
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
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

        $killed = 0;
        foreach ($processes as $process) {
            $pid = $process['pid'];

            if ($pid > 0) {
                // Kill process tree using taskkill /T
                $kill_output = array();
                exec("taskkill /F /PID {$pid} /T 2>&1", $kill_output, $kill_return);

                $stop_log .= "Killed PID {$pid}: " . implode(' ', $kill_output) . "\n";
                $killed++;
            } else {
                // Fallback: PID was not captured, use wmic to find umbrella-mines processes
                $stop_log .= "PID not tracked, searching for umbrella-mines processes...\n";

                $cmd = 'wmic process where "commandline like \'%umbrella-mines%\'" get processid 2>nul';
                exec($cmd, $pids, $return_code);

                foreach ($pids as $line) {
                    $found_pid = trim($line);
                    if (is_numeric($found_pid) && $found_pid > 0) {
                        $kill_cmd = "taskkill /F /PID {$found_pid} /T 2>&1";
                        exec($kill_cmd, $output, $kill_return);
                        $stop_log .= "Killed PID {$found_pid}: " . implode(' ', $output) . "\n";
                        $output = array();
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
}

// Initialize plugin
function umbrella_mines() {
    return Umbrella_Mines::instance();
}

umbrella_mines();
