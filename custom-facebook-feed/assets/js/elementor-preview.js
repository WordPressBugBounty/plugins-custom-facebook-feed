(function ($) {
	var cff_init_widget = function ($scope, $) {
		cff_init();
		if( jQuery('#cff.cff-lb').length && jQuery('#cff-lightbox-wrapper').length == 0) cffLightbox();
	};

	var registerHooks = function () {
		elementorFrontend.hooks.addAction('frontend/element_ready/cff-widget.default', cff_init_widget);
	};

	if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
		registerHooks();
	} else {
		$( window ).on( 'elementor/frontend/init', registerHooks );
	}

})(jQuery);
