<div class="cff-fb-feedtypes-pp-ctn cff-fb-feedtemplates-ctn sb-fs-boss cff-fb-center-boss" v-if="viewsActive.feedtemplatesPopup">
	<div class="cff-fb-feedtypes-popup cff-fb-popup-inside" role="dialog" aria-modal="true" aria-labelledby="cff-fb-feedtemplates-popup-heading">
		<button type="button" class="cff-fb-popup-cls" aria-label="Close dialog" @click.prevent.default="activateView('feedtemplatesPopup')" @keydown.enter.prevent="activateView('feedtemplatesPopup')" @keydown.space.prevent="activateView('feedtemplatesPopup')"><svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <path d="M14 1.41L12.59 0L7 5.59L1.41 0L0 1.41L5.59 7L0 12.59L1.41 14L7 8.41L12.59 14L14 12.59L8.41 7L14 1.41Z" fill="#141B38"/>
            </svg>
        </button>
        <div class="cff-fb-source-top cff-fb-fs">
            <h2 id="cff-fb-feedtemplates-popup-heading">{{selectFeedTemplateScreen.updateHeading}}</h2>
            <p class="cff-fb-feedtemplate-alert cff-fb-fs">
                <span v-html="svgIcons['info']"></span>
                {{selectFeedTemplateScreen.updateHeadingWarning}}
            </p>
            <div class="cff-fb-types cff-fb-fs">
                <div class="cff-fb-templates-list">
                    <button type="button" :class="['cff-fb-type-el', 'cff-feed-template-' + feedTemplateEl.type]" v-for="(feedTemplateEl, feedTemplateIn) in feedTemplates" :data-active="feedTemplateEl.type == 'default'" :aria-pressed="feedTemplateEl.type == 'default' ? 'true' : 'false'" @click.prevent.default="viewsActive.extensionsPopupElement = 'feedTemplates'; activateView('feedtemplatesPopup')" @keydown.enter.prevent="viewsActive.extensionsPopupElement = 'feedTemplates'; activateView('feedtemplatesPopup')" @keydown.space.prevent="viewsActive.extensionsPopupElement = 'feedTemplates'; activateView('feedtemplatesPopup')">
                        <div class="cff-fb-type-el-img cff-fb-fs" v-html="svgIcons[feedTemplateEl.icon]"></div>
                        <div class="cff-fb-type-el-info cff-fb-fs">
                            <p class="sb-small-p sb-bold sb-dark-text" v-html="feedTemplateEl.title"></p>
                            <span class="sb-caption sb-lightest">{{feedTemplateEl.description}}</span>
                        </div>
                    </button>
                </div>
            </div>
            <div class="cff-fb-srcs-update-ctn cff-fb-fs">
                <button class="cff-fb-srcs-update cff-fb-btn cff-fb-fs cff-btn-orange" @click.prevent.default="viewsActive.extensionsPopupElement = 'feedTemplates'; activateView('feedtemplatesPopup')">
                    <svg width="16" height="12" viewBox="0 0 16 12" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M6.08058 8.36133L14.0355 0.406383L15.8033 2.17415L6.08058 11.8969L0.777281 6.59357L2.54505 4.8258L6.08058 8.36133Z" fill="white"/>
                    </svg>
                    <span>{{genericText.update}}</span>
                </button>
            </div>
        </div>
	</div>
</div>