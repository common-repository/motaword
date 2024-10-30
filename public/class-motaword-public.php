<?php

/**
 * The public-facing functionality of the plugin. Currently works only to handle callbacks.
 *
 * @link       https://www.motaword.com/developer
 * @since      1.0.0
 *
 * @package    motaword
 * @subpackage motaword/public
 */

/**
 * The public-facing functionality of the plugin.
 * Currently, it works only to handle callbacks.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    motaword
 * @subpackage motaword/public
 * @author     MotaWord Engineering <it@motaword.com>
 */
class MotaWord_Public
{
    /**
     * Enable detection of callback endpoint.
     * @example domain.com?mw-callback=1
     */
    public function open_callback_endpoint()
    {
        global $motawordPlugin;

        $callbackEndpoint = !!$motawordPlugin ? MotaWord::getCallbackEndpoint() : 'mw-callback';

        add_rewrite_endpoint($callbackEndpoint, EP_PERMALINK);
    }

    /**
     * Process the callback sent from MotaWord's API for various events, such as "translated", "proofread", "completed".
     * When the action is "completed", we'll try to download and complete the project.
     *
     * @return bool
     * @throws Exception
     */
    public function handle_callback()
    {
        global $motawordPlugin;

        include_once(ABSPATH . 'wp-admin/includes/plugin.php');

        if (!function_exists('is_plugin_active') || !is_plugin_active(!!$motawordPlugin
                ? $motawordPlugin->getPluginFile() : 'motaword/motaword.php')
        ) {
            return false;
        }

        $callbackEndpoint = !!$motawordPlugin ? $motawordPlugin->getCallbackEndpoint() : 'mw-callback';

        if (isset($_GET[$callbackEndpoint])) {
            $data = json_decode(file_get_contents('php://input'), true);
            if (isset($data['type']) &&
                isset($data['project']) &&
                isset($data['action']) &&
                $this->process_callback(sanitize_text_field($data['action']), $data['project'])
            ) {
                echo json_encode(array('status' => 'success'));
            }

            exit();
        }
    }

