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

interface Entry {
	id: number
	actorId: string
	action: string
	subject: string | null
	details: Record<string, unknown> | null
	createdAt: string
}

const entries = ref<Entry[]>([])
const total = ref(0)
const offset = ref(0)
const limit = 50
const loading = ref(true)

const LABELS: Record<string, string> = {
	'category.created': 'created category',
	'category.updated': 'changed category',
	'category.archived': 'archived category',
	'category.restored': 'restored category',
	'category.deleted': 'deleted category',
	'adjustment.proposed': 'proposed an adjustment for',
	'adjustment.applied': 'applied an adjustment for',
	'adjustment.rejected': 'rejected an adjustment for',
	'adjustment.withdrawn': 'withdrew an adjustment for',
	'adjustment.expired': 'adjustment expired for',
	'setting.adjustment_threshold': 'changed the second-approval threshold',
}

const describe = (entry: Entry) => LABELS[entry.action] ?? entry.action

const detailText = (entry: Entry) => {
	const d = entry.details
	if (!d) return ''
	return Object.entries(d)
		.filter(([, v]) => v !== null && v !== undefined)
		.map(([k, v]) => {
			if (v && typeof v === 'object' && 'from' in v && 'to' in v) {
				const change = v as { from: unknown, to: unknown }
				return `${k}: ${change.from} → ${change.to}`
			}
			return `${k}: ${v}`
		})
		.join(' · ')
}

const load = async () => {
	loading.value = true
	try {
		const { data } = await axios.get(generateUrl(`/apps/timebank/api/governance?limit=${limit}&offset=${offset.value}`))
		entries.value = data.entries
		total.value = data.total
	} finally {
		loading.value = false
	}
}

const page = (delta: number) => {
	offset.value = Math.max(0, offset.value + delta * limit)
	load()
}

onMounted(load)
</script>

<template>
	<div class="governance-log">
		<h1>Governance log</h1>
		<p class="subtitle">
			Decisions that change how hours work, in the open: category changes, manual adjustments and who
			approved them, and setting changes. Entries are only ever added.
		</p>

		<NcLoadingIcon v-if="loading" :size="44" />
		<template v-else>
			<p v-if="entries.length === 0" class="subtitle">
				Nothing has been recorded yet.
			</p>
			<div v-for="entry in entries" :key="entry.id" class="entry">
				<div class="entry-head">
					<span><strong>{{ entry.actorId }}</strong> {{ describe(entry) }}<template v-if="entry.subject"> <strong>{{ entry.subject }}</strong></template></span>
					<span class="when">{{ new Date(entry.createdAt).toLocaleString() }}</span>
				</div>
				<div v-if="detailText(entry)" class="detail">
					{{ detailText(entry) }}
				</div>
			</div>
			<div class="pager">
				<NcButton :disabled="offset === 0" @click="page(-1)">
					Newer
				</NcButton>
				<span>{{ total === 0 ? 0 : offset + 1 }} - {{ Math.min(offset + limit, total) }} of {{ total }}</span>
				<NcButton :disabled="offset + limit >= total" @click="page(1)">
					Older
				</NcButton>
			</div>
		</template>
	</div>
</template>

<style scoped>
.governance-log {
	padding: 20px;
	max-width: 800px;
}

.subtitle,
.when,
.detail {
	color: var(--color-text-maxcontrast);
}

.entry {
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.entry-head {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}

.detail {
	font-size: 13px;
}

.pager {
	display: flex;
	gap: 12px;
	align-items: center;
	margin-top: 16px;
}
</style>
