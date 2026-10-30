<!--
SPDX-FileCopyrightText: 2026 NextTime contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { useCategoryStore, type Category } from '../stores/categoryStore'
import { errorMessage } from '../stores/requestStore'

interface Usage {
	inProgress: number
	openWithOffers: number
	claimsInFlight: number
	references: number
	canDelete: boolean
}

const categoryStore = useCategoryStore()
const usage = ref<Record<number, Usage>>({})
const loading = ref(true)
const error = ref<string | null>(null)
const busyId = ref<number | null>(null)

const editingId = ref<number | null>(null)
const name = ref('')
const description = ref('')
const earnRate = ref('1.00')
const saving = ref(false)

const load = async () => {
	await categoryStore.fetchCategories()
	const entries = await Promise.all(categoryStore.categories.map(async (c) => {
		try {
			const { data } = await axios.get<Usage>(generateUrl(`/apps/timebank/api/categories/${c.id}/usage`))
			return [c.id, data] as const
		} catch {
			return null
		}
	}))
	usage.value = Object.fromEntries(entries.filter((e) => e !== null))
	loading.value = false
}

const startNew = () => {
	editingId.value = 0
	name.value = ''
	description.value = ''
	earnRate.value = '1.00'
	error.value = null
}

const startEdit = (category: Category) => {
	editingId.value = category.id
	name.value = category.name
	description.value = category.description ?? ''
	earnRate.value = String(category.earnRate)
	error.value = null
}

const run = async (id: number | null, task: () => Promise<unknown>) => {
	busyId.value = id
	error.value = null
	try {
		await task()
		await load()
		return true
	} catch (e) {
		error.value = errorMessage(e)
		return false
	} finally {
		busyId.value = null
	}
}

const save = async () => {
	saving.value = true
	const body = { name: name.value, description: description.value || null, earnRate: parseFloat(earnRate.value) }
	const url = editingId.value ? `/apps/timebank/api/categories/${editingId.value}` : '/apps/timebank/api/categories'
	const ok = await run(editingId.value, () => (editingId.value ? axios.put(generateUrl(url), body) : axios.post(generateUrl(url), body)))
	saving.value = false
	if (ok) editingId.value = null
}

const archive = (c: Category) => run(c.id, () => axios.post(generateUrl(`/apps/timebank/api/categories/${c.id}/archive`)))
const restore = (c: Category) => run(c.id, () => axios.post(generateUrl(`/apps/timebank/api/categories/${c.id}/restore`)))
const remove = (c: Category) => {
	if (!window.confirm(`Delete "${c.name}" for good? This is recorded in the public log.`)) return Promise.resolve(false)
	return run(c.id, () => axios.delete(generateUrl(`/apps/timebank/api/categories/${c.id}`)))
}

const useSummary = (id: number) => {
	const u = usage.value[id]
	if (!u) return ''
	const parts = []
	if (u.inProgress) parts.push(`${u.inProgress} in progress`)
	if (u.openWithOffers) parts.push(`${u.openWithOffers} open with offers`)
	if (u.claimsInFlight) parts.push(`${u.claimsInFlight} claims waiting`)
	if (parts.length) return `In use: ${parts.join(', ')}`
	return u.references ? 'Not in use now; past work uses it' : 'Unused'
}

onMounted(load)
</script>

<template>
	<div class="admin-categories">
		<h1>Service categories</h1>
		<p class="subtitle">
			Archiving hides a category from new requests but keeps history readable. A category in use, or one
			that past work points at, cannot be deleted. Every change is written to the
			<RouterLink :to="{ name: 'GovernanceLog' }">
				public log
			</RouterLink>.
		</p>

		<p v-if="error" class="error" role="alert">
			{{ error }}
		</p>

		<NcLoadingIcon v-if="loading" :size="44" />
		<template v-else>
			<NcButton v-if="editingId === null" variant="primary" @click="startNew">
				New category
			</NcButton>

			<div v-else class="edit-form">
				<h2>{{ editingId ? 'Edit category' : 'New category' }}</h2>
				<NcTextField v-model="name" label="Name" />
				<NcTextField v-model="description" label="Description (optional)" />
				<NcTextField v-model="earnRate"
					label="Earn rate (hours credited per hour worked, up to 5)"
					type="number"
					step="0.05" />
				<div class="actions">
					<NcButton @click="editingId = null">
						Cancel
					</NcButton>
					<NcButton variant="primary" :disabled="saving || !name.trim()" @click="save">
						{{ saving ? 'Saving...' : 'Save' }}
					</NcButton>
				</div>
			</div>

			<table class="categories">
				<thead>
					<tr>
						<th>Name</th>
						<th>Rate</th>
						<th>Use</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<tr v-for="c in categoryStore.categories" :key="c.id" :class="{ archived: c.archived }">
						<td>
							{{ c.name }}
							<span v-if="c.archived" class="tag">archived</span>
						</td>
						<td>{{ c.earnRate }}</td>
						<td>{{ useSummary(c.id) }}</td>
						<td class="row-actions">
							<NcButton :disabled="busyId === c.id" @click="startEdit(c)">
								Edit
							</NcButton>
							<NcButton v-if="!c.archived" :disabled="busyId === c.id" @click="archive(c)">
								Archive
							</NcButton>
							<NcButton v-else :disabled="busyId === c.id" @click="restore(c)">
								Restore
							</NcButton>
							<NcButton variant="error" :disabled="busyId === c.id || !usage[c.id]?.canDelete" @click="remove(c)">
								Delete
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<style scoped>
.admin-categories {
	padding: 20px;
	max-width: 900px;
}

.subtitle {
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error-text);
}

.edit-form {
	display: grid;
	gap: 8px;
	max-width: 480px;
	margin-bottom: 20px;
}

.actions,
.row-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.categories {
	width: 100%;
	border-collapse: collapse;
	margin-top: 16px;
}

.categories th,
.categories td {
	padding: 10px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.archived {
	opacity: 0.7;
}

.tag {
	font-size: 12px;
	padding: 2px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
}
</style>
