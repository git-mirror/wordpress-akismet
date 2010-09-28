jQuery(document).ready(function () {
	jQuery('.akismet-status').each(function () {
		var thisId = jQuery(this).attr('commentid');
		jQuery(this).prependTo('#comment-' + thisId + ' #submitted-on');
	});
});
