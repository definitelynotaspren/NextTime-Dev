<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useCategoryStore } from '../stores/categoryStore'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

interface Earning {
	id: number
	categoryId: number
	hoursClaimed: number
	actualHoursEarned: number
	description: string
	status: string
	createdAt: string
}

const categoryStore = useCategoryStore()

const categoryId = ref<string | null>(null)
const hoursClaimed = ref('')
const description = ref('')
const submitting = ref(false)
const success = ref(false)
const error = ref<string | null>(null)

const myClaims = ref<Earning[]>([])
const loadingClaims = ref(true)

const categoryOptions = computed(() => {
	return categoryStore.categories.map((c) => ({
		id: c.id.toString(),
		label: `${c.name} (${c.earnRate}x rate)`,
	}))
})

const selectedCategory = computed(() => {
	if (!categoryId.value) return null
	return categoryStore.getCategoryById(parseInt(categoryId.value))
})

const calculatedHours = computed(() => {
	if (!hoursClaimed.value || !selectedCategory.value) return 0
	return parseFloat(hoursClaimed.value) * selectedCategory.value.earnRate
})

const isValid = computed(() => {
	return (
		categoryId.value &&
		hoursClaimed.value &&
		parseFloat(hoursClaimed.value) > 0 &&
		description.value.trim()
	)
})

const submit = async () => {
	if (!isValid.value) return

	submitting.value = true
	error.value = null
	success.value = false

	try {
		const url = generateUrl('/apps/timebank/api/earnings/claim')
		await axios.post(url, {
			categoryId: parseInt(categoryId.value!),
			hoursClaimed: parseFloat(hoursClaimed.value),
			description: description.value,
		})
		success.value = true
		categoryId.value = null
		hoursClaimed.value = ''
		description.value = ''
		fetchMyClaims()
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		error.value = err.response?.data?.error || 'Failed to submit claim'
	} finally {
		submitting.value = false
	}
}

const fetchMyClaims = async () => {
	loadingClaims.value = true
	try {
		const url = generateUrl('/apps/timebank/api/earnings/my')
		const response = await axios.get(url)
		myClaims.value = response.data
	} catch (e) {
		console.error('Error fetching claims:', e)
	} finally {
		loadingClaims.value = false
	}
}

const getStatusColor = (status: string) => {
	switch (status) {
		case 'approved':
			return 'var(--color-success)'
		case 'rejected':
			return 'var(--color-error)'
		case 'voting':
			return 'var(--color-warning)'
		default:
			return 'var(--color-primary)'
	}
}

onMounted(() => {
	fetchMyClaims()
})
</script>

<template>
	<div class="submit-earning">
		<div class="form-section">
			<h1>Submit Earning Claim</h1>
			<p class="subtitle">
				Submit hours you've worked for community verification and credit.
			</p>

			<div v-if="success" class="success-message">
				Your claim has been submitted for review!
			</div>

			<div v-if="error" class="error-message">
				{{ error }}
			</div>

			<div class="form-group">
				<label for="category">Service Category *</label>
				<NcSelect
					id="category"
					v-model="categoryId"
					:options="categoryOptions"
					placeholder="Select category"
					:disabled="submitting"
				/>
			</div>

			<div class="form-group">
				<label for="hours">Hours Worked *</label>
				<NcTextField
					id="hours"
					v-model="hoursClaimed"
					type="number"
					placeholder="How many hours did you work?"
					:disabled="submitting"
					min="0.5"
					step="0.5"
				/>
				<div v-if="calculatedHours > 0" class="hours-calculation">
					With {{ selectedCategory?.earnRate }}x multiplier = <strong>{{ calculatedHours.toFixed(2) }} hours earned</strong>
				</div>
			</div>

			<div class="form-group">
				<label for="description">Description *</label>
				<NcTextArea
					id="description"
					v-model="description"
					placeholder="Describe what work you did..."
					:disabled="submitting"
					:rows="4"
				/>
			</div>

			<NcButton
				type="primary"
				@click="submit"
				:disabled="!isValid || submitting"
			>
				{{ submitting ? 'Submitting...' : 'Submit Claim' }}
			</NcButton>
		</div>

		<div class="claims-section">
			<h2>My Claims</h2>

			<NcLoadingIcon v-if="loadingClaims" :size="32" />

			<div v-else-if="myClaims.length === 0" class="no-claims">
				No claims submitted yet.
			</div>

			<div v-else class="claims-list">
				<div v-for="claim in myClaims" :key="claim.id" class="claim-card">
					<div class="claim-header">
						<span
							class="claim-status"
							:style="{ background: getStatusColor(claim.status) }"
						>
							{{ claim.status }}
						</span>
						<span class="claim-date">
							{{ new Date(claim.createdAt).toLocaleDateString() }}
						</span>
					</div>
					<div class="claim-hours">
						{{ claim.hoursClaimed }} hours claimed &rarr; {{ claim.actualHoursEarned }} hours earned
					</div>
					<div class="claim-description">
						{{ claim.description }}
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.submit-earning {
	padding: 20px;
	display: flex;
	gap: 40px;
}

.form-section {
	flex: 1;
	max-width: 500px;
}

.claims-section {
	flex: 1;
}

.subtitle {
	color: var(--color-text-lighter);
	margin-bottom: 20px;
}

.success-message {
	background: var(--color-success);
	color: white;
	padding: 10px;
	border-radius: 4px;
	margin-bottom: 16px;
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

.hours-calculation {
	margin-top: 8px;
	color: var(--color-success);
	font-size: 14px;
}

.no-claims {
	color: var(--color-text-lighter);
	padding: 20px;
	text-align: center;
}

.claims-list {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.claim-card {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: 8px;
}

.claim-header {
	display: flex;
	justify-content: space-between;
	margin-bottom: 8px;
}

.claim-status {
	color: white;
	padding: 2px 8px;
	border-radius: 4px;
	font-size: 12px;
	text-transform: uppercase;
}

.claim-date {
	color: var(--color-text-lighter);
	font-size: 12px;
}

.claim-hours {
	font-weight: 500;
	margin-bottom: 8px;
}

.claim-description {
	color: var(--color-text-lighter);
	font-size: 14px;
}
</style>
