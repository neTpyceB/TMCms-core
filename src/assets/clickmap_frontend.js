$(function() {
	$.get('/-/ajax/clickmap_frontend/', {
			l: cms_page_id
		},
		function(htmlContentFromServer) {
			$(htmlContentFromServer).appendTo('body');
		}
	);
});