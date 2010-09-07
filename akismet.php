<?php
/**
 * @package Akismet
 */
/*
Plugin Name: Akismet
Plugin URI: http://akismet.com/
Description: Akismet checks your comments against the Akismet web service to see if they look like spam or not. You need an <a href="http://akismet.com/get/">API key</a> to use it. You can review the spam it catches under "Comments." To show off your Akismet stats just put <code>&lt;?php akismet_counter(); ?&gt;</code> in your template. See also: <a href="http://wordpress.org/extend/plugins/stats/">WP Stats plugin</a>.
Version: 2.5.0
Author: Automattic
Author URI: http://automattic.com/wordpress-plugins/
License: GPLv2
*/

/*
This program is free software; you can redistribute it and/or modify 
it under the terms of the GNU General Public License as published by 
the Free Software Foundation; version 2 of the License.

This program is distributed in the hope that it will be useful, 
but WITHOUT ANY WARRANTY; without even the implied warranty of 
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the 
GNU General Public License for more details. 

You should have received a copy of the GNU General Public License 
along with this program; if not, write to the Free Software 
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA 
*/

define('AKISMET_VERSION', '2.5.0');

/** If you hardcode a WP.com API key here, all key config screens will be hidden */
if ( defined('WPCOM_API_KEY') )
	$wpcom_api_key = constant('WPCOM_API_KEY');
else
	$wpcom_api_key = '';

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo "Hi there!  I'm just a plugin, not much I can do when called directly.";
	exit;
}

if ( $wp_db_version <= 9872 )
	include_once dirname( __FILE__ ) . '/legacy.php';

include_once dirname( __FILE__ ) . '/widget.php';

if ( is_admin() )
	require_once dirname( __FILE__ ) . '/admin.php';

function akismet_init() {
	global $wpcom_api_key, $akismet_api_host, $akismet_api_port;

	if ( $wpcom_api_key )
		$akismet_api_host = $wpcom_api_key . '.rest.akismet.com';
	else
		$akismet_api_host = get_option('wordpress_api_key') . '.rest.akismet.com';

	$akismet_api_port = 80;
}
add_action('init', 'akismet_init');

function akismet_get_key() {
	global $wpcom_api_key;
	if ( !empty($wpcom_api_key) )
		return $wpcom_api_key;
	return get_option('wordpress_api_key');
}

function akismet_verify_key( $key, $ip = null ) {
	global $akismet_api_host, $akismet_api_port, $wpcom_api_key;
	$blog = urlencode( get_option('home') );
	if ( $wpcom_api_key )
		$key = $wpcom_api_key;
	$response = akismet_http_post("key=$key&blog=$blog", 'rest.akismet.com', '/1.1/verify-key', $akismet_api_port, $ip);
	if ( !is_array($response) || !isset($response[1]) || $response[1] != 'valid' && $response[1] != 'invalid' )
		return 'failed';
	return $response[1];
}

// Check connectivity between the WordPress blog and Akismet's servers.
// Returns an associative array of server IP addresses, where the key is the IP address, and value is true (available) or false (unable to connect).
function akismet_check_server_connectivity() {
	global $akismet_api_host, $akismet_api_port, $wpcom_api_key;
	
	$test_host = 'rest.akismet.com';
	
	// Some web hosts may disable one or both functions
	if ( !function_exists('fsockopen') || !function_exists('gethostbynamel') )
		return array();
	
	$ips = gethostbynamel($test_host);
	if ( !$ips || !is_array($ips) || !count($ips) )
		return array();
		
	$servers = array();
	foreach ( $ips as $ip ) {
		$response = akismet_verify_key( akismet_get_key(), $ip );
		// even if the key is invalid, at least we know we have connectivity
		if ( $response == 'valid' || $response == 'invalid' )
			$servers[$ip] = true;
		else
			$servers[$ip] = false;
	}

	return $servers;
}

// Check the server connectivity and store the results in an option.
// Cached results will be used if not older than the specified timeout in seconds; use $cache_timeout = 0 to force an update.
// Returns the same associative array as akismet_check_server_connectivity()
function akismet_get_server_connectivity( $cache_timeout = 86400 ) {
	$servers = get_option('akismet_available_servers');
	if ( (time() - get_option('akismet_connectivity_time') < $cache_timeout) && $servers !== false )
		return $servers;
	
	// There's a race condition here but the effect is harmless.
	$servers = akismet_check_server_connectivity();
	update_option('akismet_available_servers', $servers);
	update_option('akismet_connectivity_time', time());
	return $servers;
}

// Returns true if server connectivity was OK at the last check, false if there was a problem that needs to be fixed.
function akismet_server_connectivity_ok() {
	// skip the check on WPMU because the status page is hidden
	global $wpcom_api_key;
	if ( $wpcom_api_key )
		return true;
	$servers = akismet_get_server_connectivity();
	return !( empty($servers) || !count($servers) || count( array_filter($servers) ) < count($servers) );
}

