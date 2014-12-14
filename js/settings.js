jQuery(function($){
	$('#tweak_class').change(function(a){
		if ( $('#tweak_class').is(':checked') ){
			$('#custom_class').removeAttr("disabled");
		} else {
			$('#custom_class').attr("disabled", "disabled");
		}	
	});

	$('#mode-everything').change(function(a){
		if ( $('#mode-everything').is(':checked') ){
			$('#row-whitelist').hide();
			$('#row-blacklist').show();
		} else {
			//It appears no event is fired when the checkbox is unchecked, so 
			//there's no point in handling this case.
		}
	});
	
	$('#mode-selective').change(function(a){
		if ( $('#mode-selective').is(':checked') ){
			$('#row-blacklist').hide();
			$('#row-whitelist').show();
		} else {

		}
	});

  $('#mode-affmatch').change(function(a){
		if ( $('#mode-affmatch').is(':checked') ){
			$('#row-blacklist').hide();
			$('#row-whitelist').hide();
		} else {

		}
	});
	
	//Init the tabbed interface
	$('#wp2ap-tabs').tabs();	
	
	$('#conversion-tracker-code').focus(function(){
		$(this).select();	
	});
});