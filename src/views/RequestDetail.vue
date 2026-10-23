<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getCurrentUser } from '@nextcloud/auth'
import { useRequestStore, errorMessage, type Volunteer } from '../stores/requestStore'
import { useCategoryStore } from '../stores/categoryStore'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MapComponent from '../components/MapComponent.vue'
import VolunteerCard from '../components/VolunteerCard.vue'

const route = useRoute()
const router = useRouter()
const requestStore = useRequestStore()
const categoryStore = useCategoryStore()

const currentUserId = getCurrentUser()?.uid ?? null

const proposedHours = ref('')
const volunteerMessage = ref('')
const newComment = ref('')
const volunteering = ref(false)
const commenting = ref(false)
const commentError = ref<string | null>(null)

// Errors from accept, decline, withdraw and complete, shown in plain words.
const actionError = ref<string | null>(null)
const busyOfferId = ref<number | null>(null)
const showComplete = ref(false)
const completing = ref(false)
const withdrawTarget = ref<Volunteer | null>(null)
const withdrawReason = ref('')
const withdrawing = ref(false)

const MIN_REASON_LENGTH = 3

const requestId = computed(() => parseInt(route.params.id as string))

const category = computed(() => {
	if (!requestStore.currentRequest) return null
	return categoryStore.getCategoryById(requestStore.currentRequest.request.categoryId)
})

const request = computed(() => requestStore.currentRequest?.request ?? null)
const volunteers = computed(() => requestStore.currentRequest?.volunteers ?? [])
const withdrawals = computed(() => requestStore.currentRequest?.withdrawals ?? [])
const isRequester = computed(() => !!request.value && request.value.requesterId === currentUserId)
const isActive = computed(() => request.value?.status === 'open' || request.value?.status === 'in_progress')
const myOffer = computed(() => volunteers.value.find((v) => v.volunteerId === currentUserId) ?? null)
const canOffer = computed(() => isActive.value && !isRequester.value && !myOffer.value)

const acceptedVolunteers = computed(() => volunteers.value.filter((v) => v.status === 'accepted'))
// Everyone who helped: accepted while in progress, completed once it is done.
const helpers = computed(() => volunteers.value.filter((v) => v.status === 'accepted' || v.status === 'completed'))
const helperSummary = computed(() => helpers.value.map((v) => `${v.volunteerId} (${v.proposedHours} h)`).join(', '))

const withdrawReasonRequired = computed(() => withdrawTarget.value?.status === 'accepted')
const canSubmitWithdraw = computed(() => !withdrawReasonRequired.value || withdrawReason.value.trim().length >= MIN_REASON_LENGTH)

const act = async (task: () => Promise<void>) => {
	actionError.value = null
	try {
		await task()
	} catch (e) {
		actionError.value = errorMessage(e)
	}
}

const volunteer = async () => {
	if (!proposedHours.value) return

	volunteering.value = true
	await act(async () => {
		await requestStore.offerHelp(requestId.value, parseFloat(proposedHours.value), volunteerMessage.value || null)
		proposedHours.value = ''
		volunteerMessage.value = ''
	})
	volunteering.value = false
}

const accept = async (offer: Volunteer) => {
	busyOfferId.value = offer.id
	await act(() => requestStore.acceptOffer(offer.id, requestId.value))
	busyOfferId.value = null
}

const decline = async (offer: Volunteer) => {
	busyOfferId.value = offer.id
	await act(() => requestStore.declineOffer(offer.id, requestId.value))
	busyOfferId.value = null
}

const confirmComplete = async () => {
	completing.value = true
	await act(() => requestStore.completeRequest(requestId.value))
	completing.value = false
	showComplete.value = false
}

const startWithdraw = (offer: Volunteer) => {
	withdrawReason.value = ''
	withdrawTarget.value = offer
}

const confirmWithdraw = async () => {
	const target = withdrawTarget.value
	if (!target || !canSubmitWithdraw.value) return

	withdrawing.value = true
	await act(() => requestStore.withdrawOffer(target.id, requestId.value, withdrawReason.value.trim() || null))
	withdrawing.value = false
	withdrawTarget.value = null
}

