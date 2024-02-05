<?php
/*
 * Plugin Name: Tiny Compress
 * Description: Compress images using TinyPNG API and convert them to WebP.
 * Version: 1.0
 * Author: Marius Hasan
 */

require_once("tinify-php-master/lib/Tinify/Exception.php");
require_once("tinify-php-master/lib/Tinify/ResultMeta.php");
require_once("tinify-php-master/lib/Tinify/Result.php");
require_once("tinify-php-master/lib/Tinify/Source.php");
require_once("tinify-php-master/lib/Tinify/Client.php");
require_once("tinify-php-master/lib/Tinify.php");

\Tinify\setKey('your_api_key');

function add_tc_media_column($columns) {
    $columns['tiny-compress'] = 'Compress';
    return $columns;
}

function add_tc_media_button($column_name, $post_id) {
    if ($column_name !== 'tiny-compress') {
        return;
    }

    if (get_post_mime_type($post_id) === 'image/svg+xml') {
        return;
    }

    $meta = wp_get_attachment_metadata($post_id);

    if ( !$meta ) {
        $response['errors'] = "No metadata found for image ID $post_id.";
        wp_send_json_error($response);
        return;
    }

    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit($upload_dir['basedir']);
    $file_path = $base_dir . $meta['file'];
    $backup_exists = file_exists($file_path . '.backup');

    $button = !$backup_exists ? "<button class='tc-compress button button-small button-primary' data-id='{$post_id}'>Compress</button>"
    : "<button class='tc-restore button button-small' data-id='{$post_id}'>Restore</button>";
    echo $button;
    echo "<div class='tc-message'></div>";
}

function enqueue_tc_js() {
    wp_enqueue_script('tiny-compress-button', plugins_url('tiny-compress/button.js'), array('jquery'), '1.0', true);
    $nonce = wp_create_nonce('tiny_compress_nonce');
    wp_localize_script('tiny-compress-button', 'tinyCompress', array(
        'nonce' => $nonce,
        'ajaxurl' => admin_url('admin-ajax.php')
    ));
}

function compress($file_path, $backup = false) {
    try {
        if ($backup) {
            $backup_file = $file_path . '.backup';
            copy($file_path, $backup_file);
        }

        $source = \Tinify\fromFile($file_path);
        $source->toFile($file_path);

        return ['success' => true];
    } catch(\Tinify\Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function convert_to_webp($file_path, $backup = false) {
    $output_file_path = $file_path . '.webp';
    $command = "cwebp -lossless '$file_path' -o '$output_file_path'";
    exec($command, $output, $return_code);

    if ($return_code === 0) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => $output];
    }
}

function tc_compress_ajax() {
    $response = array('errors' => array());

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'tiny_compress_nonce')) {
        $response['errors'][] = 'Security check failed.';
        wp_send_json($response);
        return;
    }

    if (!isset($_POST['image_id']) || !is_numeric($_POST['image_id'])) {
        $response['errors'] = "No image ID provided.";
        wp_send_json_error($response);
        return;
    }

    $image_id = (int) $_POST['image_id'];
    loop_images('convert_to_webp', $image_id, $response, false);
    loop_images('compress', $image_id, $response, true);
    $response['success'] = 'Successfully compressed and converted all images to WebP.';
    wp_send_json($response);
}

function tc_restore_ajax() {
    $response = array('errors' => array());

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'tiny_compress_nonce')) {
        $response['errors'][] = 'Security check failed.';
        wp_send_json($response);
        return;
    }

    if (!isset($_POST['image_id'])) {
        $response['errors'] = "No image ID provided.";
        wp_send_json_error($response);
        return;
    }

    $image_id = (int) $_POST['image_id'];
    $file_path = get_attached_file($image_id);
    $backup_file_path = $file_path . '.backup';

    if (file_exists($backup_file_path)) {
        copy($backup_file_path, $file_path);
        delete_extra($image_id);

        if (function_exists('wp_generate_attachment_metadata')) {
            $metadata = wp_generate_attachment_metadata($image_id, $file_path);
            wp_update_attachment_metadata($image_id, $metadata);
        }
        $response['success'] = "Image restored successfully.";
    } else {
        $response['errors'][] = "Backup file not found.";
    }

    wp_send_json($response);
}

