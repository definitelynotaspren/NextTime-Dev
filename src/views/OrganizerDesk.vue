<!--
SPDX-FileCopyrightText: 2026 NextTime contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

interface Field {
	name: string
	type: string
	required: boolean
	visibility: string
	choices: string | null
	description: string
}

interface Schema {
	systemFields: Field[]
	layers: Record<string, { title: string, uid_prefix: string, fields: Field[], aggregate_only?: boolean }>
	lookups: Record<string, string[]>
}

interface Row {
	id: number
	layer: string
	source: string
	chapter: string | null
	assignedTo: string | null
	assignmentStatus: string | null
	assignedAt: string | null
	fields: Record<string, string | number | null>
}

type Tab = 'mine' | 'unassigned' | 'all'

const STATUSES = ['assigned', 'in_progress', 'done']

const schema = ref<Schema | null>(null)
const layerName = ref('needs_offers')
const tab = ref<Tab>('unassigned')
const source = ref('')
const rows = ref<Row[]>([])
const loading = ref(false)
const error = ref<string | null>(null)
const busyId = ref<number | null>(null)
const showCreate = ref(false)
const draft = ref<Record<string, string>>({})
const createError = ref<string | null>(null)

const layers = computed(() => Object.entries(schema.value?.layers ?? {}))
const layerFields = computed(() => schema.value?.layers[layerName.value]?.fields ?? [])
const titleOf = (row: Row) => String(row.fields.summary ?? row.fields.title ?? row.fields.name ?? row.fields.uid ?? row.id)

const loadSchema = async () => {
	try {
		const { data } = await axios.get<Schema>(generateUrl('/apps/timebank/api/schema'))
		schema.value = data
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		error.value = err.response?.data?.error || 'Organizer role required'
	}
}

const loadRows = async () => {
	loading.value = true
	error.value = null
	const params = new URLSearchParams({ layer: layerName.value })
	if (source.value) params.set('source', source.value)

	try {
		let url: string
		if (tab.value === 'mine') {
			url = generateUrl(`/apps/timebank/api/assignments?${params}`)
		} else {
			if (tab.value === 'unassigned') params.set('assigned', 'unassigned')
			url = generateUrl(`/apps/timebank/api/layers/${layerName.value}/rows?${params}`)
		}
		const { data } = await axios.get<Row[]>(url)
		rows.value = data
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		error.value = err.response?.data?.error || 'Could not load rows'
		rows.value = []
	} finally {
		loading.value = false
	}
}

const act = async (row: Row, request: () => Promise<unknown>) => {
	busyId.value = row.id
	try {
		await request()
		await loadRows()
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		error.value = err.response?.data?.error || 'Action failed'
	} finally {
		busyId.value = null
	}
}

const base = (row: Row) => `/apps/timebank/api/layers/${row.layer}/rows/${row.id}`
const assign = (row: Row) => act(row, () => axios.post(generateUrl(`${base(row)}/assign`)))
const release = (row: Row) => act(row, () => axios.delete(generateUrl(`${base(row)}/assign`)))
const setStatus = (row: Row, status: string) =>
	act(row, () => axios.post(generateUrl(`${base(row)}/status`), { status }))

const fieldInputType = (field: Field) => {
	switch (field.type) {
	case 'number': return 'number'
	case 'date': return 'date'
	case 'datetime': return 'datetime-local'
	case 'url': return 'url'
	default: return 'text'
	}
}

const createRow = async () => {
	createError.value = null
	const payload: Record<string, string> = {}
	for (const [key, value] of Object.entries(draft.value)) {
		if (value !== '') payload[key] = value
	}

	try {
		await axios.post(generateUrl(`/apps/timebank/api/layers/${layerName.value}/rows`), payload)
		draft.value = {}
		showCreate.value = false
		await loadRows()
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		createError.value = err.response?.data?.error || 'Could not save the row'
	}
}

watch([layerName, tab, source], loadRows)

onMounted(async () => {
	await loadSchema()
	await loadRows()
})
</script>

