<!--
SPDX-FileCopyrightText: 2024 Nextcloud contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="location-picker">
		<div class="location-input-group">
			<label for="location-text">
				Location (optional)
				<span class="hint">Enter coordinates (lat,lng) or click on the map</span>
			</label>
			<input
				id="location-text"
				v-model="locationText"
				type="text"
				placeholder="e.g., 45.4215, -122.7528 or Mt. Hood, Oregon"
				@input="handleLocationInput"
				@blur="validateLocation">
			<div v-if="showValidationMessage" class="validation-message" :class="{ error: !isValid }">
				{{ validationMessage }}
			</div>
		</div>

		<div v-if="showMap" class="map-section">
			<div class="map-controls">
				<button
					type="button"
					class="button-secondary"
					@click="toggleMapVisibility">
					{{ isMapExpanded ? 'Hide Map' : 'Show Map' }}
				</button>
				<button
					v-if="selectedLocation"
					type="button"
					class="button-secondary"
					@click="clearLocation">
					Clear Location
				</button>
			</div>

			<div v-show="isMapExpanded" class="map-wrapper">
				<div ref="mapContainer" class="map-container" />
				<div class="map-instructions">
					Click on the map to select a location
				</div>
			</div>
		</div>

		<div v-if="selectedLocation" class="selected-location-preview">
			<strong>Selected:</strong> {{ selectedLocation.lat.toFixed(4) }}, {{ selectedLocation.lng.toFixed(4) }}
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import maplibregl, { type LngLatLike, type MapMouseEvent } from 'maplibre-gl'
import { useMap } from '../composables/useMap'

interface LocationCoordinates {
	lat: number
	lng: number
}

const props = defineProps({
	modelValue: {
		type: String,
		default: '',
	},
	showMap: {
		type: Boolean,
		default: true,
	},
})

const emit = defineEmits<{
	(e: 'update:modelValue', value: string): void
}>()

const locationText = ref(props.modelValue || '')
const selectedLocation = ref<LocationCoordinates | null>(null)
const isMapExpanded = ref(false)
const showValidationMessage = ref(false)
const isValid = ref(true)
const validationMessage = ref('')
const currentMarker = ref<maplibregl.Marker | null>(null)

const { mapContainer, map, addMarker, clearMarkers } = useMap({
	center: [-122.7528, 45.4215],
	zoom: 10,
})

function parseLocationString(location: string): LocationCoordinates | null {
	if (!location || !location.trim()) return null

	// Try to parse as "lat,lng" or "lat, lng"
	const parts = location.split(',').map(p => parseFloat(p.trim()))
	if (parts.length === 2 && !isNaN(parts[0]) && !isNaN(parts[1])) {
		// Validate latitude and longitude ranges
		const lat = parts[0]
		const lng = parts[1]

		if (lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
			return { lat, lng }
		}
	}

	return null
}

function handleLocationInput() {
	showValidationMessage.value = false
	emit('update:modelValue', locationText.value)
}

function validateLocation() {
	if (!locationText.value.trim()) {
		showValidationMessage.value = false
		selectedLocation.value = null
		clearMarkers()
		return
	}

	const coords = parseLocationString(locationText.value)
	if (coords) {
		isValid.value = true
		validationMessage.value = '✓ Valid coordinates'
		showValidationMessage.value = true
		selectedLocation.value = coords
		updateMapMarker(coords)
	} else {
		isValid.value = false
		validationMessage.value = 'Please enter coordinates in format: latitude, longitude (e.g., 45.4215, -122.7528)'
		showValidationMessage.value = true
		selectedLocation.value = null
	}
}

