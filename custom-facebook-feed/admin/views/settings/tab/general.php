<div v-if="selected === 'app-1'" id="cff-panel-general" role="tabpanel" aria-labelledby="cff-settings-tab-general" tabindex="0">
    <div class="sb-tab-box sb-license-box clearfix">
        <div class="tab-label">
            <h3>{{generalTab.licenseBox.title}}</h3>
            <p>{{generalTab.licenseBox.description}}</p>
        </div>

        <div class="cff-tab-form-field d-flex">
            <div v-if="licenseType === 'free' && licenseStatus == 'valid'" class="cff-tab-field-inner-wrap cff-license" :class="['license-' + licenseStatus, 'license-type-' + licenseType, {'form-error': hasError, 'dev-site-license-field': isDevSite}]">
                <div class="upgrade-info">
                    <span v-html="generalTab.licenseBox.upgradeText1"></span>
                    <span v-html="generalTab.licenseBox.upgradeText2"></span>
                </div>
                <span v-if="!isDevSite" class="license-status" v-html="generalTab.licenseBox.freeText"></span>
                <div v-if="isDevSite">
                    <a :href="upgradeUrl" target="_blank" class="cff-btn cff-btn-blue cff-upgrade-license-btn">
                        <span v-html="svgIcons.rocket"></span>
                        {{genericText.upgradeToPro}}
                    </a>
                </div>
                <div v-else class="d-flex">
                    <div class="field-left-content">
                        <div class="sb-form-field">
                            <input type="password" name="license-key" id="license-key" class="cff-form-field" :aria-label="generalTab.licenseBox.inactiveFieldPlaceholder" :placeholder="generalTab.licenseBox.inactiveFieldPlaceholder" v-model="licenseKey">
                        </div>
                        <div class="form-info d-flex justify-between">

                            <span class="cff-manage-license">
                                <a :href="links.manageLicense">{{generalTab.licenseBox.manageLicense}}</a>
                                <a :href="links.manageLicense" @click.prevent.default="deactivateLicense">{{generalTab.licenseBox.deactivate}}</a>
                            </span>
                            <span>
                                <span class="test-connection" @click="testConnection()" v-if="testConnectionStatus === null">
                                    {{generalTab.licenseBox.test}}
                                    <span v-html="testConnectionIcon()" :class="testConnectionStatus">
                                    </span>
                                </span>
                                <span v-html="testConnectionIcon()" class="test-connection"  :class="testConnectionStatus" v-if="testConnectionStatus !== null">
                                </span>
                                <span class="cff-upgrade">
                                    <a :href="upgradeUrl" target="_blank">{{generalTab.licenseBox.upgrade}}</a>
                                </span>
                            </span>
                        </div>
                    </div>
                    <div class="field-right-content">
                        <button type="button" class="cff-btn" v-on:click="licenseActiveAction" :class="{loading: loading, 'sb-btn-blue': licenseType === 'free'}">
                            <span v-if="loading && pressedBtnName === 'cff'" v-html="loaderSVG"></span>
                            <span class="cff-license-action-text" v-if="licenseType === 'pro'">{{generalTab.licenseBox.deactivate}}</span>
                            <span class="cff-license-action-text" v-if="licenseType === 'free'">{{generalTab.licenseBox.installPro}}</span>
                        </button>
                    </div>
                </div>
            </div>

            <div v-else class="cff-tab-field-inner-wrap cff-license" :class="['license-' + licenseStatus, 'license-type-' + licenseType, {'form-error': hasError, 'dev-site-license-field': isDevSite}]">
                <div class="upgrade-info">
                    <span v-html="generalTab.licenseBox.upgradeText1"></span>
                    <span v-html="generalTab.licenseBox.upgradeText2"></span>
                </div>
                <span v-if="!isDevSite" class="license-status" v-html="generalTab.licenseBox.freeText"></span>
                <div class="mb-6" v-if="licenseErrorMsg !== null">
                    <span v-html="licenseErrorMsg" class="cff-error-text"></span>
                </div>
                <div v-if="isDevSite">
                    <a :href="upgradeUrl" target="_blank" class="cff-btn cff-btn-blue cff-upgrade-license-btn">
                        <span v-html="svgIcons.rocket"></span>
                        {{genericText.upgradeToPro}}
                    </a>
                </div>
                <div v-else class="d-flex">
                    <div class="field-left-content">
                        <div class="sb-form-field">
                            <input type="password" name="license-key" id="license-key" class="cff-form-field" :aria-label="generalTab.licenseBox.inactiveFieldPlaceholder" :placeholder="generalTab.licenseBox.inactiveFieldPlaceholder" v-model="licenseKey">
                        </div>
                        <div class="form-info d-flex justify-between">

                            <span class="cff-manage-license">
                            </span>
                            <span>
                                <span class="test-connection" @click="testConnection()" v-if="testConnectionStatus === null">
                                    {{generalTab.licenseBox.test}}
                                    <span v-html="testConnectionIcon()" :class="testConnectionStatus">
                                    </span>
                                </span>
                                <span v-html="testConnectionIcon()" class="test-connection"  :class="testConnectionStatus" v-if="testConnectionStatus !== null">
                                </span>
                                <span class="cff-upgrade">
                                    <a :href="upgradeUrl" target="_blank">{{generalTab.licenseBox.upgrade}}</a>
                                </span>
                            </span>
                        </div>
                    </div>
                    <div class="field-right-content">
                        <button type="button" class="cff-btn sb-btn-blue" v-on:click="activateLicense">
                            <span v-if="loading && pressedBtnName === 'cff'" class="cff-loader-svg" v-html="loaderSVG"></span>
                            {{generalTab.licenseBox.activate}}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="licenseType === 'pro' && licenseStatus === 'valid'" class="cff-tab-field-inner-wrap cff-license cff-license" :class="['license-' + licenseStatus, 'license-type-' + licenseType, {'form-error': hasError}]">
                <span class="license-status" v-html="generalTab.licenseBox.activeText"></span>
                <div class="d-flex">
                    <div class="field-left-content">
                        <div class="sb-form-field">
                            <input type="password" name="license-key" id="license-key" class="cff-form-field" :aria-label="generalTab.licenseBox.inactiveFieldPlaceholder" value="******************************" v-model="licenseKey">
                            <span class="field-icon fa fa-check-circle"></span>
                        </div>
                        <div class="form-info d-flex justify-between">
                            <span class="cff-manage-license">
                                <a :href="links.manageLicense" target="_blank">{{generalTab.licenseBox.manageLicense}}</a>
                            </span>
                            <span>
                                <span class="test-connection" @click="testConnection()" v-if="testConnectionStatus === null">
                                    {{generalTab.licenseBox.test}}
                                    <span v-html="testConnectionIcon()" :class="testConnectionStatus">
                                    </span>
                                </span>
                                <span v-html="testConnectionIcon()" class="test-connection"  :class="testConnectionStatus" v-if="testConnectionStatus !== null">
                                </span>

                                <span class="cff-upgrade">
                                    <a :href="upgradeUrl" target="_blank">{{generalTab.licenseBox.upgrade}}</a>
                                </span>
                            </span>
                        </div>
                    </div>
                    <div class="field-right-content">
                        <button type="button" class="cff-btn" v-on:click="licenseActiveAction" :class="{loading: loading}">
                            <span v-if="loading && pressedBtnName === 'cff'" v-html="loaderSVG"></span>
                            {{generalTab.licenseBox.deactivate}}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="licenseType === 'pro' && (
                licenseStatus === 'inactive' ||
                licenseStatus === 'site_inactive' ||
                licenseStatus === 'invalid' ||
                licenseStatus === 'deactivated' ||
                licenseStatus === 'no_activations_left' ||
                licenseStatus === 'expired')"
                class="cff-tab-field-inner-wrap cff-license"
                :class="['license-' + licenseStatus, 'license-type-' + licenseType, {'form-error': hasError}]"
                >
                <span class="license-status" v-html="generalTab.licenseBox.inactiveText"></span>
                <div class="d-flex">
                    <div class="field-left-content">
                        <div class="sb-form-field">
                            <input type="password" name="license-key" id="license-key" class="cff-form-field" :aria-label="generalTab.licenseBox.inactiveFieldPlaceholder" :placeholder="generalTab.licenseBox.inactiveFieldPlaceholder" v-model="licenseKey">
                            <span class="field-icon field-icon-error fa fa-times-circle" v-if="licenseErrorMsg !== null"></span>
                        </div>
                        <div class="mb-6" v-if="licenseErrorMsg !== null">
                            <span v-html="licenseErrorMsg" class="cff-error-text"></span>
                        </div>
                        <div class="form-info d-flex justify-between">
                            <span></span>
                            <span>
                                <span class="test-connection" @click="testConnection()" v-if="testConnectionStatus === null">
                                    {{generalTab.licenseBox.test}}
                                    <span v-html="testConnectionIcon()" :class="testConnectionStatus">
                                    </span>
                                </span>
                                <span v-html="testConnectionIcon()" class="test-connection"  :class="testConnectionStatus" v-if="testConnectionStatus !== null">
                                </span>
                                <span class="cff-upgrade">
                                    <a :href="upgradeUrl" target="_blank">{{generalTab.licenseBox.upgrade}}</a>
                                </span>
                            </span>
                        </div>
                    </div>
                    <div class="field-right-content">
                        <button type="button" class="cff-btn sb-btn-blue" v-on:click="activateLicense">
                            <span v-if="loading && pressedBtnName === 'cff'" v-html="loaderSVG"></span>
                            {{generalTab.licenseBox.activate}}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Extension license -->
            <div v-if="licenseType !== 'free'" class="cff-tab-field-inner-wrap" v-for="extension in extensionsLicense" :class="[extension.name + '-license', 'license-' + extension.licenseStatus]">
                <span class="license-status" v-html="extension.statusText"></span>

                <!-- If extensions license is valid -->
                <div class="d-flex" v-if="extension.licenseStatus !== false && extension.licenseStatus == 'valid'">
                    <div class="field-left-content">
                        <div class="sb-form-field">
                            <input type="password" class="cff-form-field" :aria-label="generalTab.licenseBox.inactiveFieldPlaceholder" value="show pass" v-model="extension.licenseKey">
                            <span v-if="extension.licenseStatus == 'valid'" class="field-icon fa fa-check-circle"></span>
                        </div>
                        <div class="form-info d-flex justify-between">
                            <span class="cff-manage-license">
                                <a :href="links.manageLicense" target="_blank">{{generalTab.licenseBox.manageLicense}}</a>
                            </span>
                            <span>
                                <span class="cff-upgrade">
                                    <a :href="extension.upgradeUrl" target="_blank">{{generalTab.licenseBox.upgrade}}</a>
                                </span>
                            </span>
                        </div>
                    </div>
                    <div class="field-right-content">
                        <button type="button" class="cff-btn" v-on:click="licenseActiveAction(extension)" :class="{loading: loading}">
                            <span v-if="loading && pressedBtnName === extension.name" v-html="loaderSVG"></span>
                            <span class="cff-license-action-text" v-if="licenseType === 'pro'">{{generalTab.licenseBox.deactivate}}</span>
                            <span class="cff-license-action-text" v-if="licenseType === 'free'">{{generalTab.licenseBox.installPro}}</span>
                        </button>
                    </div>
                </div>

                <!-- If extensions license is not valid -->
                <div class="d-flex" v-else>
                    <div class="field-left-content">
                        <div class="sb-form-field" :class="{'sb-field-error': extensionFieldHasError && pressedBtnName === extension.name}">
                            <input type="password" class="cff-form-field" :aria-label="generalTab.licenseBox.inactiveFieldPlaceholder" :placeholder="generalTab.licenseBox.inactiveFieldPlaceholder" v-model="extensionsLicenseKey[extension.name]">
                        </div>
                        <div class="form-info d-flex justify-between">
                            <span class="cff-manage-license">
                                <a :href="links.manageLicense" target="_blank">{{generalTab.licenseBox.manageLicense}}</a>
                            </span>
                            <span>
                                <span class="cff-upgrade">
                                    <a :href="extension.upgradeUrl" target="_blank">{{generalTab.licenseBox.upgrade}}</a>
                                </span>
                            </span>
                        </div>
                    </div>
                    <div class="field-right-content">
                        <button @click="activateExtensionLicense(extension)" type="button" class="cff-btn cff-btn-blue" :class="{loading: loading}">
                            <span v-if="loading && pressedBtnName === extension.name" v-html="loaderSVG"></span>
                            {{generalTab.licenseBox.activate}}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="sb-tab-box sb-ai-agents-box clearfix">
        <div class="tab-label">
            <h3>{{generalTab.aiAgents.title}}</h3>
        </div>

        <div class="cff-tab-form-field">
            <div class="sb-form-field">
                <div class="sb-ai-agents-apps" role="img" :aria-label="generalTab.aiAgents.appsLabel">
                    <span class="sb-ai-agents-app sb-ai-agents-app-chatgpt" title="ChatGPT">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M22.2819 9.8211a5.9847 5.9847 0 0 0-.5157-4.9108 6.0462 6.0462 0 0 0-6.5098-2.9A6.0651 6.0651 0 0 0 4.9807 4.1818a5.9847 5.9847 0 0 0-3.9977 2.9 6.0462 6.0462 0 0 0 .7427 7.0966 5.98 5.98 0 0 0 .511 4.9107 6.051 6.051 0 0 0 6.5146 2.9001A5.9847 5.9847 0 0 0 13.2599 24a6.0557 6.0557 0 0 0 5.7718-4.2058 5.9894 5.9894 0 0 0 3.9977-2.9001 6.0557 6.0557 0 0 0-.7475-7.0729zm-9.022 12.6081a4.4755 4.4755 0 0 1-2.8764-1.0408l.1419-.0804 4.7783-2.7582a.7948.7948 0 0 0 .3927-.6813v-6.7369l2.02 1.1686a.071.071 0 0 1 .038.052v5.5826a4.504 4.504 0 0 1-4.4945 4.4944zm-9.6607-4.1254a4.4708 4.4708 0 0 1-.5346-3.0137l.142.0852 4.783 2.7582a.7712.7712 0 0 0 .7806 0l5.8428-3.3685v2.3324a.0804.0804 0 0 1-.0332.0615L9.74 19.9502a4.4992 4.4992 0 0 1-6.1408-1.6464zM2.3408 7.8956a4.485 4.485 0 0 1 2.3655-1.9728V11.6a.7664.7664 0 0 0 .3879.6765l5.8144 3.3543-2.0201 1.1685a.0757.0757 0 0 1-.071 0l-4.8303-2.7865A4.504 4.504 0 0 1 2.3408 7.872zm16.5963 3.8558L13.1038 8.364 15.1192 7.2a.0757.0757 0 0 1 .071 0l4.8303 2.7913a4.4944 4.4944 0 0 1-.6765 8.1042v-5.6772a.79.79 0 0 0-.407-.667zm2.0107-3.0231l-.142-.0852-4.7735-2.7818a.7759.7759 0 0 0-.7854 0L9.409 9.2297V6.8974a.0662.0662 0 0 1 .0284-.0615l4.8303-2.7866a4.4992 4.4992 0 0 1 6.6802 4.66zM8.3065 12.863l-2.02-1.1638a.0804.0804 0 0 1-.038-.0567V6.0742a4.4992 4.4992 0 0 1 7.3757-3.4537l-.142.0805L8.704 5.459a.7948.7948 0 0 0-.3927.6813zm1.0976-2.3654l2.602-1.4998 2.6069 1.4998v2.9994l-2.5974 1.4997-2.6067-1.4997Z"/></svg>
                    </span>
                    <span class="sb-ai-agents-app sb-ai-agents-app-cursor" title="Cursor">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M11.503.131 1.891 5.678a.84.84 0 0 0-.42.726v11.188c0 .3.162.575.42.724l9.609 5.55a1 1 0 0 0 .998 0l9.61-5.55a.84.84 0 0 0 .42-.724V6.404a.84.84 0 0 0-.42-.726L12.497.131a1.01 1.01 0 0 0-.996 0M2.657 6.338h18.55c.263 0 .43.287.297.515L12.23 22.918c-.062.107-.229.064-.229-.06V12.335a.59.59 0 0 0-.295-.51l-9.11-5.257c-.109-.063-.064-.23.061-.23"/></svg>
                    </span>
                    <span class="sb-ai-agents-app sb-ai-agents-app-claude" title="Claude">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="m4.7144 15.9555 4.7174-2.6471.079-.2307-.079-.1275h-.2307l-.7893-.0486-2.6956-.0729-2.3375-.0971-2.2646-.1214-.5707-.1215-.5343-.7042.0546-.3522.4797-.3218.686.0608 1.5179.1032 2.2767.1578 1.6514.0972 2.4468.255h.3886l.0546-.1579-.1336-.0971-.1032-.0972L6.973 9.8356l-2.55-1.6879-1.3356-.9714-.7225-.4918-.3643-.4614-.1578-1.0078.6557-.7225.8803.0607.2246.0607.8925.686 1.9064 1.4754 2.4893 1.8336.3643.3035.1457-.1032.0182-.0728-.164-.2733-1.3539-2.4467-1.445-2.4893-.6435-1.032-.17-.6194c-.0607-.255-.1032-.4674-.1032-.7285L6.287.1335 6.6997 0l.9957.1336.419.3642.6192 1.4147 1.0018 2.2282 1.5543 3.0296.4553.8985.2429.8318.091.255h.1579v-.1457l.1275-1.706.2368-2.0947.2307-2.6957.0789-.7589.3764-.9107.7468-.4918.5828.2793.4797.686-.0668.4433-.2853 1.8517-.5586 2.9021-.3643 1.9429h.2125l.2429-.2429.9835-1.3053 1.6514-2.0643.7286-.8196.85-.9046.5464-.4311h1.0321l.759 1.1293-.34 1.1657-1.0625 1.3478-.8804 1.1414-1.2628 1.7-.7893 1.36.0729.1093.1882-.0183 2.8535-.607 1.5421-.2794 1.8396-.3157.8318.3886.091.3946-.3278.8075-1.967.4857-2.3072.4614-3.4364.8136-.0425.0304.0486.0607 1.5482.1457.6618.0364h1.621l3.0175.2247.7892.522.4736.6376-.079.4857-1.2142.6193-1.6393-.3886-3.825-.9107-1.3113-.3279h-.1822v.1093l1.0929 1.0686 2.0035 1.8092 2.5075 2.3314.1275.5768-.3218.4554-.34-.0486-2.2039-1.6575-.85-.7468-1.9246-1.621h-.1275v.17l.4432.6496 2.3436 3.5214.1214 1.0807-.17.3521-.6071.2125-.6679-.1214-1.3721-1.9246L14.38 17.959l-1.1414-1.9428-.1397.079-.674 7.2552-.3156.3703-.7286.2793-.6071-.4614-.3218-.7468.3218-1.4753.3886-1.9246.3157-1.53.2853-1.9004.17-.6314-.0121-.0425-.1397.0182-1.4328 1.9672-2.1796 2.9446-1.7243 1.8456-.4128.164-.7164-.3704.0667-.6618.4008-.5889 2.386-3.0357 1.4389-1.882.929-1.0868-.0062-.1579h-.0546l-6.3385 4.1164-1.1293.1457-.4857-.4554.0608-.7467.2307-.2429 1.9064-1.3114Z"/></svg>
                    </span>
                </div>

                <span class="sb-ai-agents-heading" v-html="generalTab.aiAgents.states[aiAgentsState()].heading"></span>
                <span class="help-text" v-html="generalTab.aiAgents.states[aiAgentsState()].description"></span>

                <div class="sb-ai-agents-action">
                    <button type="button" class="cff-btn"
                            v-if="generalTab.aiAgents.status === 'active'"
                            @click.prevent.default="openWPVibe">
                        {{generalTab.aiAgents.states.active.button}}
                    </button>
                    <button type="button" class="cff-btn sb-btn-blue" v-else
                            :disabled="wpvibeInstallStatus === 'loading'"
                            @click.prevent.default="installOrActivateWPVibe">
                        <span v-if="wpvibeInstallStatus === 'loading'" v-html="loaderSVG"></span>
                        {{generalTab.aiAgents.states[aiAgentsState()].button}}
                    </button>
                </div>

                <p class="sb-ai-agents-error" v-if="wpvibeInstallStatus === 'failed'"
                   v-html="generalTab.aiAgents.states.installFailed.error"></p>
            </div>
        </div>
    </div>

    <div class="sb-tab-box sb-mcp-write-box clearfix">
        <div class="tab-label">
            <h3>{{generalTab.mcpWrite.title}}</h3>
        </div>

        <div class="cff-tab-form-field">
            <div class="sb-form-field">
                <label for="cff-ai-write-enabled" class="cff-checkbox">
                    <input type="checkbox" name="cff_ai_write_enabled" id="cff-ai-write-enabled"
                           v-model="model.advanced.cff_ai_write_enabled"
                           :aria-label="generalTab.mcpWrite.title">
                    <span class="toggle-track">
                        <div class="toggle-indicator"></div>
                    </span>
                </label>
                <span class="help-text">
                    {{generalTab.mcpWrite.helpText}}
                </span>
            </div>
        </div>
    </div>

    <?php // Free edition only: the data is an empty array in Pro (AgDR-9006). ?>
    <div class="sb-tab-box sb-ai-pro-upsell clearfix"
         v-if="licenseType === 'free' && generalTab.aiAgents.proUpsell && generalTab.aiAgents.proUpsell.url">
        <div class="sb-ai-pro-upsell-text">
            <strong>{{generalTab.aiAgents.proUpsell.title}}</strong>
            <span>{{generalTab.aiAgents.proUpsell.features.join(' · ')}}</span>
        </div>
        <a :href="generalTab.aiAgents.proUpsell.url" target="_blank" rel="noopener"
           class="cff-btn cff-btn-blue sb-ai-pro-upsell-btn">
            {{generalTab.aiAgents.proUpsell.button}}
            <span class="cff-screenreader cff-inline-sr">{{generalTab.aiAgents.proUpsell.newTab}}</span>
        </a>
    </div>

    <div class="sb-tab-box sb-manage-sources-box clearfix">
        <div class="tab-label">
            <h3>{{generalTab.manageSource.title}}</h3>
        </div>
        <div class="cff-tab-form-field">
            <div class="sb-form-field">
                <span class="help-text">
                    {{generalTab.manageSource.description}}
                </span>
                <div class="sb-sources-list" role="group" :aria-label="generalTab.manageSource.title">
                    <div class="sb-srcs-item sb-srcs-new" role="button" tabindex="0" :aria-label="genericText.addSource" @click.prevent.default="activateView('sourcePopup','creationRedirect')" @keydown.enter.prevent.default="activateView('sourcePopup','creationRedirect')" @keydown.space.prevent.default="activateView('sourcePopup','creationRedirect')">
                        <span class="add-new-icon">
                            <svg width="15" height="14" viewBox="0 0 15 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M14.25 8H8.25V14H6.25V8H0.25V6H6.25V0H8.25V6H14.25V8Z" fill="#0068A0"/>
                            </svg>
                        </span>
                        <span>{{genericText.addSource}}</span>
                    </div>
                    <div class="sb-srcs-item" v-for="(source, sourceIndex) in sourcesList" :class="{expanded: expandedFeedID == sourceIndex + 1, 'sb-account-has-error' : source.error !== ''}">
                        <div class="cff-fb-srcs-item-ins">
                            <div class="sb-srcs-item-avatar">
                                <img :src="typeof source.avatar_url !== 'undefined' && source.account_type === 'group' ? source.avatar_url : 'https://graph.facebook.com/'+source.account_id+'/picture'" alt="" aria-hidden="true">
                            </div>
                            <div class="sb-srcs-item-inf">
                                <div class="sb-srcs-item-name">
                                    {{source.username}}
                                    <div class="sb-group-small-notice" v-if="source?.account_type === 'group'">
                                        <svg width="16" height="16" viewBox="0 0 13 13" fill="none">
											<path d="M6.50008 0.666664C3.28008 0.666664 0.666748 3.28 0.666748 6.5C0.666748 9.72 3.28008 12.3333 6.50008 12.3333C9.72008 12.3333 12.3334 9.72 12.3334 6.5C12.3334 3.28 9.72008 0.666664 6.50008 0.666664ZM7.08342 9.41667H5.91675V8.25H7.08342V9.41667ZM7.08342 7.08333H5.91675V3.58333H7.08342V7.08333Z" fill="#D72C2C"/>
										</svg>
                                        <div class="cff-fb-onbrd-tltp-elem cff-fb-onbrd-tltp-elem-2 sb-tr-2" data-active="true">
                                            <div class="sb-pointer">
                                                <svg width="21" height="11" viewBox="0 0 21 11" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M8.59642 0.811655C9.39546 -0.135356 10.8545 -0.135357 11.6536 0.811654L20.25 11H0L8.59642 0.811655Z" fill="white"/>
                                                </svg>
                                            </div>
                                            <div class="cff-fb-onbrd-tltp-txt sb-small-p sb-dark-text ">
                                                <span v-html="genericText.deperecatedGroupText"></span>
                                            </div>
                                        </div>

                                    </div>
                                </div>
                                <div class="sb-srcs-item-used">
                                    <span v-html="printUsedInText(source.used_in)"></span>
                                    <div v-if="source.used_in > 0" class="sb-control-elem-tltp"><div class="sb-control-elem-tltp-icon" role="button" tabindex="0" :aria-label="(genericText.viewSourceInstances || 'View where this source is used') + ': ' + source.username" v-html="svgIcons['info']" @click.prevent.default="viewSourceInstances(source)" @keydown.enter.prevent.default="viewSourceInstances(source)" @keydown.space.prevent.default="viewSourceInstances(source)"></div></div>
                                    <div v-if="source.error !== '' || source.error_encryption" class="sb-source-error-wrap">
                                        <svg width="13" height="13" viewBox="0 0 13 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M6.50008 0.666664C3.28008 0.666664 0.666748 3.28 0.666748 6.5C0.666748 9.72 3.28008 12.3333 6.50008 12.3333C9.72008 12.3333 12.3334 9.72 12.3334 6.5C12.3334 3.28 9.72008 0.666664 6.50008 0.666664ZM7.08342 9.41667H5.91675V8.25H7.08342V9.41667ZM7.08342 7.08333H5.91675V3.58333H7.08342V7.08333Z" fill="#D72C2C"/>
                                        </svg>

                                        <span v-html="source.error !== '' ? genericText.errorSource : genericText.errorEncryption"></span><a href="#" @click.prevent.default="activateView('sourcePopup', 'creationRedirect')" v-html="genericText.reconnect"></a>
                                    </div>
                                    <div v-if="source.error === '' && typeof( source.needs_update ) !== 'undefined' && source.needs_update" class="sb-source-error-wrap">
                                        <svg width="13" height="13" viewBox="0 0 13 13" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M6.50008 0.666664C3.28008 0.666664 0.666748 3.28 0.666748 6.5C0.666748 9.72 3.28008 12.3333 6.50008 12.3333C9.72008 12.3333 12.3334 9.72 12.3334 6.5C12.3334 3.28 9.72008 0.666664 6.50008 0.666664ZM7.08342 9.41667H5.91675V8.25H7.08342V9.41667ZM7.08342 7.08333H5.91675V3.58333H7.08342V7.08333Z" fill="#D72C2C"/>
                                        </svg>

                                        <span v-html="genericText.updateRequired"></span><a href="#" @click.prevent.default="activateView('sourcePopup','creationRedirect')" v-html="genericText.reconnect"></a>
                                    </div>
                                </div>
                            </div>
                            <div class="sb-srcs-item-actions">
                                <div class="sb-srcs-item-actions-btn sb-srcs-item-delete" role="button" tabindex="0" :aria-label="(genericText.deleteSource || 'Delete') + ': ' + source.username" @click.stop.prevent.default="openDialogBox('deleteSource', source)" @keydown.enter.prevent.default="openDialogBox('deleteSource', source)" @keydown.space.prevent.default="openDialogBox('deleteSource', source)" v-html="svgIcons['delete']"></div>
                                <div class="sb-srcs-item-actions-btn sb-srcs-item-cog" v-if="expandedFeedID != sourceIndex + 1" role="button" tabindex="0" :aria-label="(genericText.sourceSettings || 'Source settings') + ': ' + source.username" v-html="svgIcons['cog']" @click="displayFeedSettings(source, sourceIndex)" @keydown.enter.prevent.default="displayFeedSettings(source, sourceIndex)" @keydown.space.prevent.default="displayFeedSettings(source, sourceIndex)"></div>
                                <div class="sb-srcs-item-actions-btn sb-srcs-item-angle-up" v-if="expandedFeedID == sourceIndex + 1" role="button" tabindex="0" :aria-label="(genericText.collapse || 'Collapse') + ': ' + source.username" v-html="svgIcons['angleUp']" @click="hideFeedSettings()" @keydown.enter.prevent.default="hideFeedSettings()" @keydown.space.prevent.default="hideFeedSettings()"></div>
                            </div>
                        </div>
                        <div class="cff-fb-srcs-info cff-fb-fs" v-if="expandedFeedID == sourceIndex + 1">
                            <div class="cff-fb-srcs-info-item">
                                <strong>{{genericText.id}}</strong>
                                <span>{{source.account_id}}</span>
                                <div class="cff-fb-srcs-info-icon" role="button" tabindex="0" :aria-label="(genericText.copyAccountId || 'Copy account ID') + ': ' + source.username" v-html="svgIcons['copy2']" @click.prevent.default="copyToClipBoard(source.account_id)" @keydown.enter.prevent.default="copyToClipBoard(source.account_id)" @keydown.space.prevent.default="copyToClipBoard(source.account_id)"></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>


    <div class="sb-tab-box sb-preserve-settings-box clearfix">
        <div class="tab-label">
            <h3>{{generalTab.preserveBox.title}}</h3>
        </div>

        <div class="cff-tab-form-field">
            <div class="sb-form-field">
                <label for="preserve-settings" class="cff-checkbox">
                    <input type="checkbox" name="preserve-settings" id="preserve-settings" v-model="model.general.preserveSettings" :aria-label="generalTab.preserveBox.title">
                    <span class="toggle-track">
                        <div class="toggle-indicator"></div>
                    </span>
                </label>
                <span class="help-text">
                    {{generalTab.preserveBox.description}}
                </span>
            </div>
        </div>
    </div>

    <div class="sb-tab-box sb-import-box sb-reset-box-style clearfix">
        <div class="tab-label">
            <h3>{{generalTab.importBox.title}}</h3>
        </div>
        <div class="cff-tab-form-field">
            <div class="sb-form-field">
                <div class="d-flex mb-15">
                    <button type="button" class="cff-btn sb-btn-lg import-btn" id="import-btn" @click="importFile" :disabled="uploadStatus !== null">
                        <span class="icon" v-html="importBtnIcon()" :class="uploadStatus"></span>
                        {{generalTab.importBox.button}}
                    </button>
                    <div class="input-hidden">
                        <input id="import_file" type="file" value="import_file" ref="file" tabindex="-1" aria-hidden="true" v-on:change="uploadFile">
                    </div>
                </div>
                <span class="help-text">
                    {{generalTab.importBox.description}}
                </span>
            </div>
        </div>
    </div>

    <div class="sb-tab-box sb-export-box clearfix">
        <div class="tab-label">
            <h3>{{generalTab.exportBox.title}}</h3>
        </div>
        <div class="cff-tab-form-field">
            <div class="sb-form-field">
                <div class="d-flex mb-15">
                    <select name="" id="cff-feeds-list" class="cff-select" v-model="exportFeed" ref="export_feed" :aria-label="generalTab.exportBox.title">
                        <option value="none" selected disabled>Select Feed</option>
                        <option v-for="feed in feeds" :value="feed.id">{{ feed.name }}</option>
                    </select>
                    <button type="button" class="cff-btn sb-btn-lg export-btn" @click="exportFeedSettings" :disabled="exportFeed === 'none'">
                        <span class="icon" v-html="exportSVG"></span>
                        {{generalTab.exportBox.button}}
                    </button>
                </div>
                <span class="help-text">
                    {{generalTab.exportBox.description}}
                </span>
            </div>
        </div>
    </div>
</div>
