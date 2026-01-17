<!--
SPDX-FileCopyrightText: 2024 Nextcloud contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="map-wrapper">
		<div ref="mapContainer" class="map-container" />
	</div>
</template>

<script setup lang="ts">
import { watch, onMounted, type PropType } from 'vue'
import maplibregl, { type LngLatLike } from 'maplibre-gl'
import { useMap } from '../composables/useMap'

interface Request {
	id: number
	title: string
	description: string
	location: string | null
	hoursBudget: number
	categoryId?: number
}

interface Offer {
	id: number
	title: string
	description: string
	location: string | null
}

const props = defineProps({
	requests: {
		type: Array as PropType<Request[]>,
		default: () => [],
	},
	offers: {
		type: Array as PropType<Offer[]>,
		default: () => [],
	},
	center: {
		type: Array as PropType<number[]>,
		default: () => [-122.7528, 45.4215], // Mt. Hood area default
	},
	zoom: {
		type: Number,
		default: 12,
	},
	showControls: {
		type: Boolean,
		default: true,
	},
})

const emit = defineEmits<{
	(e: 'marker-click', data: { type: 'request' | 'offer', id: number }): void
}>()

const { mapContainer, map, addMarker, clearMarkers, fitBounds } = useMap({
	center: props.center as LngLatLike,
	zoom: props.zoom,
})

// Parse location string to coordinates
// Expected format: "lat,lng" or "address" (for now we'll handle lat,lng)
function parseLocation(location: string | null): LngLatLike | null {
	if (!location) return null

	// Try to parse as "lat,lng"
	const parts = location.split(',').map(p => parseFloat(p.trim()))
	if (parts.length === 2 && !isNaN(parts[0]) && !isNaN(parts[1])) {
		// Return as [lng, lat] for MapLibre
		return [parts[1], parts[0]]
	}

	return null
}

function addMarkers() {
	if (!map.value) return

	clearMarkers()

	const allCoordinates: LngLatLike[] = []

	// Add request markers
	props.requests?.forEach(request => {
		const coords = parseLocation(request.location)
		if (coords) {
			// Create custom marker element
			const el = document.createElement('div')
			el.className = 'custom-marker marker-request'
			el.innerHTML = '<span class="marker-icon">🆘</span>'
			el.style.cursor = 'pointer'

			// Create popup
			const popup = new maplibregl.Popup({ offset: 25 })
				.setHTML(`
					<div class="marker-popup">
						<h3>${escapeHtml(request.title)}</h3>
						<p>${escapeHtml(request.description.substring(0, 100))}${request.description.length > 100 ? '...' : ''}</p>
						<p><strong>Hours:</strong> ${request.hoursBudget}</p>
						<button class="popup-button" data-request-id="${request.id}">View Details</button>
					</div>
				`)

			addMarker(coords, { element: el, popup })
			allCoordinates.push(coords)

			// Add click handler
			el.addEventListener('click', () => {
				emit('marker-click', { type: 'request', id: request.id })
			})
		}
	})

	// Add offer markers
	props.offers?.forEach(offer => {
		const coords = parseLocation(offer.location)
		if (coords) {
			// Create custom marker element
			const el = document.createElement('div')
			el.className = 'custom-marker marker-offer'
			el.innerHTML = '<span class="marker-icon">🤝</span>'
			el.style.cursor = 'pointer'

			// Create popup
			const popup = new maplibregl.Popup({ offset: 25 })
				.setHTML(`
					<div class="marker-popup">
						<h3>${escapeHtml(offer.title)}</h3>
						<p>${escapeHtml(offer.description.substring(0, 100))}${offer.description.length > 100 ? '...' : ''}</p>
						<button class="popup-button" data-offer-id="${offer.id}">View Details</button>
					</div>
				`)

			addMarker(coords, { element: el, popup })
			allCoordinates.push(coords)

			// Add click handler
			el.addEventListener('click', () => {
				emit('marker-click', { type: 'offer', id: offer.id })
			})
		}
	})

	// Fit map to show all markers
	if (allCoordinates.length > 0) {
		fitBounds(allCoordinates, 80)
	}
}

function escapeHtml(text: string): string {
	const div = document.createElement('div')
	div.textContent = text
	return div.innerHTML
}

// Watch for changes in requests/offers
watch(() => [props.requests, props.offers], () => {
	if (map.value) addMarkers()
}, { deep: true })

// Add markers after map is loaded
onMounted(() => {
	if (map.value) {
		map.value.on('load', () => {
			addMarkers()
		})
	}
})
</script>

<style scoped>
.map-wrapper {
	width: 100%;
	height: 100%;
	position: relative;
}

.map-container {
	width: 100%;
	height: 100%;
	min-height: 400px;
	border-radius: 8px;
	overflow: hidden;
}

:deep(.custom-marker) {
	font-size: 28px;
	transition: transform 0.2s;
}

:deep(.custom-marker:hover) {
	transform: scale(1.2);
}

:deep(.marker-request .marker-icon) {
	filter: drop-shadow(2px 2px 4px rgba(0, 0, 0, 0.3));
}

:deep(.marker-offer .marker-icon) {
	filter: drop-shadow(2px 2px 4px rgba(0, 0, 0, 0.3));
}

:deep(.maplibregl-popup-content) {
	padding: 0;
	border-radius: 8px;
	box-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
}

:deep(.marker-popup) {
	padding: 16px;
	min-width: 200px;
	max-width: 300px;
}

:deep(.marker-popup h3) {
	margin: 0 0 8px 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}

:deep(.marker-popup p) {
	margin: 4px 0;
	font-size: 14px;
	color: var(--color-text-lighter);
	line-height: 1.4;
}

:deep(.marker-popup strong) {
	color: var(--color-main-text);
}

:deep(.popup-button) {
	margin-top: 12px;
	padding: 8px 16px;
	background: var(--color-primary);
	color: var(--color-primary-text);
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 14px;
	font-weight: 500;
	width: 100%;
	transition: background-color 0.2s;
}

:deep(.popup-button:hover) {
	background: var(--color-primary-hover);
}

/* Ensure MapLibre controls use proper theme colors */
:deep(.maplibregl-ctrl-group) {
	background: var(--color-main-background);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

:deep(.maplibregl-ctrl-group button) {
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

:deep(.maplibregl-ctrl-group button:hover) {
	background-color: var(--color-background-hover);
}
</style>
