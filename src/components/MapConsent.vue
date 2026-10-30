<!--
SPDX-FileCopyrightText: 2026 Corwin Brust <corwin@bru.st>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

<!--
	Sits over a map. While the map is off it covers the map's area and says what turning it on
	would do; nothing is fetched from the tile server until the person chooses. It covers the map
	only, not the page: the list, the form and every other control stay usable, and a request can
	still be posted by ZIP or typed coordinates. Once on, it shrinks to a small "Turn map off"
	button in the corner, so the choice can be taken back as easily as it was given.
-->
<template>
	<div v-if="!enabled"
		class="map-consent"
		role="region"
		:aria-labelledby="headingId"
		data-map-consent="off">
		<div class="map-consent__card">
			<h3 :id="headingId" class="map-consent__title">
				The map is off
			</h3>
			<p>
				Its background is drawn from map tiles that your browser loads from
				<strong>{{ host }}</strong>, a server outside this Nextcloud. That server sees your
				IP address and the area you look at. Until you turn the map on, nothing is loaded.
			</p>
			<p class="map-consent__note">
				Your choice is remembered in this browser for your account, and you can turn the map
				off again at any time.
			</p>
			<NcButton variant="primary" @click="enable">
				Turn the map on
			</NcButton>
		</div>
	</div>
	<div v-else class="map-consent-off" data-map-consent="on">
		<NcButton variant="tertiary" size="small" @click="disable">
			Turn map off
		</NcButton>
	</div>
</template>

<script setup lang="ts">
import { useId } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useMapConsent } from '../composables/useMapConsent'
import { MAP_STYLE_URL } from '../composables/useMap'

const { enabled, enable, disable } = useMapConsent()
const headingId = useId()
const host = new URL(MAP_STYLE_URL).host
</script>

<style scoped>
.map-consent {
	position: absolute;
	inset: 0;
	z-index: 2;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.map-consent__card {
	max-width: 32rem;
	padding: 20px 24px;
	border-radius: var(--border-radius-large, 12px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 2px 12px var(--color-box-shadow);
}

.map-consent__title {
	margin: 0 0 8px;
	font-size: 1.15em;
}

.map-consent__card p {
	margin: 0 0 12px;
}

.map-consent__note {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.map-consent-off {
	position: absolute;
	left: 8px;
	bottom: 8px;
	z-index: 2;
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	box-shadow: 0 1px 4px var(--color-box-shadow);
}
</style>
