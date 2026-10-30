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
import NcModal from '@nextcloud/vue/components/NcModal'
import { RouterLink } from 'vue-router'
import { useCategoryStore } from '../stores/categoryStore'

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
	chapters: { slug: string, name: string }[]
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
	/** The request this row was put on the board as, if it was. */
	boardRequestId?: number | null
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

const categoryStore = useCategoryStore()
const openId = ref<number | null>(null)
const boardTarget = ref<Row | null>(null)
const board = ref({ title: '', description: '', categoryId: '', hoursBudget: '' })
const boardError = ref<string | null>(null)
const boarding = ref(false)

const toggleDetails = (row: Row) => {
	openId.value = openId.value === row.id ? null : row.id
}

/**
 * "NO-F3-17" is submission 17 of Nextcloud Forms form 3; "NO-M..." was typed in on the desk.
 * @param row
 */
const formOrigin = (row: Row) => {
	const match = /^[A-Z]{2}-F(\d+)-(\d+)$/.exec(String(row.fields.uid ?? ''))
	return match ? `Nextcloud Forms: form ${match[1]}, submission ${match[2]}` : null
}

const canPutOnBoard = (row: Row) => row.layer === 'needs_offers' && row.fields.need_or_offer === 'need' && !row.boardRequestId

const startBoard = (row: Row) => {
	const summary = String(row.fields.summary ?? '')
	const area = row.fields.area_hint ? ` Area: ${row.fields.area_hint}.` : ''
	board.value = { title: summary.slice(0, 200), description: `${summary}${area}`, categoryId: String(categoryStore.activeCategories[0]?.id ?? ''), hoursBudget: '1' }
	boardError.value = null
	boardTarget.value = row
}

const putOnBoard = async () => {
	const row = boardTarget.value
	if (!row) return
	boarding.value = true
	boardError.value = null
	try {
		await axios.post(generateUrl(`/apps/timebank/api/layers/${row.layer}/rows/${row.id}/board`), {
			title: board.value.title.trim(),
			description: board.value.description.trim(),
			categoryId: parseInt(board.value.categoryId),
			hoursBudget: parseFloat(board.value.hoursBudget),
		})
		boardTarget.value = null
		await loadRows()
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		boardError.value = err.response?.data?.error || 'Could not put it on the board'
	} finally {
		boarding.value = false
	}
}

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
const setPublished = (row: Row, publish: boolean) =>
	act(row, () => axios.post(generateUrl(`${base(row)}/publish`), { publish: publish ? 'yes' : 'no' }))
const release = (row: Row) => act(row, () => axios.delete(generateUrl(`${base(row)}/assign`)))
const setStatus = (row: Row, status: string) =>
	act(row, () => axios.post(generateUrl(`${base(row)}/status`), { status }))
// '' sends the row back to the state of its ZIP. Logged in the governance log.
const moveState = (row: Row, chapter: string) =>
	act(row, () => axios.post(generateUrl(`${base(row)}/state`), { chapter }))

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
	categoryStore.fetchCategories()
	await loadSchema()
	await loadRows()
})
</script>

