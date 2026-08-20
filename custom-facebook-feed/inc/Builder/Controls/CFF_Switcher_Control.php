<?php

/**
 * Customizer Builder
 * Switcher Field Control
 *
 * @since 4.0
 */

namespace CustomFacebookFeed\Builder\Controls;

if (!defined('ABSPATH')) {
	exit;
}

class CFF_Switcher_Control extends CFF_Controls_Base
{
	/**
	 * Get control type.
	 *
	 * Getting the Control Type
	 *
	 * @since 4.0
	 * @access public
	 *
	 * @return string
	 */
	public function get_type()
	{
		return 'switcher';
	}

	/**
	 * Output Control
	 *
	 * @since 4.0
	 * @access public
	 *
	 * @return HTML
	 */
	public function get_control_output($controlEditingTypeModel)
	{
		?>
		<button type="button" class="sb-control-switcher-ctn"
			 role="switch"
			 :aria-checked="(<?php echo $controlEditingTypeModel ?>[control.id] == control.options.enabled) ? 'true' : 'false'"
			 :aria-label="control.label"
			 :data-active="<?php echo $controlEditingTypeModel ?>[control.id] == control.options.enabled"
			 @click.prevent.default="changeSwitcherSettingValue(control.id, control.options.enabled, control.options.disabled, control.ajaxAction ? control.ajaxAction : false)"
			 @keydown.enter.prevent="changeSwitcherSettingValue(control.id, control.options.enabled, control.options.disabled, control.ajaxAction ? control.ajaxAction : false)"
			 @keydown.space.prevent="changeSwitcherSettingValue(control.id, control.options.enabled, control.options.disabled, control.ajaxAction ? control.ajaxAction : false)">
			<div class="sb-control-switcher"></div>
			<div class="sb-control-label" v-if="control.label" :data-title="control.labelStrong ? 'true' : false" aria-hidden="true">{{control.label}}</div>
		</button>
		<?php
	}
}