<!--
SPDX-FileCopyrightText: 2026 NextTime contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage } from '../stores/requestStore'

interface Triple { requests: number, offers: number, claims: number }
interface Counts {
	requests: number
	offers: number
	claims: number
	claimsApprovedBefore: number
	alreadyImported: Triple
	leftOut: Triple
	requestsWithoutZip: number
	people: { matched: number, unmatched: number }
	written?: Triple & { claimsApprovedBefore: number }
	skipped?: number
}
interface Person { matched: boolean, requests: number, offers: number, claims: number }
interface Report {
	people: Record<string, Person>
	unmatchedCategories: Record<string, number>
	leftOutReasons: Record<string, number>
	notes: string[]
}
interface Run {
	id: number
	status: 'staged' | 'finalized' | 'cancelled' | 'expired'
	sourceKind: string
	sourceLabel: string
	stagedBy: string
	stagedAt: string
	expiresAt: string
	decidedBy: string | null
	decidedAt: string | null
	counts: Counts
	canFinalize?: boolean
	report?: Report
}

const kind = ref<'pgsql' | 'mysql' | 'sqlite'>('pgsql')
const label = ref('')
const host = ref('')
const port = ref('')
const database = ref('')
const user = ref('')
const password = ref('')
const prefix = ref('oc_')
const file = ref('')

const loading = ref(true)
const busy = ref(false)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const runs = ref<Run[]>([])
const preview = ref<{ counts: Counts, report: Report } | null>(null)
const opened = ref<Run | null>(null)

const isSqlite = computed(() => kind.value === 'sqlite')
const canAsk = computed(() => label.value.trim() !== '' && (isSqlite.value ? file.value.trim() !== '' : host.value.trim() !== '' && database.value.trim() !== ''))
const wouldImport = computed(() => preview.value ? preview.value.counts.requests + preview.value.counts.offers + preview.value.counts.claims : 0)

const source = () => ({
	label: label.value,
	kind: kind.value,
	host: host.value,
	port: port.value,
	database: database.value,
	user: user.value,
	password: password.value,
	prefix: prefix.value,
	file: file.value,
})

const loadRuns = async () => {
	try {
		const { data } = await axios.get(generateUrl('/apps/timebank/api/import/runs'))
		runs.value = data.imports
	} catch (e) {
		error.value = errorMessage(e, 'Failed to load')
	} finally {
		loading.value = false
	}
}

const act = async (task: () => Promise<void>) => {
	busy.value = true
	error.value = null
	notice.value = null
	try {
		await task()
	} catch (e) {
		error.value = errorMessage(e)
	} finally {
		busy.value = false
	}
}

const ask = () => act(async () => {
	preview.value = null
	const { data } = await axios.post(generateUrl('/apps/timebank/api/import/preview'), source())
	preview.value = data
})

const stage = () => act(async () => {
	const { data } = await axios.post(generateUrl('/apps/timebank/api/import/stage'), source())
	password.value = ''
	preview.value = null
	notice.value = `Staged as import #${data.id}. Nothing has been added yet: a different admin has to finalize it, within 14 days.`
	await loadRuns()
})

const decide = (run: Run, what: 'finalize' | 'cancel') => act(async () => {
	const { data } = await axios.post(generateUrl(`/apps/timebank/api/import/runs/${run.id}/${what}`))
	notice.value = what === 'finalize'
		? `Import #${run.id} is finalized: ${data.counts.written?.requests ?? 0} requests, ${data.counts.written?.offers ?? 0} offers and ${data.counts.written?.claims ?? 0} claims were added.`
		: `Import #${run.id} was cancelled and its held rows deleted.`
	opened.value = null
	await loadRuns()
})

const details = (run: Run) => act(async () => {
	if (opened.value?.id === run.id) {
		opened.value = null
		return
	}
	const { data } = await axios.get(generateUrl(`/apps/timebank/api/import/runs/${run.id}`))
	opened.value = data
})

const summary = (c: Counts) => `${c.requests} requests, ${c.offers} offers, ${c.claims} claims`
	+ (c.claimsApprovedBefore ? ` (${c.claimsApprovedBefore} approved before)` : '')

onMounted(loadRuns)
</script>

