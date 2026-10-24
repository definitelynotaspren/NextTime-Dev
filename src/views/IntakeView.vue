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
import { errorMessage } from '../stores/requestStore'

interface FormInfo { id: number, title: string, active: boolean }
interface Enabled { formId: number, layer: string }
interface LogEntry { id: number, formId: number, submissionId: number, status: string, layer: string, uid: string | null, reason: string | null, createdAt: string }
interface Preview { mapped: { question: string, field: string }[], ignored: string[], missing: string[], defaulted: string[] }

const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const busy = ref(false)
const formsInstalled = ref(true)
const forms = ref<FormInfo[]>([])
const layers = ref<{ name: string, title: string }[]>([])
const enabled = ref<Enabled[]>([])
const recent = ref<LogEntry[]>([])
const previews = ref<Record<number, Preview>>({})

const layerOf = (formId: number) => enabled.value.find((e) => e.formId === formId)?.layer ?? null

const load = async () => {
	try {
		const { data } = await axios.get(generateUrl('/apps/timebank/api/intake'))
		formsInstalled.value = data.formsInstalled
		forms.value = data.forms
		layers.value = data.layers
		enabled.value = data.enabled
		recent.value = data.recent
	} catch (e) {
		error.value = errorMessage(e, 'Failed to load')
	} finally {
		loading.value = false
	}
}

const run = async (task: () => Promise<unknown>, done?: string) => {
	busy.value = true
	error.value = null
	notice.value = null
	try {
		await task()
		if (done) notice.value = done
		await load()
	} catch (e) {
		error.value = errorMessage(e)
	} finally {
		busy.value = false
	}
}

const preview = (formId: number, layer: string) => run(async () => {
	const { data } = await axios.get<Preview>(generateUrl(`/apps/timebank/api/intake/forms/${formId}/preview?layer=${layer}`))
	previews.value = { ...previews.value, [formId]: data }
})

const toggle = (formId: number) => {
	const layer = layers.value[0]?.name
	const next = layerOf(formId)
		? enabled.value.filter((e) => e.formId !== formId)
		: [...enabled.value, { formId, layer }]
	return run(() => axios.put(generateUrl('/apps/timebank/api/intake/forms'), { forms: next }), layerOf(formId) ? 'Switched off.' : 'Switched on. New submissions are picked up every five minutes.')
}

const checkNow = () => run(async () => {
	const { data } = await axios.post(generateUrl('/apps/timebank/api/intake/run'))
	notice.value = `${data.imported} imported, ${data.rejected} rejected.`
})

const retry = () => run(async () => {
	const { data } = await axios.post(generateUrl('/apps/timebank/api/intake/retry'))
	notice.value = `Looked again at ${data.cleared} rejected: ${data.imported} imported, ${data.rejected} still rejected.`
})

onMounted(load)
</script>

<template>
	<div class="intake">
		<h1>Form intake</h1>
		<p class="subtitle">
			Forms made in the Nextcloud Forms app can feed the organizer desk. Each new submission becomes one
			unpublished row. Its ID shows where it came from: <code>NO-F3-17</code> is submission 17 of form 3,
			<code>NO-M1A2B3C</code> was typed in on the desk. Only answers to questions that match a desk field
			are kept; the rest are dropped.
		</p>

		<p v-if="error" class="error" role="alert">
			{{ error }}
		</p>
		<p v-if="notice" class="notice" role="status">
			{{ notice }}
		</p>

		<NcLoadingIcon v-if="loading" :size="44" />
		<template v-else>
			<p v-if="!formsInstalled" class="error">
				The Nextcloud Forms app is not installed or enabled on this server.
			</p>

			<template v-else>
				<h2>Your forms</h2>
				<p v-if="forms.length === 0" class="subtitle">
					No forms yet. Create one in the Forms app. Name each question after the desk field it feeds
					(Need or offer, Category, Summary, ZIP, Urgency, Contact route, and so on).
				</p>
				<div v-for="form in forms" :key="form.id" class="card">
					<div class="head">
						<strong>{{ form.title || `Form ${form.id}` }}</strong>
						<span class="tag">{{ layerOf(form.id) ? 'on' : 'off' }}</span>
					</div>
					<div class="actions">
						<NcButton :disabled="busy" @click="preview(form.id, layerOf(form.id) ?? layers[0]?.name ?? 'needs_offers')">
							Check how it maps
						</NcButton>
						<NcButton :variant="layerOf(form.id) ? 'tertiary' : 'primary'" :disabled="busy" @click="toggle(form.id)">
							{{ layerOf(form.id) ? 'Switch off' : 'Switch on' }}
						</NcButton>
					</div>
					<div v-if="previews[form.id]" class="preview">
						<p v-for="m in previews[form.id].mapped" :key="m.question">
							“{{ m.question }}” → <strong>{{ m.field }}</strong>
						</p>
						<p v-if="previews[form.id].ignored.length" class="subtitle">
							Ignored (no matching field, answers not kept): {{ previews[form.id].ignored.join(', ') }}
						</p>
						<p v-if="previews[form.id].missing.length" class="error">
							The form has no question for: {{ previews[form.id].missing.join(', ') }}. Every submission would be rejected.
						</p>
						<p class="subtitle">
							Filled in when not asked: {{ previews[form.id].defaulted.join(', ') }}.
						</p>
					</div>
				</div>

				<div class="actions">
					<NcButton :disabled="busy || enabled.length === 0" @click="checkNow">
						Check now
					</NcButton>
					<NcButton :disabled="busy || enabled.length === 0" @click="retry">
						Look again at rejected
					</NcButton>
				</div>

				<h2>Recent submissions</h2>
				<p v-if="recent.length === 0" class="subtitle">
					Nothing yet.
				</p>
				<div v-for="entry in recent"
					:key="entry.id"
					class="entry"
					:data-status="entry.status">
					<strong>{{ entry.status === 'imported' ? entry.uid : `Form ${entry.formId}, submission ${entry.submissionId}` }}</strong>
					<span class="tag">{{ entry.status }}</span>
					<span class="subtitle">{{ new Date(entry.createdAt).toLocaleString() }}</span>
					<div v-if="entry.reason" class="error">
						{{ entry.reason }}
					</div>
				</div>
			</template>
		</template>
	</div>
</template>

<style scoped>
.intake {
	padding: 20px;
	max-width: 800px;
}

.subtitle {
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error-text);
}

.notice {
	color: var(--color-success-text);
}

.card {
	padding: 12px;
	margin-bottom: 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.head {
	display: flex;
	justify-content: space-between;
}

.actions {
	display: flex;
	gap: 8px;
	margin: 8px 0;
	flex-wrap: wrap;
}

.tag {
	font-size: 12px;
	padding: 2px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
}

.entry {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
	display: flex;
	gap: 10px;
	flex-wrap: wrap;
}
</style>
