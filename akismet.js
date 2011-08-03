jQuery(document).ready(function () {
	jQuery('.akismet-status').each(function () {
		var thisId = jQuery(this).attr('commentid');
		jQuery(this).prependTo('#comment-' + thisId + ' .column-comment div:first-child');
	});
	jQuery('.akismet-user-comment-count').each(function () {
		var thisId = jQuery(this).attr('commentid');
		jQuery(this).insertAfter('#comment-' + thisId + ' .author strong:first').show();
	});
	jQuery('#the-comment-list .column-author a[title !=""]').each(function () {
 		var thisTitle = jQuery(this).attr('title');
 		    thisCommentId = jQuery(this).parents('tr:first').attr('id').split("-");
 		
 		jQuery(this).attr("id", "author_comment_url_"+ thisCommentId[1]);
 		
 		if (thisTitle) {
 			jQuery(this).after('<a href="#" class="remove_url" commentid="'+ thisCommentId[1] +'" title="Remove this URL">x</a>');
 		}
 	});
 	jQuery('.remove_url').live('click', function () {
 		var thisId = jQuery(this).attr('commentid');
 		var data = {
 			action: 'comment_author_deurl',
 			id: thisId
 		};
 		jQuery.post(ajaxurl, data, function(response) {
 			if (response) {
 				// Removes "x" link
 				jQuery("a[commentid='"+ thisId +"']").hide();
 				// Show status/undo link
 				jQuery("#author_comment_url_"+ thisId).attr('cid', thisId).addClass('akismet_undo_link_removal').html('<span>URL removed (</span>undo<span>)</span>');
 			}
 		});
 		return false;
 	});
 	jQuery('.akismet_undo_link_removal').live('click', function () {
 		var thisId = jQuery(this).attr('cid');
		var thisUrl = jQuery(this).attr('href');
 		var data = {
 			action: 'comment_author_reurl',
 			id: thisId,
 			url: thisUrl
 		};
 		jQuery.post(ajaxurl, data, function(response) {
			if (response) {
				// Add "x" link
				jQuery("a[commentid='"+ thisId +"']").show();
				// Show link
				jQuery("#author_comment_url_"+ thisId).removeClass('akismet_undo_link_removal').html(thisUrl);
			}
		});
 		
 		return false;
 	});

});
