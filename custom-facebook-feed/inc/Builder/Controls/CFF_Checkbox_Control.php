<?php

/**
 * Customizer Builder
 * CheckBox Control
 *
 * @since 4.0
 */

namespace CustomFacebookFeed\Builder\Controls;

if (!defined('ABSPATH')) {
	exit;
}

class CFF_Checkbox_Control extends CFF_Controls_Base
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
		return 'checkbox';
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
		<button type="button" class="sb-control-checkbox-ctn cff-fb-fs"
				role="checkbox"
				:aria-checked="((control.custom != undefined && control.custom == 'feedtype') ? <?php echo $controlEditingTypeModel ?>['type'].includes(control.value) : <?php echo $controlEditingTypeModel ?>[control.id] == control.options.enabled) ? 'true' : 'false'"
				:aria-label="control.label"
				@click.prevent="(control.custom != undefined && control.custom == 'feedtype') ?  changeCheckboxSectionValue('type', control.value, 'feedFlyPreview') : changeSwitcherSettingValue(control.id, control.options.enabled, control.options.disabled)"
				@keydown.enter.prevent="(control.custom != undefined && control.custom == 'feedtype') ?  changeCheckboxSectionValue('type', control.value, 'feedFlyPreview') : changeSwitcherSettingValue(control.id, control.options.enabled, control.options.disabled)"
				@keydown.space.prevent="(control.custom != undefined && control.custom == 'feedtype') ?  changeCheckboxSectionValue('type', control.value, 'feedFlyPreview') : changeSwitcherSettingValue(control.id, control.options.enabled, control.options.disabled)">
			<span class="sb-control-checkbox" aria-hidden="true"
				  :data-active="(control.custom != undefined && control.custom == 'feedtype') ? <?php echo $controlEditingTypeModel ?>['type'].includes(control.value) : <?php echo $controlEditingTypeModel ?>[control.id] == control.options.enabled"></span>
			<span class="sb-control-label">{{control.label}}</span>
		</button>
		<?php
	}
}