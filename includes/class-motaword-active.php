<?php

/**
 * The frontend functionality of MotaWord Active.
 *
 * @link       https://www.motaword.com/developer
 * @since      2.0.0
 *
 * @package    motaword
 * @subpackage motaword/includes
 */

/**
 * The frontend functionality of MotaWord Active.
 *
 * @package    motaword
 * @subpackage motaword/includes
 * @author     MotaWord Engineering <it@motaword.com>
 */
class MotaWord_Active
{
    const SERVE_PAGE_OPTIMIZED = true;
    const SERVE_HOST_ENABLED = true;
    const OPTIMIZE_FOR_BROWSERS = true;
    const SERVE_HOST = 'https://serve.motaword.com';
    const ACTIVE_JS_HOST = 'https://active-js.motaword.com';
    const CALLBACK_QUERY_PARAM = 'mw-active-callback';
    /**
     * @var MotaWord
     */
    protected $plugin;

    public function __construct(MotaWord $plugin)
    {
        $this->plugin = $plugin;
    }

    public function generateScript()
    {
        $plugin = $this->plugin;

        $is_insert_active_js = $plugin->getOption(MotaWord_Admin::$options['is_insert_active_js']);
        if (!$is_insert_active_js) {
            if (current_user_can('administrator') && !is_admin() && empty($_GET['et_fb']) && empty($_GET['ct_builder']) && empty($_GET['oxygen_iframe'])) {
                $is_insert_for_admin_when_disabled = $plugin->getOption(MotaWord_Admin::$options['is_insert_for_admin_when_disabled']);
                if (!$is_insert_for_admin_when_disabled) {
                    return null;
                }
            } else {
                return null;
            }
        }

        $active_token = $plugin->getOption(MotaWord_Admin::$options['active_token']);
        $active_project = $plugin->getOption(MotaWord_Admin::$options['active_project_info']);
        $active_widget = $plugin->getOption(MotaWord_Admin::$options['active_widget_info']);

        if (!$active_token || !$active_project || !isset($active_project['id']) || !$active_widget || !isset($active_widget['id'])) {
            return null;
        }

        $is_active_urlmode_query = $plugin->getOption(MotaWord_Admin::$options['is_active_urlmode_query']);
        $active_project_id = $active_project['id'];
        $active_widget_id = $active_widget['id'];

        $injection = '';
        $metaTags = '<meta name="google" content="notranslate"/>';
        $scriptUrl = '';
        $pageOptimizedAttribute = '';

        if (static::SERVE_PAGE_OPTIMIZED) {
            $pageOptimizedAttribute = ' referrerpolicy="unsafe-url" ';
        }

        if (static::SERVE_HOST_ENABLED) {
            $scriptUrl = static::SERVE_HOST."/js/$active_project_id-$active_widget_id.js";
            $injection .= "<script src=\"$scriptUrl\" data-token=\"$active_token\" crossorigin async $pageOptimizedAttribute></script>";
        } else {
            $scriptUrl = static::ACTIVE_JS_HOST;
            $injection .= "<script src=\"$scriptUrl\" data-token=\"$active_token\" data-project-id=\"$active_project_id\" data-widget-id=\"$active_widget_id\" crossorigin async $pageOptimizedAttribute></script>";
        }

        if (static::OPTIMIZE_FOR_BROWSERS) {
            $preload = '';
            if (static::SERVE_HOST_ENABLED) {
                $preload = '<link rel="preconnect" href="'.static::SERVE_HOST.'"/>'.$preload;
            } else {
                $preload = '<link rel="preconnect" href="'.static::ACTIVE_JS_HOST.'"/>'.$preload;
            }
            $preload = $preload."<link rel=\"preload\" href=\"$scriptUrl\" as=\"script\" onload=\"document.dispatchEvent(new Event('ACTIVE_LOADED'))\" importance=\"high\" crossorigin".$pageOptimizedAttribute.'/><link rel="preconnect" href="'.MotaWord_API::$PRODUCTION_URL.'"/>';
            $injection = $preload.$injection;
        }

        if (current_user_can('administrator')) {
            if ($is_active_urlmode_query) {
                $metaTags .= '<meta name="motaword:urlMode" content="query">';
            }

            if (isset($active_widget['live']) && !$active_widget['live']) {
                $metaTags .= '<meta name="motaword:live" content="true"><meta name="motaword:adminMode" content="true">';
            }
        }

        echo PHP_EOL.$injection.PHP_EOL.$metaTags.PHP_EOL;
        return $injection;
    }

    public function fetchProjectWidgetMetadata($token = null)
    {
        $plugin = $this->plugin;
        if (!$token) {
            $token = $plugin->getOption(MotaWord_Admin::$options['active_token']);
        }
        if (!$token) {
            throw new Exception('MotaWord Active token is required.');
        }

        $response = wp_remote_request(static::SERVE_HOST.'/get-local-metadata', ['headers' => ['X-MotaWord-Token' => $token], 'timeout' => 60]);
        if(!is_array($response)) {
            throw new Exception('MotaWord Active project and widget info could not be fetched. Error: '.json_encode($response));
        }

        $metadata = json_decode($response['body'], true);
        if (isset($metadata['documents'])) {
            unset($metadata['documents']);
        }
        // keys: project, widget
        return $metadata;
    }

