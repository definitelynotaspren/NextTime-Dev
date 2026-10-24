<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { errorMessage, type Volunteer, type Withdrawal } from '../stores/requestStore'
import RequestCard from '../components/RequestCard.vue'

interface Request {
	id: number
	title: string
	description: string
	categoryId: number
	hoursBudget: number
	status: string
	priority: string
	deadline: string | null
	location: string | null
	volunteerCount?: number
	commentCount?: number
	requesterId: string
}

interface MyOffer extends Volunteer {
	request: { id: number, title: string, status: string, requesterId: string }
}

type Tab = 'requests' | 'offers' | 'withdrawn'

const router = useRouter()
const tab = ref<Tab>('requests')
const offers = ref<MyOffer[]>([])
const withdrawals = ref<Withdrawal[]>([])
const actionError = ref<string | null>(null)
const withdrawTarget = ref<MyOffer | null>(null)
const withdrawReason = ref('')
const withdrawing = ref(false)

const MIN_REASON_LENGTH = 3
const OFFER_LABELS: Record<string, string> = {
	offered: 'Waiting for the requester',
	accepted: 'Accepted',
	declined: 'Declined',
	completed: 'Completed',
}

const reasonRequired = computed(() => withdrawTarget.value?.status === 'accepted')
const canWithdraw = computed(() => !reasonRequired.value || withdrawReason.value.trim().length >= MIN_REASON_LENGTH)
const canWithdrawOffer = (offer: MyOffer) =>
	['offered', 'accepted'].includes(offer.status) && ['open', 'in_progress'].includes(offer.request.status)

