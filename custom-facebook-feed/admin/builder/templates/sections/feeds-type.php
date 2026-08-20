<div class="cff-fb-types-ctn cff-fb-fs sb-box-shadow" v-if="viewsActive.selectedFeedSection == 'feedsType'">
	<div class="cff-fb-types cff-fb-fs">
		<h4>{{selectFeedTypeScreen.feedTypeHeading}}</h4>
		<div class="cff-fb-types-list">
			<button type="button" class="cff-fb-type-el" v-for="(feedTypeEl, feedTypeIn) in feedTypes" :data-active="selectedFeed === feedTypeEl.type" :aria-pressed="selectedFeed === feedTypeEl.type ? 'true' : 'false'" :aria-label="feedTypeEl.title" @click.prevent.default="chooseFeedType('advanced', feedTypeEl)" @keydown.enter.prevent="chooseFeedType('advanced', feedTypeEl)" @keydown.space.prevent="chooseFeedType('advanced', feedTypeEl)">
				<div class="cff-fb-type-el-img cff-fb-fs" v-html="svgIcons[feedTypeEl.icon]"></div>
				<div class="cff-fb-type-el-info cff-fb-fs">
					<p class="sb-small-p sb-bold sb-dark-text">
						{{feedTypeEl.title}}
						<span v-html="svgIcons.rocketPremiumBlue" v-if="feedTypeEl.type !== 'timeline'"></span>
					</p>
					<span class="sb-caption sb-lightest">{{feedTypeEl.description}}</span>
				</div>
			</button>
		</div>
	</div>
	<div class="cff-fb-adv-types cff-fb-fs">
		<h4>{{selectFeedTypeScreen.advancedHeading}}</h4>
		<div class="cff-fb-types-list">
			<button type="button" class="cff-fb-type-el" v-for="(feedTypeEl, feedTypeIn) in advancedFeedTypes" :data-active="selectedFeed === feedTypeEl.type" :aria-pressed="selectedFeed === feedTypeEl.type ? 'true' : 'false'" :aria-label="feedTypeEl.title" @click.prevent.default="chooseFeedType('advanced', feedTypeEl)" @keydown.enter.prevent="chooseFeedType('advanced', feedTypeEl)" @keydown.space.prevent="chooseFeedType('advanced', feedTypeEl)">
				<div class="cff-fb-type-el-img cff-fb-fs" v-html="svgIcons[feedTypeEl.icon]"></div>
				<div class="cff-fb-type-el-info cff-fb-fs">
					<p class="sb-small-p sb-bold sb-dark-text">
						{{feedTypeEl.title}}
						<span v-html="svgIcons.rocketPremiumBlue"></span>
					</p>
					<span class="sb-caption sb-lightest">{{feedTypeEl.description}}</span>
				</div>
			</button>
		</div>
	</div>
</div>