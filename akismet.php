<?php
/*
Plugin Name: Akismet
Plugin URI: http://akismet.com/
Description: Akismet checks your comments against the Akismet web serivce to see if they look like spam or not. You need a <a href="http://wordpress.com/api-keys/">WordPress.com API key</a> to use this service. You can review the spam it catches under "Manage" and it automatically deletes old spam after 15 days. To show off your Akismet stats just put <code>&lt;?php akismet_counter(); ?></code> in your template.
Version: 1.2.1
Author: Matt Mullenweg
Author URI: http://photomatt.net/
*/

add_action('admin_menu', 'ksd_config_page');

if ( ! function_exists('wp_nonce_field') ) {
	function akismet_nonce_field($action = -1) {
		return;	
	}
	$akismet_nonce = -1;
} else {
	function akismet_nonce_field($action = -1) {
		return wp_nonce_field($action);
	}
	$akismet_nonce = 'akismet-update-key';
}

function ksd_config_page() {
	global $wpdb;
	if ( function_exists('add_submenu_page') )
		add_submenu_page('plugins.php', __('Akismet Configuration'), __('Akismet Configuration'), 'manage_options', 'akismet-key-config', 'akismet_conf');
}

function akismet_conf() {
	global $akismet_nonce;
	if ( isset($_POST['submit']) ) {
		if ( function_exists('current_user_can') && !current_user_can('manage_options') )
			die(__('Cheatin&#8217; uh?'));

		check_admin_referer($akismet_nonce);
		$key = preg_replace('/[^a-h0-9]/i', '', $_POST['key']);
		if ( akismet_verify_key( $key ) )
			update_option('wordpress_api_key', $key);
		else
			$invalid_key = true;
	}
	if ( !akismet_verify_key( get_option('wordpress_api_key') ) )
		$invalid_key = true;
?>

<div class="wrap">
<h2><?php _e('Akismet Configuration'); ?></h2>
<div class="narrow">
	<p><?php printf(__('For many people, <a href="%1$s">Akismet</a> will greatly reduce or even completely eliminate the comment and trackback spam you get on your site. If one does happen to get through, simply mark it as "spam" on the moderation screen and Akismet will learn from the mistakes. If you don\'t have a WordPress.com account yet, you can get one at <a href="%2$s">WordPress.com</a>.'), 'http://akismet.com/', 'http://wordpress.com/api-keys/'); ?></p>

<form action="" method="post" id="akismet-conf" style="margin: auto; width: 400px; ">
<?php akismet_nonce_field($akismet_nonce) ?>
<h3><label for="key"><?php _e('WordPress.com API Key'); ?></label></h3>
<?php if ( $invalid_key ) { ?>
	<p style="padding: .5em; background-color: #f33; color: #fff; font-weight: bold;"><?php _e('Your key appears invalid. Double-check it.'); ?></p>
<?php } ?>
<p><input id="key" name="key" type="text" size="15" maxlength="12" value="<?php echo get_option('wordpress_api_key'); ?>" style="font-family: 'Courier New', Courier, mono; font-size: 1.5em;" /> (<?php _e('<a href="http://faq.wordpress.com/2005/10/19/api-key/">What is this?</a>'); ?>)</p>
	<p class="submit"><input type="submit" name="submit" value="<?php _e('Update API Key &raquo;'); ?>" /></p>
</form>
</div>
</div>
<?php
}

function akismet_verify_key( $key ) {
	global $auto_comment_approved, $ksd_api_host, $ksd_api_port;
	$blog = urlencode( get_option('home') );
	$response = ksd_http_post("key=$key&blog=$blog", 'rest.akismet.com', '/1.1/verify-key', $ksd_api_port);
	if ( 'valid' == $response[1] )
		return true;
	else
		return false;
}

if ( !get_option('wordpress_api_key') && !isset($_POST['submit']) ) {
	function akismet_warning() {
		echo "
		<div id='akismet-warning' class='updated fade-ff0000'><p><strong>".__('Akismet is not active.')."</strong> ".sprintf(__('You must <a href="%1$s">enter your WordPress.com API key</a> for it to work.'), "plugins.php?page=akismet-key-config")."</p></div>
		<style type='text/css'>
		#adminmenu { margin-bottom: 5em; }
		#akismet-warning { position: absolute; top: 7em; }
		</style>
		";
	}
	add_action('admin_footer', 'akismet_warning');
	return;
}

