<?php
/**
 * Start/Stop Mining Handler
 */

function umbrella_mines_handle_action($action, $log_file) {
    $pid_file = WP_CONTENT_DIR . '/umbrella-mining.pid';

    if ($action === 'start') {
        // Get form values
        $max_attempts = isset($_POST['max_attempts']) ? intval($_POST['max_attempts']) : 500000;
        $derivation_path = isset($_POST['derivation_path']) ? sanitize_text_field($_POST['derivation_path']) : '0/0/0';

        // Find PHP CLI
        $php_cli = '';
        $username = getenv('USERNAME') ?: getenv('USER') ?: get_current_user();
        $possible_php_paths = array(
            'C:/Users/' . $username . '/AppData/Roaming/Local/lightning-services/php-8.3.17+1/bin/win64/php.exe',
            '/usr/bin/php',
            '/usr/local/bin/php',
        );
        foreach ($possible_php_paths as $path) {
            if (file_exists($path)) {
                $php_cli = $path;
                break;
            }
        }

        // Find WP-CLI
        $wp_cli = '';
        $possible_wp_paths = array(
            'C:/Users/' . $username . '/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar',
            dirname(ABSPATH) . '/vendor/bin/wp',
            '/usr/local/bin/wp',
        );
        foreach ($possible_wp_paths as $path) {
            if (file_exists($path)) {
                $wp_cli = $path;
                break;
            }
        }

        // Find the site's php.ini
        $php_ini = '';
        $site_id_dirs = glob('C:/Users/' . $username . '/AppData/Roaming/Local/run/*', GLOB_ONLYDIR);
        foreach ($site_id_dirs as $dir) {
            if (file_exists($dir . '/conf/php/php.ini')) {
                $php_ini = $dir . '/conf/php/php.ini';
                break;
            }
        }

        // Fail fast if paths not found
        if (!$php_cli || !file_exists($php_cli)) {
            file_put_contents($log_file, "ERROR: PHP CLI not found\n", FILE_APPEND);
            echo 'ERROR: PHP CLI not found';
            exit;
        }
        if (!$wp_cli || !file_exists($wp_cli)) {
            file_put_contents($log_file, "ERROR: WP-CLI not found\n", FILE_APPEND);
            echo 'ERROR: WP-CLI not found';
            exit;
        }

        // Direct command that works when I run it manually
        $cmd = '"' . $php_cli . '" -c "' . $php_ini . '" "' . $wp_cli . '" umbrella-mines start --max-attempts=' . $max_attempts . ' --derive=' . $derivation_path . ' --path="' . ABSPATH . '" >> "' . $log_file . '" 2>&1';

        // Write to a batch file
        $bat = WP_CONTENT_DIR . '/start.bat';
        file_put_contents($bat, $cmd);

        // Execute it in background with pclose/popen to not hang
        pclose(popen('start /B cmd /c "' . $bat . '"', 'r'));

        $exec_log = "Mining started\n";

        file_put_contents($log_file, $exec_log . "\n", FILE_APPEND);

        // Redirect back
        wp_redirect(admin_url('admin.php?page=umbrella-mines'));
        exit;

    } elseif ($action === 'stop') {
        // Create stop flag file - miner will see it and stop gracefully
        $stop_file = WP_CONTENT_DIR . '/umbrella-mines-stop.flag';
        file_put_contents($stop_file, 'stop');

        // Redirect back
        wp_redirect(admin_url('admin.php?page=umbrella-mines'));
        exit;
    }
}
