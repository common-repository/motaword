<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://www.motaword.com/developer
 * @since      1.0.0
 *
 * @package    motaword
 * @subpackage motaword/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @version    2.0.3
 * @package    motaword
 * @subpackage motaword/includes
 * @author     MotaWord Engineering <it@motaword.com>
 */
class MotaWord
{

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      motaword_Loader $loader Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * The unique identifier of this plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string $motaword The string used to uniquely identify this plugin.
     */
    protected $motaword;
    /**
     * @var MotaWord_Active
     */
    protected $active;
    /**
     * @var MotaWord_Active_Serve
     */
    protected $active_serve;

    /**
     * The current version of the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      string $version The current version of the plugin.
     */
    protected $version;

    /**
     * @var MotaWord_i18n
     */
    protected $i18n;

    /**
     * Callback URL parameter name. Used such:
     *          wordpress.com/?$callbackEndpoint=1
     *
     * @var string
     */
    protected static $callbackEndpoint = 'mw-callback';
    /**
     * Database table name to store MotaWord projects.
     *
     * @warning A separate table for MW projects is not enabled by default.
     *
     * @var string
     */
    protected static $projectsTableName = 'motaword_projects';
    /**
     * Settings key used in register_setting.
     *
     * @var string
     */
    protected static $optionsKey = 'motaword_options';
    /**
     * @var string
     */
    protected $pluginFile = 'motaword/motaword.php';

