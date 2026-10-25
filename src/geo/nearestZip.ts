import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

// Turns a pin dropped on the posting form into the nearest ZIP, entirely in the browser: the
// public ZIP-center table (Census 2023, the same one the server uses) is downloaded once and
// searched here, so a pin the requester does not choose to save never leaves their device.

/**
 * A pin counts as in a ZIP area when it is within 1.5 of that area's radii (a circle of the same
 * land area) plus this margin of its center. Farther than that from every center, it is treated as
 * outside the US.
 */
export const MARGIN_KM = 10

interface Table {
	zips: string[]
	lat: Float64Array
	lng: Float64Array
	radius: Float64Array
}

let table: Promise<Table> | null = null

// Each line is "ZZZZZ LLLLLLLL GGGGGGGGG RRRRR": zip, latitude, longitude, radius in km
// (see lib/Geo/ZipCenters.php).
export function parseTable(text: string): Table {
	const lines = text.split('\n').filter((line) => line.length >= 30)
	const n = lines.length
	const parsed: Table = { zips: [], lat: new Float64Array(n), lng: new Float64Array(n), radius: new Float64Array(n) }
	lines.forEach((line, i) => {
		parsed.zips.push(line.slice(0, 5))
		parsed.lat[i] = parseFloat(line.slice(6, 14))
		parsed.lng[i] = parseFloat(line.slice(15, 24))
		parsed.radius[i] = Math.max(1, parseFloat(line.slice(25, 30)))
	})
	return parsed
}

function loadTable(): Promise<Table> {
	if (!table) {
		table = axios.get<string>(generateUrl('/apps/timebank/api/geo/zip-centers'), { responseType: 'text' })
			.then((response) => parseTable(response.data))
			.catch((error) => {
				table = null
				throw error
			})
	}
	return table
}

// Distance in km on a sphere; plenty for picking the closest ZIP center.
function distanceKm(lat1: number, lng1: number, lat2: number, lng2: number): number {
	const rad = Math.PI / 180
	const dLat = (lat2 - lat1) * rad
	const dLng = (lng2 - lng1) * rad
	const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLng / 2) ** 2
	return 2 * 6371 * Math.asin(Math.sqrt(a))
}

// The ZIP area the pin most likely falls in: the smallest distance measured in that area's radii,
// so a pin well inside a large rural ZIP is not handed to a small neighbour whose center is closer.
export function nearestIn(data: Table, lat: number, lng: number): { zip: string, km: number } | null {
	let best = -1
	let bestScore = Infinity
	let bestKm = Infinity
	for (let i = 0; i < data.zips.length; i++) {
		// Cheap filter first: no ZIP area is wider than a few degrees.
		if (Math.abs(data.lat[i] - lat) > 2 || Math.abs(data.lng[i] - lng) > 3) continue
		const km = distanceKm(lat, lng, data.lat[i], data.lng[i])
		if (km > 1.5 * data.radius[i] + MARGIN_KM) continue
		const score = km / data.radius[i]
		if (score < bestScore) {
			best = i
			bestScore = score
			bestKm = km
		}
	}
	return best >= 0 ? { zip: data.zips[best], km: bestKm } : null
}

/**
 * The ZIP area the pin most likely falls in, or null when it is near none (outside the US, or
 * in a large unpopulated area with no ZIP). This works from centers and sizes, not ZIP borders,
 * so near a border it can be wrong: callers keep the field editable.
 * @param lat latitude of the pin
 * @param lng longitude of the pin
 */
export async function nearestZip(lat: number, lng: number): Promise<{ zip: string, km: number } | null> {
	return nearestIn(await loadTable(), lat, lng)
}
