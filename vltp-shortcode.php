<?php

/**
 * Process the [vltp] shortcode.
 *
 * @param array $attrs {
 *     Shortcode attributes. 
 *
 *     @type int $id  The VPN test ID
 * }
 *
 * @return string Content with shortcode parsed
 */
function vltp_shortcode($attrs)
{

	global $wpdb;

	extract(shortcode_atts(array(
		'id' => 1,
	), $attrs));

	if (!$id) {
		return '';
	}

	$id = intval($id);

	// Retrives the Test configuration
	$row = $wpdb->get_row('SELECT * FROM ' . $wpdb->prefix . 'vltp WHERE vltp_id=' . $id, ARRAY_A);

	if (!$row) {
		return '';
	}

	if (!in_array($row['vltp_type'], array('dns', 'email', 'webrtc', 'torrent'))) {
		return '';
	}

	$content = '';

	// 
	$options = @unserialize(stripslashes($row['vltp_options']));

	// Extract the Test configuration from the database table `vltp`
	// For fields description see file vltp_admin_page.php, function vltp_admin_page() 
	$row['vltp_progress_image'] = isset($options['vltp_progress_image']) ? stripslashes($options['vltp_progress_image']) : '';
	$row['vltp_start'] = isset($options['vltp_start']) ? stripslashes($options['vltp_start']) : '';
	$row['vltp_result'] = isset($options['vltp_result']) ? stripslashes($options['vltp_result']) : '';
	$row['vltp_conclusion'] = isset($options['vltp_conclusion']) ? stripslashes($options['vltp_conclusion']) : '';

	wp_enqueue_style('vltp-css');
	wp_enqueue_script('vltp-js');

	$image = '';
	if ($row['vltp_progress_image']) {
		$arr = wp_get_attachment_image_src(intval($row['vltp_progress_image']), 'medium', false);
		if (isset($arr[0])) {
			$image = $arr[0];
		}
	}

	if (!$image) {
		$image = plugin_dir_url(__FILE__) . 'include/ajax-loader.gif';
	}

	global $wp;

	$script_array = array();
	$script_array['vltp_progress_image'] = $image;

	$script_array['vltp_test_id'] = mt_rand(100000000, 999999999);
	$script_array['vltp_url'] = home_url(add_query_arg(array('vltp_test_id' => $script_array['vltp_test_id'], 'vltp_id' => $id), $wp->request));
	$script_array['vltp_ajax_url'] = admin_url('admin-ajax.php');

	if ($row['vltp_type'] == 'email') {
		$m = $script_array['vltp_test_id'] . '@bash.ws';
		$script_array['vltp_email_message'] = sprintf(__('Please send an email to <a href="mailto:%s">%s</a>. The email subject and body doesn\'t matter. Do not refresh the page.', 'vpn-leaks-test'), $m, $m);
	}

	if ($row['vltp_type'] == 'torrent') {
		$m = 'https://bash.ws/torrent/' . $script_array['vltp_test_id'];
		$script_array['vltp_torrent_message'] = sprintf(__('Please download the torrent <a href="%s">%s.torrent</a>. Do not refresh the page.', 'vpn-leaks-test'), $m, $script_array['vltp_test_id']);
	}

	wp_localize_script('vltp-js', 'vltp_settings_' . $id, $script_array);

	$content = '<div class="vltp-test">';
	$content .= '<div class="vltp-start" data-type="' . esc_attr($row['vltp_type']) . '" data-id="' . esc_attr($id) . '">' . __($row['vltp_start'], 'vpn-leaks-test') . '</div>';

	$test_id = isset($_REQUEST['vltp_test_id']) ? intval($_REQUEST['vltp_test_id']) : 0;
	$vltp_id = isset($_REQUEST['vltp_id']) ? intval($_REQUEST['vltp_id']) : 0;

	if ($test_id && $vltp_id == $id) {
		$content .= vltp_test_result($row);
	}

	$content .= '</div>';

	return $content;
}

