<?php
/**
 * Request Generator Component
 *
 * Handles the creation and execution of HTTP requests for load testing.
 */

// Prevent direct access
if (!defined('ABSPATH') && !defined('WP_CLI')) {
    exit;
}

/**
 * Request Generator class
 */
class MicroChaos_Request_Generator {
    /**
     * Status sentinel for a request abandoned at the timeout
     */
    const STATUS_TIMEOUT = 'TIMEOUT';

    /**
     * Status sentinel for a request that failed for any other reason
     */
    const STATUS_ERROR = 'ERROR';

    /**
     * Collect and process cache headers
     *
     * @var bool
     */
    private bool $collect_cache_headers = false;

    /**
     * Cache headers data storage
     *
     * @var array<string, array<string, int>>
     */
    private array $cache_headers = [];

    /**
     * Last request cache headers
     *
     * @var array<string, string>
     */
    private array $last_request_cache_headers = [];

    /**
     * Seconds to wait for a response before abandoning the request
     *
     * @var int
     */
    private int $timeout = MicroChaos_Constants::DEFAULT_REQUEST_TIMEOUT;

    /**
     * Constructor
     *
     * @param array<string, mixed> $options Options for the request generator
     */
    public function __construct(array $options = []) {
        $this->collect_cache_headers = isset($options['collect_cache_headers']) ?
            $options['collect_cache_headers'] : false;

        if (isset($options['timeout'])) {
            $this->timeout = max(1, (int) $options['timeout']);
        }
    }

    /**
     * Get the configured request timeout
     *
     * @return int Timeout in seconds
     */
    public function get_timeout(): int {
        return $this->timeout;
    }

    /**
     * Custom headers storage
     *
     * @var array<string, string>
     */
    private array $custom_headers = [];

    /**
     * Custom User-Agent string
     *
     * @var string|null
     */
    private ?string $custom_user_agent = null;

    /**
     * Set custom headers
     *
     * @param array<string, string> $headers Custom headers in key-value format
     */
    public function set_custom_headers(array $headers): void {
        $this->custom_headers = $headers;
    }

    /**
     * Set custom User-Agent string
     *
     * @param string $user_agent Custom User-Agent header value
     */
    public function set_user_agent(string $user_agent): void {
        $this->custom_user_agent = $user_agent;
    }

    /**
     * Fire a single request
     *
     * @param string $url Target URL
     * @param string|null $log_path Optional path for logging
     * @param array|null $cookies Optional cookies for authentication
     * @param string $method HTTP method
     * @param string|null $body Request body for POST/PUT
     * @return array{time: float, code: int|string} Result of the request
     */
    public function fire_request(string $url, ?string $log_path = null, ?array $cookies = null, string $method = 'GET', ?string $body = null): array {
        $start = microtime(true);

        $args = [
            'timeout' => $this->timeout,
            'blocking' => true,
            'user-agent' => $this->get_user_agent(),
            'method' => $method,
        ];
        
        // Add custom headers if any
        if (!empty($this->custom_headers)) {
            $args['headers'] = [];
            foreach ($this->custom_headers as $name => $value) {
                $args['headers'][$name] = $value;
            }
        }

        if ($body) {
            if ($this->is_json($body)) {
                if (!isset($args['headers'])) {
                    $args['headers'] = [];
                }
                $args['headers']['Content-Type'] = 'application/json';
                $args['body'] = $body;
            } else {
                // Handle URL-encoded form data or other types
                $args['body'] = $body;
            }
        }

        if ($cookies) {
            $selected = MicroChaos_Authentication_Manager::is_multi_auth($cookies)
                ? MicroChaos_Authentication_Manager::select_random_session($cookies)
                : $cookies;
            $args['cookies'] = MicroChaos_Authentication_Manager::format_for_wp_remote($selected);
        }

        $response = wp_remote_request($url, $args);
        $end = microtime(true);

        $duration = round($end - $start, 4);
        $code = is_wp_error($response)
            ? self::classify_wp_error($response)
            : wp_remote_retrieve_response_code($response);

        // Get response body for GraphQL error detection
        $response_body = is_wp_error($response) ? null : wp_remote_retrieve_body($response);
        $graphql_errors = $this->detect_graphql_errors($response_body);

        // Collect cache headers if enabled and the response is valid
        if ($this->collect_cache_headers && !is_wp_error($response)) {
            $headers = wp_remote_retrieve_headers($response);
            $this->collect_cache_header_data($headers);
        }

        $message = "⏱ MicroChaos Request | Time: {$duration}s | Code: {$code} | URL: $url | Method: $method";

        error_log($message);
        if ($log_path) {
            $this->log_to_file($message, $log_path);
        }

        if (class_exists('WP_CLI')) {
            $cache_display = '';
            if ($this->collect_cache_headers && !empty($this->last_request_cache_headers)) {
                $cache_display = ' ' . $this->format_cache_headers_for_display($this->last_request_cache_headers);
            }
            $gql_display = $graphql_errors > 0 ? " [GQL errors: {$graphql_errors}]" : '';
            MicroChaos_Log::log("-> {$code} in {$duration}s{$cache_display}{$gql_display}");
        }

        // Return result for reporting
        return [
            'time' => $duration,
            'code' => $code,
            'graphql_errors' => $graphql_errors,
        ];
    }