function akismet_get_host($host) {
	// if all servers are accessible, just return the host name.
	// if not, return an IP that was known to be accessible at the last check.
	if ( akismet_server_connectivity_ok() ) {
		return $host;
	} else {
		$ips = akismet_get_server_connectivity();
		// a firewall may be blocking access to some Akismet IPs
		if ( count($ips) > 0 && count(array_filter($ips)) < count($ips) ) {
			// use DNS to get current IPs, but exclude any known to be unreachable
			$dns = (array)gethostbynamel( rtrim($host, '.') . '.' );
			$dns = array_filter($dns);
			foreach ( $dns as $ip ) {
				if ( array_key_exists( $ip, $ips ) && empty( $ips[$ip] ) )
					unset($dns[$ip]);
			}
			// return a random IP from those available
			if ( count($dns) )
				return $dns[ array_rand($dns) ];
			
		}
	}
	// if all else fails try the host name
	return $host;
}

// return a comma-separated list of role names for the given user
function akismet_get_user_roles($user_id ) {
	$roles = false;
	
	if ( !class_exists('WP_User') )
		return false;
	
	if ( $user_id > 0 ) {
		$comment_user = new WP_User($user_id);
		if ( isset($comment_user->roles) )
			$roles = join(',', $comment_user->roles);
	}
	
	return $roles;
}

// Returns array with headers in $response[0] and body in $response[1]
function akismet_http_post($request, $host, $path, $port = 80, $ip=null) {
	global $wp_version;

	$akismet_ua = "WordPress/{$wp_version} | ";
	$akismet_ua .= 'Akismet/' . constant( 'AKISMET_VERSION' );

	$content_length = strlen( $request );

	$http_host = $host;
	// use a specific IP if provided
	// needed by akismet_check_server_connectivity()
	if ( $ip && long2ip( ip2long( $ip ) ) ) {
		$http_host = $ip;
	} else {
		$http_host = akismet_get_host( $host );
	}

	// use the WP HTTP class if it is available
	if ( function_exists( 'wp_remote_post' ) ) {
		$http_args = array(
			'body'			=> $request,
			'headers'		=> array(
				'Content-Type'	=> 'application/x-www-form-urlencoded; ' .
									'charset=' . get_option( 'blog_charset' ),
				'Host'			=> $host,
				'User-Agent'	=> $akismet_ua
			)
		);
		$akismet_url = "http://{$http_host}{$path}";
		$response = wp_remote_post( $akismet_url, $http_args );
		if ( is_wp_error( $response ) )
			return '';

		return array( $response['headers'], $response['body'] );
	} else {
		$http_request  = "POST $path HTTP/1.0\r\n";
		$http_request .= "Host: $host\r\n";
		$http_request .= 'Content-Type: application/x-www-form-urlencoded; charset=' . get_option('blog_charset') . "\r\n";
		$http_request .= "Content-Length: {$content_length}\r\n";
		$http_request .= "User-Agent: {$akismet_ua}\r\n";
		$http_request .= "\r\n";
		$http_request .= $request;
		
		$response = '';
		if( false != ( $fs = @fsockopen( $http_host, $port, $errno, $errstr, 10 ) ) ) {
			fwrite( $fs, $http_request );

			while ( !feof( $fs ) )
				$response .= fgets( $fs, 1160 ); // One TCP-IP packet
			fclose( $fs );
			$response = explode( "\r\n\r\n", $response, 2 );
		}
		return $response;
	}
}

// filter handler used to return a spam result to pre_comment_approved
function akismet_result_spam( $approved ) {
	// bump the counter here instead of when the filter is added to reduce the possibility of overcounting
	if ( $incr = apply_filters('akismet_spam_count_incr', 1) )
		update_option( 'akismet_spam_count', get_option('akismet_spam_count') + $incr );
	return 'spam';
}

// log an event for a given comment, storing it in comment_meta
function akismet_update_comment_history( $comment_id, $message, $event=null ) {
	global $current_user;
	
	$user = '';
	if ( is_object($current_user) )
		$user = $current_user->user_login;

	$event = array(
		'time' => time(),
		'message' => $message,
		'event' => $event,
		'user' => $user,
	);
	
	// $unique = false so as to allow multiple values per comment
	$r = add_comment_meta( $comment_id, 'akismet_history', $event, false );
}

// get the full comment history for a given comment, as an array in reverse chronological order
function akismet_get_comment_history( $comment_id ) {
	
	$history = get_comment_meta( $comment_id, 'akismet_history', false );
	usort( $history, 'akismet_cmp_time' );
	return $history;
}

function akismet_cmp_time( $a, $b ) {
	return $a['time'] > $b['time'] ? -1 : 1;
}