/**
 * Receives and displays the results of testing from 3rd party service bash.ws
 * 
 * @param array $row {
 *     The VPN test configuration
 *
 *     @type string $vltp_type        VPN test type
 *     @type string $vltp_result      Line by line HTML to format the VPN test result
 *     @type string $vltp_conclusion  Line by line HTML to format the VPN test conclusion
 * }
 *
 * @return string The result of testing
 */
function vltp_test_result($row)
{
	$content = '';

	$test_id = isset($_REQUEST['vltp_test_id']) ? intval($_REQUEST['vltp_test_id']) : 0;

	if (!$test_id) {
		return '';
	}

	if ($row['vltp_type'] == 'dns') {
		$url = 'https://bash.ws/dnsleak/test/' . $test_id;
	} else if ($row['vltp_type'] == 'email') {
		$url = 'https://bash.ws/email-leak-test/test/' . $test_id;
	} else if ($row['vltp_type'] == 'webrtc') {
		$url = 'https://bash.ws/webrtc-leak-test/test/' . $test_id;
	} else if ($row['vltp_type'] == 'torrent') {
		$url = 'https://bash.ws/torrent-leak-test/test/' . $test_id;
	} else {
		return '';
	}

	$ip = get_client_ip();

	if (!$ip) {
		return '';
	}

	$data = array('ip' => $ip);

	/**
	 * Receives array of JSON objects from the 3rd party service bash.ws
	 *
	 * @param string $ip The user IP address
	 *
	 * @return array $results { 
	 * 
	 *     @type string $type         The result type: "dns" - DNS leak test, "webrtc" - WebRtc test, "email" - Email test, "conclusion" - test conclusion
	 *     @type string $ip           IP address
	 *     @type int    $country      Country code of IP address 
	 *     @type string $country_name Country name of IP address
	 *     @type string $asn          ASN name of IP address
	 *
	 * }
	 *
	 */

	$results = array();

	try {
		$results =	get_test_data(esc_url_raw($url), $row['vltp_type']);
	} catch (\Throwable $th) {
		$code = $th->getCode();
		$msg = $th->getMessage();

		if ($code >= 20 && $code < 30) $results['error'] = $msg;

	}

	if ($results && !isset($results['error'])) {

		$total  = 0;
		foreach ($results as $k => $v) {

			if (!isset($v['type'])) {
				continue;
			}

			if ($row['vltp_type'] == 'dns') {
				if ($v['type'] != 'dns') {
					continue;
				}
			} else if ($row['vltp_type'] == 'email') {
				if ($v['type'] != 'mail') {
					continue;
				}
			} else if ($row['vltp_type'] == 'webrtc') {
				if ($v['type'] != 'webrtc') {
					continue;
				}
			} else if ($row['vltp_type'] == 'torrent') {
				if ($v['type'] != 'torrent') {
					continue;
				}
			}
			$total++;
		}

		$content .= '<div class="vltp-title">';

		if ($row['vltp_type'] == 'dns') {
			$content .= sprintf(_n('You use %d DNS server', 'You use %d DNS servers', $total, 'vpn-leaks-test'), $total);
		} else if ($row['vltp_type'] == 'email') {
			$content .= sprintf(_n('Your email contains %d IP', 'Your email contains %d IPs', $total, 'vpn-leaks-test'), $total);
		} else if ($row['vltp_type'] == 'webrtc') {
			$content .= sprintf(_n('WebRTC is able to see %d IP', 'WebRTC is able to see %d IPs', $total, 'vpn-leaks-test'), $total);
		} else if ($row['vltp_type'] == 'torrent') {
			$content .= sprintf(_n('Your torrent application shares %d IP', 'Your torrent application shares %d IPs', $total, 'vpn-leaks-test'), $total);
		}

		$content .= '</div>';
		$content .= '<div class="vltp-results">';



		foreach ($results as $k => $v) {

			$check_fields = array('country', 'country_name', 'ip', 'type');

			foreach ($check_fields as $field) {
				if (!isset($v[$field]) || !$v[$field]) {
					continue;
				}
			}

			if ($row['vltp_type'] == 'dns') {
				if ($v['type'] != 'dns') {
					continue;
				}
			} else if ($row['vltp_type'] == 'email') {
				if ($v['type'] != 'mail') {
					continue;
				}
			} else if ($row['vltp_type'] == 'webrtc') {
				if ($v['type'] != 'webrtc') {
					continue;
				}
			} else if ($row['vltp_type'] == 'torrent') {
				if ($v['type'] != 'torrent') {
					continue;
				}
			}

			$r = array();

			$r['%country_code'] = esc_attr($v['country']);
			$r['%country_name'] = esc_attr($v['country_name']);
			$r['%asn'] = esc_attr($v['asn']);
			$r['%ip'] = esc_attr($v['ip']);
			$r['%flag'] = plugin_dir_url(__FILE__) . 'include/flags/' . esc_attr($v['country']) . '.png';

			$content .=  str_replace(array_keys($r), array_values($r), $row['vltp_result']);
		}
		$content .= '</div>';
		$content .= '<div class="vltp-conclusion">';

		foreach ($results as $k => $v) {

			if (!isset($v['type'])) {
				continue;
			}

			if ($v['type'] != 'conclusion') {
				continue;
			}

			$r = array();
			$r['%text'] = esc_attr($v['ip']);

			$content .= str_replace(array_keys($r), array_values($r),  $row['vltp_conclusion']);
		}
		$content .= '</div>';
	} else {
		$content .= '<div class="vltp-results">' . __($results['error'] ? $results['error'] : 'The Test failed, please try again...', 'vpn-leaks-test') . '</div>';
	}

	return $content;
}