<template>
	<div class="import">
		<h1>Import from another NextTime</h1>
		<p class="subtitle">
			Bring requests, offers and hour claims over from an older NextTime database. Two admins are always involved:
			one stages the import, a <strong>different</strong> one finalizes it, and each step is written to the public
			governance log. Claims come in as <strong>pending</strong> with their hours as they were; nothing is credited
			until a claim is approved here, and claims the old system had approved are marked "approved before".
			No accounts are created: people are matched by identical user name.
		</p>

		<p v-if="error" class="error" role="alert">
			{{ error }}
		</p>
		<p v-if="notice" class="notice" role="status">
			{{ notice }}
		</p>

		<section class="card">
			<h2>1. The source</h2>
			<p class="hint">
				The connection details are used for this one connection and are never stored or logged. Use a read-only
				database user if you can. Use the same name each time you import from the same source: it is how rows
				already imported are recognised.
			</p>
			<NcTextField v-model="label" label="Name for this source" placeholder="Old dev instance" />
			<label class="field">
				<span>Kind of database</span>
				<select v-model="kind">
					<option value="pgsql">PostgreSQL</option>
					<option value="mysql">MySQL / MariaDB</option>
					<option value="sqlite">SQLite file</option>
				</select>
			</label>
			<template v-if="isSqlite">
				<NcTextField v-model="file" label="File name" placeholder="owncloud.db" />
				<p class="hint">
					The file must be inside Nextcloud's own data folder; give only its name.
				</p>
			</template>
			<template v-else>
				<NcTextField v-model="host" label="Host" placeholder="nexttime-db" />
				<NcTextField v-model="port" label="Port (blank for the usual one)" />
				<NcTextField v-model="database" label="Database name" placeholder="nextcloud" />
				<NcTextField v-model="user" label="User" />
				<NcTextField v-model="password"
					type="password"
					label="Password"
					autocomplete="off" />
			</template>
			<NcTextField v-model="prefix" label="Table prefix" />
			<NcButton variant="primary" :disabled="!canAsk || busy" @click="ask">
				Preview (writes nothing)
			</NcButton>
		</section>

		<section v-if="preview" class="card">
			<h2>2. What would come in</h2>
			<table class="counts">
				<thead>
					<tr>
						<th />
						<th>Would import</th>
						<th>Already imported</th>
						<th>Left out</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="[name, key] in ([['Requests', 'requests'], ['Offers', 'offers'], ['Claims', 'claims']] as const)" :key="key">
						<th scope="row">
							{{ name }}
						</th>
						<td>{{ preview.counts[key] }}<span v-if="key === 'claims' && preview.counts.claimsApprovedBefore"> ({{ preview.counts.claimsApprovedBefore }} approved before)</span></td>
						<td>{{ preview.counts.alreadyImported[key] }}</td>
						<td>{{ preview.counts.leftOut[key] }}</td>
					</tr>
				</tbody>
			</table>
			<p v-if="preview.counts.requestsWithoutZip" class="hint">
				{{ preview.counts.requestsWithoutZip }} request(s) have no ZIP. They belong to no state and are not on others' maps until their poster adds one.
			</p>

			<h3>People</h3>
			<p class="hint">
				{{ preview.counts.people.matched }} have an account here; {{ preview.counts.people.unmatched }} do not, and their rows are left out until the account exists.
				Run the import again later and only the missing rows are added.
			</p>
			<table class="counts">
				<thead>
					<tr>
						<th>User</th>
						<th>Requests</th>
						<th>Offers</th>
						<th>Claims</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="(person, uid) in preview.report.people" :key="uid" :class="{ missing: !person.matched }">
						<th scope="row">
							{{ uid }}
						</th>
						<td>{{ person.requests }}</td>
						<td>{{ person.offers }}</td>
						<td>{{ person.claims }}</td>
						<td>{{ person.matched ? '' : 'no account here' }}</td>
					</tr>
				</tbody>
			</table>

			<template v-if="Object.keys(preview.report.leftOutReasons).length">
				<h3>Why rows are left out</h3>
				<ul>
					<li v-for="(n, reason) in preview.report.leftOutReasons" :key="reason">
						{{ n }} × {{ reason }}
					</li>
				</ul>
			</template>
			<template v-if="Object.keys(preview.report.unmatchedCategories).length">
				<h3>Categories this install does not have</h3>
				<ul>
					<li v-for="(n, name) in preview.report.unmatchedCategories" :key="name">
						{{ name }} ({{ n }})
					</li>
				</ul>
			</template>
			<ul class="hint">
				<li v-for="line in preview.report.notes" :key="line">
					{{ line }}
				</li>
			</ul>

			<NcButton variant="primary" :disabled="wouldImport === 0 || busy" @click="stage">
				Stage this import for a second admin
			</NcButton>
			<p v-if="wouldImport === 0" class="hint">
				There is nothing new to import from this source.
			</p>
		</section>

		<section class="card">
			<h2>Imports</h2>
			<NcLoadingIcon v-if="loading" :size="32" />
			<p v-else-if="runs.length === 0" class="hint">
				No import has been staged yet.
			</p>
			<div v-for="run in runs" :key="run.id" class="run">
				<div class="run-head">
					<strong>#{{ run.id }} {{ run.sourceLabel }}</strong>
					<span class="tag" :data-status="run.status">{{ run.status }}</span>
				</div>
				<p class="meta">
					Staged by {{ run.stagedBy }} on {{ new Date(run.stagedAt).toLocaleString() }}.
					<template v-if="run.decidedBy">
						{{ run.status === 'finalized' ? 'Finalized' : run.status === 'cancelled' ? 'Cancelled' : 'Closed' }} by {{ run.decidedBy }}.
					</template>
					<template v-else-if="run.status === 'staged'">
						Expires {{ new Date(run.expiresAt).toLocaleDateString() }}.
					</template>
				</p>
				<p class="meta">
					{{ run.counts.written ? `Added: ${run.counts.written.requests} requests, ${run.counts.written.offers} offers, ${run.counts.written.claims} claims` : `Held: ${summary(run.counts)}` }}
				</p>
				<div class="actions">
					<NcButton v-if="run.status === 'staged' && run.canFinalize"
						variant="primary"
						:disabled="busy"
						@click="decide(run, 'finalize')">
						Finalize
					</NcButton>
					<span v-else-if="run.status === 'staged'" class="hint">Waiting for a different admin to finalize it.</span>
					<NcButton v-if="run.status === 'staged'" :disabled="busy" @click="decide(run, 'cancel')">
						Cancel
					</NcButton>
					<NcButton variant="tertiary" :disabled="busy" @click="details(run)">
						{{ opened?.id === run.id ? 'Hide details' : 'Details' }}
					</NcButton>
				</div>
				<div v-if="opened?.id === run.id && opened.report" class="detail">
					<table class="counts">
						<thead>
							<tr>
								<th>User</th>
								<th>Requests</th>
								<th>Offers</th>
								<th>Claims</th>
								<th />
							</tr>
						</thead>
						<tbody>
							<tr v-for="(person, uid) in opened.report.people" :key="uid" :class="{ missing: !person.matched }">
								<th scope="row">
									{{ uid }}
								</th>
								<td>{{ person.requests }}</td>
								<td>{{ person.offers }}</td>
								<td>{{ person.claims }}</td>
								<td>{{ person.matched ? '' : 'no account here' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>
		</section>
	</div>
</template>

<style scoped>
.import {
	padding: 20px;
	max-inline-size: 900px;
}

.subtitle,
.hint,
.meta {
	color: var(--color-text-maxcontrast);
}

.card {
	margin-block: 20px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.counts {
	inline-size: 100%;
	border-collapse: collapse;
}

.counts th,
.counts td {
	padding: 6px 8px;
	border-block-end: 1px solid var(--color-border);
	text-align: start;
}

.counts .missing {
	color: var(--color-warning-text);
}

.run {
	padding-block: 10px;
	border-block-end: 1px solid var(--color-border);
}

.run-head {
	display: flex;
	gap: 10px;
	align-items: center;
}

.actions {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
}

.tag {
	padding: 1px 8px;
	border-radius: 10px;
	background: var(--color-background-dark);
	font-size: 12px;
}

.tag[data-status="finalized"] {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.error {
	color: var(--color-error-text);
}

.notice {
	color: var(--color-success-text);
}
</style>