$ksd_api_host = get_option('wordpress_api_key') . '.rest.akismet.com';
$ksd_api_port = 80;
$ksd_user_agent = "WordPress/$wp_version | Akismet/1.2.1";

// Returns array with headers in $response[0] and entity in $response[1]
function ksd_http_post($request, $host, $path, $port = 80) {
	global $ksd_user_agent;

	$http_request  = "POST $path HTTP/1.0\r\n";
	$http_request .= "Host: $host\r\n";
	$http_request .= "Content-Type: application/x-www-form-urlencoded; charset=" . get_option('blog_charset') . "\r\n";
	$http_request .= "Content-Length: " . strlen($request) . "\r\n";
	$http_request .= "User-Agent: $ksd_user_agent\r\n";
	$http_request .= "\r\n";
	$http_request .= $request;

	$response = '';
	if( false != ( $fs = @fsockopen($host, $port, $errno, $errstr, 10) ) ) {
		fwrite($fs, $http_request);

		while ( !feof($fs) )
			$response .= fgets($fs, 1160); // One TCP-IP packet
		fclose($fs);
		$response = explode("\r\n\r\n", $response, 2);
	}
	return $response;
}

function ksd_auto_check_comment( $comment ) {
	global $auto_comment_approved, $ksd_api_host, $ksd_api_port;
	$comment['user_ip']    = preg_replace( '/[^0-9., ]/', '', $_SERVER['REMOTE_ADDR'] );
	$comment['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
	$comment['referrer']   = $_SERVER['HTTP_REFERER'];
	$comment['blog']       = get_option('home');

	$ignore = array( 'HTTP_COOKIE' );

	foreach ( $_SERVER as $key => $value )
		if ( !in_array( $key, $ignore ) )
			$comment["$key"] = $value;

	$query_string = '';
	foreach ( $comment as $key => $data )
		$query_string .= $key . '=' . urlencode( stripslashes($data) ) . '&';

	$response = ksd_http_post($query_string, $ksd_api_host, '/1.1/comment-check', $ksd_api_port);
	if ( 'true' == $response[1] ) {
		$auto_comment_approved = 'spam';
		update_option( 'akismet_spam_count', get_option('akismet_spam_count') + 1 );
	}
	akismet_delete_old();
	return $comment;
}

function akismet_delete_old() {
	global $wpdb;
	$now_gmt = current_time('mysql', 1);
	$wpdb->query("DELETE FROM $wpdb->comments WHERE DATE_SUB('$now_gmt', INTERVAL 15 DAY) > comment_date_gmt AND comment_approved = 'spam'");
	$n = mt_rand(1, 1000);
	if ( $n == 11 ) // lucky number
		$wpdb->query("OPTIMIZE TABLE $wpdb->comments");
}

function ksd_auto_approved( $approved ) {
	global $auto_comment_approved;
	if ( 'spam' == $auto_comment_approved )
		$approved = $auto_comment_approved;
	return $approved;
}

function ksd_submit_nonspam_comment ( $comment_id ) {
	global $wpdb, $ksd_api_host, $ksd_api_port;

	$comment = $wpdb->get_row("SELECT * FROM $wpdb->comments WHERE comment_ID = '$comment_id'");
	if ( !$comment ) // it was deleted
		return;
	$comment->blog = get_option('home');
	$query_string = '';
	foreach ( $comment as $key => $data )
		$query_string .= $key . '=' . urlencode( stripslashes($data) ) . '&';
	$response = ksd_http_post($query_string, $ksd_api_host, "/1.1/submit-ham", $ksd_api_port);
}

function ksd_submit_spam_comment ( $comment_id ) {
	global $wpdb, $ksd_api_host, $ksd_api_port;

	$comment = $wpdb->get_row("SELECT * FROM $wpdb->comments WHERE comment_ID = '$comment_id'");
	if ( !$comment ) // it was deleted
		return;
	if ( 'spam' != $comment->comment_approved )
		return;
	$comment->blog = get_option('home');
	$query_string = '';
	foreach ( $comment as $key => $data )
		$query_string .= $key . '=' . urlencode( stripslashes($data) ) . '&';

	$response = ksd_http_post($query_string, $ksd_api_host, "/1.1/submit-spam", $ksd_api_port);
}

add_action('wp_set_comment_status', 'ksd_submit_spam_comment');
add_action('edit_comment', 'ksd_submit_spam_comment');
add_action('preprocess_comment', 'ksd_auto_check_comment', 1);
add_filter('pre_comment_approved', 'ksd_auto_approved');


function ksd_spam_count() {
	global $wpdb, $comments;
	$count = $wpdb->get_var("SELECT COUNT(comment_ID) FROM $wpdb->comments WHERE comment_approved = 'spam'");
	return $count;
}

function ksd_manage_page() {
	global $wpdb, $submenu;
	$count = sprintf(__('Akismet Spam (%s)'), ksd_spam_count());
	if ( isset( $submenu['edit-comments.php'] ) )
		add_submenu_page('edit-comments.php', __('Akismet Spam'), $count, 'moderate_comments', 'akismet-admin', 'ksd_caught' );
	elseif ( function_exists('add_management_page') )
		add_management_page(__('Akismet Spam'), $count, 'moderate_comments', 'akismet-admin', 'ksd_caught');
}

function ksd_caught() {
	global $wpdb, $comment;
	akismet_recheck_queue();
	if (isset($_POST['submit']) && 'recover' == $_POST['action'] && ! empty($_POST['not_spam'])) {
		if ( function_exists('current_user_can') && !current_user_can('moderate_comments') )
			die(__('You do not have sufficient permission to moderate comments.'));
		
		$i = 0;
		foreach ($_POST['not_spam'] as $comment):
			$comment = (int) $comment;
			if ( function_exists('wp_set_comment_status') )
				wp_set_comment_status($comment, 'approve');
			else
				$wpdb->query("UPDATE $wpdb->comments SET comment_approved = '1' WHERE comment_ID = '$comment'");
			ksd_submit_nonspam_comment($comment);
			++$i;
		endforeach;
		echo '<div class="updated"><p>' . sprintf(__('%1$s comments recovered.'), $i) . "</p></div>";
	}
	if ('delete' == $_POST['action']) {
		if ( function_exists('current_user_can') && !current_user_can('moderate_comments') )
			die(__('You do not have sufficient permission to moderate comments.'));

		$delete_time = addslashes( $_POST['display_time'] );
		$nuked = $wpdb->query( "DELETE FROM $wpdb->comments WHERE comment_approved = 'spam' AND '$delete_time' > comment_date_gmt" );
		if (isset($nuked)) {
			echo '<div class="updated"><p>';
			if ($nuked) {
				_e('All spam deleted.');
			}
			echo "</p></div>";
		}
	}
?>
<div class="wrap">
<h2><?php _e('Caught Spam') ?></h2>
<?php
$count = get_option('akismet_spam_count');
if ( $count ) {
?>
<p><?php printf(__('Akismet has caught <strong>%1$s spam</strong> for you since you first installed it.'), number_format($count) ); ?></p>
<?php
}
$spam_count = ksd_spam_count();
if (0 == $spam_count) {
	echo '<p>'.__('You have no spam currently in the queue. Must be your lucky day. :)').'</p>';
	echo '</div>';
} else {
	echo '<p>'.__('You can delete all of the spam from your database with a single click. This operation cannot be undone, so you may wish to check to ensure that no legitimate comments got through first. Spam is automatically deleted after 15 days, so don&#8217;t sweat it.').'</p>';
?>
<form method="post" action="">
<input type="hidden" name="action" value="delete" />
<?php printf(__('There are currently %1$s comments identified as spam.'), $spam_count); ?>&nbsp; &nbsp; <input type="submit" name="Submit" value="<?php _e('Delete all'); ?>" />
<input type="hidden" name="display_time" value="<?php echo current_time('mysql', 1); ?>" />
</form>
</div>
<div class="wrap">
<h2><?php _e('Latest Spam'); ?></h2>
<?php echo '<p>'.__('These are the latest comments identified as spam by Akismet. If you see any mistakes, simply mark the comment as "not spam" and Akismet will learn from the submission. If you wish to recover a comment from spam, simply select the comment, and click Not Spam. After 15 days we clean out the junk for you.').'</p>'; ?>
<?php
if ( isset( $_GET['apage'] ) )
	$page = (int) $_GET['apage'];
else
	$page = 1;
$start = ( $page - 1 ) * 50;
$end = $start + 50;
$comments = $wpdb->get_results("SELECT * FROM $wpdb->comments WHERE comment_approved = 'spam' ORDER BY comment_date DESC LIMIT $start, $end");
$total = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = 'spam'" );

if ($comments) {
?>

<?php if ( $total > 50 ) {
$total_pages = ceil( $total / 50 );
$r = '';
if ( 1 < $page ) {
	$args['apage'] = ( 1 == $page - 1 ) ? '' : $page - 1;
	$r .=  '<a class="prev" href="' . add_query_arg( $args ) . '">&laquo; '. __('Previous Page') .'</a>' . "\n";
}
if ( ( $total_pages = ceil( $total / 50 ) ) > 1 ) {
	for ( $page_num = 1; $page_num <= $total_pages; $page_num++ ) :
		if ( $page == $page_num ) :
			$r .=  "<span>$page_num</span>\n";
		else :
			$p = false;
			if ( $page_num < 3 || ( $page_num >= $page - 3 && $page_num <= $page + 3 ) || $page_num > $total_pages - 3 ) :
				$args['apage'] = ( 1 == $page_num ) ? '' : $page_num;
				$r .= '<a class="page-numbers" href="' . add_query_arg($args) . '">' . ( $page_num ) . "</a>\n";
				$in = true;
			elseif ( $in == true ) :
				$r .= "...\n";
				$in = false;
			endif;
		endif;
	endfor;
}
if ( ( $page ) * 50 < $total || -1 == $total ) {
	$args['apage'] = $page + 1;
	$r .=  '<a class="next" href="' . add_query_arg($args) . '">'. __('Next Page') .' &raquo;</a>' . "\n";
}
echo "<p>$r</p>";
?>

<?php } ?>

<form method="post" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
<input type="hidden" name="action" value="recover" />
<ul id="spam-list" class="commentlist" style="list-style: none; margin: 0; padding: 0;">
<?php
$i = 0;
foreach($comments as $comment) {
	$i++;
	$comment_date = mysql2date(get_option("date_format") . " @ " . get_option("time_format"), $comment->comment_date);
	$post = get_post($comment->comment_post_ID);
	$post_title = $post->post_title;
	if ($i % 2) $class = 'class="alternate"';
	else $class = '';
	echo "\n\t<li id='comment-$comment->comment_ID' $class>"; 
	?>

<p><strong><?php comment_author() ?></strong> <?php if ($comment->comment_author_email) { ?>| <?php comment_author_email_link() ?> <?php } if ($comment->comment_author_url && 'http://' != $comment->comment_author_url) { ?> | <?php comment_author_url_link() ?> <?php } ?>| <?php _e('IP:') ?> <a href="http://ws.arin.net/cgi-bin/whois.pl?queryinput=<?php comment_author_IP() ?>"><?php comment_author_IP() ?></a></p>

<?php comment_text() ?>

<p><label for="spam-<?php echo $comment->comment_ID; ?>">
<input type="checkbox" id="spam-<?php echo $comment->comment_ID; ?>" name="not_spam[]" value="<?php echo $comment->comment_ID; ?>" />
<?php _e('Not Spam') ?></label> &#8212; <?php comment_date('M j, g:i A');  ?> &#8212; [ 
<?php
$post = get_post($comment->comment_post_ID);
$post_title = wp_specialchars( $post->post_title, 'double' );
$post_title = ('' == $post_title) ? "# $comment->comment_post_ID" : $post_title;
?>
 <a href="<?php echo get_permalink($comment->comment_post_ID); ?>" title="<?php echo $post_title; ?>"><?php _e('View Post') ?></a> ] </p>


<?php
}
}
?>
</ul>
<p class="submit"> 
<input type="submit" name="submit" value="<?php _e('De-spam marked comments &raquo;'); ?>" />
</p>
<p><?php _e('Comments you de-spam will be submitted to Akismet as mistakes so it can learn and get better.'); ?></p>
</form>
<form method="post" action="">
<p><input type="hidden" name="action" value="delete" />
<?php printf(__('There are currently %1$s comments identified as spam.'), $spam_count); ?>&nbsp; &nbsp; <input type="submit" name="Submit" value="<?php _e('Delete all'); ?>" />
<input type="hidden" name="display_time" value="<?php echo current_time('mysql', 1); ?>" /></p>
</form>
</div>
<?php
	}
}

