(function($){ 

//Run when the DOM is ready
$( function(){
			
	//The various handlers for the "Add link" form 
	var form = $('#form-add-cloaked-link');
	$('a.cancel', form).click(function() {
		form.hide();
		return false; 
	});
	
	$('a.save', form).click(function() {
		form.submit();
		return false;
	});
	
	form.keyup(function(e) { if(e.which == 27) form.hide(); });
	form.keydown(function(e) { if ( e.which == 13 ) form.submit(); });
	
	//And the "Import Links" form
	var import_form = $('#form-import-static-links');
	$('a.save', import_form).click(function() {
		import_form.submit();
		return false;
	});
	
	//The "Add new" button - show or hide the form
	$('#wp2ap-add-new').click(function(){
		if (form.is(':hidden')){
			form.slideDown('fast');
		} else {
			form.hide();
		}
		return false;
	});
	
	//Automatically select the entire cloaked URL when the user clicks the URL box
	$('.wp2ap-cloaked-url-box').focus(function(){
		$(this).select();	
	});
	
	//The "Stats" button
	$('a.wp2ap-stats-button').live('click', function(){
		//Find the ID of the relevant link
		var id = this.tagName == 'TR' ? this.id : $(this).parents('tr').attr('id'), parts = id.split('-');
		id = parts[parts.length - 1];
		
		var stats_row = $('#wp2ap-stats-row-'+id);
		if ( stats_row.is(':visible') ){
			stats_row.hide();
		} else {
			stats_row.show();
			//Only load the chart once. It improves performance and the graphed data is unlikely to 
			//change during the average time that the user spends on the page.
			if ( !stats_row.hasClass('loaded') ){
				var container = $('#wp2ap-link-stats-'+id);
				container.html('<img class="waiting" src="images/wpspin_light.gif" alt="" /> Loading...');
				
				//Load the holy graph!
				params = {
					action : 'wp2ap_show_stats',
					link_id : id,
					_ajax_nonce : wp2ap_show_stats_nonce, //Must be defined on the page that loads this script 
				}
				params = $.param(params);
				
				$.post('admin-ajax.php', params,
					function(data) {
						if (data) {
							container.html(data);
							stats_row.addClass('loaded');
						} else {
							container.html('An error occured while loading the statistics');
						}
					}
				, 'html');
			}
		}
		
		return false;
	});
	
	//Warn users when bulk-deleting links
	$('#wp2a-bulk-action-form').submit(function(){
		if ( ($('#bulk-action').val() == 'bulk-delete') || ($('#bulk-action2').val() == 'bulk-delete') ){
			return confirm(wp2ap_bulk_delete_warning);
		}
		return true;
	});
	
	//Inline editor geklaut von wp-admin/js/inline-edit-post.dev.js
	wp2apInlineEditor = {
		init : function() {
			var t = this, row = $('#wp2ap-inline-edit');
	
			// prepare the edit row
			row.keyup(function(e) { if(e.which == 27) return wp2apInlineEditor.revert(); });
	
			$('a.cancel', row).click(function() { return wp2apInlineEditor.revert(); });
			$('a.save', row).click(function() { return wp2apInlineEditor.save(this); });
			$('td', row).keypress(function(e) { 
				if ( e.which == 13 ) {
					wp2apInlineEditor.save(this);
					//Prevent the keypress from bubbling up and causing the bulk-action form to be submitted.
					e.preventDefault();
					return false; 
				}; 
			});
	
			$('a.wp2ap-editinline').live('click', function() { wp2apInlineEditor.edit(this); return false; });
		},
		
		toggle : function(el) {
			var t = this;
			$(t.what+t.getId(el)).css('display') == 'none' ? t.revert() : t.edit(el);
		},
		
		edit : function(id) {
			var t = this, fields, editRow, rowData, f;
			t.revert();
	
			if ( typeof(id) == 'object' )
				id = t.getId(id);
	
			fields = ['link_name', 'link_url', 'link_keywords', 'max_links', 'append_html'];
	
			// add the new blank row
			editRow = $('#wp2ap-inline-edit').clone(true);
			$('td.editor-container-cell', editRow).attr('colspan', $('.widefat:first thead th:visible').length-1);
	
			if ( $('#wp2ap-link-'+id).hasClass('alternate') )
				$(editRow).addClass('alternate');
			$('#wp2ap-stats-row-'+id).hide(); //hide the stats row
			$('#wp2ap-link-'+id).hide().after(editRow);
	
			// populate the data
			rowData = $('#inline_'+id);
			for ( f = 0; f < fields.length; f++ ) {
				$(':input[name="'+fields[f]+'"]', editRow)
					.val( $('.'+fields[f], rowData).text() ) 
					.change() //Required to make the jQuery-Example plugin sync up.
					.blur();  //Likewise.
			}
			
			$(editRow).attr('id', 'edit-'+id).addClass('inline-editor').show();
			$('.ptitle', editRow).focus();
	
			return false;
		},
		
		save : function(id) {
			var params, fields, page = $('.post_status_page').val() || '';
	
			if( typeof(id) == 'object' )
				id = this.getId(id);
	
			$('table.widefat .inline-edit-save .waiting').show();
			
			params = {
				action : 'wp2ap_update_link',
				link_id : id,
				_ajax_nonce : wp2ap_update_link_nonce, //Must be defined on the page that loads this script
			}
			
			fields = $('#edit-'+id+' :input:not(.wp2ap-optional-empty)').serialize();
			params = fields + '&' + $.param(params);
			
			//Perform an AJAX request
			$.post('admin-ajax.php', params,
				function(data) {
					$('table.widefat .inline-edit-save .waiting').hide();
	
					if (data) {
						//Did the server return an updated table row?  
						if ( -1 != data.indexOf('<tr') ) {
							//Replace the old row and hide the inline editor.
							
							var was_alternate = $('#wp2ap-link-'+id).hasClass('alternate');
							//Remove the link row
							$('#wp2ap-link-'+id).remove();
							//Remove the (possibly invisible) stats row
							$('#wp2ap-stats-row-'+id).remove();
							//Insert the new row(s) and remove the editor 
							$('#edit-'+id).before(data).remove();
							if ( was_alternate ) $('#wp2ap-link-'+id).addClass('alternate');
							$('#wp2ap-link-'+id).hide().fadeIn();
							
						} else {
							//No? Then show the error message that was returned.
							data = data.replace( /<.[^<>]*?>/g, '' );
							$('#edit-'+id+' .inline-edit-save').append('<span class="error">'+data+'</span>');
						}
					} else {
						$('#edit-'+id+' .inline-edit-save').append('<span class="error">An error occured while saving the link!</span>');
					}
				}
			, 'html');
			
			return false;
		},
		
		revert : function() {
			var id;
			
			if ( id = $('table.widefat tr.inline-editor').attr('id') ) {
				$('table.widefat .inline-edit-save .waiting').hide();
				
				$('#'+id).remove();
				id = id.substr( id.lastIndexOf('-') + 1 );
				$('#wp2ap-link-'+id).show();
			}
	
			return false;
		},
	
		getId : function(o) {
			var id = o.tagName == 'TR' ? o.id : $(o).parents('tr').attr('id'), parts = id.split('-');
			return parts[parts.length - 1];
		}

	};
	
	//Initialize the inline editor
	wp2apInlineEditor.init();

});
		
})(jQuery);