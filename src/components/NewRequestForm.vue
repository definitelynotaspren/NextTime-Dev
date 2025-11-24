<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRequestStore } from '../stores/requestStore'
import { useCategoryStore } from '../stores/categoryStore'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcSelect from '@nextcloud/vue/components/NcSelect'

const emit = defineEmits<{
	(e: 'created'): void
	(e: 'cancel'): void
}>()

const requestStore = useRequestStore()
const categoryStore = useCategoryStore()

const title = ref('')
const description = ref('')
const categoryId = ref<string | null>(null)
const hoursBudget = ref('')
const priority = ref('normal')
const deadline = ref('')
const location = ref('')
const submitting = ref(false)
const error = ref<string | null>(null)

const categoryOptions = computed(() => {
	return categoryStore.categories.map((c) => ({
		id: c.id.toString(),
		label: c.name,
	}))
})

const priorityOptions = [
	{ id: 'urgent', label: 'Urgent' },
	{ id: 'normal', label: 'Normal' },
	{ id: 'flexible', label: 'Flexible' },
]

const isValid = computed(() => {
	return (
		title.value.trim() &&
		description.value.trim() &&
		categoryId.value &&
		hoursBudget.value &&
		parseFloat(hoursBudget.value) > 0
	)
})

const submit = async () => {
	if (!isValid.value) return

	submitting.value = true
	error.value = null

	try {
		await requestStore.createRequest({
			title: title.value,
			description: description.value,
			categoryId: parseInt(categoryId.value!),
			hoursBudget: parseFloat(hoursBudget.value),
			priority: priority.value,
			deadline: deadline.value || undefined,
			location: location.value || undefined,
		})
		emit('created')
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		error.value = err.response?.data?.error || 'Failed to create request'
	} finally {
		submitting.value = false
	}
}
</script>

<template>
	<div class="new-request-form">
		<h2>Post a New Request</h2>

		<div v-if="error" class="error-message">
			{{ error }}
		</div>

		<div class="form-group">
			<label for="title">Title *</label>
			<NcTextField
				id="title"
				v-model="title"
				placeholder="What do you need help with?"
				:disabled="submitting"
			/>
		</div>

		<div class="form-group">
			<label for="description">Description *</label>
			<NcTextArea
				id="description"
				v-model="description"
				placeholder="Describe what you need in detail..."
				:disabled="submitting"
				:rows="4"
			/>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="category">Category *</label>
				<NcSelect
					id="category"
					v-model="categoryId"
					:options="categoryOptions"
					placeholder="Select category"
					:disabled="submitting"
				/>
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
					step="0.5"
				/>
			</div>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="priority">Priority</label>
				<NcSelect
					id="priority"
					v-model="priority"
					:options="priorityOptions"
					:disabled="submitting"
				/>
			</div>

			<div class="form-group">
				<label for="deadline">Deadline (optional)</label>
				<NcTextField
					id="deadline"
					v-model="deadline"
					type="date"
					:disabled="submitting"
				/>
			</div>
		</div>

		<div class="form-group">
			<label for="location">Location (optional)</label>
			<NcTextField
				id="location"
				v-model="location"
				placeholder="Where is help needed?"
				:disabled="submitting"
			/>
		</div>

		<div class="form-actions">
			<NcButton @click="$emit('cancel')" :disabled="submitting">
				Cancel
			</NcButton>
			<NcButton
				type="primary"
				@click="submit"
				:disabled="!isValid || submitting"
			>
				{{ submitting ? 'Posting...' : 'Post Request' }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.new-request-form {
	padding: 20px;
	max-width: 600px;
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
