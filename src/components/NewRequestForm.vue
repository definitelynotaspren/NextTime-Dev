<script setup lang="ts">
import { ref, computed, type PropType } from 'vue'
import { useRequestStore, errorMessage, type Request } from '../stores/requestStore'
import { useCategoryStore } from '../stores/categoryStore'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import LocationPicker from './LocationPicker.vue'
import { nearestZip } from '../geo/nearestZip'

const props = defineProps({
	/** When given, the form edits this request instead of posting a new one. */
	request: {
		type: Object as PropType<Request | null>,
		default: null,
	},
})

const emit = defineEmits<{
	(e: 'created'): void
	(e: 'saved'): void
	(e: 'cancel'): void
}>()

const editing = computed(() => props.request !== null)

const requestStore = useRequestStore()
const categoryStore = useCategoryStore()

const title = ref(props.request?.title ?? '')
const description = ref(props.request?.description ?? '')
const categoryId = ref<string | null>(props.request ? String(props.request.categoryId) : null)
const hoursBudget = ref(props.request ? String(props.request.hoursBudget) : '')
const priority = ref(props.request?.priority ?? 'normal')
const deadline = ref(props.request?.deadline ? props.request.deadline.slice(0, 10) : '')
const zip = ref(props.request?.zip ?? '')
const location = ref(props.request?.location ?? '')
const zipValid = computed(() => /^\d{5}(-\d{4})?$/.test(zip.value.trim()))
// The exact location (address text or pin) is sent only when this is ticked. Unticked, a dropped
// pin is used in the browser to fill in the ZIP and never leaves the device.
const saveExact = ref(!!props.request?.location)
const showUsername = ref(props.request?.showUsername ?? false)
const zipHint = ref<string | null>(null)
let zipFromPin = false

const onPin = async ({ lat, lng }: { lat: number, lng: number }) => {
	// Fill the ZIP from the pin unless the requester typed one themselves.
	if (zip.value.trim() !== '' && !zipFromPin) return
	try {
		const found = await nearestZip(lat, lng)
		if (found) {
			zip.value = found.zip
			zipFromPin = true
			zipHint.value = `Filled in from your pin: ${found.zip}. Near a ZIP border this can be wrong, so check it.`
		} else {
			zipHint.value = 'No US ZIP code near that pin. Please type the ZIP code.'
		}
	} catch {
		zipHint.value = 'Could not look up a ZIP for that pin. Please type the ZIP code.'
	}
}
const onZipTyped = () => {
	zipFromPin = false
	zipHint.value = null
}
const submitting = ref(false)
const error = ref<string | null>(null)

const categoryOptions = computed(() => {
	// An archived category stays selectable on a request that already has it.
	const keep = props.request?.categoryId
	return categoryStore.categories.filter((c) => !c.archived || c.id === keep).map((c) => ({
		id: c.id.toString(),
		label: c.name,
	}))
})

const priorityOptions = [
	{ id: 'urgent', label: 'Urgent' },
	{ id: 'high', label: 'High' },
	{ id: 'normal', label: 'Normal' },
	{ id: 'low', label: 'Low' },
]

const isValid = computed(() => {
	return (
		title.value.trim()
		&& description.value.trim()
		&& categoryId.value
		&& hoursBudget.value
		&& parseFloat(hoursBudget.value) > 0
		&& zipValid.value
	)
})

const submit = async () => {
	if (!isValid.value) return

	submitting.value = true
	error.value = null

	const fields = {
		title: title.value,
		description: description.value,
		categoryId: parseInt(categoryId.value!),
		hoursBudget: parseFloat(hoursBudget.value),
		priority: priority.value,
		deadline: deadline.value || undefined,
		zip: zip.value.trim(),
		showUsername: showUsername.value,
		// Unticked: nothing exact is sent; an edit sends '' so a saved location is removed.
		location: saveExact.value ? (location.value || undefined) : (editing.value ? '' : undefined),
	}

	try {
		if (props.request) {
			await requestStore.updateRequest(props.request.id, fields)
			emit('saved')
		} else {
			await requestStore.createRequest(fields)
			emit('created')
		}
	} catch (e: unknown) {
		error.value = errorMessage(e, editing.value ? 'Failed to save changes' : 'Failed to create request')
	} finally {
		submitting.value = false
	}
}
</script>

