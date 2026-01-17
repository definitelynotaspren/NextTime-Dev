<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useRequestStore } from '../stores/requestStore'
import { useCategoryStore } from '../stores/categoryStore'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MapComponent from '../components/MapComponent.vue'

const route = useRoute()
const router = useRouter()
const requestStore = useRequestStore()
const categoryStore = useCategoryStore()

const proposedHours = ref('')
const volunteerMessage = ref('')
const newComment = ref('')
const volunteering = ref(false)
const commenting = ref(false)

const requestId = computed(() => parseInt(route.params.id as string))

const category = computed(() => {
	if (!requestStore.currentRequest) return null
	return categoryStore.getCategoryById(requestStore.currentRequest.request.categoryId)
})

const volunteer = async () => {
	if (!proposedHours.value) return

	volunteering.value = true
	try {
		const url = generateUrl(`/apps/timebank/api/requests/${requestId.value}/volunteer`)
		await axios.post(url, {
			proposedHours: parseFloat(proposedHours.value),
			message: volunteerMessage.value || null,
		})
		await requestStore.fetchRequestDetails(requestId.value)
		proposedHours.value = ''
		volunteerMessage.value = ''
	} catch (e) {
		console.error('Error volunteering:', e)
	} finally {
		volunteering.value = false
	}
}

const addComment = async () => {
	if (!newComment.value.trim()) return

	commenting.value = true
	try {
		const url = generateUrl(`/apps/timebank/api/requests/${requestId.value}/comments`)
		await axios.post(url, {
			comment: newComment.value,
		})
		await requestStore.fetchRequestDetails(requestId.value)
		newComment.value = ''
	} catch (e) {
		console.error('Error adding comment:', e)
	} finally {
		commenting.value = false
	}
}

onMounted(() => {
	requestStore.fetchRequestDetails(requestId.value)
})
</script>

<template>
	<div class="request-detail">
		<NcButton @click="router.push({ name: 'Board' })">
			&larr; Back to Board
		</NcButton>

		<NcLoadingIcon v-if="requestStore.loading" :size="44" />

		<div v-else-if="requestStore.currentRequest" class="detail-content">
			<div class="main-section">
				<div class="request-header">
					<span class="category-badge">{{ category?.name || 'Other' }}</span>
					<span class="status-badge" :class="`status-${requestStore.currentRequest.request.status}`">
						{{ requestStore.currentRequest.request.status.replace('_', ' ') }}
					</span>
					<span class="priority-badge" :class="`priority-${requestStore.currentRequest.request.priority}`">
						{{ requestStore.currentRequest.request.priority }}
					</span>
				</div>

				<h1>{{ requestStore.currentRequest.request.title }}</h1>

				<div class="request-meta">
					<span>&#9201; {{ requestStore.currentRequest.request.hoursBudget }} hours budget</span>
					<span v-if="requestStore.currentRequest.request.location">
						&#128205; {{ requestStore.currentRequest.request.location }}
					</span>
					<span v-if="requestStore.currentRequest.request.deadline">
						&#128197; Due: {{ new Date(requestStore.currentRequest.request.deadline).toLocaleDateString() }}
					</span>
				</div>

				<div class="description">
					<h3>Description</h3>
					<p>{{ requestStore.currentRequest.request.description }}</p>
				</div>

				<div class="comments-section">
					<h3>Comments ({{ requestStore.currentRequest.comments.length }})</h3>

					<div class="add-comment">
						<NcTextArea
							v-model="newComment"
							placeholder="Add a comment..."
							:rows="2"
						/>
						<NcButton
							type="primary"
							@click="addComment"
							:disabled="!newComment.trim() || commenting"
						>
							{{ commenting ? 'Posting...' : 'Post Comment' }}
						</NcButton>
					</div>

					<div v-for="comment in requestStore.currentRequest.comments" :key="comment.id" class="comment">
						<div class="comment-header">
							<strong>{{ comment.userId }}</strong>
							<span class="comment-date">{{ new Date(comment.createdAt).toLocaleString() }}</span>
						</div>
						<p>{{ comment.comment }}</p>
					</div>
				</div>
			</div>

			<div class="sidebar">
				<!-- Location Map -->
				<div v-if="requestStore.currentRequest.request.location" class="location-map-section">
					<h3>Location</h3>
					<MapComponent
						:requests="[requestStore.currentRequest.request]"
						:zoom="14"
						class="location-map"
					/>
				</div>

				<div class="volunteer-section">
					<h3>Volunteers ({{ requestStore.currentRequest.volunteers.length }})</h3>

					<div v-if="requestStore.currentRequest.request.status === 'open'" class="volunteer-form">
						<h4>Offer to Help</h4>
						<NcTextField
							v-model="proposedHours"
							type="number"
							placeholder="Hours you can contribute"
							min="0.5"
							step="0.5"
						/>
						<NcTextArea
							v-model="volunteerMessage"
							placeholder="Why are you a good fit? (optional)"
							:rows="2"
						/>
						<NcButton
							type="primary"
							@click="volunteer"
							:disabled="!proposedHours || volunteering"
						>
							{{ volunteering ? 'Submitting...' : 'Volunteer' }}
						</NcButton>
					</div>

					<div class="volunteer-list">
						<div v-for="vol in requestStore.currentRequest.volunteers" :key="vol.id" class="volunteer-card">
							<div class="volunteer-header">
								<strong>{{ vol.volunteerId }}</strong>
								<span class="volunteer-status" :class="`status-${vol.status}`">
									{{ vol.status }}
								</span>
							</div>
							<div class="volunteer-hours">
								{{ vol.proposedHours }} hours proposed
							</div>
							<div v-if="vol.message" class="volunteer-message">
								"{{ vol.message }}"
							</div>
							<div class="volunteer-stats">
								<span>{{ vol.completedInCategory }} completed in category</span>
								<span>{{ vol.totalHoursProvided }} total hours</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<style scoped>
