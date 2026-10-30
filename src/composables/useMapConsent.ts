/**
 * SPDX-FileCopyrightText: 2026 Corwin Brust <corwin@bru.st>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref, readonly } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'

// The basemap's tiles come from a third-party server (MAP_STYLE_URL in useMap.ts). Loading them tells
// that server the viewer's IP address and the area they are looking at, so no map is created, and
// nothing is fetched, until the person turns the map on. The choice is kept in this browser, per
// Nextcloud account, so a shared computer does not carry one member's choice to the next.
//
// Off to start, and sticky: once on, it stays on (in every tab) until the person turns it off.

const PREFIX = 'timebank.mapTiles.v1.'

function key(): string {
	return PREFIX + (getCurrentUser()?.uid ?? 'guest')
}

function read(): boolean {
	try {
		return window.localStorage.getItem(key()) === 'on'
	} catch {
		// Storage refused (private window, blocked site data): the map simply starts off.
		return false
	}
}

function write(on: boolean): void {
	try {
		if (on) {
			window.localStorage.setItem(key(), 'on')
		} else {
			window.localStorage.removeItem(key())
		}
	} catch {
		// Not remembered; the choice still holds until the page is reloaded.
	}
}

const enabled = ref(read())

// Another tab turned the map on or off: follow it.
window.addEventListener('storage', (event: StorageEvent) => {
	if (event.key === key()) {
		enabled.value = event.newValue === 'on'
	}
})

export function useMapConsent() {
	return {
		/** True once the person has turned the map on in this browser. */
		enabled: readonly(enabled),
		enable(): void {
			write(true)
			enabled.value = true
		},
		disable(): void {
			write(false)
			enabled.value = false
		},
	}
}
