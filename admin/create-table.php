<?php
/**
 * Manual Table Creation Page
 *
 * This page manually creates all required database tables.
 * Use this if the automatic activation hook didn't run.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;

// Handle table creation
if (isset($_POST['create_tables']) && check_admin_referer('create_umbrella_tables', 'umbrella_nonce')) {
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

    // Table 3: Receipts from server
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

    // Table 5: Mining config
    $table_config = $wpdb->prefix . 'umbrella_mining_config';
    $sql_config = "CREATE TABLE {$table_config} (
        config_key varchar(100) NOT NULL,
        config_value longtext NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (config_key)
    ) $charset_collate;";

    // Table 6: Mining jobs
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

    // Table 7: Mining progress
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

    // Table 8: Mining processes
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
    $defaults = array(
        'api_url' => 'https://scavenger.prod.gd.midnighttge.io',
        'network' => 'mainnet',
        'auto_register' => '0',
        'auto_submit' => '0',
        'batch_size' => '10',
        'submission_delay' => '2'
    );

    foreach ($defaults as $key => $value) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT config_value FROM {$table_config} WHERE config_key = %s",
            $key
        ));

        if ($existing === null) {
            $wpdb->insert(
                $table_config,
                array('config_key' => $key, 'config_value' => $value),
                array('%s', '%s')
            );
        }
    }

    echo '<div class="notice notice-success"><p><strong>Success!</strong> All 8 database tables have been created.</p></div>';
}

// Check which tables exist
$existing_tables = array();
$table_names = array(
    'umbrella_mining_wallets',
    'umbrella_mining_solutions',
    'umbrella_mining_receipts',
    'umbrella_mining_challenges',
    'umbrella_mining_config',
    'umbrella_mining_jobs',
    'umbrella_mining_progress',
    'umbrella_mining_processes'
);

foreach ($table_names as $table) {
    $full_name = $wpdb->prefix . $table;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_name'");
    $existing_tables[$table] = ($exists !== null);
}

?>

<div class="wrap">
    <h1>Create Database Tables</h1>

    <div class="card" style="max-width: 800px;">
        <h2>Table Status</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Table Name</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($existing_tables as $table => $exists): ?>
                    <tr>
                        <td><code><?php echo esc_html($wpdb->prefix . $table); ?></code></td>
                        <td>
                            <?php if ($exists): ?>
                                <span style="color: green;">✓ Exists</span>
                            <?php else: ?>
                                <span style="color: red;">✗ Missing</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (in_array(false, $existing_tables)): ?>
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>Create Missing Tables</h2>
            <p>Some tables are missing. Click the button below to create them.</p>

            <form method="post">
                <?php wp_nonce_field('create_umbrella_tables', 'umbrella_nonce'); ?>
                <button type="submit" name="create_tables" class="button button-primary button-large">
                    Create All Tables
                </button>
            </form>
        </div>
    <?php else: ?>
        <div class="notice notice-success" style="margin-top: 20px;">
            <p><strong>All tables exist!</strong> Your database is properly set up.</p>
        </div>
    <?php endif; ?>

    <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2>What This Does</h2>
        <p>This page creates the 8 required database tables for Umbrella Mines:</p>
        <ol>
            <li><strong>wallets</strong> - Stores mining wallet addresses and keys</li>
            <li><strong>solutions</strong> - Stores found mining solutions</li>
            <li><strong>receipts</strong> - Stores server receipts/proofs</li>
            <li><strong>challenges</strong> - Caches current mining challenges</li>
            <li><strong>config</strong> - Stores plugin configuration</li>
            <li><strong>jobs</strong> - Mining job queue</li>
            <li><strong>progress</strong> - Live mining progress logs</li>
            <li><strong>processes</strong> - Tracks running mining processes</li>
        </ol>
        <p><em>Note: Normally these tables are created automatically when you activate the plugin. Use this page if automatic creation failed.</em></p>
    </div>
</div>
