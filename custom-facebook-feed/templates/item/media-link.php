<?php

/**
 * Custom Facebook Feed Item : Media Link
 * Displays the custom feed item media link
 *
 * @version 2.19 Custom Facebook Feed by Smash Balloon
 */

use CustomFacebookFeed\CFF_Utils;
use CustomFacebookFeed\CFF_Shortcode_Display;

// Don't load directly
if (! defined('ABSPATH')) {
	die('-1');
}


if ($cff_show_media_link && ($cff_post_type == 'photo' || $cff_post_type == 'video' || $cff_album)) :
	$media_link_txt = CFF_Shortcode_Display::get_media_link_text($atts, $cff_post_type, $cff_album);
	$media_link_icon = CFF_Shortcode_Display::get_media_link_icon($cff_post_type, $cff_album);
	// A11Y-041: this is an icon-first link, so give the anchor an explicit
	// accessible name. Prefer the (localized) link text; fall back to a
	// type-specific label so the anchor is never nameless if the media link
	// text has been cleared. The icon itself is decorative -> aria-hidden.
	$media_link_label = (trim(wp_strip_all_tags($media_link_txt)) !== '')
		? wp_strip_all_tags($media_link_txt)
		: (($cff_post_type == 'photo' || $cff_album) ? esc_html__('View photo on Facebook', 'custom-facebook-feed') : esc_html__('View video on Facebook', 'custom-facebook-feed'));

	?>
<p class="cff-media-link">
	<a href="<?php echo esc_url($link) ?>" aria-label="<?php echo esc_attr($media_link_label); ?>" <?php echo $target; ?> style="color: #<?php echo esc_attr($cff_posttext_link_color) ?>">
		<span style="padding-right: 5px;" class="fa fas fa-<?php echo $media_link_icon ?>" aria-hidden="true"></span><?php echo wp_kses_post($media_link_txt); ?>
	</a>
</p>
	<?php
endif;