/**
 * Checks to see if the IP for testing was received and analyzed by the bash.ws
 * The bash.ws sends JSON object as an answer:
 * [{ 
 *     'type': 'done', 
 *     'done': '1' 
 * }]
 * if everything is ok the field 'done' is equal to '1', otherwise it is equal to '0'
 *
 * @return null
 */
function vltp_test_webrtc()
{

	$ips = isset($_REQUEST['ips']) ? (array) $_REQUEST['ips'] : array();
	$ips = array_map('sanitize_text_field', $ips);
	$ip = isset($_REQUEST['ip']) ? sanitize_text_field($_REQUEST['ip']) : '';
	$test_id = isset($_REQUEST['vltp_test_id']) ? intval($_REQUEST['vltp_test_id']) : 0;

	if (!$ip) {
		$ip = isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : '';
	}

	if (!$ip) {
		$ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
	}

	if (!$ip) {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	}

	$url = 'https://bash.ws/webrtc-leak-test/test/' . $test_id;

	$data = array('ajax' => '1', 'ips' => $ips, 'ip' => $ip);

	$response = wp_remote_post(esc_url_raw($url), array('body' => $data));

	if (is_wp_error($response)) {
		wp_send_json(array('done' => 0, 'error' => __('The internal communication error occurred...', 'vpn-leak-test')));
	}

	$json_data = json_decode(wp_remote_retrieve_body($response), true);
	wp_send_json($json_data);
}

/**
 * Checks to see if the Email for testing was received and analyzed by the bash.ws
 * The bash.ws sends JSON object as an answer:
 * [{ 
 *     'type': 'done', 
 *     'done': '1' 
 * }]
 * if everything is ok the field 'done' is equal to '1', otherwise it is equal to '0'
 *
 * @return null
 */
