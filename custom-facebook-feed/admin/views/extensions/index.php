<?php if (! defined('ABSPATH')) {
	exit;
} // Exit if accessed directly ?>
<div id="cff-extensions" class="cff-extensions">
	<?php
		CustomFacebookFeed\CFF_View::render('sections.header');
		CustomFacebookFeed\CFF_View::render('extensions.content');
	?>
</div>