const addComment = async () => {
	if (!newComment.value.trim()) return

	commenting.value = true
	commentError.value = null
	try {
		await requestStore.addComment(requestId.value, newComment.value)
		newComment.value = ''
	} catch (e) {
		commentError.value = errorMessage(e)
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

				<div v-if="request?.status === 'in_progress' && helpers.length" class="status-banner banner-progress" role="status">
					In progress with {{ helperSummary }}.
				</div>
				<div v-else-if="request?.status === 'completed'" class="status-banner banner-done" role="status">
					Completed<span v-if="helpers.length"> with {{ helperSummary }}</span>.
					Hours are not credited automatically: each volunteer can claim theirs under Submit Earnings,
					and an admin or 3 community votes approve them.
				</div>
				<div v-else-if="request?.status === 'cancelled'" class="status-banner banner-cancelled" role="status">
					This request was cancelled.
				</div>

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

				<div v-if="isRequester && isActive" class="requester-actions">
					<NcButton variant="primary" :disabled="!acceptedVolunteers.length || completing" @click="showComplete = true">
						Mark complete
					</NcButton>
					<span v-if="!acceptedVolunteers.length" class="hint">Accept at least one volunteer to complete this request.</span>
				</div>

				<div v-if="isRequester && withdrawals.length" class="withdrawn-section">
					<h3>Withdrawn help ({{ withdrawals.length }})</h3>
					<div v-for="entry in withdrawals" :key="entry.id" class="withdrawn-entry">
						<strong>{{ entry.volunteerId }}</strong>
						<span class="comment-date">{{ entry.proposedHours }} h, {{ new Date(entry.createdAt).toLocaleDateString() }}</span>
						<p>{{ entry.reason }}</p>
					</div>
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
							variant="primary"
							:disabled="!newComment.trim() || commenting"
							@click="addComment"
						>
							{{ commenting ? 'Posting...' : 'Post Comment' }}
						</NcButton>
						<p v-if="commentError" class="action-error" role="alert">
							{{ commentError }}
						</p>
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
					<h3>Volunteers ({{ volunteers.length }})</h3>

					<p v-if="actionError" class="action-error" role="alert">
						{{ actionError }}
					</p>

					<div v-if="canOffer" class="volunteer-form">
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
							variant="primary"
							:disabled="!proposedHours || volunteering"
							@click="volunteer"
						>
							{{ volunteering ? 'Submitting...' : 'Volunteer' }}
						</NcButton>
					</div>

					<div class="volunteer-list">
						<VolunteerCard
							v-for="vol in volunteers"
							:key="vol.id"
							:volunteer="vol"
							:is-requester="isRequester"
							:is-mine="vol.volunteerId === currentUserId"
							:request-active="isActive"
							:busy="busyOfferId === vol.id"
							@accept="accept"
							@decline="decline"
							@withdraw="startWithdraw" />
					</div>
				</div>
			</div>
		</div>

		<NcModal v-if="showComplete" size="small" @close="showComplete = false">
			<div class="dialog">
				<h2>Mark this request complete?</h2>
				<p>These volunteers will be completed, each for the hours they proposed:</p>
				<ul class="dialog-list">
					<li v-for="vol in acceptedVolunteers" :key="vol.id">
						<strong>{{ vol.volunteerId }}</strong>: {{ vol.proposedHours }} hours
					</li>
				</ul>
				<p class="hint">
					Hours are not credited automatically. Each volunteer can claim theirs under Submit Earnings;
					an admin or 3 community votes approve them. Volunteers you have not accepted are left as they are.
				</p>
				<div class="dialog-actions">
					<NcButton @click="showComplete = false">
						Not yet
					</NcButton>
					<NcButton variant="primary" :disabled="completing" @click="confirmComplete">
						{{ completing ? 'Completing...' : 'Mark complete' }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<NcModal v-if="withdrawTarget" size="small" @close="withdrawTarget = null">
			<div class="dialog">
				<h2>Withdraw your offer?</h2>
				<p v-if="withdrawReasonRequired">
					The requester has accepted your help. Please say why you are stepping back. They will see your
					reason, and it is kept in a log of withdrawn help. The request will reopen if nobody else is helping.
				</p>
				<p v-else>
					Your offer will be removed. You can offer again later.
				</p>
				<NcTextArea
					v-model="withdrawReason"
					:label="withdrawReasonRequired ? 'Reason (required)' : 'Reason (optional)'"
					:rows="3" />
				<div class="dialog-actions">
					<NcButton @click="withdrawTarget = null">
						Keep my offer
					</NcButton>
					<NcButton variant="primary" :disabled="!canSubmitWithdraw || withdrawing" @click="confirmWithdraw">
						{{ withdrawing ? 'Withdrawing...' : 'Withdraw' }}
					</NcButton>
				</div>
			</div>
		</NcModal>
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
	background: var(--color-success-text);
	color: white;
}

.status-badge.status-in_progress {
	background: var(--color-warning-text);
	color: white;
}

.status-badge.status-completed {
	background: var(--color-text-maxcontrast);
	color: white;
}

.status-badge.status-cancelled {
	background: var(--color-error-text);
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

.status-banner {
	padding: 10px 14px;
	border-radius: 8px;
	margin: 12px 0;
	background: var(--color-background-hover);
	border-inline-start: 4px solid var(--color-primary-element);
}

.status-banner.banner-done {
	border-inline-start-color: var(--color-success-text);
}

.status-banner.banner-cancelled {
	border-inline-start-color: var(--color-error-text);
}

.requester-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: center;
	margin: 16px 0;
}

.hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.action-error {
	color: var(--color-error-text);
	margin: 8px 0;
}

.withdrawn-section {
	margin: 16px 0;
	padding: 12px;
	border-radius: 8px;
	background: var(--color-background-hover);
}

.withdrawn-entry p {
	margin: 4px 0 12px;
}

.dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.dialog-list {
	margin: 0;
	padding-inline-start: 20px;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
