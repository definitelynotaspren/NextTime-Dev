<!--
SPDX-FileCopyrightText: 2024 Nextcloud contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="map-wrapper" :data-settled="settled">
		<div ref="mapContainer" class="map-container" />
	</div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted, type PropType } from 'vue'
import maplibregl, { type LngLatLike } from 'maplibre-gl'
import { useMap } from '../composables/useMap'

interface Request {
	id: number
	title: string
	description: string
	location: string | null
	hoursBudget: number
	categoryId?: number
	latitude?: number | null
	longitude?: number | null
	zipCenter?: { latitude: number, longitude: number } | null
}

interface Offer {
	id: number
	title: string
	description: string
	location: string | null
	latitude?: number | null
	longitude?: number | null
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

// True once the pins are drawn and the map has finished zooming to fit them (the browser test waits for it).
const settled = ref(false)

const { mapContainer, map, addMarker, clearMarkers, fitBounds } = useMap({
	center: props.center as LngLatLike,
	zoom: props.zoom,
})

// Parse location string to coordinates
// Expected format: "lat,lng" or "address" (for now we'll handle lat,lng)
// Prefer stored coordinates; fall back to "lat, lng" location text for older rows, then to
// the ZIP area's center when the exact location is not shared with you.
function pointOf(item: { latitude?: number | null, longitude?: number | null, location: string | null, zipCenter?: { latitude: number, longitude: number } | null }): LngLatLike | null {
	if (typeof item.latitude === 'number' && typeof item.longitude === 'number') {
		return [item.longitude, item.latitude]
	}
	const parsed = parseLocation(item.location)
	if (parsed) {
		return parsed
	}
	return item.zipCenter ? [item.zipCenter.longitude, item.zipCenter.latitude] : null
}

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

interface Pin {
	coords: [number, number]
	items: Array<{ kind: 'request' | 'offer', data: Request | Offer }>
}

// Requests in the same ZIP share one point (the ZIP area's center, so exact places stay private).
// They are drawn as one pin with a count instead of lying on top of each other, where only one
// could be seen or clicked.
function pinsOf(): Pin[] {
	const pins = new Map<string, Pin>()
	const place = (item: Request | Offer, kind: 'request' | 'offer') => {
		const coords = pointOf(item) as [number, number] | null
		if (!coords) return
		const key = `${coords[0].toFixed(5)},${coords[1].toFixed(5)}`
		const pin = pins.get(key) ?? { coords, items: [] }
		pin.items.push({ kind, data: item })
		pins.set(key, pin)
	}
	props.requests?.forEach((request) => place(request, 'request'))
	props.offers?.forEach((offer) => place(offer, 'offer'))
	return [...pins.values()]
}

function addSinglePin(pin: Pin) {
	const { kind, data } = pin.items[0]
	const el = document.createElement('div')
	el.className = `custom-marker marker-${kind}`
	el.innerHTML = `<span class="marker-icon">${kind === 'request' ? '🆘' : '🤝'}</span>`
	el.style.cursor = 'pointer'

	const hours = kind === 'request' ? `<p><strong>Hours:</strong> ${(data as Request).hoursBudget}</p>` : ''
	const popup = new maplibregl.Popup({ offset: 25 })
		.setHTML(`
			<div class="marker-popup">
				<h3>${escapeHtml(data.title)}</h3>
				<p>${escapeHtml(data.description.substring(0, 100))}${data.description.length > 100 ? '...' : ''}</p>
				${hours}
				<button class="popup-button" data-${kind}-id="${data.id}">View Details</button>
			</div>
		`)

	addMarker(pin.coords, { element: el, popup })
	el.addEventListener('click', () => {
		emit('marker-click', { type: kind, id: data.id })
	})
}

function addGroupPin(pin: Pin) {
	const el = document.createElement('div')
	el.className = 'custom-marker marker-group'
	el.dataset.count = String(pin.items.length)
	el.style.cursor = 'pointer'

	const icon = document.createElement('span')
	icon.className = 'marker-icon'
	icon.textContent = pin.items.every((item) => item.kind === 'offer') ? '🤝' : '🆘'
	const badge = document.createElement('span')
	badge.className = 'marker-count'
	badge.textContent = String(pin.items.length)
	el.append(icon, badge)

	const content = document.createElement('div')
	content.className = 'marker-popup'
	const heading = document.createElement('h3')
	heading.textContent = `${pin.items.length} in this area`
	const list = document.createElement('div')
	list.className = 'popup-list'
	pin.items.forEach(({ kind, data }) => {
		const button = document.createElement('button')
		button.type = 'button'
		button.className = 'popup-item'
		button.textContent = kind === 'request' ? `${data.title} (${(data as Request).hoursBudget} h)` : data.title
		button.addEventListener('click', () => emit('marker-click', { type: kind, id: data.id }))
		list.appendChild(button)
	})
	content.append(heading, list)

	const popup = new maplibregl.Popup({ offset: 25, maxWidth: '320px' }).setDOMContent(content)
	addMarker(pin.coords, { element: el, popup })
}

function addMarkers() {
	if (!map.value) return

	clearMarkers()
	settled.value = false

	const pins = pinsOf()
	pins.forEach((pin) => {
		if (pin.items.length === 1) {
			addSinglePin(pin)
		} else {
			addGroupPin(pin)
		}
	})

	// Fit map to show all pins
	if (pins.length > 0) {
		map.value.once('idle', () => { settled.value = true })
		fitBounds(pins.map((pin) => pin.coords), 80)
	} else {
		settled.value = true
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

:deep(.marker-count) {
	position: absolute;
	top: -6px;
	inset-inline-end: -12px;
	min-width: 20px;
	padding: 0 5px;
	border-radius: 10px;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 12px;
	font-weight: 700;
	line-height: 20px;
	text-align: center;
}

:deep(.popup-list) {
	max-height: 240px;
	overflow-y: auto;
}

:deep(.popup-item) {
	display: block;
	width: 100%;
	margin: 4px 0;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 14px;
	text-align: start;
}

:deep(.popup-item:hover) {
	background: var(--color-background-hover);
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
