<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getCurrentUser } from '@nextcloud/auth'
import { errorMessage } from '../stores/requestStore'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'

interface Earning {
	id: number
	userId: string
	categoryId: number
	hoursClaimed: number
	actualHoursEarned: number
	description: string
	status: string
	createdAt: string
	votes?: { voterId: string }[]
	decidingAs?: 'admin' | 'facilitator' | 'national' | null
	decidingChapter?: string | null
	/** Pending claims: you may approve, reject or send it to a vote. */
	canDecide?: boolean
	/** Claims in a vote: you are a chapter member of the claimant's state and have not voted. */
	canVote?: boolean
	approveCount?: number
	rejectCount?: number
}

const currentUserId = getCurrentUser()?.uid ?? null
const actionError = ref<string | null>(null)

const pendingClaims = ref<Earning[]>([])
const votingClaims = ref<Earning[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const processing = ref<number | null>(null)
const rejectReason = ref('')
const showRejectModal = ref<number | null>(null)

const fetchClaims = async () => {
	loading.value = true
	error.value = null

	try {
		// Everyone sees every claim; the server marks the ones you may act on.
		const [pendingRes, votingRes] = await Promise.all([
			axios.get(generateUrl('/apps/timebank/api/earnings/pending')),
			axios.get(generateUrl('/apps/timebank/api/earnings/voting')),
		])
		pendingClaims.value = pendingRes.data
		votingClaims.value = votingRes.data
	} catch (e: unknown) {
		error.value = errorMessage(e, 'Failed to fetch claims')
	} finally {
		loading.value = false
	}
}

const approve = async (id: number) => {
	actionError.value = null
	processing.value = id
	try {
		await axios.post(generateUrl(`/apps/timebank/api/earnings/${id}/approve`))
		await fetchClaims()
	} catch (e) {
		actionError.value = errorMessage(e)
	} finally {
		processing.value = null
	}
}

const reject = async (id: number) => {
	if (!rejectReason.value.trim()) return

	actionError.value = null
	processing.value = id
	try {
		await axios.post(generateUrl(`/apps/timebank/api/earnings/${id}/reject`), {
			reason: rejectReason.value,
		})
		showRejectModal.value = null
		rejectReason.value = ''
		await fetchClaims()
	} catch (e) {
		actionError.value = errorMessage(e)
	} finally {
		processing.value = null
	}
}

const sendToVote = async (id: number) => {
	actionError.value = null
	processing.value = id
	try {
		await axios.post(generateUrl(`/apps/timebank/api/earnings/${id}/send-to-vote`))
		await fetchClaims()
	} catch (e) {
		actionError.value = errorMessage(e)
	} finally {
		processing.value = null
	}
}

const vote = async (id: number, decision: string) => {
	actionError.value = null
	processing.value = id
	try {
		await axios.post(generateUrl(`/apps/timebank/api/earnings/${id}/vote`), {
			vote: decision,
		})
		await fetchClaims()
	} catch (e) {
		actionError.value = errorMessage(e)
	} finally {
		processing.value = null
	}
}

onMounted(() => {
	fetchClaims()
})
</script>

<template>
	<div class="admin-approvals">
		<h1>Approvals &amp; Votes</h1>
		<p class="intro">
			Every claim is shown to every member. A claim is approved by a facilitator of the claimant's state, a national
			organizer or an admin, or sent to a vote of the state's members (national organizers vote when the state has
			fewer than three people who could). Buttons appear only where you may act; nobody decides on their own claim.
		</p>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent v-else-if="error" name="Error">
			<template #description>
				{{ error }}
			</template>
		</NcEmptyContent>

		<template v-else>
			<p v-if="actionError" class="action-error" role="alert">
				{{ actionError }}
			</p>
			<section class="pending-section">
				<h2>Pending Claims ({{ pendingClaims.length }})</h2>

				<div v-if="pendingClaims.length === 0" class="empty-state">
					No pending claims.
				</div>

				<div v-else class="claims-list">
					<div v-for="claim in pendingClaims" :key="claim.id" class="claim-card">
						<div class="claim-header">
							<strong>{{ claim.userId }}</strong>
							<span class="claim-date">{{ new Date(claim.createdAt).toLocaleDateString() }}</span>
						</div>
						<div class="claim-hours">
							{{ claim.hoursClaimed }} hours claimed &rarr;
							<strong>{{ claim.actualHoursEarned }} hours to be earned</strong>
						</div>
						<div class="claim-description">
							{{ claim.description }}
						</div>
						<p v-if="claim.userId === currentUserId" class="own-claim">
							This is your claim. Someone else has to approve it, reject it or send it to a vote.
						</p>
						<p v-else-if="claim.canDecide" class="deciding-as">
							You decide on this {{ claim.decidingAs === 'admin' ? 'as an admin' : claim.decidingAs === 'facilitator' ? `as a facilitator of ${claim.decidingChapter}` : 'as a national organizer' }}.
						</p>
						<p v-else class="deciding-as">
							View only: a facilitator of this member's state or a national organizer decides.
						</p>
						<div v-if="claim.canDecide && claim.userId !== currentUserId" class="claim-actions">
							<NcButton
								variant="primary"
								:disabled="processing === claim.id"
								@click="approve(claim.id)">
								Approve
							</NcButton>
							<NcButton
								variant="error"
								:disabled="processing === claim.id"
								@click="showRejectModal = claim.id">
								Reject
							</NcButton>
							<NcButton
								:disabled="processing === claim.id"
								@click="sendToVote(claim.id)">
								Send to Vote
							</NcButton>
						</div>

						<div v-if="showRejectModal === claim.id" class="reject-form">
							<NcTextArea
								v-model="rejectReason"
								placeholder="Reason for rejection..."
								:rows="2" />
							<div class="reject-actions">
								<NcButton @click="showRejectModal = null">
									Cancel
								</NcButton>
								<NcButton
									variant="error"
									:disabled="!rejectReason.trim() || processing === claim.id"
									@click="reject(claim.id)">
									Confirm Reject
								</NcButton>
							</div>
						</div>
					</div>
				</div>
			</section>

			<section class="voting-section">
				<h2>In Voting ({{ votingClaims.length }})</h2>

				<div v-if="votingClaims.length === 0" class="empty-state">
					No claims currently in voting.
				</div>

				<div v-else class="claims-list">
					<div v-for="claim in votingClaims" :key="claim.id" class="claim-card">
						<div class="claim-header">
							<strong>{{ claim.userId }}</strong>
							<span class="vote-count">
								{{ claim.approveCount }} approve / {{ claim.rejectCount }} reject
							</span>
						</div>
						<div class="claim-hours">
							{{ claim.hoursClaimed }} hours &rarr; {{ claim.actualHoursEarned }} hours
						</div>
						<div class="claim-description">
							{{ claim.description }}
						</div>
						<p v-if="claim.userId === currentUserId" class="own-claim">
							This is your claim, so you cannot vote on it.
						</p>
						<p v-else-if="claim.votes?.some((v) => v.voterId === currentUserId)" class="own-claim">
							You have voted on this claim.
						</p>
						<p v-else-if="!claim.canVote" class="deciding-as">
							View only: chapter members of this member's state vote on it.
						</p>
						<div v-else class="claim-actions">
							<NcButton
								variant="primary"
								:disabled="processing === claim.id"
								@click="vote(claim.id, 'approve')">
								Vote Approve
							</NcButton>
							<NcButton
								variant="error"
								:disabled="processing === claim.id"
								@click="vote(claim.id, 'reject')">
								Vote Reject
							</NcButton>
						</div>
					</div>
				</div>
			</section>
		</template>
	</div>
</template>

<style scoped>
.admin-approvals {
	padding: 20px;
}

.admin-approvals h1 {
	margin-bottom: 8px;
}

.intro {
	max-width: 760px;
	margin-bottom: 20px;
	color: var(--color-text-maxcontrast);
}

.deciding-as {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.own-claim {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.action-error {
	color: var(--color-error-text);
}

section {
	margin-bottom: 40px;
}

section h2 {
	margin-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.empty-state {
	color: var(--color-text-lighter);
	padding: 20px;
	text-align: center;
	background: var(--color-background-hover);
	border-radius: 8px;
}

.claims-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
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

.claim-date, .vote-count {
	color: var(--color-text-lighter);
	font-size: 14px;
}

.claim-hours {
	margin-bottom: 8px;
}

.claim-description {
	color: var(--color-text-lighter);
	margin-bottom: 12px;
}

.claim-actions {
	display: flex;
	gap: 8px;
}

.reject-form {
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.reject-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}
</style>