    /**
     * Downloads the translation from MotaWord's API and replaces the related post with the translation, including
     * title, content, excerpt, custom fields etc.
     *
     * @param string $action
     * @param array $project Array of project data, ideally as received from the API endpoint.
     *                              This data is sent by our API during callbacks.
     *                              Currently only $project['id'] fields is used.
     *
     * @warning If this method starts using more than $project['id'] value, related JS method which allows manual
     *          translation fetch should be updated as well.
     *
     * @warning Media fields are not enabled yet.
     *
     * @return bool
     * @throws Exception
     */
    public function process_callback($action, $project)
    {
        if (!$action || ($action !== 'completed') || !$project) {
            return false;
        }

        $mwProjectId = intval(sanitize_text_field($project['id']));

        if (!$mwProjectId) {
            return false;
        }

        global $motawordPlugin;

        $mwApiHelper = new MotaWord_API($motawordPlugin->getOption(MotaWord_Admin::$options['client_id']),
            $motawordPlugin->getOption(MotaWord_Admin::$options['client_secret']),
            false);

        //$wpPostId = $project['custom']['wp_post_id'];
        $DBHelper = new MotaWord_DB(MotaWord::getProjectsTableName());
        $wpPostId = $DBHelper->getPostIDByProjectID($mwProjectId, 'any');

        // Post data
        $updateData = array(
            'ID' => $wpPostId
            // Other data to be updated will be added below.
        );
        // Custom fields data
        $customFieldData = array();

        // Incoming data from MotaWord to be updated on the WP post.
        // this is a merge of all html and json files.
        $incomingData = array();
        // $blockData is a key => value of serializedSource => arrayTarget.
        // this is extracted from the json file we send for translation.
        // blockData array is then used to replace in post_content the blocks with their translated versions.
        $blockData = array();

        $zipFileBody = $mwApiHelper->downloadProject($mwProjectId);

        $zipFile = tmpfile();
        fwrite($zipFile, $zipFileBody);
        $zipFilePath = stream_get_meta_data($zipFile);
        $zipFilePath = $zipFilePath['uri'];

        $baseFolder = wp_upload_dir();
        $baseFolder = $baseFolder['path'];
        $baseFolder = trailingslashit($baseFolder) . '' . rand();

        if (!mkdir($baseFolder)) {
            throw new Exception('Could not create a temporary folder for translated files. Make sure you have required permissions.');
        }

        $zip = new \ZipArchive;

        if ($zip->open($zipFilePath) === true) {
            if (!$zip->extractTo($baseFolder)) {
                throw new Exception('Could not extract the translation ZIP file.');
            }

            $zip->close();
        } else {
            throw new Exception('Could not open the translation ZIP file.');
        }

        // MW is sending files in a subfolder, per language.
        $languageFolders = glob($baseFolder . '/*');

        // Folder is the actual inner folder which contains .json and .html files. It can be a language folder.
        $folder = $baseFolder;

        if (is_array($languageFolders) && count($languageFolders) > 0) {
            $folder = $languageFolders[0];
            $folderPrefix = substr(basename($folder), 0, 1);
            // prevents using OS folders such as __MACOS in custom packages.
            if (($folderPrefix === '_' || $folderPrefix === '.') && $languageFolders[1]) {
                $folder = $languageFolders[1];
            }
        }

        // If a 123.json file exists, which keeps non-html key-value content.
        // We are merging all incoming data, from json or html, in one array (or two, with custom fields).
        // We will apply the update later altogether.
        $jsonFiles = glob($folder . "/*.json");

        if (is_Array($jsonFiles) && isset($jsonFiles[0])) {
            $jsonFile = $jsonFiles[0];

            $fields = file_get_contents($jsonFile);
            $fields = json_decode($fields, true);

            if (!!$fields && is_array($fields)) {
                $incomingData = array_replace_recursive($incomingData, $fields);
            }

            unset($fields);
        }

        // Get remaining html files, if any.
        // We are merging all incoming data, from json or html, in one array (or two, with custom fields).
        // We will apply the update later altogether.
        foreach (glob($folder . "/*.html") as $file) {
            $fileName = basename($file);
            $key = str_replace('.html', '', $fileName);

            if (!$key) {
                continue;
            }

            $value = file_get_contents($file);

            if (strlen($value) < 1) {
                continue;
            }

            $incomingData[$key] = $value;

            // $value can be large.
            unset($value);
        }

        // We don't need downloaded files any more. All data in memory at the moment.
        $this->recursive_rmdir($baseFolder);
        // tmpfile Resource will be deleted here.
        fclose($zipFile);

        foreach ($incomingData as $key => $value) {
            if (!$value) {
                continue;
            }

            // Check if there is any vc_raw_html tags.
            // This tag contains base64 encoded strings and corrupting everything.
            // We decrypt before sending for translation and then encrypt it back to original.
            if (is_string($value) && strpos($value, '[vc_raw_html]') > -1) {
                $encrypted = MotaWord::encryptVCRaw($value);

                if (!!$encrypted) {
                    $value = $encrypted;
                }
            }

            switch ($key) {
                case 'TITLE':
                    $updateData['post_title'] = $value;
                    break;
                case 'CONTENT':
                    $updateData['post_content'] = $value;
                    break;
                case 'EXCERPT':
                    $updateData['post_excerpt'] = $value;
                    break;
                case 'SLUG':
                    $updateData['post_name'] = $value;
                    break;
                default:
                    if (strpos($key, 'CUSTOMFIELD_') > -1) {
                        $key = str_replace('CUSTOMFIELD_', '', $key);

                        if (strlen($key) > 0) {
                            $customFieldData[$key] = $value;
                        }
                    } else if (strpos($key, '<!--') > -1) {
                        // this is a block element. we are translating the attributes of blocks.
                        // the key, is actually the serialized version of a block.
                        // it is currently the only way for us to find a specific block in post_content.
                        // and we need to find that specific block so that we can replace it with the translated block.
                        // the key is defined in class-motaword-admin.php
                        // so $blockData is a key => value of serializedSource => arrayTarget.
                        $blockData[$key] = $value;
                    } else {
                        $updateData[$key] = $value;
                    }

                    break;
            }
        }

        $postBlocks = function_exists('parse_blocks') ? parse_blocks($updateData['post_content']) : [];
        $is_gutenberg_page = !empty($postBlocks) && !!$postBlocks[0]['blockName'];
        if ($is_gutenberg_page) {
            $modifiedBlocks = 0;
            foreach ($postBlocks as $i => $postBlock) {
                $translatedBlockAttrs = $this->findBlockTranslation($postBlock, $blockData);
                if (!$translatedBlockAttrs) {
                    continue;
                }

                $postBlock['attrs'] = array_replace_recursive($postBlock['attrs'], $translatedBlockAttrs);
                $postBlocks[$i] = $postBlock;
                $modifiedBlocks++;
            }

            if ($modifiedBlocks) {
                $updateData['post_content'] = wp_slash(serialize_blocks($postBlocks));
            }
        }

        foreach ($customFieldData as $key => $value) {
            update_post_meta($wpPostId, $key, $value);

            $tsf = function_exists( 'the_seo_framework' ) ? the_seo_framework() : null;
            if ( $tsf ) {
                try {
                    $tsf->update_single_post_meta_item( $key, $value, $wpPostId );
                } catch (Exception $e) {
                    update_post_meta($wpPostId, $key, $value);
                }
            }

            // Update Media Posts
            /*
            if(strpos($key,'ATTACHMENT_'))
            {
                $mediakeyArr = explode("_",$key);

                // Update Post
                $updated_post = array(
                    'ID'           => $$mediakeyArr[1],
                    'post_title'   => $translatedProject->title,
                    'post_content' => $translatedProject->content,
                    'post_excerpt' => $translatedProject->excerpt
                );

                update_post_meta($wpPostId, $metakeyArr[1], $val);
            }
            */
        }

        $result = wp_update_post($updateData, true);

        if (class_exists('\AIOSEO\Plugin\Common\Models\Post')) {
            $aioseoFields = [];
            foreach ($customFieldData as $fieldKey => $fieldValue) {
                $aioseoFields[str_replace( '_aioseo_', '', $fieldKey )] = $fieldValue;
            }
            \AIOSEO\Plugin\Common\Models\Post::savePost($wpPostId, $aioseoFields);
        }

        $DBHelper->updateProject($wpPostId, 'completed', 100, 100);

        if ((int)$result > 0) {
            return true;
        }

        return false;
    }