// this fires on wp_insert_comment.  we can't update comment_meta when akismet_auto_check_comment() runs
// because we don't know the comment ID at that point.
function akismet_auto_check_update_meta( $id, $comment ) {
	global $akismet_last_comment;


	// wp_insert_comment() might be called in other contexts, so make sure this is the same comment
	// as was checked by akismet_auto_check_comment
	if ( is_object($comment) && !empty($akismet_last_comment) && is_array($akismet_last_comment) ) {
		if ( intval($akismet_last_comment['comment_post_ID']) == intval($comment->comment_post_ID)
			&& $akismet_last_comment['comment_author'] == $comment->comment_author
			&& $akismet_last_comment['comment_author_email'] == $comment->comment_author_email ) {
				// normal result: true or false
				if ( $akismet_last_comment['akismet_result'] == 'true' ) {
					update_comment_meta( $comment->comment_ID, 'akismet_result', 'true' );
					akismet_update_comment_history( $comment->comment_ID, __('Akismet caught this comment as spam'), 'check-spam' );
				} elseif ( $akismet_last_comment['akismet_result'] == 'false' ) {
					update_comment_meta( $comment->comment_ID, 'akismet_result', 'false' );
					akismet_update_comment_history( $comment->comment_ID, __('Akismet cleared this comment'), 'check-ham' );
				// abnormal result: error
				} else {
					update_comment_meta( $comment->comment_ID, 'akismet_result', 'error' );
					akismet_update_comment_history( $comment->comment_ID, sprintf( __('Akismet was unable to check this comment (response: %s)'), $akismet_last_comment['akismet_result']), 'check-error' );
				}
				
		}
	}
}

add_action( 'wp_insert_comment', 'akismet_auto_check_update_meta', 10, 2 );


function akismet_auto_check_comment( $commentdata ) {
	global $akismet_api_host, $akismet_api_port, $akismet_last_comment;

	$comment = $commentdata;
	$comment['user_ip']    = $_SERVER['REMOTE_ADDR'];
	$comment['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
	$comment['referrer']   = $_SERVER['HTTP_REFERER'];
	$comment['blog']       = get_option('home');
	$comment['blog_lang']  = get_locale();
	$comment['blog_charset'] = get_option('blog_charset');
	$comment['permalink']  = get_permalink($comment['comment_post_ID']);
	
	$comment['user_role'] = akismet_get_user_roles($comment['user_ID']);

	$ignore = array( 'HTTP_COOKIE', 'HTTP_COOKIE2', 'PHP_AUTH_PW' );

	foreach ( $_SERVER as $key => $value )
		if ( !in_array( $key, $ignore ) && is_string($value) )
			$comment["$key"] = $value;
		else
			$comment["$key"] = '';

	$query_string = '';
	foreach ( $comment as $key => $data )
		$query_string .= $key . '=' . urlencode( stripslashes($data) ) . '&';

	$response = akismet_http_post($query_string, $akismet_api_host, '/1.1/comment-check', $akismet_api_port);
	$commentdata['akismet_result'] = $response[1];
	if ( 'true' == $response[1] ) {
		// akismet_spam_count will be incremented later by akismet_result_spam()
		add_filter('pre_comment_approved', 'akismet_result_spam');

		do_action( 'akismet_spam_caught' );

		$post = get_post( $comment['comment_post_ID'] );
		$last_updated = strtotime( $post->post_modified_gmt );
		$diff = time() - $last_updated;
		$diff = $diff / 86400;
		
		if ( $post->post_type == 'post' && $diff > 30 && get_option( 'akismet_discard_month' ) == 'true' && empty($comment['user_ID']) ) {
			// akismet_result_spam() won't be called so bump the counter here
			if ( $incr = apply_filters('akismet_spam_count_incr', 1) )
				update_option( 'akismet_spam_count', get_option('akismet_spam_count') + $incr );
			die;
		}
	}
	
	if ( function_exists('wp_next_scheduled') && function_exists('wp_schedule_event') ) {
		// WP 2.1+: delete old comments daily
		if ( !wp_next_scheduled('akismet_scheduled_delete') )
			wp_schedule_event(time(), 'daily', 'akismet_scheduled_delete');
	} elseif ( (mt_rand(1, 10) == 3) ) {
		// WP 2.0: run this one time in ten
		akismet_delete_old();
	}
	$akismet_last_comment = $commentdata;
	return $commentdata;
}

function akismet_delete_old() {
	global $wpdb;
	$now_gmt = current_time('mysql', 1);
	$comment_ids = $wpdb->get_col("SELECT comment_id FROM $wpdb->comments WHERE DATE_SUB('$now_gmt', INTERVAL 15 DAY) > comment_date_gmt AND comment_approved = 'spam'");
	if ( empty( $comment_ids ) )
		return;

	do_action( 'delete_comment', $comment_ids );
	$wpdb->query("DELETE FROM $wpdb->comments WHERE comment_id IN ( " . implode( ', ', $comment_ids ) . " )");
	$n = mt_rand(1, 5000);
	if ( apply_filters('akismet_optimize_table', ($n == 11)) ) // lucky number
		$wpdb->query("OPTIMIZE TABLE $wpdb->comments");

}

add_action('akismet_scheduled_delete', 'akismet_delete_old');

