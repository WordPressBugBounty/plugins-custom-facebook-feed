<?php

/**
 * Customizer Builder
 * CheckBox Section Control
 *
 * @since 4.0
 */

namespace CustomFacebookFeed\Builder\Controls;

if (!defined('ABSPATH')) {
	exit;
}

class CFF_Checkboxsection_Control extends CFF_Controls_Base
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
		return 'checkboxsection';
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
		<div class="sb-control-checkboxsection-header" v-if="control.header">
			<div class="sb-control-checkboxsection-name">
				<span aria-hidden="true" v-html="svgIcons['preview']"></span>
				<strong class="">{{genericText.name}}</strong>
			</div>
			<strong>{{genericText.edit}}</strong>
		</div>
		<div class="sb-control-checkbox-ctn cff-fb-fs" role="group" :aria-label="control.label">
			<span class="sb-control-checkbox-hover" aria-hidden="true"></span>
			<button type="button"
					class="sb-control-checkbox"
					role="checkbox"
					:aria-checked="checkboxSectionValueExists(control.id, control.value) ? 'true' : 'false'"
					:aria-label="control.label"
					@click.stop.prevent="changeCheckboxSectionValue(control.id, control.value)"
					@keydown.enter.stop.prevent="changeCheckboxSectionValue(control.id, control.value)"
					@keydown.space.stop.prevent="changeCheckboxSectionValue(control.id, control.value)"
					:data-active="checkboxSectionValueExists(control.id, control.value)"></button>
			<button type="button"
					class="sb-control-checkboxsection-open cff-fb-fs"
					:aria-controls="control.section && control.section.id ? control.section.id : null"
					:aria-label="control.label"
					@click.prevent="switchNestedSection(control.section.id, control.section)"
					@keydown.enter.prevent="switchNestedSection(control.section.id, control.section)"
					@keydown.space.prevent="switchNestedSection(control.section.id, control.section)"
					:data-active="checkboxSectionValueExists(control.id, control.value)">
				<strong class="sb-control-label">{{control.label}}</strong>
				<span class="sb-control-checkboxsection-btn" aria-hidden="true"></span>
			</button>
		</div>
		<?php
	}
}