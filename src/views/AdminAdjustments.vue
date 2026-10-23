<!--
SPDX-FileCopyrightText: 2026 NextTime-Dev contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue'
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
}

const adjustments = ref<Adjustment[]>([])
const threshold = ref(5)
const canPropose = ref(false)
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
			: `Proposed. It needs a second person (another admin, or a facilitator of ${data.userId}'s chapter) and expires in ${expiryDays.value} days.`
	})
	if (ok) {
		memberId.value = ''
		hours.value = ''
		reason.value = ''
	}
}

const approve = (a: Adjustment) => run(() => axios.post(generateUrl(`/apps/timebank/api/adjustments/${a.id}/approve`)), 'Approved and applied.')
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
			Up to {{ threshold }} hours one admin can adjust a balance. Anything larger needs a second person:
			another admin, or a facilitator of a chapter the member belongs to. Nobody adjusts or approves their own.
			Every step is public in the
			<RouterLink :to="{ name: 'GovernanceLog' }">
				governance log
			</RouterLink>.
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

			<section v-if="canPropose" class="card">
				<h2>Threshold for needing a second person</h2>
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
				<h2>Recent adjustments</h2>
				<p v-if="adjustments.length === 0" class="subtitle">
					None yet.
				</p>
				<div v-for="a in adjustments"
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
						<template v-if="a.decisionNote">
							. Note: {{ a.decisionNote }}
						</template>
					</p>
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
