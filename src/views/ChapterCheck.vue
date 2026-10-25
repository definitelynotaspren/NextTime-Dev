<!--
SPDX-FileCopyrightText: 2026 NextTime contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { errorMessage } from '../stores/requestStore'

interface ChapterRow {
	slug: string
	name: string
	state: string | null
	zipPrefixes: string[]
	memberGroup: string | null
	facilitatorGroup: string
	memberCount: number
	facilitators: string[]
}

interface Report {
	chapters: ChapterRow[]
	unpairedFacilitatorGroups: { id: string, name: string }[]
	stateGroupsWithoutFacilitators: { id: string, name: string, state: string }[]
	nationalOrganizers: {
		groupName: string
		groupId: string | null
		members: string[]
		legacyOnly: { group: string, users: string[] }[]
	}
	requestsWithoutZip: number
}

const report = ref<Report | null>(null)
const error = ref<string | null>(null)

onMounted(async () => {
	try {
		const { data } = await axios.get<Report>(generateUrl('/apps/timebank/api/chapters/check'))
		report.value = data
	} catch (e) {
		error.value = errorMessage(e, 'Failed to load chapters')
	}
})
</script>

<template>
	<div class="chapter-check">
		<h1>Chapters and facilitators</h1>
		<p class="subtitle">
			Found from your Nextcloud groups: a group "Colorado" (members) and a group "Colorado Facilitators"
			(facilitators) make the Colorado chapter. Being in the facilitator group is what gives the power to
			approve claims and see that chapter's rows; remove someone from it and the power goes at once.
		</p>

		<p v-if="error" class="error" role="alert">
			{{ error }}
		</p>
		<NcLoadingIcon v-else-if="!report" :size="44" />
		<template v-else>
			<h2>Chapters ({{ report.chapters.length }})</h2>
			<p v-if="report.chapters.length === 0" class="subtitle">
				None found. Create a group for a state and a group named like "<em>State</em> Facilitators".
			</p>
			<table v-else class="chapters">
				<thead>
					<tr>
						<th>Chapter</th>
						<th>Covers</th>
						<th>Members</th>
						<th>Facilitators</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="c in report.chapters" :key="c.slug">
						<td>
							{{ c.name }}
							<div class="groups">
								{{ c.memberGroup ?? 'no member group' }} / {{ c.facilitatorGroup }}
							</div>
						</td>
						<td>{{ c.zipPrefixes.length ? `ZIPs starting ${c.zipPrefixes.join(', ')}` : c.state ? `every ZIP in ${c.state}` : 'no ZIPs' }}</td>
						<td>{{ c.memberCount }}</td>
						<td :class="{ warn: c.facilitators.length === 0 }">
							{{ c.facilitators.length ? c.facilitators.join(', ') : 'nobody: national organizers decide for its members' }}
						</td>
					</tr>
				</tbody>
			</table>

			<p v-if="report.requestsWithoutZip" class="warning">
				{{ report.requestsWithoutZip }} open or in-progress {{ report.requestsWithoutZip === 1 ? 'request has' : 'requests have' }}
				no ZIP code, from before ZIPs were required. They belong to no state, so no facilitator sees their exact location
				and they are not on the map, until the person who posted each one edits it and adds a ZIP.
			</p>

			<h2>National organizers</h2>
			<p class="subtitle">
				Everyone in the group "{{ report.nationalOrganizers.groupName }}" decides claims and adjustments for any member
				(never their own) and sees every desk row.
			</p>
			<p v-if="!report.nationalOrganizers.groupId" class="warning">
				No group named "{{ report.nationalOrganizers.groupName }}" exists yet. Create it in Nextcloud's group settings.
			</p>
			<p v-else>
				{{ report.nationalOrganizers.members.length ? report.nationalOrganizers.members.join(', ') : 'Nobody is in it yet.' }}
			</p>
			<template v-for="legacy in report.nationalOrganizers.legacyOnly" :key="legacy.group">
				<p class="warning">
					Still national organizers only through the old group "{{ legacy.group }}": {{ legacy.users.join(', ') }}.
					Add them to "{{ report.nationalOrganizers.groupName }}" (or remove them from "{{ legacy.group }}" if they
					should not have the role).
				</p>
			</template>

			<template v-if="report.stateGroupsWithoutFacilitators.length">
				<h2>States with no facilitator group</h2>
				<p class="subtitle">
					These have members but no group named like "<em>Name</em> Facilitators". Their members' claims go to the
					national organizers. If a facilitator group exists with a slightly different name, rename it to match.
				</p>
				<ul>
					<li v-for="g in report.stateGroupsWithoutFacilitators" :key="g.id">
						{{ g.name }}
					</li>
				</ul>
			</template>

			<template v-if="report.unpairedFacilitatorGroups.length">
				<h2>Facilitator groups that match no state or group</h2>
				<p class="subtitle">
					Ignored. Nothing is lost if they are on purpose (for example a group for all chapters). If one was meant
					for a state, check its spelling against the state's group.
				</p>
				<ul>
					<li v-for="g in report.unpairedFacilitatorGroups" :key="g.id">
						{{ g.name }}
					</li>
				</ul>
			</template>
		</template>
	</div>
</template>

<style scoped>
.warning {
	color: var(--color-warning-text);
}

.chapter-check {
	padding: 20px;
	max-width: 900px;
}

.subtitle,
.groups {
	color: var(--color-text-maxcontrast);
}

.groups {
	font-size: 12px;
}

.error,
.warn {
	color: var(--color-error-text);
}

.chapters {
	width: 100%;
	border-collapse: collapse;
}

.chapters th,
.chapters td {
	padding: 10px;
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}
</style>
