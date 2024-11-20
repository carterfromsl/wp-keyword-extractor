<?php
/**
 * Plugin Name: WP Keyword Extractor
 * Description: A plugin to extract keywords from one CSV and output them as frequency values to another CSV!
 * Version: 1.5.1
 * Author: StratLab Marketing
 * Author URI: https://strategylab.ca
 * Text Domain: wp-keyword-extractor
 * Requires at least: 6.0
 * Requires PHP: 7.0
 * Update URI: https://github.com/carterfromsl/wp-keyword-extractor/
*/

if (!defined('ABSPATH')) {
    exit; // You don't belong here.
}

// Connect with the StratLab Auto-Updater for plugin updates
add_action('plugins_loaded', function() {
    if (class_exists('StratLabUpdater')) {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $plugin_file = __FILE__;
        $plugin_data = get_plugin_data($plugin_file);

        do_action('stratlab_register_plugin', [
            'slug' => plugin_basename($plugin_file),
            'repo_url' => 'https://api.github.com/repos/carterfromsl/wp-keyword-extractor/releases/latest',
            'version' => $plugin_data['Version'], 
            'name' => $plugin_data['Name'],
            'author' => $plugin_data['Author'],
            'homepage' => $plugin_data['PluginURI'],
            'description' => $plugin_data['Description'],
            'access_token' => '', // Add if needed for private repo
        ]);
    }
});

class WPKeywordExtractor {
    private $output_url;
    private $output_path;

    public function __construct() {
        add_action('admin_menu', array($this, 'create_admin_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('update_option_keyword_extractor_csv_path', array($this, 'run_keyword_extractor_on_save'));
        add_action('update_option_keyword_extractor_output_name', array($this, 'run_keyword_extractor_on_save'));
        add_action('update_option_keyword_extractor_timeout', array($this, 'run_keyword_extractor_on_save'));
        add_action('update_option_keyword_extractor_stop_words', array($this, 'run_keyword_extractor_on_save'));

        // Set up paths and URLs
        $upload_dir = wp_upload_dir();
        $this->output_path = $upload_dir['basedir'] . '/keyword-data/';
        $this->output_url = $upload_dir['baseurl'] . '/keyword-data/';

        // Register the cron job hook
        add_action('run_keyword_extractor', array($this, 'run_keyword_extractor'));
        add_filter('cron_schedules', array($this, 'add_custom_intervals'));

        // Handle file deletion requests
        add_action('admin_post_delete_csv_file', array($this, 'delete_csv_file'));
    }

    // Admin page
    public function create_admin_page() {
        add_submenu_page(
            'tools.php',
            'Keyword Extractor',
            'Keyword Extractor',
            'manage_options',
            'keyword-extractor',
            array($this, 'admin_page_html')
        );
    }

    public function admin_page_html() {
        ?>
        <div class="wrap">
            <h1>Keyword Extractor Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('keyword_extractor_options');
                do_settings_sections('keyword-extractor');
                submit_button();
                ?>
            </form>

            <h2>Generated CSV Files</h2>
            <?php
            $csv_files = $this->get_csv_files();

            if (empty($csv_files)) {
                echo '<p>No CSV files found.</p>';
            } else {
                echo '<ul>';
                foreach ($csv_files as $file) {
                    $file_url = esc_url($this->output_url . $file);
                    $delete_url = esc_url(admin_url('admin-post.php?action=delete_csv_file&file=' . urlencode($file) . '&_wpnonce=' . wp_create_nonce('delete_csv_file_nonce')));

                    echo "<li><a href='{$file_url}' target='_blank'>{$file}</a> 
                          <a href='{$delete_url}' class='button button-link-delete' 
                          onclick='return confirm(\"Are you sure you want to delete this file?\");'>Delete</a></li>";
                }
                echo '</ul>';
            }
            ?>
        </div>
        <?php
    }

    // Get all CSV files in the keyword-data directory
    public function get_csv_files() {
        if (!file_exists($this->output_path)) {
            return [];
        }

        $files = glob($this->output_path . '*.csv');

        if ($files === false) {
            return [];
        }

        // Only return file names, not full paths
        return array_map('basename', $files);
    }

    public function register_settings() {
        register_setting('keyword_extractor_options', 'keyword_extractor_csv_path');
        register_setting('keyword_extractor_options', 'keyword_extractor_output_name');
        register_setting('keyword_extractor_options', 'keyword_extractor_timeout');
        register_setting('keyword_extractor_options', 'keyword_extractor_stop_words');

        add_settings_section(
            'keyword_extractor_section',
            'Settings',
            null,
            'keyword-extractor'
        );

        add_settings_field(
            'keyword_extractor_csv_path',
            'CSV File Path',
            array($this, 'csv_path_callback'),
            'keyword-extractor',
            'keyword_extractor_section'
        );

        add_settings_field(
            'keyword_extractor_output_name',
            'Output File Name',
            array($this, 'output_name_callback'),
            'keyword-extractor',
            'keyword_extractor_section'
        );

        add_settings_field(
            'keyword_extractor_timeout',
            'File Check Interval (minutes)',
            array($this, 'timeout_callback'),
            'keyword-extractor',
            'keyword_extractor_section'
        );

        add_settings_field(
            'keyword_extractor_stop_words',
            'Additional Stop Words (comma-separated)',
            array($this, 'stop_words_callback'),
            'keyword-extractor',
            'keyword_extractor_section'
        );
    }

    public function csv_path_callback() {
        $csv_path = esc_attr(get_option('keyword_extractor_csv_path'));
        echo "<input type='text' name='keyword_extractor_csv_path' value='{$csv_path}' />";
    }

    public function output_name_callback() {
        $output_name = esc_attr(get_option('keyword_extractor_output_name'));
        echo "<input type='text' name='keyword_extractor_output_name' value='{$output_name}' />";
    }

    public function timeout_callback() {
        $timeout = esc_attr(get_option('keyword_extractor_timeout', 5));
        echo "<input type='number' name='keyword_extractor_timeout' value='{$timeout}' />";
    }

    public function stop_words_callback() {
        $stop_words = esc_attr(get_option('keyword_extractor_stop_words'));
        echo "<textarea name='keyword_extractor_stop_words' rows='5' cols='50'>" . esc_textarea($stop_words) . "</textarea>";
    }

    // Handle the CSV file deletion and clear cron job if the file was the current one
    public function delete_csv_file() {
        if (!isset($_GET['file']) || !wp_verify_nonce($_GET['_wpnonce'], 'delete_csv_file_nonce')) {
            wp_die('Invalid request.');
        }
    
        $file = urldecode($_GET['file']);
        $file_path = $this->output_path . $file;
    
        // Check if the file exists and delete it
        if (file_exists($file_path) && strpos($file, '.csv') !== false) {
            unlink($file_path);
    
            // Check if this is the current output file or CSV file
            $current_output_name = get_option('keyword_extractor_output_name', 'keyword-results') . '.csv';
            $current_csv_file = basename(get_option('keyword_extractor_csv_path'));
    
            if ($file === $current_output_name || $file === $current_csv_file) {
                // Clear the cron job as the active file has been deleted
                wp_clear_scheduled_hook('run_keyword_extractor');
                
                // Display a notice to the user
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning"><p>The scheduled keyword extraction cron job has been cleared because the associated CSV file was deleted. Please upload or select a new CSV file.</p></div>';
                });
            }
    
            wp_redirect(admin_url('admin.php?page=keyword-extractor&deleted=true'));
            exit;
        } else {
            wp_die('File not found.');
        }
    }
    