    private function findBlockTranslation($sourceBlock, $translatedBlocksAttrs)
    {
        if (!$sourceBlock['attrs'] || !$sourceBlock['attrs']['id'] || !$sourceBlock['attrs']['name']
            || !is_array($translatedBlocksAttrs)) {
            return null;
        }

        foreach ($translatedBlocksAttrs as $translatedBlockAttrs) {
            if (!$translatedBlockAttrs['id'] || !$translatedBlockAttrs['name']) {
                continue;
            }

            if ($sourceBlock['attrs']['name'] === $translatedBlockAttrs['name']
                && $sourceBlock['attrs']['id'] === $translatedBlockAttrs['id']) {
                return $translatedBlockAttrs;
            }
        }

        return null;
    }

    /**
     * Recursively delete a directory.
     *
     * @param string $dir Directory path
     */
    function recursive_rmdir($dir)
    {
        if (is_dir($dir)) {
            $objects = array_diff(scandir($dir), array('..', '.'));
            foreach ($objects as $object) {
                $objectPath = $dir . "/" . $object;
                if (is_dir($objectPath)) {
                    $this->recursive_rmdir($objectPath);
                } else {
                    unlink($objectPath);
                }
            }
            rmdir($dir);
        }
    }

    /**
     * @param $string
     * @return mixed
     */
    public function sanitize($string)
    {
        return filter_var($string, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    }
}