function updateMapMarker(coords: LocationCoordinates) {
	if (!map.value) return

	// Clear existing marker
	if (currentMarker.value) {
		currentMarker.value.remove()
	}
	clearMarkers()

	// Add new marker
	const lngLat: LngLatLike = [coords.lng, coords.lat]

	// Create custom marker element
	const el = document.createElement('div')
	el.className = 'location-marker'
	el.innerHTML = '📍'

	currentMarker.value = new maplibregl.Marker({
		element: el,
		draggable: true,
	})
		.setLngLat(lngLat)
		.addTo(map.value)

	// Handle marker drag
	currentMarker.value.on('dragend', () => {
		if (currentMarker.value) {
			const lngLat = currentMarker.value.getLngLat()
			updateSelectedLocation(lngLat.lat, lngLat.lng)
		}
	})

	// Fly to the location
	map.value.flyTo({
		center: lngLat,
		zoom: 14,
	})
}

function handleMapClick(e: MapMouseEvent) {
	const { lng, lat } = e.lngLat
	updateSelectedLocation(lat, lng)
}

function updateSelectedLocation(lat: number, lng: number) {
	selectedLocation.value = { lat, lng }
	locationText.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`
	emit('update:modelValue', locationText.value)

	isValid.value = true
	validationMessage.value = '✓ Location selected on map'
	showValidationMessage.value = true

	updateMapMarker({ lat, lng })
}

function toggleMapVisibility() {
	isMapExpanded.value = !isMapExpanded.value
}

function clearLocation() {
	locationText.value = ''
	selectedLocation.value = null
	showValidationMessage.value = false
	emit('update:modelValue', '')

	if (currentMarker.value) {
		currentMarker.value.remove()
		currentMarker.value = null
	}
	clearMarkers()
}

// Watch for external changes to modelValue
watch(() => props.modelValue, (newValue) => {
	if (newValue !== locationText.value) {
		locationText.value = newValue
		validateLocation()
	}
})

// Setup map click handler
onMounted(() => {
	if (map.value) {
		map.value.on('load', () => {
			map.value?.on('click', handleMapClick)
		})
	}

	// If there's an initial value, validate it
	if (locationText.value) {
		validateLocation()
	}
})
</script>

<style scoped>
.location-picker {
	margin-bottom: 20px;
}

.location-input-group {
	margin-bottom: 16px;
}

.location-input-group label {
	display: block;
	margin-bottom: 8px;
	font-weight: 500;
	color: var(--color-main-text);
}

.location-input-group .hint {
	display: block;
	font-size: 12px;
	font-weight: normal;
	color: var(--color-text-lighter);
	margin-top: 4px;
}

.location-input-group input {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	font-size: 14px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	transition: border-color 0.2s;
}

.location-input-group input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.validation-message {
	margin-top: 6px;
	padding: 6px 10px;
	border-radius: 4px;
	font-size: 13px;
	background-color: var(--color-success);
	color: white;
}

.validation-message.error {
	background-color: var(--color-error);
}

.map-section {
	margin-top: 16px;
}

.map-controls {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

.button-secondary {
	padding: 8px 16px;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
	cursor: pointer;
	transition: background-color 0.2s;
}

.button-secondary:hover {
	background-color: var(--color-background-hover);
}

.map-wrapper {
	position: relative;
	margin-top: 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
}

.map-container {
	width: 100%;
	height: 400px;
}

.map-instructions {
	position: absolute;
	top: 10px;
	left: 50%;
	transform: translateX(-50%);
	background-color: rgba(255, 255, 255, 0.95);
	padding: 8px 16px;
	border-radius: 4px;
	font-size: 13px;
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	pointer-events: none;
	z-index: 1;
}

.selected-location-preview {
	margin-top: 12px;
	padding: 10px 12px;
	background-color: var(--color-background-hover);
	border-radius: 4px;
	font-size: 14px;
	color: var(--color-main-text);
}

.selected-location-preview strong {
	margin-right: 8px;
}

:deep(.location-marker) {
	font-size: 32px;
	cursor: move;
	transition: transform 0.2s;
}

:deep(.location-marker:hover) {
	transform: scale(1.2);
}
</style>