.request-detail {
	padding: 20px;
}

.detail-content {
	display: flex;
	gap: 30px;
	margin-top: 20px;
}

.main-section {
	flex: 2;
}

.sidebar {
	flex: 1;
	min-width: 300px;
}

.request-header {
	display: flex;
	gap: 10px;
	margin-bottom: 10px;
}

.category-badge, .status-badge, .priority-badge {
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 12px;
	text-transform: uppercase;
}

.category-badge {
	background: var(--color-background-hover);
}

.status-badge.status-open {
	background: var(--color-success);
	color: white;
}

.status-badge.status-in_progress {
	background: var(--color-warning);
	color: white;
}

.priority-badge.priority-urgent {
	background: var(--color-error);
	color: white;
}

.request-meta {
	display: flex;
	gap: 20px;
	color: var(--color-text-lighter);
	margin: 10px 0 20px;
}

.description {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: 8px;
	margin-bottom: 20px;
}

.comments-section h3 {
	margin-bottom: 10px;
}

.add-comment {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin-bottom: 20px;
}

.comment {
	background: var(--color-background-hover);
	padding: 12px;
	border-radius: 8px;
	margin-bottom: 10px;
}

.comment-header {
	display: flex;
	justify-content: space-between;
	margin-bottom: 8px;
}

.comment-date {
	color: var(--color-text-lighter);
	font-size: 12px;
}

.location-map-section {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: 8px;
	margin-bottom: 20px;
}

.location-map-section h3 {
	margin-top: 0;
	margin-bottom: 12px;
}

.location-map {
	height: 300px;
	border-radius: 8px;
	overflow: hidden;
}

.volunteer-section {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: 8px;
}

.volunteer-form {
	margin-bottom: 20px;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.volunteer-card {
	background: var(--color-main-background);
	padding: 12px;
	border-radius: 8px;
	margin-bottom: 10px;
	border: 1px solid var(--color-border);
}

.volunteer-header {
	display: flex;
	justify-content: space-between;
	margin-bottom: 8px;
}

.volunteer-status {
	font-size: 12px;
	padding: 2px 6px;
	border-radius: 4px;
}

.volunteer-status.status-offered {
	background: var(--color-primary-light);
}

.volunteer-status.status-accepted {
	background: var(--color-success);
	color: white;
}

.volunteer-hours {
	color: var(--color-primary);
	font-weight: 500;
}

.volunteer-message {
	font-style: italic;
	color: var(--color-text-lighter);
	margin: 8px 0;
}

.volunteer-stats {
	display: flex;
	gap: 16px;
	font-size: 12px;
	color: var(--color-text-lighter);
	margin-top: 8px;
}
</style>
