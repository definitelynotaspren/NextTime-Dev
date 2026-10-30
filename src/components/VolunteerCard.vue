<!--
SPDX-FileCopyrightText: 2026 NextTime contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref, type PropType } from 'vue'
import { RouterLink } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import type { Volunteer } from '../stores/requestStore'

const props = defineProps({
	volunteer: {
		type: Object as PropType<Volunteer>,
		required: true,
	},
	/** The viewer owns the request and can accept or decline offers. */
	isRequester: {
		type: Boolean,
		default: false,
	},
	/** The offer is the viewer's own. */
	isMine: {
		type: Boolean,
		default: false,
	},
	/** The request is still open or in progress. */
	requestActive: {
		type: Boolean,
		default: false,
	},
	busy: {
		type: Boolean,
		default: false,
	},
	/** The hours the requester posted the request with. */
	postedHours: {
		type: Number,
		default: 0,
	},
})

const emit = defineEmits<{
	(e: 'accept', volunteer: Volunteer, agreedHours?: number): void
	(e: 'decline', volunteer: Volunteer): void
	(e: 'withdraw', volunteer: Volunteer): void
}>()

const STATUS_LABELS: Record<string, string> = {
	offered: 'Offered',
	accepted: 'Accepted',
	declined: 'Declined',
	completed: 'Completed',
}

const statusLabel = computed(() => STATUS_LABELS[props.volunteer.status] ?? props.volunteer.status)
// The requester may settle on hours between what they posted and what was offered.
const lowest = computed(() => Math.min(props.volunteer.proposedHours, props.postedHours))
const highest = computed(() => Math.max(props.volunteer.proposedHours, props.postedHours))
const agreed = ref(String(props.volunteer.proposedHours))
const agreedValid = computed(() => {
	const n = parseFloat(agreed.value)
	return Number.isFinite(n) && n >= lowest.value && n <= highest.value
})
const accept = () => emit('accept', props.volunteer, parseFloat(agreed.value))
const canDecide = computed(() => props.isRequester && props.requestActive && props.volunteer.status === 'offered')
const canWithdraw = computed(() => props.isMine && props.requestActive && ['offered', 'accepted'].includes(props.volunteer.status))
const canClaim = computed(() => props.isMine && props.volunteer.status === 'completed')
</script>

<template>
	<div class="volunteer-card" :data-status="volunteer.status">
		<div class="volunteer-header">
			<strong><RouterLink :to="{ name: 'UserLedger', params: { userId: volunteer.volunteerId } }">{{ volunteer.volunteerId }}</RouterLink><span v-if="isMine" class="you"> (you)</span></strong>
			<span class="volunteer-status" :class="`status-${volunteer.status}`">
				{{ statusLabel }}
			</span>
		</div>
		<div class="volunteer-hours">
			<template v-if="volunteer.agreedHours != null && volunteer.agreedHours !== volunteer.proposedHours">
				{{ volunteer.agreedHours }} hours agreed ({{ volunteer.proposedHours }} offered)
			</template>
			<template v-else-if="volunteer.agreedHours != null">
				{{ volunteer.agreedHours }} hours agreed
			</template>
			<template v-else>
				{{ volunteer.proposedHours }} hours proposed
			</template>
		</div>
		<div v-if="volunteer.message" class="volunteer-message">
			"{{ volunteer.message }}"
		</div>
		<div class="volunteer-stats">
			<span>{{ volunteer.completedInCategory ?? 0 }} completed in category</span>
			<span>{{ volunteer.totalHoursProvided ?? 0 }} total hours</span>
		</div>

		<div v-if="canDecide || canWithdraw || canClaim" class="volunteer-actions">
			<template v-if="canDecide">
				<label v-if="lowest !== highest" class="agreed">
					Agreed hours
					<input
						v-model="agreed"
						type="number"
						step="0.25"
						:min="lowest"
						:max="highest"
						:disabled="busy">
					<span class="hint">between {{ lowest }} and {{ highest }}</span>
				</label>
				<NcButton variant="primary" :disabled="busy || !agreedValid" @click="accept">
					Accept
				</NcButton>
				<NcButton :disabled="busy" @click="emit('decline', volunteer)">
					Decline
				</NcButton>
			</template>
			<NcButton v-if="canWithdraw" :disabled="busy" @click="emit('withdraw', volunteer)">
				Withdraw
			</NcButton>
			<RouterLink v-if="canClaim" :to="{ name: 'SubmitEarning' }" class="claim-link">
				Claim your hours
			</RouterLink>
		</div>
	</div>
</template>

<style scoped>
.volunteer-card {
	background: var(--color-main-background);
	padding: 12px;
	border-radius: 8px;
	margin-bottom: 10px;
	border: 1px solid var(--color-border);
}

.volunteer-card[data-status="accepted"],
.volunteer-card[data-status="completed"] {
	border-inline-start: 4px solid var(--color-success-text);
}

.volunteer-card[data-status="declined"] {
	opacity: 0.7;
}

.volunteer-header {
	display: flex;
	justify-content: space-between;
	margin-bottom: 8px;
}

.you {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.volunteer-status {
	font-size: 12px;
	padding: 2px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
}

.volunteer-status.status-accepted,
.volunteer-status.status-completed {
	background: var(--color-success-text);
	color: white;
}

.agreed {
	display: flex;
	align-items: center;
	gap: 6px;
}

.agreed input {
	width: 5em;
}

.hint {
	color: var(--color-text-maxcontrast);
}

.volunteer-hours {
	color: var(--color-primary-element);
	font-weight: 500;
}

.volunteer-message {
	font-style: italic;
	color: var(--color-text-maxcontrast);
	margin: 8px 0;
}

.volunteer-stats {
	display: flex;
	gap: 16px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 8px;
}

.volunteer-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	margin-top: 12px;
}

.claim-link {
	font-weight: 600;
	color: var(--color-primary-element);
}
</style>
