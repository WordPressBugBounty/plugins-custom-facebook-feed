<?php // phpcs:ignoreFile -- Vue template, no PHP. ?>
<div v-if="selected === 'app-5'" class="sb-data-sharing-tab" id="cff-panel-data-sharing" role="tabpanel" aria-labelledby="cff-settings-tab-data-sharing" tabindex="0">
    <div class="sb-tab-box sb-data-sharing-consent-box sb-reset-box-style clearfix">
        <div class="tab-label">
            <h3>{{debugTab.dataSharingTitle}}</h3>
        </div>

        <div class="cff-tab-form-field">
            <div class="sb-form-field">
                <label for="cff-data-sharing-consent" class="cff-checkbox">
                    <input type="checkbox" name="cff-data-sharing-consent" id="cff-data-sharing-consent"
                           v-model="model.debug.sbc_data_sharing_consent">
                    <span class="toggle-track">
                        <div class="toggle-indicator"></div>
                    </span>
                </label>
                <span class="help-text">{{debugTab.dataSharingDesc}}</span>
                <div class="cff-debug-consent-links">
                    <a :href="debugTab.permissionsUrl" target="_blank" rel="noopener">{{debugTab.permissionsLinkText}}</a>
                    <a :href="debugTab.termsUrl" target="_blank" rel="noopener">{{debugTab.termsLinkText}}</a>
                    <a :href="debugTab.privacyUrl" target="_blank" rel="noopener">{{debugTab.privacyLinkText}}</a>
                </div>
            </div>
        </div>
    </div>

    <div class="sb-tab-box sb-in-plugin-notifications-box sb-reset-box-style clearfix">
        <div class="tab-label">
            <h3>{{debugTab.notificationsTitle}}</h3>
        </div>

        <div class="cff-tab-form-field">
            <div class="sb-form-field">
                <label for="cff-in-plugin-notifications" class="cff-checkbox">
                    <input type="checkbox" name="cff-in-plugin-notifications" id="cff-in-plugin-notifications"
                           v-model="model.debug.sbc_in_plugin_notifications"
                           :disabled="model.debug.sbc_data_sharing_consent">
                    <span class="toggle-track">
                        <div class="toggle-indicator"></div>
                    </span>
                </label>
                <span class="help-text">{{debugTab.notificationsDesc}}</span>
            </div>
        </div>
    </div>
</div>
