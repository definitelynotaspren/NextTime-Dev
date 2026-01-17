/**
 * SPDX-FileCopyrightText: 2024 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref, onMounted, onUnmounted, type Ref } from 'vue'
import maplibregl, { type Map, type Marker, type Popup, type LngLatLike } from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'

export interface UseMapOptions {
	style?: string
	center?: LngLatLike
	zoom?: number
}

export interface MarkerOptions {
	element?: HTMLElement
	popup?: Popup
	color?: string
}

export function useMap(options: UseMapOptions = {}) {
	const mapContainer: Ref<HTMLElement | null> = ref(null)
	const map: Ref<Map | null> = ref(null)
	const markers: Ref<Marker[]> = ref([])

	onMounted(() => {
		if (!mapContainer.value) {
			console.error('Map container ref is not set')
			return
		}

		map.value = new maplibregl.Map({
			container: mapContainer.value,
			// Free OSM tiles via Carto
			style: options.style || 'https://basemaps.cartocdn.com/gl/voyager-gl-style/style.json',
			// Default to Mt. Hood area (NextTime's region)
			center: options.center || [-122.7528, 45.4215] as LngLatLike,
			zoom: options.zoom ?? 12,
		})

		// Add navigation controls (zoom buttons)
		map.value.addControl(new maplibregl.NavigationControl(), 'top-right')

		// Add geolocate control (find my location)
		map.value.addControl(
			new maplibregl.GeolocateControl({
				positionOptions: {
					enableHighAccuracy: true,
				},
				trackUserLocation: true,
			}),
			'top-right',
		)
	})

	onUnmounted(() => {
		// Clean up markers
		markers.value.forEach(m => m.remove())
		markers.value = []

		// Clean up map
		map.value?.remove()
		map.value = null
	})

	function addMarker(lngLat: LngLatLike, options: MarkerOptions = {}): Marker | null {
		if (!map.value) {
			console.warn('Map not initialized yet')
			return null
		}

		const marker = new maplibregl.Marker({
			element: options.element,
			color: options.color || '#3B82F6',
		})
			.setLngLat(lngLat)
			.addTo(map.value)

		if (options.popup) {
			marker.setPopup(options.popup)
		}

		markers.value.push(marker)
		return marker
	}

	function clearMarkers() {
		markers.value.forEach(m => m.remove())
		markers.value = []
	}

	function flyTo(lngLat: LngLatLike, zoom?: number) {
		if (!map.value) return

		map.value.flyTo({
			center: lngLat,
			zoom: zoom ?? map.value.getZoom(),
			essential: true,
		})
	}

	function fitBounds(coordinates: LngLatLike[], padding = 50) {
		if (!map.value || coordinates.length === 0) return

		const bounds = new maplibregl.LngLatBounds()
		coordinates.forEach(coord => bounds.extend(coord))

		map.value.fitBounds(bounds, {
			padding,
			maxZoom: 15,
		})
	}

	return {
		mapContainer,
		map,
		markers,
		addMarker,
		clearMarkers,
		flyTo,
		fitBounds,
	}
}