<template>
	<div class="organizer-desk">
		<h1>Organizer desk</h1>

		<div v-if="schema" class="controls">
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
					<span class="meta">{{ row.fields.publish === 'yes' ? 'Public' : 'Not public' }}</span>
				</div>

				<div class="row-actions">
					<NcButton @click="toggleDetails(row)">
						{{ openId === row.id ? 'Hide details' : 'Details' }}
					</NcButton>
					<RouterLink v-if="row.boardRequestId" :to="{ name: 'RequestDetail', params: { id: row.boardRequestId } }">
						On the request board
					</RouterLink>
					<NcButton v-else-if="canPutOnBoard(row)" :disabled="busyId === row.id" @click="startBoard(row)">
						Put on the board
					</NcButton>
					<NcButton :disabled="busyId === row.id" @click="setPublished(row, row.fields.publish !== 'yes')">
						{{ row.fields.publish === 'yes' ? 'Unpublish' : 'Publish' }}
					</NcButton>
					<template v-if="!row.assignedTo">
						<NcButton :disabled="busyId === row.id" @click="assign(row)">
							Assign to me
						</NcButton>
					</template>
					<select
						:value="''"
						:disabled="busyId === row.id"
						aria-label="Move to another state"
						title="Moves are written to the public Governance Log"
						@change="moveState(row, ($event.target as HTMLSelectElement).value === '-' ? '' : ($event.target as HTMLSelectElement).value)">
						<option value="" disabled>
							Move to state…
						</option>
						<option v-for="c in (schema?.chapters ?? []).filter((c) => c.slug !== row.chapter)" :key="c.slug" :value="c.slug">
							{{ c.name }}
						</option>
						<option value="-">
							Back to its ZIP's state
						</option>
					</select>
					<template v-if="row.assignedTo && tab === 'mine'">
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
				<dl v-if="openId === row.id" class="details">
					<template v-for="[name, value] in Object.entries(row.fields).filter(([, v]) => v !== null && v !== '')" :key="name">
						<dt>{{ name }}</dt>
						<dd>{{ value }}</dd>
					</template>
					<template v-if="formOrigin(row)">
						<dt>origin</dt>
						<dd>{{ formOrigin(row) }}</dd>
					</template>
					<p class="meta">
						You see the fields your role allows. Contact details stay on the desk.
					</p>
				</dl>
			</li>
		</ul>

		<NcModal v-if="boardTarget" size="normal" @close="boardTarget = null">
			<form class="board-dialog" @submit.prevent="putOnBoard">
				<h2>Put this need on the request board</h2>
				<p class="hint">
					Members will see exactly what you type here, plus the ZIP ({{ boardTarget.fields.zip }}). Leave out
					names, addresses and phone numbers. You become the contact: you accept the volunteers, and the board
					shows "posted for the desk". Everything is logged in the governance log.
				</p>
				<label class="field">
					<span>Title</span>
					<input v-model="board.title" type="text" maxlength="200">
				</label>
				<label class="field">
					<span>Description (at least 20 characters)</span>
					<textarea v-model="board.description" rows="4" />
				</label>
				<label class="field">
					<span>Category</span>
					<select v-model="board.categoryId">
						<option v-for="c in categoryStore.activeCategories" :key="c.id" :value="String(c.id)">
							{{ c.name }}
						</option>
					</select>
				</label>
				<label class="field">
					<span>Hours</span>
					<input v-model="board.hoursBudget"
						type="number"
						min="0.25"
						step="0.25">
				</label>
				<p v-if="boardError" class="error" role="alert">
					{{ boardError }}
				</p>
				<div class="dialog-actions">
					<NcButton @click="boardTarget = null">
						Cancel
					</NcButton>
					<NcButton variant="primary" type="submit" :disabled="boarding">
						{{ boarding ? 'Posting...' : 'Put on the board' }}
					</NcButton>
				</div>
			</form>
		</NcModal>
	</div>
</template>

<style scoped>
.organizer-desk {
	padding: 16px;
	max-width: 1000px;
}

.details {
	flex-basis: 100%;
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 8px 0 0;
}

.details dt,
.details dd {
	margin: 0;
	padding: 0;
	min-height: 0;
	text-align: start;
}

.details dt {
	font-weight: 600;
}

.details dd {
	overflow-wrap: anywhere;
}

.details .meta {
	grid-column: 1 / -1;
}

.board-dialog {
	display: grid;
	gap: 10px;
	padding: 20px;
}

.board-dialog .field {
	display: grid;
	gap: 4px;
}

.board-dialog input,
.board-dialog textarea,
.board-dialog select {
	width: 100%;
	box-sizing: border-box;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
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
	flex-wrap: wrap;
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
