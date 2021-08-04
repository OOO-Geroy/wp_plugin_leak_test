<?php


if (!defined('ABSPATH')) {
	die('An attempt to call the vltp plugin directly...');
}

add_action('init', 'vltp_admin_init');

include dirname(__FILE__) . '/vltp-admin-page.php';
include dirname(__FILE__) . '/vltp-admin-help.php';

register_activation_hook(VLTP_FILE, 'vltp_activation');
add_action('admin_enqueue_scripts', 'vltp_admin_media_files');
add_action('wp_ajax_vltp_get_progress_image', 'vltp_get_progress_image');

/**
 * Process ajax request
 *
 * Gets the progress image from the library
 *
 * @return null
 */
function vltp_get_progress_image()
{
	if (isset($_REQUEST['id'])) {
		$image = wp_get_attachment_image(filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT), 'medium', false, array('id' => 'vltp_progress_image_preview'));
		$data = array(
			'image'    => $image,
		);
		wp_send_json_success($data);
	} else {
		wp_send_json_error();
	}
}

/**
 * Attaches the media files to the WP admin interface
 *
 * @param string $page the page ID
 *
 * @return null
 */
function vltp_admin_media_files($page)
{

	if (strpos($page, 'vltp-admin-page') === false) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_script('vltp-admin-script');

	$translation_array = array('default_progress_image' => vltp_default_progress_image());
	wp_localize_script('vltp-admin-script', 'vltp_settings', $translation_array);

	wp_enqueue_style('vltp-admin.css');
}

/**
 * Admin initialization hook
 */
function vltp_admin_init()
{
	add_action('admin_menu', 'vltp_admin_menu');
	wp_register_style('vltp-admin.css', plugin_dir_url(__FILE__) . '/include/vltp-admin.css');
	wp_register_script('vltp-admin-script', plugins_url('include/vltp-admin.js', __FILE__), array('jquery'), '0.1');
}

/**
 * Admin menu
 */
function vltp_admin_menu()
{
	$hook = add_options_page(__('VPN leaks test', VLTP_PLUGIN_NAME), __('VPN leaks test', VLTP_PLUGIN_NAME),  'manage_options', 'vltp-admin-page',  'vltp_admin_page');

	if ($hook) {
		add_action("load-$hook", 'vltp_admin_help');
	}
}

/**
 * Activation hook
 */
function vltp_activation()
{

	global $wpdb;

	$sql = 'CREATE TABLE ' . $wpdb->prefix . 'vltp (
		vltp_id int(11) NOT NULL AUTO_INCREMENT,
		vltp_type varchar(10) NOT NULL default "",
		vltp_options text NOT NULL default "",
		PRIMARY KEY (vltp_id)
		) ' . $wpdb->get_charset_collate() . ';';

	$wpdb->query($sql);

	copy_lang();

	register_uninstall_hook(VLTP_FILE, 'vltp_uninstall');
}

/**
 * Uninstall hook
 */
function vltp_uninstall()
{
	global $wpdb;

	$sql = 'DROP TABLE ' . $wpdb->prefix . 'vltp';

	$wpdb->query($sql);

	delete_lang();
}


function copy_lang()
{
	$from = plugin_dir_path(__FILE__) . '/.languages';
	$to = WP_CONTENT_DIR . '/languages/plugins';

	recursive_lang_copy($from, $to);
}

function delete_lang()
{
	recursive_lang_delete();
}

function recursive_lang_copy($src, $dst)
{
	$dir = opendir($src);
	@mkdir($dst);
	while (($file = readdir($dir))) {
		if (($file != '.') && ($file != '..')) {
			if (is_dir($src . '/' . $file)) {
				recursive_lang_copy($src . '/' . $file, $dst . '/' . $file);
			} elseif (!file_exists($dst . '/' . $file)) {
				copy($src . '/' . $file, $dst . '/' . VLTP_PLUGIN_NAME . '-' . $file);
			}
		}
	}
	closedir($dir);
}

function recursive_lang_delete()
{
	foreach (glob(WP_CONTENT_DIR . '/languages/plugins/' . VLTP_PLUGIN_NAME . '-*') as $file) {
		unlink($file);
	}
}
