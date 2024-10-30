<?php

/**
 * The public-facing functionality of the plugin. Currently works only to handle callbacks.
 *
 * @link       https://www.motaword.com/developer
 * @since      2.0.0
 *
 * @package    motaword
 * @subpackage motaword/includes
 */

/**
 * The public-facing functionality of the plugin.
 * Currently, it works only to handle callbacks.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    motaword
 * @subpackage motaword/includes
 * @author     MotaWord Engineering <it@motaword.com>
 */
class MotaWord_Active_Serve
{
    /**
     * @var MotaWord
     */
    protected $plugin;
    /**
     * @var bool
     */
    protected $is_active_serve_enabled;
    /**
     * @var string
     */
    protected $active_token;
    /**
     * @var array|null MotaWord Active Project info
     */
    protected $active_project;
    /**
     * @var array|null MotaWord Active Widget info
     */
    protected $active_widget;
    /**
     * @var string
     */
    protected $currentUrl;
    /**
     * @var array
     */
    protected $currentHeaders;
    /**
     * @var bool When true, we will forward the incoming HTTP headers onto Active Serve.
     */
    const PRESERVE_HEADERS = false;
    const HIDE_CURRENT_LOCALE_IN_MENU = false;
    /**
     * @param MotaWord $plugin
     */
    public function __construct(MotaWord $plugin)
    {
        $this->plugin = $plugin;
        $this->is_active_serve_enabled = $plugin->getOption(MotaWord_Admin::$options['is_active_serve_enabled']);
        $this->active_token = $plugin->getOption(MotaWord_Admin::$options['active_token']);
        $this->active_token = $plugin->getOption(MotaWord_Admin::$options['active_token']);
        $this->active_project = $plugin->getOption(MotaWord_Admin::$options['active_project_info']);
        $this->active_widget = $plugin->getOption(MotaWord_Admin::$options['active_widget_info']);
    }