const requests = ref<Request[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const fetchMyRequests = async () => {
	loading.value = true
	error.value = null

	try {
		const url = generateUrl('/apps/timebank/api/requests/my')
		const response = await axios.get(url)
		requests.value = response.data.requests
	} catch (e: unknown) {
		const err = e as { response?: { data?: { error?: string } } }
		error.value = err.response?.data?.error || 'Failed to fetch requests'
	} finally {
		loading.value = false
	}
}

const fetchOffers = async () => {
	try {
		const [mine, log] = await Promise.all([
			axios.get(generateUrl('/apps/timebank/api/volunteers/my')),
			axios.get(generateUrl('/apps/timebank/api/withdrawals/my')),
		])
		offers.value = mine.data
		withdrawals.value = log.data
	} catch (e: unknown) {
		actionError.value = errorMessage(e, 'Failed to fetch your offers')
	}
}

const startWithdraw = (offer: MyOffer) => {
	withdrawReason.value = ''
	withdrawTarget.value = offer
}

const confirmWithdraw = async () => {
	const target = withdrawTarget.value
	if (!target || !canWithdraw.value) return

	withdrawing.value = true
	actionError.value = null
	try {
		await axios.delete(generateUrl(`/apps/timebank/api/volunteers/${target.id}`), {
			data: { reason: withdrawReason.value.trim() || null },
		})
		withdrawTarget.value = null
		await fetchOffers()
	} catch (e: unknown) {
		actionError.value = errorMessage(e)
		withdrawTarget.value = null
	} finally {
		withdrawing.value = false
	}
}

const viewRequest = (id: number) => {
	router.push({ name: 'RequestDetail', params: { id } })
}

onMounted(() => {
	fetchMyRequests()
	fetchOffers()
})
</script>

<template>
	<div class="my-requests">
		<h1>My activity</h1>

		<div class="tabs" role="tablist">
			<NcButton :variant="tab === 'requests' ? 'primary' : 'tertiary'" role="tab" @click="tab = 'requests'">
				My requests
			</NcButton>
			<NcButton :variant="tab === 'offers' ? 'primary' : 'tertiary'" role="tab" @click="tab = 'offers'">
				My offers ({{ offers.length }})
			</NcButton>
			<NcButton :variant="tab === 'withdrawn' ? 'primary' : 'tertiary'" role="tab" @click="tab = 'withdrawn'">
				Withdrawn help ({{ withdrawals.length }})
			</NcButton>
		</div>

		<p v-if="actionError" class="action-error" role="alert">
			{{ actionError }}
		</p>

		<div v-if="tab === 'offers'" class="offers">
			<NcEmptyContent v-if="offers.length === 0" name="No offers">
				<template #description>
					You haven't offered to help with anything yet.
				</template>
			</NcEmptyContent>
			<div
				v-for="offer in offers"
				:key="offer.id"
				class="offer-row"
				:data-status="offer.status">
				<div>
					<a class="offer-title" href="#" @click.prevent="viewRequest(offer.request.id)">{{ offer.request.title }}</a>
					<div class="offer-meta">
						{{ OFFER_LABELS[offer.status] ?? offer.status }}, {{ offer.proposedHours }} h proposed,
						request {{ offer.request.status.replace('_', ' ') }}
					</div>
				</div>
				<div class="offer-actions">
					<NcButton v-if="canWithdrawOffer(offer)" @click="startWithdraw(offer)">
						Withdraw
					</NcButton>
					<NcButton v-if="offer.status === 'completed'" @click="router.push({ name: 'SubmitEarning' })">
						Claim hours
					</NcButton>
				</div>
			</div>
		</div>

		<div v-else-if="tab === 'withdrawn'" class="offers">
			<NcEmptyContent v-if="withdrawals.length === 0" name="Nothing withdrawn">
				<template #description>
					When you step back from help you had been accepted for, it is logged here with your reason.
				</template>
			</NcEmptyContent>
			<div v-for="entry in withdrawals" :key="entry.id" class="offer-row">
				<div>
					<a class="offer-title" href="#" @click.prevent="viewRequest(entry.requestId)">Request #{{ entry.requestId }}</a>
					<div class="offer-meta">
						{{ entry.proposedHours }} h, {{ new Date(entry.createdAt).toLocaleDateString() }}
					</div>
					<p class="offer-reason">
						{{ entry.reason }}
					</p>
				</div>
			</div>
		</div>

		<NcLoadingIcon v-else-if="loading" :size="44" />

		<NcEmptyContent v-else-if="error" name="Error">
			<template #description>
				{{ error }}
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="requests.length === 0" name="No Requests">
			<template #description>
				You haven't posted any requests yet.
			</template>
			<template #action>
				<NcButton variant="primary" @click="router.push({ name: 'Board' })">
					Post a Request
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="requests-grid">
			<RequestCard
				v-for="request in requests"
				:key="request.id"
				:request="request"
				@click="viewRequest(request.id)" />
		</div>

		<NcModal v-if="withdrawTarget" size="small" @close="withdrawTarget = null">
			<div class="dialog">
				<h2>Withdraw your offer?</h2>
				<p v-if="reasonRequired">
					The requester has accepted your help. Please say why you are stepping back. They will see your
					reason, and it is kept in a log of withdrawn help.
				</p>
				<p v-else>
					Your offer will be removed. You can offer again later.
				</p>
				<NcTextArea v-model="withdrawReason" :label="reasonRequired ? 'Reason (required)' : 'Reason (optional)'" :rows="3" />
				<div class="dialog-actions">
					<NcButton @click="withdrawTarget = null">
						Keep my offer
					</NcButton>
					<NcButton variant="primary" :disabled="!canWithdraw || withdrawing" @click="confirmWithdraw">
						{{ withdrawing ? 'Withdrawing...' : 'Withdraw' }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<style scoped>
.my-requests {
	padding: 20px;
}

.my-requests h1 {
	margin-bottom: 20px;
}

.tabs {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}

.action-error {
	color: var(--color-error-text);
}

.offer-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 12px;
	margin-bottom: 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.offer-title {
	font-weight: 600;
}

.offer-meta,
.offer-reason {
	color: var(--color-text-maxcontrast);
}

.offer-actions {
	display: flex;
	gap: 8px;
}

.dialog {
	padding: 20px;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 10px;
	margin-top: 16px;
}

.requests-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
	gap: 20px;
}
</style>