add_action('admin_menu', 'ksd_manage_page');

function akismet_stats() {
	$count = get_option('akismet_spam_count');
	if ( !$count )
		return;
	$path = plugin_basename(__FILE__);
	echo '<h3>'.__('Spam').'</h3>';
	global $submenu;
	if ( isset( $submenu['edit-comments.php'] ) )
		$link = 'edit-comments.php';
	else
		$link = 'edit.php';
	echo '<p>'.sprintf(__('<a href="%1$s">Akismet</a> has protected your site from <a href="%2$s">%3$s spam comments</a>.'), 'http://akismet.com/', "$link?page=akismet-admin", number_format($count) ).'</p>';
}

add_action('activity_box_end', 'akismet_stats');

function widget_akismet_register() {
	if ( function_exists('register_sidebar_widget') ) :
	function widget_akismet($args) {
		extract($args);
		$options = get_option('widget_akismet');
		$count = number_format(get_option('akismet_spam_count'));
		$text = __('%d spam comments have been blocked by <a href="http://akismet.com">Akismet</a>.');
		?>
			<?php echo $before_widget; ?>
				<?php echo $before_title . $options['title'] . $after_title; ?>
				<div id="akismetwrap"><div id="akismetstats"><a id="aka" href="http://akismet.com" title=""><div id="akismet1"><span id="akismetcount"><?php echo $count; ?></span> <span id="akismetsc"><?php _e('spam comments') ?></span></div> <div id="akismet2"><span id="akismetbb"><?php _e('blocked by') ?></span><br /><span id="akismeta">Akismet</span></div></a></div></div>
			<?php echo $after_widget; ?>
	<?php
	}
	
	function widget_akismet_style() {
		?>
		<style type="text/css">
#aka, #aka:link, #aka:hover, #aka:visited, #aka:active {
	color: #fff;
	text-decoration: none;
}

#aka:hover{
	border: none;
	text-decoration: none;
}

