(function( $ ){
	
	// declaring variables at initialization
	var $scanBtn = $('#bbconv-scan-btn'),
		$convertAllBtn = $('#bbconv-convert-all-btn'),
		$output = $('#bbconv-output'),
		$singleConvertLinks = $('.bbconv-single-convert'),
		$doactionTopBtn = $('#doaction'),
		$doactionBottomBtn = $('#doaction2'),
		convertQueue = [],
		doingAjax = false;
	
	// creating hidden Blocks editor
	$('<div />').attr('id', 'bbconv-editor').attr('style', 'display: none').appendTo('body');
	wp.editPost.initializeEditor('bbconv-editor');
	
	$scanBtn.click(function(e){
		e.preventDefault();
		$scanBtn.prop("disabled", true);
		$convertAllBtn.hide();
		scanPosts();
	});
	
	// scanning posts via ajax
	function scanPosts( offset = 0, total = -1 ){
		if ( doingAjax ) return;
		doingAjax = true;
		var nonce = $scanBtn.data('nonce');
		$output.html( bbconvObj.scanningMessage );
		$.ajax({
			method: "POST",
			url: bbconvObj.ajaxUrl,
			data: { action : "bbconv_scan_posts", offset : offset, total : total, _wpnonce : nonce }
		})
		.done(function( data ){
			doingAjax = false;
			if ( typeof data !== "object" ) {
				$output.html( bbconvObj.serverErrorMessage );
				return;
			}
			if ( data.error ) {
				$output.html( data.message );
				return;
			}
			if ( data.offset >= data.total ) {
				$scanBtn.prop("disabled", false);
				$output.html( data.message );
				document.location.href = document.location.href + "&bbconv_scan_finished=1";
				return;
			}
			scanPosts( data.offset, data.total );
			$output.html( data.message );
		})
		.fail(function(){
			doingAjax = false;
			$output.html( bbconvObj.serverErrorMessage );
		});
	}
	
	// "Bulk Convert All" button handler
	$convertAllBtn.click(function(e){
		e.preventDefault();
		if( ! confirm( bbconvObj.confirmConvertAllMessage ) ) return;
		$convertAllBtn.prop("disabled", true);
		bulkConvertPosts();
	});
	
	// table "Convert" link handler
	$singleConvertLinks.click(function(e){
		e.preventDefault();
		
		var postID = $(this).data('json').post;
		convertQueue.push( postID );
		convertPosts();
	});
	
	// bulk posts converting via ajax
	function bulkConvertPosts( offset = 0, total = -1 ){
		if ( doingAjax ) return;
		doingAjax = true;
		var nonce = $convertAllBtn.data('nonce');
		$output.html( bbconvObj.bulkConvertingMessage );
		$.ajax({
			method: "GET",
			url: bbconvObj.ajaxUrl,
			data: { action : "bbconv_bulk_convert", offset : offset, total : total, _wpnonce : nonce }
		})
		.done(function( data ){
			doingAjax = false;
			if ( typeof data !== "object" ) {
				$output.html( bbconvObj.serverErrorMessage );
				return;
			}
			if ( data.error ) {
				$output.html( data.message );
				return;
			}
			var convertedData = [];
			var arrayLength = data.postsData.length;
			for (var i = 0; i < arrayLength; i++) {
				var convertedPost = {
					id		: data.postsData[i].id,
					content	: convertToBlocks( data.postsData[i].content )
				};
				convertedData.push( convertedPost );
			}
			bulkSaveConverted( convertedData, data.offset, data.total, data.message );
			return;
		})
		.fail(function(){
			doingAjax = false;
			$output.html( bbconvObj.serverErrorMessage );
		});
	}
	
	// bulk saving converted posts via ajax
	function bulkSaveConverted( convertedData, offset, total, message ){
		if ( doingAjax ) return;
		doingAjax = true;
		var nonce = $convertAllBtn.data('nonce');
		var jsonData = {
			action : "bbconv_bulk_convert",
			offset : offset,
			total : total,
			postsData : convertedData,
			_wpnonce : nonce
		};
		$.ajax({
			method: "POST",
			url: bbconvObj.ajaxUrl,
			data: jsonData
		})
		.done(function( data ){
			doingAjax = false;
			if ( typeof data !== "object" ) {
				$output.html( bbconvObj.serverErrorMessage );
				return;
			}
			if ( data.error ) {
				$output.html( data.message );
				return;
			}
			if ( data.offset >= data.total ) {
				$convertAllBtn.prop("disabled", false);
				$output.html( bbconvObj.bulkConvertingSuccessMessage );
				return;
			}
			bulkConvertPosts( offset, total );
			$output.html( message );
			return;
		})
		.fail(function(){
			doingAjax = false;
			$output.html( bbconvObj.serverErrorMessage );
		});
	}
	
	// single or group posts converting via ajax
	function convertPosts(){
		if( convertQueue.length == 0 ){
			$doactionTopBtn.prop("disabled", false);
			$doactionBottomBtn.prop("disabled", false);
			return;
		}
		if ( doingAjax ) return;
		doingAjax = true;
		var postID = convertQueue.shift();
		var $linkObject = $('#bbconv-single-convert-' + postID);
		$linkObject.hide().after( bbconvObj.convertingSingleMessage );
		$.ajax({
			method: "GET",
			url: bbconvObj.ajaxUrl,
			data: $linkObject.data('json')
		})
		.done(function( data ){
			doingAjax = false;
			if ( typeof data !== "object" ) {
				$linkObject.parent().html( bbconvObj.failedMessage );
				return;
			}
			if ( data.error ) {
				$linkObject.parent().html( bbconvObj.failedMessage );
				return;
			}
			var content = convertToBlocks( data.message );
			saveConverted( content, $linkObject );
			return;
		})
		.fail(function(){
			doingAjax = false;
			$linkObject.parent().html( bbconvObj.failedMessage );
		});
	}
	
	// posts converting using built in Wordpress library
	function convertToBlocks( content ){
		var blocks = wp.blocks.rawHandler({ 
			HTML: content
		});
		return wp.blocks.serialize(blocks);
	}
	
	// single or group saving of converted posts via ajax
	function saveConverted( content, $linkObject ){
		if ( doingAjax ) return;
		doingAjax = true;
		var jsonData = $linkObject.data('json');
		jsonData.content = content;
		$.ajax({
			method: "POST",
			url: bbconvObj.ajaxUrl,
			data: jsonData
		})
		.done(function( data ){
			doingAjax = false;
			$("#bbconv-convert-checkbox-"+jsonData.post).prop("checked", false);
			$("#bbconv-convert-checkbox-"+jsonData.post).prop("disabled", true);
			if ( typeof data !== "object" ) {
				$linkObject.parent().html( bbconvObj.failedMessage );
				return;
			}
			if ( data.error ) {
				$linkObject.parent().html( bbconvObj.failedMessage );
				return;
			}
			$linkObject.parent().html( bbconvObj.convertedSingleMessage );
			convertPosts();
			return;
		})
		.fail(function(){
			doingAjax = false;
			$("#bbconv-convert-checkbox-"+jsonData.post).prop("checked", false);
			$("#bbconv-convert-checkbox-"+jsonData.post).prop("disabled", true);
			$linkObject.parent().html( bbconvObj.failedMessage );
		});
	}
	
	// top action button handler
	$doactionTopBtn.click(function(e){
		e.preventDefault();
		if( $('select[name="action"]').val() === 'bulk-convert' ){
			convertChecked();
		}
	});
	
	// bottom action button handler
	$doactionBottomBtn.click(function(e){
		e.preventDefault();
		if( $('select[name="action2"]').val() === 'bulk-convert' ){
			convertChecked();
		}
	});
	
	// add checked posts to converting queue and run converting process
	function convertChecked(){
		$('input[name="bulk-convert[]"]').each(function( index ){
			if( $(this).prop("checked") == true ){
				convertQueue.push( $(this).val() );
			}
		});
		$doactionTopBtn.prop("disabled", true);
		$doactionBottomBtn.prop("disabled", true);
		convertPosts();
	}
	
})( jQuery );