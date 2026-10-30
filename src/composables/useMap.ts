/**
 * SPDX-FileCopyrightText: 2024 Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref, watch, nextTick, onMounted, onUnmounted, type Ref } from 'vue'
import maplibregl, { type Map, type Marker, type Popup, type LngLatLike } from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'
import { useMapConsent } from './useMapConsent'

// OpenFreeMap (OpenStreetMap data, no API key). Its host must also be listed in the
// page's Content-Security-Policy: see MAP_HOST in lib/Controller/PageController.php.
// Nothing is fetched from it until the person turns the map on (useMapConsent).
export const MAP_STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty'

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

	const { enabled } = useMapConsent()

	// The map, and every request to the tile server, exists only while the map is turned on.
	function create() {
		if (map.value || !enabled.value) return
		if (!mapContainer.value) {
			console.error('Map container ref is not set')
			return
		}

		map.value = new maplibregl.Map({
			container: mapContainer.value,
			style: options.style || MAP_STYLE_URL,
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
	}

	function destroy() {
		markers.value.forEach(m => m.remove())
		markers.value = []
		map.value?.remove()
		map.value = null
	}

	onMounted(create)
	watch(enabled, (on) => {
		if (on) {
			nextTick(create)
		} else {
			destroy()
		}
	})
	onUnmounted(destroy)

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
		enabled,
		markers,
		addMarker,
		clearMarkers,
		flyTo,
		fitBounds,
	}
}
