<!--
SPDX-FileCopyrightText: 2026 NextTime-Dev contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { errorMessage } from '../stores/requestStore'

interface Adjustment {
	id: number
	userId: string
	hours: number
	reason: string
	proposerId: string
	status: string
	approverId: string | null
	decisionNote: string | null
	createdAt: string
	expiresAt: string
	canDecide: boolean
	canWithdraw: boolean
	canVote: boolean
	/** Present when the adjustment is or was decided by a vote. Votes are public. */
	vote?: {
		approve: string[]
		reject: string[]
		votes: number
		eligible: number
		needed: number
		closes: string
		myVote: 'approve' | 'reject' | null
	}
}

// Days left, rounded up, for "closes in N days".
const daysLeft = (when: string) => Math.max(0, Math.ceil((new Date(when.replace(' ', 'T')).getTime() - Date.now()) / 86400000))

const adjustments = ref<Adjustment[]>([])
const openAdjustments = computed(() => adjustments.value.filter((a) => ['pending', 'voting'].includes(a.status)))
const threshold = ref(5)
const canPropose = ref(false)
const isAdmin = ref(false)
const minVotes = ref(3)
const expiryDays = ref(14)
const loading = ref(true)
const error = ref<string | null>(null)
const notice = ref<string | null>(null)
const busy = ref(false)

const memberId = ref('')
const hours = ref('')
const reason = ref('')
const rejectingId = ref<number | null>(null)
const rejectNote = ref('')
const newThreshold = ref('')
const thresholdReason = ref('')

const load = async () => {
	try {
		const { data } = await axios.get(generateUrl('/apps/timebank/api/adjustments'))
		adjustments.value = data.adjustments
		threshold.value = data.threshold
		canPropose.value = data.canPropose
		isAdmin.value = data.isAdmin
		minVotes.value = data.minVotes
		expiryDays.value = data.expiryDays
		newThreshold.value = String(data.threshold)
	} catch (e) {
		error.value = errorMessage(e, 'Failed to load adjustments')
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
		return true
	} catch (e) {
		error.value = errorMessage(e)
		return false
	} finally {
		busy.value = false
	}
}

const propose = async () => {
	const h = parseFloat(hours.value)
	const ok = await run(async () => {
		const { data } = await axios.post(generateUrl('/apps/timebank/api/adjustments'), {
			userId: memberId.value.trim(),
			hours: h,
			reason: reason.value,
		})
		notice.value = data.status === 'approved'
			? 'Applied. It is in the ledger and the public log.'
			: data.status === 'voting'
				? `Proposed. It is now a vote of ${data.userId}'s state, open for ${expiryDays.value} days.`
				: `Proposed. It needs a second person (another facilitator of ${data.userId}'s state, a national organizer or an admin) and expires in ${expiryDays.value} days.`
	})
	if (ok) {
		memberId.value = ''
		hours.value = ''
		reason.value = ''
	}
}

const approve = (a: Adjustment) => run(() => axios.post(generateUrl(`/apps/timebank/api/adjustments/${a.id}/approve`)), 'Approved and applied.')
const castVote = (a: Adjustment, vote: 'approve' | 'reject') =>
	run(() => axios.post(generateUrl(`/apps/timebank/api/adjustments/${a.id}/vote`), { vote }), 'Your vote is counted. Votes are public.')
const withdraw = (a: Adjustment) => run(() => axios.post(generateUrl(`/apps/timebank/api/adjustments/${a.id}/withdraw`)), 'Withdrawn.')
const reject = async (a: Adjustment) => {
	const ok = await run(() => axios.post(generateUrl(`/apps/timebank/api/adjustments/${a.id}/reject`), { note: rejectNote.value }), 'Rejected.')
	if (ok) {
		rejectingId.value = null
		rejectNote.value = ''
	}
}
const saveThreshold = async () => {
	const ok = await run(() => axios.put(generateUrl('/apps/timebank/api/settings/adjustment-threshold'), {
		hours: parseFloat(newThreshold.value),
		reason: thresholdReason.value,
	}), 'Threshold changed and logged.')
	if (ok) thresholdReason.value = ''
}

onMounted(load)
</script>