function vltp_test_email_check()
{

	$test_id = isset($_REQUEST['vltp_test_id']) ? intval($_REQUEST['vltp_test_id']) : 0;
	$url = 'https://bash.ws/email-leak-test/test/' . $test_id;

	$data = array('ajax' => '1');
	$response = wp_remote_post(esc_url_raw($url), array('body' => $data));

	if (is_wp_error($response)) {
		wp_send_json(array('done' => 0, 'error' => __('The internal communication error occurred...', 'vpn-leak-test')));
	}

	$json_data = json_decode(wp_remote_retrieve_body($response), true);
	wp_send_json($json_data);
}


/**
 * Checks to see if the Torrent for testing downloaded and analyzed by the bash.ws
 * The bash.ws sends JSON object as an answer:
 * [{ 
 *     'type': 'done', 
 *     'done': '1' 
 * }]
 * if everything is ok the field 'done' is equal to '1', otherwise it is equal to '0'
 *
 * @return null
 */
function vltp_test_torrent_check()
{

	$test_id = isset($_REQUEST['vltp_test_id']) ? intval($_REQUEST['vltp_test_id']) : 0;
	$url = 'https://bash.ws/torrent-leak-test/test/' . $test_id;

	$data = array('ajax' => '1');
	$response = wp_remote_post(esc_url_raw($url), array('body' => $data));

	if (is_wp_error($response)) {
		wp_send_json(array('done' => 0, 'error' => __('The internal communication error occurred...', 'vpn-leak-test')));
	}

	$json_data = json_decode(wp_remote_retrieve_body($response), true);
	wp_send_json($json_data);
}


function get_test_data($url, $type)
{
	$results = [];
	$results = get_test_data_by_html($url, $type);

	return $results;
}

function get_test_data_by_html($url, $type)
{
	libxml_use_internal_errors(true);
	$html = load_test_html($url);
	$doc = new DOMDocument();
	$doc->loadHTML($html);
	$xpath = new DomXPath($doc);

	$results = [];

	$test_results = generate_test_result_by_html($xpath, $type);
	array_push($results, ...$test_results);

	$ip_detail = get_ip_detail($url);
	array_push($results, $ip_detail);

	$test_conclusions = generate_test_conclusion_by_html($xpath);
	array_push($results, ...$test_conclusions);

	return $results;
}

function load_test_html($url)
{
	$ip = get_client_ip();

	$response = wp_remote_post(esc_url_raw($url), [
		'body' => [
			'ip' => $ip
		],
		'headers' => [
			"Client-IP" => $ip
		]
	]);

	if (!is_wp_error($response)) {

		$results = wp_remote_retrieve_body($response);
		return $results;
	} else throw new Exception("Bad response", 17);
}

function get_ip_detail($url)
{
	$ip = get_client_ip();

	$response = wp_remote_post(esc_url_raw("$url?json"), [
		'body' => [
			'ip' => $ip
		],
		'headers' => [
			"Client-IP" => $ip
		]
	]);

	$results = array();

	$ip_info = [
		"asn" => "",
		"country" => "",
		"country_name" => "",
		"ip" => "",
		"type" => "ip"
	];

	if (!is_wp_error($response)) {

		$results = @json_decode(wp_remote_retrieve_body($response), true);
		if (isset($results['error'])) {
			$results = array();
		}
	}

	$find_ips = array_filter($results, function ($res) {
		return $res['type']  === 'ip';
	});

	if (count($find_ips)) $ip_info = $find_ips[0];
	// if not found ip
	else {
		$ip_info = get_ip_info($ip);
	}

	return $ip_info;
}

function get_ip_info($ip)
{
	$ip_info = [
		"asn" => "",
		"country" => "",
		"country_name" => "",
		"ip" => "",
		"type" => "ip"
	];
	$isp_res = wp_remote_get(esc_url_raw("http://ip-api.com/json/{$ip}"));
	if (!is_wp_error($isp_res)) {
		$ip_data = @json_decode(wp_remote_retrieve_body($isp_res), true);
		if ($ip_data["status"] !== "fail") $ip_info = [
			"asn" => $ip_data['as'],
			"country" => strtolower($ip_data['countryCode']),
			"country_name" => $ip_data['country'],
			"ip" => $ip,
			"type" => "ip"
		];
	};
	return $ip_info;
}