    public function fetchCustomerUrlDetails($url, $token = null)
    {
        $plugin = $this->plugin;
        if (!$token) {
            $token = $plugin->getOption(MotaWord_Admin::$options['active_token']);
        }
        if (!$token) {
            throw new Exception('MotaWord Active token is required.');
        }

        $body = ['urls' => [$url]];

        $reqUrl = static::SERVE_HOST.'/prepare-customer-urls';
        if (current_user_can('administrator')) {
            $is_active_urlmode_query = $plugin->getOption(MotaWord_Admin::$options['is_active_urlmode_query']);
            if ($is_active_urlmode_query) {
                $reqUrl = $reqUrl.'?urlMode=query';
            }
        }
        $response = wp_remote_request($reqUrl, [
            'headers' => ['X-MotaWord-Token' => $token, 'Content-Type' => 'application/json; charset=utf-8'],
            'body' => json_encode($body),
            'data_format' => 'body',
            'method'      => 'POST',
            'timeout' => 30,
        ]);
        if(!is_array($response)) {
            throw new Exception('MotaWord Active project and widget info could not be fetched. Error: '.json_encode($response));
        }

        $metadata = json_decode($response['body'], true);
        if (isset($metadata['urls'][0])) {
            return $metadata['urls'][0];
        }
        return [];
    }

    /**
     * Generate some utility CSS to hide/show elements per locale.
     * This CSS is added in all cases, no matter if ActiveJS insertion is enabled.
     * @return string|null
     */
    public function generateCss()
    {
        $active_project = $this->plugin->getOption(MotaWord_Admin::$options['active_project_info']);
        if (!isset($active_project['sourceLanguage']) && !isset($active_project['targetLanguages'])) {
            return null;
        }

        $injection = '<style class="mw-active-hide-locale-css">';

        if (isset($active_project['sourceLanguage'])) {
            $sourceLanguage = $active_project['sourceLanguage'];
            $injection .= 'html[lang='.$sourceLanguage.'] .hide-locale-'.$sourceLanguage.' {display:none!important;}'.PHP_EOL;

            if (strlen($sourceLanguage) > 2) {
                $shorterSourceLanguage = substr($sourceLanguage, 0, 2);
                // add rule variations of shorter-longer language codes
                $injection .= 'html[lang='.$shorterSourceLanguage.'] .hide-locale-'.$shorterSourceLanguage.' {display:none!important;}'.PHP_EOL;
                $injection .= 'html[lang='.$shorterSourceLanguage.'] .hide-locale-'.$sourceLanguage.' {display:none!important;}'.PHP_EOL;
                $injection .= 'html[lang='.$sourceLanguage.'] .hide-locale-'.$shorterSourceLanguage.' {display:none!important;}'.PHP_EOL;
            }
        }

        if (isset($active_project['targetLanguages'])) {
            foreach ($active_project['targetLanguages'] as $targetLanguage) {
                $injection .= 'html[lang='.$targetLanguage.'] .hide-locale-'.$targetLanguage.' {display:none!important;}'.PHP_EOL;

                // also add the shorter code for the language, e.g. for "fr-CA", "fr" rule is also added.
                if (strlen($targetLanguage) > 2) {
                    $shorterTargetLanguage = substr($targetLanguage, 0, 2);
                    // add rule variations of shorter-longer language codes
                    $injection .= 'html[lang='.$shorterTargetLanguage.'] .hide-locale-'.$shorterTargetLanguage.' {display:none!important;}'.PHP_EOL;
                    $injection .= 'html[lang='.$shorterTargetLanguage.'] .hide-locale-'.$targetLanguage.' {display:none!important;}'.PHP_EOL;
                    $injection .= 'html[lang='.$targetLanguage.'] .hide-locale-'.$shorterTargetLanguage.' {display:none!important;}'.PHP_EOL;
                }
            }
        }

        $injection .= '</style>';

        echo PHP_EOL.$injection.PHP_EOL;
        return $injection;
    }

    /**
     * Register the JavaScript for MotaWord Active frontend.
     */
    public function enqueue_scripts()
    {
        add_action('wp_head', [$this, 'generateScript']);
    }

    public function enqueue_styles()
    {
        add_action('wp_head', [$this, 'generateCss']);
    }

    public function open_callback_endpoint()
    {
        add_rewrite_endpoint(static::CALLBACK_QUERY_PARAM, EP_PERMALINK);
    }

    public function handle_callback()
    {
        global $motawordPlugin;

        include_once(ABSPATH . 'wp-admin/includes/plugin.php');

        if (!function_exists('is_plugin_active')
            || !is_plugin_active(!!$motawordPlugin ? $motawordPlugin->getPluginFile() : 'motaword/motaword.php')) {
            return;
        }

        $callbackEndpoint = static::CALLBACK_QUERY_PARAM;

        if (isset($_GET[$callbackEndpoint])) {
            $callback = $_GET[$callbackEndpoint];
            if ($callback === 'updated' || $callback === 'project-updated' || $callback === 'widget-updated') {
                $metadata = $this->fetchProjectWidgetMetadata();

                if ($metadata) {
                    if (isset($metadata['project'])) {
                        $motawordPlugin->setOption(MotaWord_Admin::$options['active_project_info'], $metadata['project']);
                    }

                    if (isset($metadata['widget'])) {
                        $motawordPlugin->setOption(MotaWord_Admin::$options['active_widget_info'], $metadata['widget']);
                    }
                }

                MotaWord_Active_Serve::respond(json_encode(array('status' => 'success')), 200, ['Content-Type' => 'application/json']);
            }
        }
    }
}
