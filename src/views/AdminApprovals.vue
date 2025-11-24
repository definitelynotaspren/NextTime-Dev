<script setup lang="ts">
import { ref, onMounted } from 'vue'
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
}

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
		const [pendingRes, votingRes] = await Promise.all([
			axios.get(generateUrl('/apps/timebank/api/earnings/pending')),
			axios.get(generateUrl('/apps/timebank/api/earnings/voting')),
		])
		pendingClaims.value = pendingRes.data
		votingClaims.value = votingRes.data
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		error.value = err.response?.data?.error || 'Failed to fetch claims'
	} finally {
		loading.value = false
	}
}

const approve = async (id: number) => {
	processing.value = id
	try {
		await axios.post(generateUrl(`/apps/timebank/api/earnings/${id}/approve`))
		await fetchClaims()
	} catch (e) {
		console.error('Error approving:', e)
	} finally {
		processing.value = null
	}
}

const reject = async (id: number) => {
	if (!rejectReason.value.trim()) return

	processing.value = id
	try {
		await axios.post(generateUrl(`/apps/timebank/api/earnings/${id}/reject`), {
			reason: rejectReason.value,
		})
		showRejectModal.value = null
		rejectReason.value = ''
		await fetchClaims()
	} catch (e) {
		console.error('Error rejecting:', e)
	} finally {
		processing.value = null
	}
}

const sendToVote = async (id: number) => {
	processing.value = id
	try {
		await axios.post(generateUrl(`/apps/timebank/api/earnings/${id}/send-to-vote`))
		await fetchClaims()
	} catch (e) {
		console.error('Error sending to vote:', e)
	} finally {
		processing.value = null
	}
}

const vote = async (id: number, decision: string) => {
	processing.value = id
	try {
		await axios.post(generateUrl(`/apps/timebank/api/earnings/${id}/vote`), {
			vote: decision,
		})
		await fetchClaims()
	} catch (e) {
		console.error('Error voting:', e)
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
		<h1>Approval Dashboard</h1>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcEmptyContent v-else-if="error" name="Error">
			<template #description>
				{{ error }}
			</template>
		</NcEmptyContent>

		<template v-else>
			<section class="pending-section">
				<h2>Pending Claims ({{ pendingClaims.length }})</h2>

				<div v-if="pendingClaims.length === 0" class="empty-state">
					No pending claims to review.
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
						<div class="claim-actions">
							<NcButton
								type="primary"
								@click="approve(claim.id)"
								:disabled="processing === claim.id"
							>
								Approve
							</NcButton>
							<NcButton
								type="error"
								@click="showRejectModal = claim.id"
								:disabled="processing === claim.id"
							>
								Reject
							</NcButton>
							<NcButton
								@click="sendToVote(claim.id)"
								:disabled="processing === claim.id"
							>
								Send to Vote
							</NcButton>
						</div>

						<div v-if="showRejectModal === claim.id" class="reject-form">
							<NcTextArea
								v-model="rejectReason"
								placeholder="Reason for rejection..."
								:rows="2"
							/>
							<div class="reject-actions">
								<NcButton @click="showRejectModal = null">Cancel</NcButton>
								<NcButton
									type="error"
									@click="reject(claim.id)"
									:disabled="!rejectReason.trim() || processing === claim.id"
								>
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
						<div class="claim-actions">
							<NcButton
								type="primary"
								@click="vote(claim.id, 'approve')"
								:disabled="processing === claim.id"
							>
								Vote Approve
							</NcButton>
							<NcButton
								type="error"
								@click="vote(claim.id, 'reject')"
								:disabled="processing === claim.id"
							>
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
	margin-bottom: 20px;
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