#aka:hover #akismet1{
	display: none;
}

#aka:hover #akismet2{
	display: block;
}

#akismet1{
	display: block;
}

#akismet2{
	display: none;
	padding-top: 2px;
}

#akismeta{
	font-size: 16px;
	font-weight: bold;
	line-height: 18px;
	text-decoration: none;
}

#akismetcount{
	display: block;
	font: 15px Verdana,Arial,Sans-Serif;
	font-weight: bold;
	text-decoration: none;
}

#akismetwrap #akismetstats{
	background: url(<?php echo get_option('siteurl'); ?>/wp-content/plugins/akismet/akismet.gif) no-repeat top left;
	border: none;
	color: #fff;
	font: 11px 'Trebuchet MS','Myriad Pro',sans-serif;
	height: 40px;
	line-height: 100%;
	overflow: hidden;
	padding: 8px 0 0;
	text-align: center;
	width: 120px;
}


		</style>
		<?php
	}

	function widget_akismet_control() {
		$options = $newoptions = get_option('widget_akismet');
		if ( $_POST["akismet-submit"] ) {
			$newoptions['title'] = strip_tags(stripslashes($_POST["akismet-title"]));
			if ( empty($newoptions['title']) ) $newoptions['title'] = 'Spam Blocked';
		}
		if ( $options != $newoptions ) {
			$options = $newoptions;
			update_option('widget_akismet', $options);
		}
		$title = htmlspecialchars($options['title'], ENT_QUOTES);
	?>
				<p><label for="akismet-title"><?php _e('Title:'); ?> <input style="width: 250px;" id="akismet-title" name="akismet-title" type="text" value="<?php echo $title; ?>" /></label></p>
				<input type="hidden" id="akismet-submit" name="akismet-submit" value="1" />
	<?php
	}

	register_sidebar_widget('Akismet', 'widget_akismet', null, 'akismet');
	register_widget_control('Akismet', 'widget_akismet_control', 300, 75, 'akismet');
	if ( is_active_widget('widget_akismet') )
		add_action('wp_head', 'widget_akismet_style');
	endif;
}
add_action('init', 'widget_akismet_register');

