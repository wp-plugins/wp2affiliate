(function($){ 

//Run when the DOM is ready
$( function(){
			
  
	//Inline editor adapted/stolen wholesale from wp-admin/js/inline-edit-post.dev.js
	wp2apInlineEditorAff = {
		init : function() {
			var t = this, row = $('#wp2ap-inline-edit-aff');
	
			// prepare the edit row
			row.keyup(function(e) { if(e.which == 27) return wp2apInlineEditorAff.revert(); });
	
			$('a.cancel', row).click(function() { return wp2apInlineEditorAff.revert(); });
			$('a.save', row).click(function() { return wp2apInlineEditorAff.save(this); });
			$('td', row).keypress(function(e) { 
				if ( e.which == 13 ) {
					wp2apInlineEditorAff.save(this);
					//Prevent the keypress from bubbling up and causing the bulk-action form to be submitted.
					e.preventDefault();
					return false; 
				}; 
			});
	
			$('a.wp2ap-editinlineaff').live('click', function() { wp2apInlineEditorAff.edit(this); return false; });
		},
		
		toggle : function(el) {
			var t = this;
			$(t.what+t.getId(el)).css('display') == 'none' ? t.revert() : t.edit(el);
		},
		
		edit : function(id) {
			var t = this, fields, editRow, netzwerke, rowData, f;
			t.revert();
	
			if ( typeof(id) == 'object' )
				id = t.getId(id);
	
			fields = ['ap_name', 'link_url', 'nw_aktiv', 'netzwerke', 'aff_code'];
         
	
			// add the new blank row
			editRow = $('#wp2ap-inline-edit-aff').clone(true);
			$('td.editor-container-cell', editRow).attr('colspan', $('.widefat:first thead th:visible').length-0);
	
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

// Holger: Hier wird jedes Netzwerk entfernt, das nicht zum Link passt      
		netzwerkliste = $('.netzwerke', rowData).text();
	   if ( netzwerkliste.indexOf('tradedoubler') == -1) {
	 		$('select[name="nw_aktiv"] option[value="tradedoubler"]', editRow).remove();
	   	}
	   if ( netzwerkliste.indexOf('zanox') == -1) {
	 		$('select[name="nw_aktiv"] option[value="zanox"]', editRow).remove();
	   	}
	   if ( netzwerkliste.indexOf('affilinet') == -1) {
	 		$('select[name="nw_aktiv"] option[value="affilinet"]', editRow).remove();
	   	}
	   if ( netzwerkliste.indexOf('amazon') == -1) {
	 		$('select[name="nw_aktiv"] option[value="amazon"]', editRow).remove();
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
				action : 'wp2ap_update_afflink',
				link_id : id,
				_ajax_nonce : wp2ap_update_afflink_nonce, //Must be defined on the page that loads this script
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
	wp2apInlineEditorAff.init();

});
		
})(jQuery);