<template>
	<div class="new-request-form">
		<h2>{{ editing ? 'Edit request' : 'Post a New Request' }}</h2>

		<div v-if="error" class="error-message">
			{{ error }}
		</div>

		<div class="form-group">
			<label for="title">Title *</label>
			<NcTextField
				id="title"
				v-model="title"
				placeholder="What do you need help with?"
				:disabled="submitting" />
		</div>

		<div class="form-group">
			<label for="description">Description *</label>
			<NcTextArea
				id="description"
				v-model="description"
				placeholder="Describe what you need in detail..."
				:disabled="submitting"
				:rows="4" />
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="category">Category *</label>
				<NcSelect
					id="category"
					v-model="categoryId"
					:options="categoryOptions"
					:reduce="(option) => option.id"
					placeholder="Select category"
					:disabled="submitting" />
			</div>

			<div class="form-group">
				<label for="hours">Hours Budget *</label>
				<NcTextField
					id="hours"
					v-model="hoursBudget"
					type="number"
					placeholder="Estimated hours"
					:disabled="submitting"
					min="0.5"
					step="0.5" />
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="priority">Priority</label>
				<NcSelect
					id="priority"
					v-model="priority"
					:options="priorityOptions"
					:reduce="(option) => option.id"
					:disabled="submitting" />
			</div>

			<div class="form-group">
				<label for="deadline">Deadline (optional)</label>
				<NcTextField
					id="deadline"
					v-model="deadline"
					type="date"
					:disabled="submitting" />
			</div>
		</div>

		<div class="form-group">
			<label for="zip">ZIP code *</label>
			<NcTextField
				id="zip"
				v-model="zip"
				placeholder="For example 97055, or drop a pin below"
				inputmode="numeric"
				autocomplete="postal-code"
				:disabled="submitting"
				@update:model-value="onZipTyped" />
			<p v-if="zipHint" class="hint zip-hint" role="status">
				{{ zipHint }}
			</p>
			<p class="hint">
				Everyone sees the ZIP, and the map shows the request at the middle of the ZIP area.
			</p>
		</div>

		<div class="form-group">
			<LocationPicker
				v-model="location"
				:show-map="true"
				@pin="onPin" />
			<label class="save-exact">
				<input
					id="save-exact"
					v-model="saveExact"
					type="checkbox"
					:disabled="submitting">
				Also save this exact location. Only you, your state's facilitators and volunteers you accept will see it.
			</label>
			<p v-if="!saveExact && location" class="hint">
				Not saved: only the ZIP is sent. Your pin stays on this device.
			</p>
		</div>

		<div class="form-group">
			<label class="save-exact">
				<input
					id="show-username"
					v-model="showUsername"
					type="checkbox"
					:disabled="submitting">
				Show my username on this request. Unticked, other members see "a member who keeps their username
				private". Facilitators of your state, national organizers, admins and the volunteers you accept
				can always see it.
			</label>
		</div>

		<div class="form-actions">
			<NcButton :disabled="submitting" @click="$emit('cancel')">
				Cancel
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!isValid || submitting"
				@click="submit">
				{{ submitting ? (editing ? 'Saving...' : 'Posting...') : (editing ? 'Save changes' : 'Post Request') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.new-request-form {
	padding: 20px;
	max-width: 600px;
}

.save-exact {
	display: flex;
	gap: 8px;
	align-items: flex-start;
	margin-top: 8px;
}

.zip-hint {
	color: var(--color-main-text);
}

.hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.new-request-form h2 {
	margin-top: 0;
	margin-bottom: 20px;
}

.error-message {
	background: var(--color-error);
	color: white;
	padding: 10px;
	border-radius: 4px;
	margin-bottom: 16px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 20px;
}
</style>
