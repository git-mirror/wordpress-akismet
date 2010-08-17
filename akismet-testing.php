<?php

/*
Plugin Name: Akismet-testing
Plugin URI: http://akismet.com/
Description: Additional code to help while testing the Akismet plugin
Version: 2.4.0
Author: Automattic
Author URI: http://automattic.com/wordpress-plugins/
License: GPLv2
*/

function akismet_debug_message( $comment, $message ) {
	//get_comment_meta($comment_id, $key, $single = false
		
	$now = strftime("%Y-%m-%d %H:%M:%S");
	
	$comment_id = $comment_author_email = null;
	if ( is_array($comment) ) {
		$comment_author_email = $comment['comment_author_email'];
	} else {
		$comment = get_comment($comment);
		$comment_id = $comment->comment_ID;
		$comment_author_email = $comment->comment_author_email;
	}
	error_log( "$now [comment {$comment_id} by {$comment_author_email}]: $message\n" );
}

// use comment_meta to record the result of the comment auto_check 
function akismet_debug_check_result( $comment ) { 
        if ( isset( $comment->akismet_result ) ) { 
				akismet_debug_message( $comment, "Akismet comment-check response: ".$comment['akismet_result'] );
        } 

		return $comment;
}

function akismet_debug_ham_result( $comment_id, $response ) {
	akismet_debug_message( $comment_id, "Akismet submit-ham response: ".$response );
}

function akismet_debug_spam_result( $comment_id, $response ) {
	akismet_debug_message( $comment_id, "Akismet submit-spam response: ".$response );
}

add_action('preprocess_comment', 'akismet_debug_check_result', 2);
add_action('akismet_submit_nonspam_comment', 'akismet_debug_ham_result', 10, 2);
add_action('akismet_submit_spam_comment', 'akismet_debug_spam_result', 10, 2);