// Counter for non-widget users
function akismet_counter() {
?>
<style type="text/css">
#akismetwrap #aka,#aka:link,#aka:hover,#aka:visited,#aka:active{color:#fff;text-decoration:none}
#aka:hover{border:none;text-decoration:none}
#aka:hover #akismet1{display:none}
#aka:hover #akismet2,#akismet1{display:block}
#akismet2{display:none;padding-top:2px}
#akismeta{font-size:16px;font-weight:bold;line-height:18px;text-decoration:none}
#akismetcount{display:block;font:15px Verdana,Arial,Sans-Serif;font-weight:bold;text-decoration:none}
#akismetwrap #akismetstats{background:url(<?php echo get_option('siteurl'); ?>/wp-content/plugins/akismet/akismet.gif) no-repeat top left;border:none;color:#fff;font:11px 'Trebuchet MS','Myriad Pro',sans-serif;height:40px;line-height:100%;overflow:hidden;padding:8px 0 0;text-align:center;width:120px}
</style>
<?php
$count = number_format(get_option('akismet_spam_count'));
?>
<div id="akismetwrap"><div id="akismetstats"><a id="aka" href="http://akismet.com" title=""><div id="akismet1"><span id="akismetcount"><?php echo $count; ?></span> <span id="akismetsc"><?php _e('spam comments') ?></span></div> <div id="akismet2"><span id="akismetbb"><?php _e('blocked by') ?></span><br /><span id="akismeta">Akismet</span></div></a></div></div>
<?php
}