    /**
     * Evaluates the incoming WordPress request to proxy via Active Serve.
     * @return void
     */
    public function handle_request()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET'
            || strpos($_SERVER["REQUEST_URI"], '/wp-json/') !== false) {
            return;
        }

        $sourcePath = $this->get_current_path(false);

        if (!$this->is_active_serve_enabled || !$this->active_token) {
            // is_url_allowed will redirect to non-locale URL is Serve is not enabled.
            $this->is_url_allowed($sourcePath);
            return;
        }

        // if widget is not live, only admins will see Serve-rendered page.
        if (!isset($this->active_widget['live']) || !$this->active_widget['live']) {
            if (!current_user_can('administrator')) {
                return;
            }
        }

        $this->reset_request();

        if(is_admin()) {
            return;
        }

        $sourcePathAndQuery = $this->get_current_path(true);
        $sourceUrl = $this->get_wp_current_url($sourcePathAndQuery);
        $sourceHeaders = $this->get_incoming_headers();

        if (!$this->is_url_allowed($sourcePath)) {
            return;
        }

        $this->currentUrl = $sourceUrl;
        $this->currentHeaders = $sourceHeaders;
        $this->send_to_serve();
    }

    private function get_incoming_headers()
    {
        static $headers = array();
        if (!$headers) {
            foreach ($_SERVER as $name => $value) {
                if (substr($name, 0, 5) !== 'HTTP_'
                    || in_array($name, array('HTTP_HOST', 'HTTP_ACCEPT_ENCODING', 'HTTP_CONTENT_LENGTH'))) {
                    continue;
                }

                $key = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    private function send_to_serve()
    {
        $host = MotaWord_Active::SERVE_HOST;
        $headers = [];
        if (static::PRESERVE_HEADERS) {
            $headers = $this->currentHeaders;
        } else {
            if (isset($this->currentHeaders['User-Agent']) && $this->currentHeaders['User-Agent']) {
                $headers = ['User-Agent' => $this->currentHeaders['User-Agent']];
            }
        }
        $headers['X-MotaWord-Token'] = $this->active_token;
        // Serve CDN cache differs by accept-encoding, this is a common header for better cache development
        $headers['Accept-Encoding'] = 'gzip, deflate, br';
        if (isset($this->currentHeaders['Authorization']) && $this->currentHeaders['Authorization']) {
            $headers['Authorization'] = $this->currentHeaders['Authorization'];
        }

        $url = user_trailingslashit($this->currentUrl);
        $url = urlencode_deep($url);

        $args = array(
            'headers' => $headers,
            'body' => null,
            'method' => 'GET',
            'timeout' => '70',
            'blocking' => true,
            'redirection' => 0,
        );
        if (isset($headers['User-Agent'])) {
            $args['user-agent'] = $headers['User-Agent'];
        }

        $response = wp_remote_request( $host.'/'.$url, $args );

        $this->reset_request();

        if(!is_array($response) || !isset($response['body']) || !isset($response['response']['code']) || (int)$response['response']['code'] >= 500) {
            return null;
        }

        if ($response['response']['code'] >= 300 && $response['response']['code'] < 400) {
            if (!empty($response['headers']['location'])) {
                return wp_redirect($response['headers']['location'], $response['response']['code']);
            }
        }

        $this->respond($response['body'], $response['response']['code']);
    }

    public function invalidate_post($post)
    {
        if ( defined( 'DOING_AUTOSAVE' ) ) {
            return;
        }

        if (!is_object($post)) {
            $post = get_post($post);
            if (!is_object($post)) {
                return;
            }
        }

        if ('auto-draft' === $post->post_status || empty($post->post_type) || 'nav_menu_item' === $post->post_type || 'attachment' === $post->post_type) {
            return;
        }

        $post_type = get_post_type_object($post->post_type);
        if (!is_object($post_type) || true !== $post_type->public) {
            return;
        }

        $url = rtrim(get_permalink($post), '/');
        if (!$url) {
            return;
        }

        $urls = [$url, $url . "/", $url . "/*"];
        $this->invalidate_urls($urls);
    }

    public function invalidate_domain()
    {
        static $alreadyInvalidated = false;
        if ($alreadyInvalidated) {
            return;
        }
        $url = rtrim(home_url(), '/');
        if (!$url) {
            return;
        }
        $urls = [$url, $url . "/", $url . "/*"];
        $this->invalidate_urls($urls);
        $alreadyInvalidated = true;

        // start a new crawl to refresh Active Serve cache
        $host = MotaWord_Active::SERVE_HOST;
        $serveCrawlerUrl = $host.'/crawler/';
        $args = [
            'headers' => ['X-MotaWord-Token' => $this->active_token, 'Content-Type' => 'application/json'],
            'body' => json_encode([
                'operation' => 'refresh',
                'token' => $this->active_token,
                'urls' => [$url."/"],
                'targetLocales' => $this->active_project['targetLanguages'],
                'gracefulRequestLimit' => 100,
                'followLinks' => true,
                'maxConcurrency' => 1
            ]),
            'method' => 'POST',
            'timeout' => '30',
            'redirection' => '10',
            'blocking' => true,
        ];
        try {
            wp_remote_request( $serveCrawlerUrl, $args );
        } catch (Exception $e) {}
    }

    public function invalidate_urls($urls)
    {
        static $alreadyInvalidated = [];
        if (!$urls) {
            return;
        }

        $urls = array_diff($urls, $alreadyInvalidated);
        if (!$urls) {
            return;
        }

        $host = MotaWord_Active::SERVE_HOST;
        $servePurgeUrl = $host.'/purge-page';
        $args = [
            'headers' => ['X-MotaWord-Token' => $this->active_token],
            'body' => ['pages' => $urls],
            'method' => 'POST',
            'timeout' => '30',
            'redirection' => '10',
            'blocking' => true,
        ];

        // specify purge reason in the purge url
        $current_action = function_exists('current_action') ? current_action() : null;
        if ($current_action) {
            $servePurgeUrl .= '?reason='.$current_action;
        }

        try {
            wp_remote_request( $servePurgeUrl, $args );
        } catch (Exception $e) {}

        $alreadyInvalidated = array_unique(array_merge($alreadyInvalidated, $urls));
    }

    public static function respond($content, $response_code, $headers = [])
    {
        ignore_user_abort(true);
        header_remove();
        @ob_end_clean();
        ob_start();

        $prependToBody = null;
        if (is_admin_bar_showing()) {
            do_action( 'activate_header', '_wp_admin_bar_init' );
            do_action( 'wp_body_open', 'wp_admin_bar_render', 0 );
            do_action( 'wp_footer', 'wp_admin_bar_render', 1000 ); // Back-compat for themes not using `wp_body_open`.
            do_action( 'in_admin_header', 'wp_admin_bar_render', 0 );

            $prependToBody = @ob_get_clean();
            ob_start();
        }

        if ($content) {
            if ($prependToBody && trim($prependToBody)) {
                $content = preg_replace('/<body([^>]*)>/m', "$0$prependToBody", $content);
            }
            echo $content;
        }

        header('Connection: close');
        header_remove('Content-Length');
        if ($headers) {
            foreach ($headers as $key => $value) {
                header("$key: $value");
            }
        }
        http_response_code($response_code);
        ob_end_flush();
        flush();
        exit(0);
    }

    public function menu_items($items)
    {
        if(is_admin()) {
            return $items;
        }
        static $urlDetails = [];
        if (!$items) {
            return $items;
        }
        $headers = $this->currentHeaders ? $this->currentHeaders : $this->get_incoming_headers();
        // when $requestedForLocale set, it means we are (Serve is) fetching this page
        // to translate into $requestedForLocale. In that case, we'll hide the switch for $requestedForLocale.
        $requestedForLocale = null;
        if (static::HIDE_CURRENT_LOCALE_IN_MENU) {
            if (isset($headers['X-Motaword-Targetlanguage'])) {
                $requestedForLocale = $headers['X-Motaword-Targetlanguage'];
            }
            if (isset($_GET['x-motaword-targetlanguage'])) {
                $requestedForLocale = $_GET['x-motaword-targetlanguage'];
            }
        }

        foreach ($items as $k => $item) {
            if (!property_exists($item, 'classes')
                || !$item->classes) {
                continue;
            }

            $classes = is_array($item->classes) ? $item->classes : explode(' ', $item->classes);
            foreach ($classes as $class) {
                // hide-locale- logic is handled with CSS rules in: MotaWord_Active::enqueue_styles
                if (strpos($class, 'hide-locale-') !== false) {
                    continue;
                }

                if (strpos($class, 'switch-current-url-') === false) {
                    continue;
                }

                $parts = explode('switch-current-url-', $class);
                if (!isset($parts[1]) || !$parts[1]) {
                    continue;
                }

                $locale = $parts[1];
                // call serve to fetch customer url for locale
                if (!$urlDetails) {
                    try {
                        $url = $this->get_wp_current_url();
                        $urlDetails = $this->plugin->getActive()->fetchCustomerUrlDetails($url);
                    } catch (\Exception $e) {}
                }

                if (isset($urlDetails['customerUrl']) && isset($urlDetails['customerLocalizedUrls'][$locale])) {
                    $item->url = $urlDetails['customerLocalizedUrls'][$locale];
                    if ($requestedForLocale && $locale === $requestedForLocale) {
                        // we are already in this locale url, no need to show it in the menu.
                        unset($items[$k]);
                    }
                }
            }
        }

        return $items;
    }

    public function notranslate_menu_attributes($atts, $item, $args)
    {
        if (!property_exists($item, 'classes')
            || !$item->classes) {
            return $atts;
        }

        $classes = is_array($item->classes) ? $item->classes : explode(' ', $item->classes);
        foreach ($classes as $class) {
            // hide-locale- logic is handled with CSS rules in: MotaWord_Active::enqueue_styles
            if (strpos($class, 'hide-locale-') !== false
                || strpos($class, 'switch-current-url-') !== false) {
                $atts['translate'] = 'nolocalize';
                break;
            }
        }

        return $atts;
    }

    // starts and ends with slash /home/
    private function get_current_path($includeQueryParams = false)
    {
        global $wp;
        $wp->parse_request();
        $path = $wp->request;
        if (substr($path, 0, 1) !== '/') {
            $path = '/'.$path;
        }
        if (substr($path, -1, 1) !== '/') {
            $path = $path.'/';
        }
        if (!$includeQueryParams) {
            return $path;
        }
        $incomingParams = $_GET;
        if (isset($incomingParams['x-motaword-targetlanguage'])) {
            unset($incomingParams['x-motaword-targetlanguage']);
        }
        return add_query_arg(array($incomingParams), $path);
    }

    public function get_wp_current_url($path = null)
    {
        if ($path === null) {
            $path = $this->get_current_path(true);
        }
        return home_url($path);
    }

    private function is_url_allowed($path): bool
    {
        if (strpos($path, '/wp-admin') === 0) {
            return false;
        }

        $locales = $this->active_project && isset($this->active_project['targetLanguages']) ? (array)$this->active_project['targetLanguages'] : [];
        if (!$locales) {
            return false;
        }

        // todo evaluate language mappings
        // $languageMappings = $this->active_widget['languageMappings'];

        $blacklistUrls = explode("\n", (string)$this->plugin->getOption(MotaWord_Admin::$options['active_blacklist_urls']));
        $blacklistUrls = array_filter(array_map(function ($url) { return isset($url) ? trim($url) : null; }, $blacklistUrls));
        $patternWithLocale = '#^(^([/]?)(' . implode('|', $locales) . '))(?:\/.*|\z)#u';
        preg_match($patternWithLocale, rtrim(trim($path), '/'), $matches);
        $isLocalePath = isset($matches[1]) && $matches[1] ? $matches[1] : ($matches[0] ?? null);
        $pathWithoutLocale = isset($matches[4]) ? $matches[4] : null;

        if ($isLocalePath && trim(trim($isLocalePath), '/')) {
            if ($pathWithoutLocale && $pathWithoutLocale !== $path && (
                !$this->is_active_serve_enabled
                || strpos($pathWithoutLocale, 'wp-admin') > -1
                || in_array($pathWithoutLocale, $blacklistUrls)
                || (substr($pathWithoutLocale, -1, 1) !== '/' && in_array($pathWithoutLocale.'/', $blacklistUrls))
                || (substr($pathWithoutLocale, -1, 1) === '/' && in_array(substr($pathWithoutLocale, 0, -1), $blacklistUrls)))
            ) {
                $pathWithoutLocale = add_query_arg(array($_GET), $pathWithoutLocale);
                wp_redirect($pathWithoutLocale);
                exit();
            }
            return true;
        }
        return false;
    }

    private function reset_request()
    {
        $this->currentUrl = null;
        $this->currentHeaders = [];
    }
}
