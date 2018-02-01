( function( $ ) {
	'use strict';
	
	var adminAjaxRequest = function( formdata, _action ){
		$.ajax({
			type: 'POST', 
			url: scriptObject.ajaxurl,
			data: {
				action: _action,
				data: formdata, 
				security: scriptObject.security,
			},

			success: function(response){
				console.log('data sent');

				var dl = document.createElement( 'a' );
				document.body.appendChild( dl );
				dl.href = response.data.path;
				dl.download = response.data.fname;
				dl.click();

			},
			error: function(jqXHR, status, errorThrown){

				console.log( errorThrown );

			}
		});
	};

	// add that button on the fly, yep this is hacky
	var input = document.createElement( 'input' );
	$( input )
		.addClass( 'frm_form_field frm_first button-secondary' )
		.attr( 'id', scriptObject.id )
		.attr( 'value', 'Download Files' )
		.attr( 'type', 'button' )
		.click( function( e ){
			// send form id
			adminAjaxRequest( { 'entry_id' : scriptObject.id }, 'dl_files_zip' );
		})
		.insertAfter( '#frm_field_123_container' );

})( jQuery );