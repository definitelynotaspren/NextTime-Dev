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
import { errorMessage } from '../stores/requestStore'

interface Added { formId: number, layer: string, title: string, exists: boolean }
interface LogEntry { id: number, formId: number, submissionId: number, status: string, layer: string, uid: string | null, reason: string | null, createdAt: string }
interface Preview { mapped: { question: string, field: string }[], ignored: string[], missing: string[], defaulted: string[] }
interface Found { id: number, title: string, questions: number, owner: string, layer: string, preview: Preview, alreadyAdded: boolean }

const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const busy = ref(false)
const formsInstalled = ref(true)
const added = ref<Added[]>([])
const layers = ref<{ name: string, title: string }[]>([])
const recent = ref<LogEntry[]>([])
const previews = ref<Record<number, Preview>>({})

const link = ref('')
const found = ref<Found | null>(null)
const lookupError = ref<string | null>(null)

const load = async () => {
	try {
		const { data } = await axios.get(generateUrl('/apps/timebank/api/intake'))
		formsInstalled.value = data.formsInstalled
		added.value = data.forms
		layers.value = data.layers
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

const lookUp = async () => {
	busy.value = true
	lookupError.value = null
	found.value = null
	try {
		const { data } = await axios.post<Found>(generateUrl('/apps/timebank/api/intake/forms/lookup'), { link: link.value })
		found.value = data
	} catch (e) {
		lookupError.value = errorMessage(e, 'Could not look that up')
	} finally {
		busy.value = false
	}
}

const saveList = (forms: { formId: number, layer: string }[]) =>
	axios.put(generateUrl('/apps/timebank/api/intake/forms'), { forms })

const addFound = () => {
	const f = found.value
	if (!f) return Promise.resolve()
	const next = [...added.value.map(({ formId, layer }) => ({ formId, layer })), { formId: f.id, layer: f.layer }]
	return run(async () => {
		await saveList(next)
		found.value = null
		link.value = ''
	}, `Added "${f.title}". New submissions are picked up every five minutes, or press Check now.`)
}

const remove = (formId: number) =>
	run(() => saveList(added.value.filter((a) => a.formId !== formId).map(({ formId: id, layer }) => ({ formId: id, layer }))),
		'Removed. Rows already imported stay on the desk.')

const preview = (formId: number, layer: string) => run(async () => {
	const { data } = await axios.get<Preview>(generateUrl(`/apps/timebank/api/intake/forms/${formId}/preview?layer=${layer}`))
	previews.value = { ...previews.value, [formId]: data }
})

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
				<h2>Add a form</h2>
				<div class="card">
					<p>
						<strong>Which link to paste.</strong> Make the form in the Nextcloud <em>Forms</em> app on this
						server, then copy its link from there:
					</p>
					<ol>
						<li>
							Open the form in Forms and choose <strong>Share</strong> and copy the <strong>share link</strong>. It looks like
							<code>https://your-server/index.php/apps/forms/s/AbCdEf123456</code>.
						</li>
						<li>
							Or copy the address from your browser while the form is open in Forms:
							<code>https://your-server/index.php/apps/forms/AbCdEf123456</code> (also fine with
							<code>/edit</code> or <code>/results</code> on the end).
						</li>
					</ol>
					<p class="subtitle">
						The long code at the end is what identifies the form. It has to be a form on <em>this</em> Nextcloud:
						links to Google Forms, other servers, or a downloaded results file will not work. The form's questions
						must be named after desk fields (Need or offer, Category, Summary, ZIP, Urgency, Contact route); the next
						step shows how each question would be used before anything is added.
					</p>
					<NcTextField v-model="link"
						label="Paste the form's link"
						placeholder="https://your-server/index.php/apps/forms/s/..."
						@keyup.enter="lookUp" />
					<div class="actions">
						<NcButton variant="primary" :disabled="busy || !link.trim()" @click="lookUp">
							Look up this form
						</NcButton>
					</div>
					<p v-if="lookupError" class="error" role="alert">
						{{ lookupError }}
					</p>

					<div v-if="found" class="preview" data-test="found">
						<p>
							<strong>{{ found.title || `Form ${found.id}` }}</strong>
							<span class="subtitle"> · {{ found.questions }} questions · made by {{ found.owner }}</span>
						</p>
						<p v-for="m in found.preview.mapped" :key="m.question">
							“{{ m.question }}” → <strong>{{ m.field }}</strong>
						</p>
						<p v-if="found.preview.ignored.length" class="subtitle">
							Ignored (no matching field, answers not kept): {{ found.preview.ignored.join(', ') }}
						</p>
						<p v-if="found.preview.missing.length" class="error">
							This form has no question for: {{ found.preview.missing.join(', ') }}. Every submission would be rejected until it does.
						</p>
						<p class="subtitle">
							Filled in when not asked: {{ found.preview.defaulted.join(', ') }}.
						</p>
						<p v-if="found.alreadyAdded" class="notice">
							This form is already added.
						</p>
						<NcButton v-else
							variant="primary"
							:disabled="busy"
							@click="addFound">
							Add this form
						</NcButton>
					</div>
				</div>

				<h2>Added forms</h2>
				<p v-if="added.length === 0" class="subtitle">
					None yet. Add one above.
				</p>
				<div v-for="form in added" :key="form.formId" class="card">
					<div class="head">
						<strong>{{ form.exists ? (form.title || `Form ${form.formId}`) : `Form ${form.formId} (no longer exists)` }}</strong>
						<span class="tag">checked every 5 minutes</span>
					</div>
					<div class="actions">
						<NcButton :disabled="busy || !form.exists" @click="preview(form.formId, form.layer)">
							Check how it maps
						</NcButton>
						<NcButton variant="tertiary" :disabled="busy" @click="remove(form.formId)">
							Remove
						</NcButton>
					</div>
					<div v-if="previews[form.formId]" class="preview">
						<p v-for="m in previews[form.formId].mapped" :key="m.question">
							“{{ m.question }}” → <strong>{{ m.field }}</strong>
						</p>
						<p v-if="previews[form.formId].ignored.length" class="subtitle">
							Ignored: {{ previews[form.formId].ignored.join(', ') }}
						</p>
						<p v-if="previews[form.formId].missing.length" class="error">
							No question for: {{ previews[form.formId].missing.join(', ') }}.
						</p>
					</div>
				</div>

				<div class="actions">
					<NcButton :disabled="busy || added.length === 0" @click="checkNow">
						Check now
					</NcButton>
					<NcButton :disabled="busy || added.length === 0" @click="retry">
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