<template>
	<div class="adjustments">
		<h1>Balance adjustments</h1>
		<p class="subtitle">
			A facilitator proposes adjustments for members of their own state; a national organizer for anyone.
			Up to {{ threshold }} hours, a second facilitator of that state or a national organizer approves it.
			Anything larger goes to a {{ expiryDays }}-day vote of the state's chapter members: it passes with at least
			{{ minVotes }} votes and more for than against. Nobody adjusts, approves or votes on their own.
			Every step is public in the
			<RouterLink :to="{ name: 'GovernanceLog' }">
				governance log
			</RouterLink>.
		</p>
		<p v-if="isAdmin" class="subtitle">
			As an admin you can still adjust up to {{ threshold }} hours alone, and larger amounts with a second person.
			Use it as a last resort; it is logged like everything else.
		</p>

		<p v-if="error" class="error" role="alert">
			{{ error }}
		</p>
		<p v-if="notice" class="notice" role="status">
			{{ notice }}
		</p>

		<NcLoadingIcon v-if="loading" :size="44" />
		<template v-else>
			<section v-if="canPropose" class="card">
				<h2>Adjust a balance</h2>
				<NcTextField v-model="memberId" label="Member (Nextcloud user name)" />
				<NcTextField v-model="hours"
					label="Hours (negative to deduct)"
					type="number"
					step="0.25" />
				<NcTextArea v-model="reason" label="Reason (public, 10+ characters)" :rows="2" />
				<NcButton variant="primary" :disabled="busy || !memberId.trim() || !hours || reason.trim().length < 10" @click="propose">
					Submit
				</NcButton>
			</section>

			<section v-if="isAdmin" class="card">
				<h2>Threshold for a vote</h2>
				<NcTextField v-model="newThreshold"
					label="Hours (1 to 20)"
					type="number"
					step="0.5" />
				<NcTextArea v-model="thresholdReason" label="Reason for changing it (public)" :rows="2" />
				<NcButton :disabled="busy || thresholdReason.trim().length < 10 || parseFloat(newThreshold) === threshold" @click="saveThreshold">
					Change threshold
				</NcButton>
			</section>

			<section>
				<h2>Open adjustments</h2>
				<p class="subtitle">
					Only adjustments still waiting for an approval or a vote are listed here. Decided ones, with who
					decided and why, stay in the
					<RouterLink :to="{ name: 'GovernanceLog' }">
						governance log
					</RouterLink>.
				</p>
				<p v-if="openAdjustments.length === 0" class="subtitle">
					Nothing is waiting.
				</p>
				<div v-for="a in openAdjustments"
					:key="a.id"
					class="item"
					:data-status="a.status">
					<div class="item-head">
						<strong>{{ a.hours > 0 ? '+' : '' }}{{ a.hours }} h for
							<RouterLink :to="{ name: 'UserLedger', params: { userId: a.userId } }">{{ a.userId }}</RouterLink></strong>
						<span class="tag">{{ a.status }}</span>
					</div>
					<p>{{ a.reason }}</p>
					<p class="meta">
						Proposed by {{ a.proposerId }}, {{ new Date(a.createdAt).toLocaleDateString() }}
						<template v-if="a.approverId">
							; {{ a.status }} by {{ a.approverId }}
						</template>
						<template v-if="a.status === 'pending'">
							; expires {{ new Date(a.expiresAt).toLocaleDateString() }}
						</template>
						<template v-if="a.approverId === 'community-vote'">
							; applied by vote
						</template>
						<template v-if="a.decisionNote">
							. Note: {{ a.decisionNote }}
						</template>
					</p>
					<div v-if="a.vote" class="vote">
						<p class="meta">
							<template v-if="a.status === 'voting'">
								Vote open: {{ a.vote.votes }} of {{ a.vote.eligible }} eligible voted
								(at least {{ a.vote.needed }} needed); closes in {{ daysLeft(a.vote.closes) }}
								{{ daysLeft(a.vote.closes) === 1 ? 'day' : 'days' }}, sooner if everyone votes.
							</template>
							<template v-else>
								Vote closed: {{ a.vote.votes }} of {{ a.vote.eligible }} voted.
							</template>
						</p>
						<p class="meta">
							For: {{ a.vote.approve.length ? a.vote.approve.join(', ') : 'nobody yet' }}.
							Against: {{ a.vote.reject.length ? a.vote.reject.join(', ') : 'nobody yet' }}.
						</p>
						<p v-if="a.vote.myVote" class="meta">
							You voted {{ a.vote.myVote === 'approve' ? 'for' : 'against' }}.
						</p>
						<div v-if="a.canVote" class="actions">
							<NcButton variant="primary" :disabled="busy" @click="castVote(a, 'approve')">
								Vote for
							</NcButton>
							<NcButton variant="error" :disabled="busy" @click="castVote(a, 'reject')">
								Vote against
							</NcButton>
						</div>
					</div>
					<div v-if="a.canDecide || a.canWithdraw" class="actions">
						<NcButton v-if="a.canDecide"
							variant="primary"
							:disabled="busy"
							@click="approve(a)">
							Approve
						</NcButton>
						<NcButton v-if="a.canDecide"
							variant="error"
							:disabled="busy"
							@click="rejectingId = a.id">
							Reject
						</NcButton>
						<NcButton v-if="a.canWithdraw" :disabled="busy" @click="withdraw(a)">
							Withdraw
						</NcButton>
					</div>
					<p v-else-if="a.status === 'pending'" class="meta">
						Waiting for someone else to look at it.
					</p>
					<div v-if="rejectingId === a.id" class="reject">
						<NcTextArea v-model="rejectNote" label="Why (public, 10+ characters)" :rows="2" />
						<NcButton variant="error" :disabled="busy || rejectNote.trim().length < 10" @click="reject(a)">
							Confirm reject
						</NcButton>
					</div>
				</div>
			</section>
		</template>
	</div>
</template>

<style scoped>
.adjustments {
	padding: 20px;
	max-width: 800px;
}

.subtitle,
.meta {
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error-text);
}

.notice {
	color: var(--color-success-text);
}

.card {
	display: grid;
	gap: 8px;
	max-width: 480px;
	margin-bottom: 24px;
}

.item {
	padding: 12px;
	margin-bottom: 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.item[data-status="voting"] {
	border-inline-start: 4px solid var(--color-primary-element);
}

.vote {
	margin-bottom: 8px;
}

.item[data-status="pending"] {
	border-inline-start: 4px solid var(--color-warning);
}

.item-head {
	display: flex;
	justify-content: space-between;
}

.tag {
	font-size: 12px;
	padding: 2px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
}

.actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}
</style>
