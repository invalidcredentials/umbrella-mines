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

        // Find php.ini (OS-specific)
        $php_ini = '';
        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

        if ($is_windows) {
            // Windows: Look for Local by Flywheel configuration
            $site_id_dirs = glob('C:/Users/' . $username . '/AppData/Roaming/Local/run/*', GLOB_ONLYDIR);

            // Try to match based on the site's actual path
            // ABSPATH looks like: C:\Users\pb\Local Sites\umbrella-mine\app\public/
            // nginx conf has: C:/Users/pb/Local Sites/umbrella-mine/app/public
            $current_site_path = rtrim(str_replace('\\', '/', ABSPATH), '/');

            file_put_contents($log_file, "Looking for site with path: $current_site_path\n", FILE_APPEND);

            foreach ($site_id_dirs as $dir) {
                $site_conf = $dir . '/conf/nginx/site.conf';
                if (file_exists($site_conf)) {
                    $conf_content = file_get_contents($site_conf);
                    file_put_contents($log_file, "Checking $dir\n", FILE_APPEND);

                    // Extract the root path from nginx config
                    if (preg_match('/root\s+"([^"]+)";/', $conf_content, $matches)) {
                        $nginx_root = rtrim($matches[1], '/');
                        file_put_contents($log_file, "  Found root: $nginx_root\n", FILE_APPEND);

                        if ($nginx_root === $current_site_path) {
                            $php_ini = $dir . '/conf/php/php.ini';
                            file_put_contents($log_file, "  MATCH! Using: $php_ini\n", FILE_APPEND);
                            if (file_exists($php_ini)) {
                                break;
                            }
                        }
                    }
                }
            }

            // Fallback: just find ANY php.ini if we couldn't match
            if (!$php_ini) {
                file_put_contents($log_file, "WARNING: Could not match site, using first php.ini found\n", FILE_APPEND);
                foreach ($site_id_dirs as $dir) {
                    if (file_exists($dir . '/conf/php/php.ini')) {
                        $php_ini = $dir . '/conf/php/php.ini';
                        break;
                    }
                }
            }
        } else {
            // Linux/Mac: Use system's default php.ini or auto-detect
            $detected = php_ini_loaded_file();
            if ($detected && file_exists($detected)) {
                $php_ini = $detected;
                file_put_contents($log_file, "Using detected php.ini: $php_ini\n", FILE_APPEND);
            } else {
                // Don't set php.ini, let PHP use default
                file_put_contents($log_file, "Using system default php.ini (auto-detected)\n", FILE_APPEND);
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

        // Build command
        $cmd_parts = array('"' . $php_cli . '"');
        if ($php_ini) {
            $cmd_parts[] = '-c "' . $php_ini . '"';
        }
        $cmd_parts[] = '"' . $wp_cli . '" umbrella-mines start';
        $cmd_parts[] = '--max-attempts=' . $max_attempts;
        $cmd_parts[] = '--derive=' . $derivation_path;
        $cmd_parts[] = '--path="' . ABSPATH . '"';
        $cmd_parts[] = '>>' . '"' . $log_file . '" 2>&1';

        $cmd = implode(' ', $cmd_parts);

        // Detect OS and create appropriate script
        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

        if ($is_windows) {
            // Windows: batch file
            $script = WP_CONTENT_DIR . '/start.bat';
            file_put_contents($script, $cmd);
            pclose(popen('start /B cmd /c "' . $script . '"', 'r'));
        } else {
            // Linux/Mac: shell script
            $script = WP_CONTENT_DIR . '/start.sh';
            file_put_contents($script, "#!/bin/bash\n" . $cmd);
            chmod($script, 0755);
            exec($script . ' > /dev/null 2>&1 &');
        }

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