<template>
	<div class="organizer-desk">
		<h1>Organizer desk</h1>

		<div class="controls">
			<label>
				Layer
				<select v-model="layerName">
					<option v-for="[name, layer] in layers" :key="name" :value="name">
						{{ layer.title }}
					</option>
				</select>
			</label>

			<div class="tabs" role="tablist">
				<button type="button" :class="{ active: tab === 'unassigned' }" @click="tab = 'unassigned'">
					Unassigned
				</button>
				<button type="button" :class="{ active: tab === 'mine' }" @click="tab = 'mine'">
					Assigned to me
				</button>
				<button type="button" :class="{ active: tab === 'all' }" @click="tab = 'all'">
					All
				</button>
			</div>

			<label>
				Source
				<select v-model="source">
					<option value="">Any</option>
					<option value="form">From a form</option>
					<option value="manual">Entered by hand</option>
				</select>
			</label>

			<NcButton @click="showCreate = !showCreate">
				{{ showCreate ? 'Close' : 'Add a row by hand' }}
			</NcButton>
		</div>

		<form v-if="showCreate" class="create" @submit.prevent="createRow">
			<p class="hint">
				Rows start unpublished. Set publish to yes to show them on the public map.
			</p>
			<label v-for="field in layerFields" :key="field.name" class="field">
				<span>
					{{ field.name }}<span v-if="field.required" class="req">*</span>
					<span class="vis">({{ field.visibility }})</span>
				</span>
				<select v-if="field.choices" v-model="draft[field.name]">
					<option value="">—</option>
					<option v-for="option in schema?.lookups[field.choices] ?? []" :key="option" :value="option">
						{{ option }}
					</option>
				</select>
				<input v-else
					v-model="draft[field.name]"
					:type="fieldInputType(field)"
					:title="field.description">
			</label>
			<label class="field">
				<span>publish <span class="vis">(public)</span></span>
				<select v-model="draft.publish">
					<option value="">no</option>
					<option value="yes">yes</option>
				</select>
			</label>
			<p v-if="createError" class="error">
				{{ createError }}
			</p>
			<NcButton type="submit">
				Save row
			</NcButton>
		</form>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent v-else-if="error" name="Not available">
			<template #description>
				{{ error }}
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="rows.length === 0" name="Nothing here" />

		<ul v-else class="rows">
			<li v-for="row in rows" :key="row.id" class="row">
				<div class="row-main">
					<strong>{{ titleOf(row) }}</strong>
					<span class="meta">
						{{ row.fields.uid }} · {{ row.source === 'form' ? 'from a form' : 'entered by hand' }}
						<span v-if="row.chapter"> · {{ row.chapter }}</span>
						<span v-if="row.fields.zip"> · ZIP {{ row.fields.zip }}</span>
					</span>
					<span v-if="row.assignedTo" class="meta">
						Assigned to {{ row.assignedTo }}<span v-if="row.assignmentStatus"> · {{ row.assignmentStatus }}</span>
					</span>
				</div>

				<div class="row-actions">
					<template v-if="!row.assignedTo">
						<NcButton :disabled="busyId === row.id" @click="assign(row)">
							Assign to me
						</NcButton>
					</template>
					<template v-else-if="tab === 'mine'">
						<select
							v-if="row.assignedTo"
							:value="row.assignmentStatus ?? 'assigned'"
							:disabled="busyId === row.id"
							@change="setStatus(row, ($event.target as HTMLSelectElement).value)">
							<option v-for="status in STATUSES" :key="status" :value="status">
								{{ status }}
							</option>
						</select>
						<NcButton :disabled="busyId === row.id" @click="release(row)">
							Release
						</NcButton>
					</template>
				</div>
			</li>
		</ul>
	</div>
</template>

<style scoped>
.organizer-desk {
	padding: 16px;
	max-width: 1000px;
}

.controls {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
	margin-bottom: 16px;
}

.tabs button {
	padding: 6px 12px;
	background: none;
	border: 1px solid var(--color-border);
	cursor: pointer;
}

.tabs button.active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.create {
	display: grid;
	gap: 8px;
	margin-bottom: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.field {
	display: grid;
	gap: 4px;
}

.req {
	color: var(--color-error);
}

.vis,
.hint,
.meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.error {
	color: var(--color-error);
}

.rows {
	display: grid;
	gap: 8px;
}

.row {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.row-main {
	display: grid;
	gap: 4px;
}

.row-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
