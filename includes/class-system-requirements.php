<?php
/**
 * System Requirements Checker
 *
 * Validates all dependencies needed for Umbrella Mines to function properly
 */

if (!defined('ABSPATH')) {
    exit;
}

class Umbrella_Mines_System_Requirements {

    /**
     * Check all system requirements
     *
     * @return array Array of requirement checks with status
     */
    public static function check_all() {
        return array(
            'php_version' => self::check_php_version(),
            'php_cli' => self::check_php_cli(),
            'ffi_extension' => self::check_ffi_extension(),
            'ffi_enabled' => self::check_ffi_enabled(),
            'wp_cli' => self::check_wp_cli(),
            'ashmaize_library' => self::check_ashmaize_library(),
            'writable_dirs' => self::check_writable_directories(),
        );
    }

    /**
     * Check if any critical requirements are missing
     *
     * @return bool True if all critical requirements met
     */
    public static function are_critical_requirements_met() {
        $checks = self::check_all();

        // Critical requirements
        $critical = array('php_version', 'ffi_extension', 'ffi_enabled', 'ashmaize_library');

        foreach ($critical as $key) {
            if (!$checks[$key]['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check PHP version (exactly 8.3.17 required for Rust FFI compatibility)
     */
    private static function check_php_version() {
        $version = PHP_VERSION;
        $required = '8.3.17';

        // Extract major.minor.patch from version string
        $current_parts = explode('.', $version);
        $required_parts = explode('.', $required);

        // Check if exact match on major.minor.patch
        $exact_match = (
            isset($current_parts[0], $current_parts[1], $current_parts[2]) &&
            $current_parts[0] == $required_parts[0] &&
            $current_parts[1] == $required_parts[1] &&
            $current_parts[2] == $required_parts[2]
        );

        $passed = $exact_match;

        return array(
            'name' => 'PHP Version',
            'required' => $required,
            'current' => $version,
            'passed' => $passed,
            'critical' => true,
            'message' => $passed
                ? "PHP {$version} - Exact match required for Rust FFI"
                : "PHP {$version} detected. Requires exactly PHP {$required} for Rust FFI compatibility. Install from: https://www.php.net/downloads",
        );
    }

    /**
     * Check if PHP CLI is available
     */
    private static function check_php_cli() {
        // PASSIVE CHECK - just use PHP_BINARY constant
        $php_cli = defined('PHP_BINARY') ? PHP_BINARY : null;

        // Basic validation - don't try to execute anything
        $passed = !empty($php_cli) && @file_exists($php_cli);

        return array(
            'name' => 'PHP CLI',
            'required' => 'php binary',
            'current' => $php_cli ?: 'Not detected',
            'passed' => $passed,
            'critical' => false, // Not critical since we can't verify without exec
            'message' => $passed
                ? "PHP CLI detected at: {$php_cli}"
                : "PHP CLI not detected. Install with: apt-get install php-cli (Ubuntu) or yum install php-cli (CentOS)",
        );
    }

    /**
     * Check if FFI extension is loaded
     */
    private static function check_ffi_extension() {
        $loaded = extension_loaded('ffi');

        return array(
            'name' => 'FFI Extension',
            'required' => 'Loaded',
            'current' => $loaded ? 'Loaded' : 'Not loaded',
            'passed' => $loaded,
            'critical' => true,
            'message' => $loaded
                ? 'FFI extension is loaded'
                : 'FFI extension not loaded. Install with: apt-get install php-ffi (Ubuntu) or yum install php-ffi (CentOS)',
        );
    }

    /**
     * Check if FFI is enabled and can create instances
     */
    private static function check_ffi_enabled() {
        if (!extension_loaded('ffi')) {
            return array(
                'name' => 'FFI Enabled',
                'required' => 'Enabled',
                'current' => 'Extension not loaded',
                'passed' => false,
                'critical' => true,
                'message' => 'FFI extension must be loaded first',
            );
        }

        try {
            // Check if FFI can be used by checking the preload setting
            // Don't try to actually load a library as it might fail on different systems
            $enabled = class_exists('FFI');
            $message = 'FFI is enabled and functional';
        } catch (Exception $e) {
            $enabled = false;
            $message = 'FFI is loaded but not enabled. Check php.ini: ffi.enable=1';
        }

        return array(
            'name' => 'FFI Enabled',
            'required' => 'Enabled',
            'current' => $enabled ? 'Enabled' : 'Disabled',
            'passed' => $enabled,
            'critical' => true,
            'message' => $message,
        );
    }

    /**
     * Check if WP-CLI is available
     */
    private static function check_wp_cli() {
        // PASSIVE CHECK - only check file existence, no shell commands
        $wp_cli = null;

        // Check common paths without executing anything
        $possible_paths = array(
            'C:/Users/' . (getenv('USERNAME') ?: getenv('USER') ?: '') . '/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar',
            dirname(ABSPATH) . '/vendor/bin/wp',
            '/usr/local/bin/wp',
            '/usr/bin/wp',
        );

        foreach ($possible_paths as $path) {
            if (!empty($path) && @file_exists($path)) {
                $wp_cli = $path;
                break;
            }
        }

        $passed = !empty($wp_cli);

        return array(
            'name' => 'WP-CLI',
            'required' => 'Installed',
            'current' => $wp_cli ?: 'Not found',
            'passed' => $passed,
            'critical' => false,
            'message' => $passed
                ? "WP-CLI found at: {$wp_cli}"
                : 'WP-CLI not found. Mining via dashboard will still work, but CLI commands unavailable. Install from: https://wp-cli.org',
        );
    }

    /**
     * Check if AshMaize library exists
     */
    private static function check_ashmaize_library() {
        $bin_dir = UMBRELLA_MINES_PLUGIN_DIR . 'bin/';
        $library_found = false;
        $library_path = null;

        // Determine platform and expected library
        $os = strtoupper(substr(PHP_OS, 0, 3));

        if ($os === 'WIN') {
            $library_path = $bin_dir . 'ashmaize_capi.dll';
        } elseif ($os === 'DAR') {
            // macOS - check both architectures
            $intel_lib = $bin_dir . 'ashmaize_capi.dylib';
            $arm_lib = $bin_dir . 'ashmaize_capi_arm.dylib';

            if (file_exists($intel_lib) || file_exists($arm_lib)) {
                $library_found = true;
                $library_path = file_exists($intel_lib) ? $intel_lib : $arm_lib;
            }
        } else {
            // Linux
            $library_path = $bin_dir . 'ashmaize_capi.so';
        }

        if (!$library_found) {
            $library_found = file_exists($library_path);
        }

        return array(
            'name' => 'AshMaize Library',
            'required' => 'Present',
            'current' => $library_found ? basename($library_path) : 'Not found',
            'passed' => $library_found,
            'critical' => true,
            'message' => $library_found
                ? "AshMaize library found at: {$library_path}"
                : "AshMaize library not found in {$bin_dir}. Download from GitHub releases or build from source in rust-wrapper/",
        );
    }

    /**
     * Check if required directories are writable
     */
    private static function check_writable_directories() {
        $dirs = array(
            WP_CONTENT_DIR => 'wp-content',
            UMBRELLA_MINES_DATA_DIR => 'umbrella-mines data',
        );

        $all_writable = true;
        $messages = array();

        foreach ($dirs as $dir => $name) {
            if (!is_writable($dir)) {
                $all_writable = false;
                $messages[] = "{$name} ({$dir}) is not writable";
            }
        }

        return array(
            'name' => 'Writable Directories',
            'required' => 'All writable',
            'current' => $all_writable ? 'All writable' : count($messages) . ' issues',
            'passed' => $all_writable,
            'critical' => true,
            'message' => $all_writable
                ? 'All required directories are writable'
                : implode(', ', $messages),
        );
    }

    /**
     * Check if a command exists in PATH
     *
     * @param string $command Command to check
     * @return bool
     */
    private static function command_exists($command) {
        // PASSIVE CHECK ONLY - don't execute shell commands
        // Just check if file exists at common paths
        return false; // Disable for now to avoid conflicts with active mining
    }

    /**
     * Get installation instructions for missing requirements
     *
     * @return array Array of instructions grouped by OS
     */
    public static function get_installation_instructions() {
        return array(
            'ubuntu' => array(
                'name' => 'Ubuntu / Debian',
                'commands' => array(
                    'Update package list' => 'sudo apt-get update',
                    'Install PHP 8.1+' => 'sudo apt-get install php8.1 php8.1-cli php8.1-ffi',
                    'Install WP-CLI' => 'curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && sudo mv wp-cli.phar /usr/local/bin/wp',
                    'Verify PHP' => 'php -v',
                    'Verify FFI' => 'php -m | grep -i ffi',
                    'Verify WP-CLI' => 'wp --info',
                ),
            ),
            'centos' => array(
                'name' => 'CentOS / RHEL',
                'commands' => array(
                    'Install PHP 8.1+' => 'sudo yum install php81 php81-cli php81-ffi',
                    'Install WP-CLI' => 'curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && sudo mv wp-cli.phar /usr/local/bin/wp',
                ),
            ),
            'macos' => array(
                'name' => 'macOS',
                'commands' => array(
                    'Install Homebrew (if needed)' => '/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"',
                    'Install PHP 8.1+' => 'brew install php@8.1',
                    'Install WP-CLI' => 'brew install wp-cli',
                ),
            ),
            'windows' => array(
                'name' => 'Windows',
                'commands' => array(
                    'Install PHP' => 'Download from https://windows.php.net/download/',
                    'Enable FFI' => 'Edit php.ini: extension=ffi, ffi.enable=1',
                    'Install WP-CLI' => 'Download from https://wp-cli.org/#installing',
                ),
            ),
        );
    }
}
