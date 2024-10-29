jQuery.noConflict(); 
jQuery(document).ready(function(){
  jQuery("#bbagg_remove_stream").click(function(event){
    var nrStreams = jQuery("input[name=bbagg_stream_ids[]]:checked").length;
    if(nrStreams > 0 ) {       
      return confirm("Are you sure you want to remove the selected stream(s)?");
    }
    alert("Nothing to remove. It seems you forgot to select one or more streams."); 
    return false;
  });
});