if ( 'moderation.php' == $pagenow ) {
	function akismet_recheck_button( $page ) {
		global $submenu;
		if ( isset( $submenu['edit-comments.php'] ) )
			$link = 'edit-comments.php';
		else
			$link = 'edit.php';
		$button = "<a href='$link?page=akismet-admin&amp;recheckqueue=true&amp;noheader=true' style='display: block; width: 100px; position: absolute; right: 7%; padding: 5px; font-size: 14px; text-decoration: underline; background: #fff; border: 1px solid #ccc;'>Recheck Queue for Spam</a>";
		$page = str_replace( '<div class="wrap">', '<div class="wrap">' . $button, $page );
		return $page;
	}

	if ( $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->comments WHERE comment_approved = '0'" ) )
		ob_start( 'akismet_recheck_button' );
}

function akismet_recheck_queue() {
	global $wpdb, $ksd_api_host, $ksd_api_port;

	if ( !isset( $_GET['recheckqueue'] ) )
		return;

	$moderation = $wpdb->get_results( "SELECT * FROM $wpdb->comments WHERE comment_approved = '0'", ARRAY_A );
	foreach ( $moderation as $c ) {
		$c['user_ip']    = $c['comment_author_IP'];
		$c['user_agent'] = $c['comment_agent'];
		$c['referrer']   = '';
		$c['blog']       = get_option('home');
		$id = $c['comment_ID'];
		
		$query_string = '';
		foreach ( $c as $key => $data )
		$query_string .= $key . '=' . urlencode( stripslashes($data) ) . '&';
		
		$response = ksd_http_post($query_string, $ksd_api_host, '/1.1/comment-check', $ksd_api_port);
		if ( 'true' == $response[1] ) {
			$wpdb->query( "UPDATE $wpdb->comments SET comment_approved = 'spam' WHERE comment_ID = $id" );
		}
	}
	wp_redirect( $_SERVER['HTTP_REFERER'] );
	exit;
}

?>