    // Add custom cron interval
    public function add_custom_intervals($schedules) {
        // Get the timeout value from settings
        $interval = get_option('keyword_extractor_timeout', 5);
    
        // Add a custom interval (in seconds) based on the user's setting
        $schedules['keyword_extractor_interval'] = array(
            'interval' => $interval * 60, // Convert minutes to seconds
            'display' => __('Every ' . $interval . ' minutes')
        );
        
        return $schedules;
    }    
    
    public function run_keyword_extractor_on_save() {
        // Clear any existing cron job before scheduling a new one
        wp_clear_scheduled_hook('run_keyword_extractor');
    
        // Fetch timeout interval and CSV file path
        $interval = get_option('keyword_extractor_timeout', 5) * 60; // Convert minutes to seconds
        $csv_path = get_option('keyword_extractor_csv_path');
    
        // Ensure the CSV path is valid
        $csv_path = $this->get_local_csv_path($csv_path);
        error_log('CSV Path: ' . $csv_path);
    
        // Only schedule the cron job if the CSV file exists
        if (file_exists($csv_path)) {
            // Schedule the cron job as a recurring event
            if (!wp_next_scheduled('run_keyword_extractor')) {
                wp_schedule_event(time(), 'keyword_extractor_interval', 'run_keyword_extractor');
                error_log('Recurring cron job scheduled every ' . get_option('keyword_extractor_timeout', 5) . ' minutes.');
            }
        } else {
            error_log('CSV file does not exist at ' . $csv_path);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>The specified CSV file does not exist. Please provide a valid file to enable keyword extraction.</p></div>';
            });
        }
    }

    // The core functionality
    public function run_keyword_extractor() {
        $csv_path = get_option('keyword_extractor_csv_path');
        $output_name = get_option('keyword_extractor_output_name', 'keyword-results');
        $output_path = $this->output_path . $output_name . '.csv';
    
        // Get the local path to the CSV file
        $csv_path = $this->get_local_csv_path($csv_path);
    
        // Check if CSV path is valid
        if (!file_exists($csv_path)) {
            error_log('CSV file does not exist at ' . $csv_path);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>CSV file does not exist at the specified path.</p></div>';
            });
            return;
        }
    
        // Ensure the upload directory exists
        if (!file_exists($this->output_path)) {
            if (!mkdir($this->output_path, 0755, true)) {
                error_log('Failed to create directory: ' . $this->output_path);
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>Failed to create directory for keyword data output.</p></div>';
                });
                return;
            }
        }
    
        // Read the CSV data
        $data = array_map('str_getcsv', file($csv_path));
        if (!$data) {
            error_log('Failed to read the CSV file.');
            return;
        }
    
        // Extract stop words and keywords
        $stop_words = get_option('keyword_extractor_stop_words');
        $stop_words_list = array_merge(explode(',', $stop_words), $this->get_default_stop_words());
        $keywords = $this->extract_keywords_by_column($data, $stop_words_list);
    
        // Write keywords to the CSV
        $this->write_to_csv_by_column($keywords, $output_path);
    
        // Log success
        error_log('Keyword extraction completed. Results saved to ' . $output_path);
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>Keyword extraction completed! Results saved to ' . esc_html($output_path) . '</p></div>';
        });
    }

    private function get_local_csv_path($csv_url) {
        $upload_dir = wp_upload_dir();
        if (strpos($csv_url, $upload_dir['baseurl']) !== false) {
            return str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $csv_url);
        }
        return $csv_url;
    }

    private function extract_keywords_by_column($data, $stop_words_list) {
        $keywords = [];
    
        // Initialize keyword storage for each column
        $column_count = count($data[0]);
        for ($i = 0; $i < $column_count; $i++) {
            $keywords[$i] = [];
        }
    
        // Iterate over each row and each column
        foreach ($data as $row) {
            foreach ($row as $index => $cell) {
                // Split each cell's content into words, using spaces and common punctuation as delimiters
                $words = preg_split('/[\s,.;!?]+/', $cell);
    
                foreach ($words as $word) {
                    $word = strtolower(trim($word)); // Convert to lowercase and trim whitespace
    
                    // Ignore empty strings or stop words
                    if (empty($word) || in_array($word, $stop_words_list) || strlen($word) <= 3) {
                        continue;
                    }
    
                    // Count the frequency of each word in the respective column
                    if (isset($keywords[$index][$word])) {
                        $keywords[$index][$word]++;
                    } else {
                        $keywords[$index][$word] = 1;
                    }
                }
            }
        }
    
        // Sort each column by frequency in descending order
        foreach ($keywords as $index => $keyword_data) {
            arsort($keywords[$index]);
        }
    
        return $keywords;
    }

    private function write_to_csv_by_column($keywords, $output_path) {
        // Ensure the directory exists
        if (!file_exists(dirname($output_path))) {
            mkdir(dirname($output_path), 0755, true);
        }
    
        // Open the file for writing
        $file = fopen($output_path, 'w');
    
        // Get the number of columns
        $column_count = count($keywords);
    
        // Write the header row
        $header = [];
        for ($i = 0; $i < $column_count; $i++) {
            $header[] = 'column_' . ($i + 1);
        }
        fputcsv($file, $header);
    
        // Determine the maximum number of unique keywords in any column
        $max_keywords = max(array_map('count', $keywords));
    
        // Write each row of keywords and their frequencies
        for ($i = 0; $i < $max_keywords; $i++) {
            $row = [];
            for ($j = 0; $j < $column_count; $j++) {
                if (isset(array_keys($keywords[$j])[$i])) {
                    $key = array_keys($keywords[$j])[$i];
                    $row[] = $key . ':' . $keywords[$j][$key];
                } else {
                    $row[] = '';
                }
            }
            fputcsv($file, $row);
        }
    
        // Close the file
        fclose($file);
    }

    private function get_default_stop_words() {
        return [
            'the', 'is', 'at', 'which', 'on', 'and', 'a', 'in', 'with', 'not',
            'from', 'to', 'onto', 'into', 'was', 'what', 'that', 'we\'re',
            'this', 'your', 'when', 'have','it\'s', 'don\'t', 'just', 'like',
            'been', 'here', 'there','that\'s', 'where', 'can', 'can\'t', 'cannot', 'wont',
            'you\'re', '&nbsp', 'i\'ve', 'i\'ll', 'there\'s', 'those', 'he\'s', 'she\'s', 'she',
            'isn\'t', 'what\'s', 'we\'ll', 'they\'re', 'then', 'doesn\'t', 'what\'s', '&amp', 'https',
            'dont', 'these', '&gt', '&lt', 'their', 'also', 'nigger', 'niggers', 'shit', 'jew', 'jews',
            'fuck', 'fucking', 'nigga'
        ];
    }
}

new WPKeywordExtractor();