    /**
     * Classify a WP_Error returned by wp_remote_request()
     *
     * WordPress collapses every transport failure into the single error code
     * 'http_request_failed', so the message is the only place the cause
     * survives. cURL phrases it "Operation timed out after N milliseconds" and
     * the streams transport "Connection timed out", hence matching on the
     * shared substring.
     *
     * @param WP_Error $error Error from wp_remote_request()
     * @return string Status sentinel for the result row
     */
    private static function classify_wp_error($error): string {
        return false !== stripos($error->get_error_message(), 'timed out')
            ? self::STATUS_TIMEOUT
            : self::STATUS_ERROR;
    }

    /**
     * Resolve endpoint slug to a URL
     *
     * @param string $slug Endpoint slug or custom path
     * @return string|false URL or false if invalid
     */
    public function resolve_endpoint(string $slug): string|false {
        if (strpos($slug, 'custom:') === 0) {
            return home_url(substr($slug, 7));
        }
        switch ($slug) {
            case 'home': return home_url('/');
            case 'shop': return home_url('/shop/');
            case 'cart': return home_url('/cart/');
            case 'checkout': return home_url('/checkout/');
            default: return false;
        }
    }

    /**
     * Collect and catalog cache headers from the response
     *
     * @param array<string, string>|\WpOrg\Requests\Utility\CaseInsensitiveDictionary $headers Response headers
     */
    public function collect_cache_header_data(array|\WpOrg\Requests\Utility\CaseInsensitiveDictionary $headers): void {
        // Headers to track (Pressable specific and general cache headers)
        $cache_headers = ['x-ac', 'x-nananana', 'x-cache', 'age', 'x-cache-hits'];

        // Store current request cache headers for display
        $this->last_request_cache_headers = [];

        foreach ($cache_headers as $header) {
            if (isset($headers[$header])) {
                $value = $headers[$header];
                
                // Store for current request display
                $this->last_request_cache_headers[$header] = $value;
                
                // Store for overall accumulation
                if (!isset($this->cache_headers[$header])) {
                    $this->cache_headers[$header] = [];
                }
                if (!isset($this->cache_headers[$header][$value])) {
                    $this->cache_headers[$header][$value] = 0;
                }
                $this->cache_headers[$header][$value]++;
            }
        }
    }

    /**
     * Get cache headers data
     *
     * @return array<string, array<string, int>> Collection of cache headers
     */
    public function get_cache_headers(): array {
        return $this->cache_headers;
    }

    /**
     * Reset cache headers collection
     *
     * Clears the accumulated cache headers data
     */
    public function reset_cache_headers(): void {
        $this->cache_headers = [];
    }

    /**
     * Get cache headers for the last request
     *
     * @return array<string, string> Cache headers from the last request
     */
    public function get_last_request_cache_headers(): array {
        return $this->last_request_cache_headers;
    }

    /**
     * Format cache headers for display
     *
     * @param array<string, string> $headers Cache headers to format
     * @return string Formatted cache headers string
     */
    private function format_cache_headers_for_display(array $headers): string {
        $display_parts = [];
        
        // Focus on Pressable-specific headers
        if (isset($headers['x-ac'])) {
            $display_parts[] = "x-ac: {$headers['x-ac']}";
        }
        
        if (isset($headers['x-nananana'])) {
            $display_parts[] = "x-nananana: {$headers['x-nananana']}";
        }
        
        // Add other cache headers if present
        foreach (['x-cache', 'age'] as $header) {
            if (isset($headers[$header])) {
                $display_parts[] = "$header: {$headers[$header]}";
            }
        }
        
        return empty($display_parts) ? '' : '[' . implode('] [', $display_parts) . ']';
    }

    /**
     * Log message to a file
     *
     * @param string $message Message to log
     * @param string $path Path relative to WP_CONTENT_DIR
     */
    private function log_to_file(string $message, string $path): void {
        $path = sanitize_text_field($path);
        $filepath = trailingslashit(WP_CONTENT_DIR) . ltrim($path, '/');
        @file_put_contents($filepath, $message . PHP_EOL, FILE_APPEND);
    }

    /**
     * Get user agent string (custom if set, otherwise random)
     *
     * @return string User agent string
     */
    private function get_user_agent(): string {
        // Use custom User-Agent if set (required for Pressable headless apps)
        if ($this->custom_user_agent !== null) {
            return $this->custom_user_agent;
        }

        // Otherwise return random realistic user agent
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/92.0.4515.107 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.1.2 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.114 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (iPad; CPU OS 14_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/14.0 Mobile/15E148 Safari/604.1'
        ];
        return $agents[array_rand($agents)];
    }

    /**
     * Check if a string is valid JSON
     *
     * @param mixed $string String to check
     * @return bool Whether string is valid JSON
     */
    private function is_json(mixed $string): bool {
        if (!is_string($string)) {
            return false;
        }

        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    /**
     * Detect GraphQL errors in response body
     *
     * GraphQL returns HTTP 200 even when queries fail - errors are in the response body.
     * This method parses JSON responses and counts errors in the 'errors' array.
     *
     * @param string|null $body Response body to check
     * @return int Number of GraphQL errors (0 if none or not a GraphQL response)
     */
    private function detect_graphql_errors(?string $body): int {
        if ($body === null || $body === '') {
            return 0;
        }

        // Only parse if it looks like JSON
        if (!$this->is_json($body)) {
            return 0;
        }

        $decoded = json_decode($body, true);

        // Check for GraphQL errors array
        if (!is_array($decoded) || !isset($decoded['errors']) || !is_array($decoded['errors'])) {
            return 0;
        }

        // Return count of errors (empty array = 0 errors)
        return count($decoded['errors']);
    }
}
