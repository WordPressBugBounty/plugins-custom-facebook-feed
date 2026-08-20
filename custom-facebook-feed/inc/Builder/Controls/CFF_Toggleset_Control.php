<?php

/**
 * Customizer Builder
 * Toggle Set Control
 *
 * @since 4.0
 */

namespace CustomFacebookFeed\Builder\Controls;

if (!defined('ABSPATH')) {
	exit;
}

class CFF_Toggleset_Control extends CFF_Controls_Base
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
		return 'toggleset';
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
		<div class="sb-control-toggle-set-ctn cff-fb-fs"
			 role="radiogroup"
			 :aria-label="control.heading || control.label || control.id">
			<div class="sb-control-toggle-elm cff-fb-fs sb-tr-2" v-for="(toggle, toggleIndex) in control.options"
				 role="radio"
				 :aria-checked="<?php echo $controlEditingTypeModel ?>[control.id] == toggle.value ? 'true' : 'false'"
				 :aria-disabled="toggle.checkExtension != undefined ? !checkExtensionActive(toggle.checkExtension) : false"
				 :tabindex="<?php echo $controlEditingTypeModel ?>[control.id] == toggle.value ? 0 : (<?php echo $controlEditingTypeModel ?>[control.id] == undefined && toggleIndex === 0 ? 0 : -1)"
				 :data-toggle-value="toggle.value"
				 :data-active="<?php echo $controlEditingTypeModel ?>[control.id] == toggle.value"
				 @click.prevent.default="changeSettingValue(control.id,toggle.value, toggle.checkExtension != undefined ? checkExtensionActive(toggle.checkExtension) : true)"
				 @keydown.space.prevent="changeSettingValue(control.id,toggle.value, toggle.checkExtension != undefined ? checkExtensionActive(toggle.checkExtension) : true)"
				 @keydown.enter.prevent="changeSettingValue(control.id,toggle.value, toggle.checkExtension != undefined ? checkExtensionActive(toggle.checkExtension) : true)"
				 @keydown.left.prevent="onTogglesetArrowKey($event, control, 'prev')"
				 @keydown.up.prevent="onTogglesetArrowKey($event, control, 'prev')"
				 @keydown.right.prevent="onTogglesetArrowKey($event, control, 'next')"
				 @keydown.down.prevent="onTogglesetArrowKey($event, control, 'next')"
				 @keydown.home.prevent="onTogglesetArrowKey($event, control, 'first')"
				 @keydown.end.prevent="onTogglesetArrowKey($event, control, 'last')"
				 v-show="toggle.condition != undefined ? checkControlCondition(toggle.condition) : true"
				 :data-disabled="toggle.checkExtension != undefined ? !checkExtensionActive(toggle.checkExtension) : false">
				<div class="sb-control-toggle-extension-cover"
					 aria-hidden="true"
					 v-show="toggle.checkExtension != undefined && !checkExtensionActive(toggle.checkExtension)"></div>
				<div class="sb-control-toggle-deco sb-tr-1"></div>
				<div class="sb-control-toggle-icon" v-if="toggle.icon" v-html="svgIcons[toggle.icon]"></div>
				<div class="sb-control-label" v-html="toggle.label"></div>
			</div>
		</div>
		<?php
	}
}