    /**
     * Define the core functionality of the plugin.
     *
     * Set the plugin name and the plugin version that can be used throughout the plugin.
     * Load the dependencies, define the locale, and set the hooks for the admin area and
     * the public-facing side of the site.
     *
     * @param string $pluginName
     *
     * @version    2.0.3
     */
    public function __construct($pluginName = 'motaword/motaword.php')
    {

        $this->motaword = 'motaword';
        $this->version = '2.0.3';

        $this->setPluginFile($pluginName);
        $this->load_dependencies();
        $this->define_active_serve();
        $this->define_active();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the following files that make up the plugin:
     *
     * - MotaWord_Loader. Orchestrates the hooks of the plugin.
     * - MotaWord_i18n. Defines internationalization functionality.
     * - MotaWord_Admin. Defines all hooks for the admin area.
     * - MotaWord_Public. Defines all hooks for the public side of the site.
     *
     * Create an instance of the loader which will be used to register the hooks
     * with WordPress.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies()
    {
        /**
         * The class responsible for Active frontend integration.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-motaword-active.php';

        /**
         * The class responsible for Active Serve backend integration.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-motaword-active-serve.php';

        /**
         * The class responsible for orchestrating the actions and filters of the
         * core plugin.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-motaword-loader.php';

        /**
         * The class responsible for db interactions
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-motaword-db.php';

        /**
         * The class responsible for motaword api interactions
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-motaword-api.php';

        /**
         * The class responsible for defining internationalization functionality
         * of the plugin.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-motaword-i18n.php';

        /**
         * The class responsible for defining all actions that occur in the admin area.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-motaword-admin.php';

        /**
         * The class responsible for defining all actions that occur in the public-facing
         * side of the site.
         */
        require_once plugin_dir_path(dirname(__FILE__)) . 'public/class-motaword-public.php';

        $this->loader = new MotaWord_Loader();
    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * Uses the motaword_i18n class in order to set the domain and to register the hook
     * with WordPress.
     *
     * @since    1.0.0
     * @access   private
     */
    private function set_locale()
    {
        $this->i18n = new MotaWord_i18n();
        $this->loader->add_action('plugins_loaded', $this->i18n, 'load_plugin_textdomain');
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks()
    {
        $plugin_admin = new MotaWord_Admin($this->get_motaword(), $this->get_version(), $this);

        // Admin dependencies
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');

        // MotaWord call to actions
        $this->loader->add_action('add_meta_boxes', $plugin_admin, 'add_meta_boxes');
        $this->loader->add_action('admin_menu', $plugin_admin, 'register_my_custom_menu_page');

        if (is_multisite() && is_plugin_active_for_network($this->getPluginFile())) {
            $this->loader->add_action('network_admin_menu', $plugin_admin, 'register_network_settings');
        }

        // @note admin_init also initializes post columns.
        $this->loader->add_action('admin_init', $plugin_admin, 'admin_init');
        $this->loader->add_filter('plugin_action_links_' . $this->getPluginFile(), $plugin_admin, 'add_plugin_links');

        // Add actions to post listing page.
        $this->loader->add_action('load-edit.php', $plugin_admin, 'add_bulk_thickbox');
        $this->loader->add_action('admin_footer-edit.php', $plugin_admin, 'bulk_action');

        // Quote and project functionality
        $this->loader->add_action('wp_ajax_mw_get_quote', $plugin_admin, 'get_quote');
        $this->loader->add_action('wp_ajax_mw_prepare_bulk_quote', $plugin_admin, 'prepare_bulk_quote');
        $this->loader->add_action('wp_ajax_mw_get_bulk_quote', $plugin_admin, 'get_quote');
        $this->loader->add_action('wp_ajax_mw_submit_quote', $plugin_admin, 'start_project');
        $this->loader->add_action('admin_action_mw_callback', $plugin_admin, 'callback');

        $this->loader->add_filter('manage_posts_columns', $plugin_admin, 'init_columns', 9999, 2);
        $this->loader->add_action('manage_posts_custom_column', $plugin_admin, 'modify_column', 2, 2);

        $this->loader->add_filter('manage_pages_columns', $plugin_admin, 'init_columns', 9999, 2);
        $this->loader->add_action('manage_pages_custom_column', $plugin_admin, 'modify_column', 2, 2);
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_public_hooks()
    {
        //@todo Can we find a better alternative to the callback flow?
        //  I don't want to run this block for each frontend request.
        $plugin_public = new MotaWord_Public($this->get_motaword(), $this->get_version());

        $this->loader->add_action('init', $plugin_public, 'open_callback_endpoint');
        $this->loader->add_action('template_redirect', $plugin_public, 'handle_callback');
    }

    private function define_active_serve()
    {
        $this->active_serve = new MotaWord_Active_Serve($this);
        $this->loader->add_filter('wp_get_nav_menu_items', $this->active_serve, 'menu_items');
        $this->loader->add_filter('nav_menu_link_attributes', $this->active_serve, 'notranslate_menu_attributes', 10, 3);
        $this->loader->add_action('init', $this->active_serve, 'handle_request');
        $this->loader->add_action('post_updated', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('wp_trash_post', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('delete_post', $this->active_serve, 'invalidate_post');
        // $this->loader->add_action('clean_post_cache', $this->active_serve, 'invalidate_domain');
        $this->loader->add_action('wp_update_comment_count', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('admin_post_purge_cache', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('switch_theme', $this->active_serve, 'invalidate_domain');
        $this->loader->add_action('wp_update_nav_menu', $this->active_serve, 'invalidate_domain');
        $this->loader->add_action('update_option_sidebars_widgets', $this->active_serve, 'invalidate_domain');
        $this->loader->add_action('update_option_category_base', $this->active_serve, 'invalidate_domain');
        $this->loader->add_action('update_option_tag_base', $this->active_serve, 'invalidate_domain');
        $this->loader->add_action('permalink_structure_changed', $this->active_serve, 'invalidate_domain');
        $this->loader->add_action('create_term', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('edited_terms', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('delete_term', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('add_link', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('edit_link', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('delete_link', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('customize_save', $this->active_serve, 'invalidate_post');
        $this->loader->add_action('update_option_theme_mods_' . get_option( 'stylesheet' ), $this->active_serve, 'invalidate_domain');
    }

    private function define_active()
    {
        $this->active = new MotaWord_Active($this);
        $this->loader->add_action('init', $this->active, 'enqueue_scripts');
        $this->loader->add_action('init', $this->active, 'enqueue_styles');
        $this->loader->add_action('init', $this->active, 'open_callback_endpoint');
        $this->loader->add_action('template_redirect', $this->active, 'handle_callback');
    }

    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run()
    {
        $this->loader->run();
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     * @return    string    The name of the plugin.
     * @since     1.0.0
     */
    public function get_motaword()
    {
        return $this->motaword;
    }

    /**
     * The reference to the class that orchestrates the hooks with the plugin.
     *
     * @return    motaword_Loader    Orchestrates the hooks of the plugin.
     * @since     1.0.0
     */
    public function get_loader()
    {
        return $this->loader;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     * @return    string    The version number of the plugin.
     * @since     1.0.0
     */
    public function get_version()
    {
        return $this->version;
    }

    /**
     * Callback URL parameter name. Used such:
     *          wordpress.com/?$callbackEndpoint=1
     *
     * @default mw-callback
     *
     * @return string
     */
    public static function getCallbackEndpoint()
    {
        return static::$callbackEndpoint;
    }

    /**
     * Database table name to store MotaWord projects.
     *
     * @warning A separate table for MW projects is not enabled by default.
     *
     * @return string
     */
    public static function getProjectsTableName()
    {
        return static::$projectsTableName;
    }

    /**
     * Settings key used in register_setting.
     *
     * @return string
     */
    public static function getOptionsKey()
    {
        return static::$optionsKey;
    }

    public function getNetworkOption($key)
    {
        return get_site_option($key);
    }

    public function getBlogOption($key)
    {
        return get_option($key);
    }

    public function getOption($key, $returnNetwork = null)
    {
        // When null, we don't evaluate it.
        // True: return network option.
        // False: return single site (blog) option.
        if ($returnNetwork === true) {
            return $this->getNetworkOption($key);
        } elseif ($returnNetwork === false) {
            return $this->getBlogOption($key);
        }

        $value = $this->getBlogOption($key);

        if ($value === null && is_multisite() && is_plugin_active_for_network($this->getPluginFile())) {
            $value = $this->getNetworkOption($key);
        }

        return $value;
    }

    public function setOption($key, $value, $network = false)
    {
        if ($network === true) {
            update_site_option($key, $value);
        } else {
            update_option($key, $value);
        }

        return true;
    }

    public function setPluginFile($name)
    {
        $this->pluginFile = $name;

        return true;
    }

    public function getPluginFile()
    {
        return $this->pluginFile;
    }

    public static function decryptVCRaw($string)
    {
        return preg_replace_callback('/\[vc_raw_html\].+?\[\/vc_raw_html\]/s', function ($it) {
            $rep = str_replace(['[vc_raw_html]', '[/vc_raw_html]'], [], $it[0]);

            return '[vc_raw_html]' . rawurldecode(base64_decode(strip_tags($rep))) . '[/vc_raw_html]';
        }, $string);
    }

    public static function encryptVCRaw($string)
    {
        return preg_replace_callback('/\[vc_raw_html\].+?\[\/vc_raw_html\]/s', function ($it) {
            $rep = str_replace(['[vc_raw_html]', '[/vc_raw_html]'], [], $it[0]);

            return '[vc_raw_html]' . base64_encode(rawurlencode($rep)) . '[/vc_raw_html]';
        }, $string);
    }

    /**
     * @return MotaWord_Active
     */
    public function getActive(): MotaWord_Active
    {
        return $this->active;
    }

    /**
     * @return MotaWord_Active_Serve
     */
    public function getActiveServe(): MotaWord_Active_Serve
    {
        return $this->active_serve;
    }


}