function generate_test_result_by_html($xpath, $type)
{
	$results = [];

	//find ip type
	if ($type === "email") {
		$nodeList = $xpath->query("//span[ @data-type = 'ip' ]");
		$node = $nodeList->item(0);
		if (!$node) throw new Exception("No mail header found. Please try again...", 20);

		$res_el = get_ip_info($node->getAttribute("data-host"));

		$res_el["type"] = "mail";
		array_push($results, $res_el);
	} else {
		$nodeList = $xpath->query("//div[contains(@class, 'leak-exec')]//div[contains(@class, 'tr')]");
		if (!$nodeList->count()) {
			if ($type === 'dns') throw new Exception("No DNS servers found. Try again...", 21);
			if ($type === 'torrent') throw new Exception("No torrent header found. Please try again...", 22);
			if ($type === 'webrtc') throw new Exception("No IPs is visible to WebRTC", 22);
		}

		foreach ($nodeList as $trNode) {
			$res_el = [
				"asn" => "",
				"country" => "",
				"country_name" => "",
				"ip" => "",
				"type" => $type
			];

			foreach ($trNode->childNodes as $ind => $tdNode) {
				if ($ind === 0) {
					$spanNode = $tdNode->firstChild;
					$sNode = $spanNode->firstChild;
					$res_el['ip'] = $spanNode->textContent;
					$res_el['country'] = $sNode->getAttribute("data-ctr");
					$res_el['country_name'] = $sNode->getAttribute("data-ctrname");
				}

				if ($ind + 1 === count($trNode->childNodes)) {
					$res_el['asn'] = $tdNode->textContent;
				}
			}
			array_push($results, $res_el);
		}
	}

	return $results;
}

function generate_test_conclusion_by_html($xpath)
{
	$conclusions = [];
	$nodeList = $xpath->query("//div[ @id = 'conclusion' ]");
	$node = $nodeList->item(0);

	if ($node->childNodes[0]) {
		array_push($conclusions, [
			"asn" => "",
			"country" => "",
			"country_name" => "",
			"ip" => __(trim($node->childNodes[0]->textContent), 'vpn-leaks-test'),
			"type" => "conclusion"
		]);
	}
	if ($node->childNodes[1]) {
		[$str, $replace] = prepareConclusionForInt($node->childNodes[1]->textContent);
		array_push($conclusions, [
			"asn" => "",
			"country" => "",
			"country_name" => "",
			"ip" => sprintf(__($str, 'vpn-leaks-test'), ...$replace),
			"type" => "conclusion"
		]);

	}
	
	return $conclusions;
}

function get_client_ip()
{
	$ip = isset($_REQUEST['ip']) ? sanitize_text_field($_REQUEST['ip']) : '';

	if (!$ip) {
		$ip = isset($_SERVER['HTTP_CLIENT_IP']) ? $_SERVER['HTTP_CLIENT_IP'] : '';
	}

	if (!$ip) {
		$ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
	}

	if (!$ip) {
		$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	}

	return $ip;
}

function DOMinnerHTML(DOMNode $element)
{
	$innerHTML = "";
	$children  = $element->childNodes;

	foreach ($children as $child) {
		$innerHTML .= $element->ownerDocument->saveHTML($child);
	}

	return $innerHTML;
}


function prepareConclusionForInt($str)
{
	$re = '/".+"/mU';
	preg_match_all($re, $str, $matches, PREG_SET_ORDER, 0);
	$result = array();
	array_walk_recursive($matches, function ($v) use (&$result) {
		$result[] = $v;
	});
	$result = array_map(function ($s) {
		return preg_replace('/(^"|"$)/m', '', $s);
	}, $result);

	$new_str = trim(preg_replace($re, '"%s"', str_replace("%", "%%", $str)));

	return [$new_str, $result];
}