function loop_images($funcName, $image_id, $response, $webp) {
    $meta = wp_get_attachment_metadata( $image_id );

    if ( !$meta ) {
        $response['errors'] = "No metadata found for image ID $image_id.";
        wp_send_json_error($response);
        return;
    }

    $upload_dir = wp_upload_dir();
    $base_dir = trailingslashit($upload_dir['basedir']);
    $original_file_path = $base_dir . $meta['file'];
    $file_dirname = dirname($meta['file']) === '.' ? '' : trailingslashit(dirname($meta['file']));
    
    $result = call_user_func($funcName, $original_file_path, true);
    if (!$result['success']) {
        $response['errors'][] = "Failed to " . $funcName . " original: " . $result['error'];
    }

    if ($webp) {
        $result = call_user_func($funcName, $original_file_path . '.webp', true);
        if (!$result['success']) {
            $response['errors'][] = "Failed to " . $funcName . " original: " . $result['error'];
        }
    }

    if ( isset( $meta['sizes'] ) ) {
        foreach ($meta['sizes'] as $size => $size_info) {
            $file_path = $base_dir . $file_dirname . $size_info['file'];
            $slices = explode('.', $size_info['file']);
            $file_name_end = $slices[count($slices) - 2];

            if ( file_exists($file_path) && $file_path !== $original_file_path && is_numeric($file_name_end[strlen($file_name_end) - 1]) ) {
                $result = call_user_func($funcName, $file_path);
                if (!$result['success']) {
                    $response['errors'][] = "Failed to " . $funcName . " " . $size . ": " . $result['error'];
                }
                if (!$webp) {
                    continue;
                }
                $webp_file_path = $file_path . ".webp";
                if (file_exists($webp_file_path)) {
                    $result = call_user_func($funcName, $webp_file_path);
                    if (!$result['success']) {
                        $response['errors'][] = "Failed to " . $funcName . " " . $size . " (WebP): " . $result['error'];
                    }
                }
            } else {
                $response['errors'][] = "File not found: $file_path";
            }
        }
    }
    return $response;
}

function enqueue_tc_styles() {
    global $pagenow;
    if ($pagenow == 'upload.php' && ( ! isset($_GET['mode']) || $_GET['mode'] !== 'grid' )) {
        wp_enqueue_style('tc-style', plugins_url('style.css', __FILE__));
    }
}

function delete_extra($post_id) {
    $file_path = get_attached_file($post_id);
    $backup_file_path = $file_path . '.backup';
    $webp_file_path = $file_path . '.webp';

    if (file_exists($backup_file_path)) {
        unlink($backup_file_path);
    }

    if (file_exists($webp_file_path)) {
        unlink($webp_file_path);
    }

    $meta = wp_get_attachment_metadata($post_id);
    if ($meta && isset($meta['sizes'])) {
        $base_dir = trailingslashit(dirname($file_path));
        foreach ($meta['sizes'] as $size => $size_info) {
            $size_webp_path = $base_dir . $size_info['file'] . '.webp';
            if (file_exists($size_webp_path)) {
                unlink($size_webp_path);
            }
        }
    }
}

function custom_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
    if (strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false) {
        $upload_dir = wp_upload_dir();
        foreach ($sources as $key => $source) {
            $file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $source['url']);
            $webp_file_path = $file_path . ".webp";

            if (file_exists($webp_file_path)) {
                $sources[$key]['url'] = $source['url'] . ".webp";
            }
        }
    }
    return $sources;
}

add_filter('wp_calculate_image_srcset', 'custom_image_srcset', 10, 5);
add_filter('manage_media_columns', 'add_tc_media_column');
add_action('manage_media_custom_column', 'add_tc_media_button', 10, 2);
add_action('admin_enqueue_scripts', 'enqueue_tc_js');
add_action('wp_ajax_tc_compress', 'tc_compress_ajax');
add_action('wp_ajax_tc_restore', 'tc_restore_ajax');
add_action('admin_enqueue_scripts', 'enqueue_tc_styles');
add_action('delete_attachment', 'delete_extra');
