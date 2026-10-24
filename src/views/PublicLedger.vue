<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

interface Transaction {
	id: number
	fromUserId: string | null
	toUserId: string | null
	hours: number
	description: string
	transactionType: string
	referenceType: string | null
	createdAt: string
}

interface Pagination {
	total: number
	limit: number
	offset: number
}

// With a userId this shows only that member's movements; the whole ledger is public anyway.
const props = defineProps({
	userId: {
		type: String,
		default: null,
	},
})

const transactions = ref<Transaction[]>([])
const pagination = ref<Pagination>({ total: 0, limit: 50, offset: 0 })
const loading = ref(true)

const fetchLedger = async () => {
	loading.value = true
	try {
		const base = props.userId ? `/apps/timebank/api/ledger/user/${encodeURIComponent(props.userId)}` : '/apps/timebank/api/ledger'
		const url = generateUrl(`${base}?limit=${pagination.value.limit}&offset=${pagination.value.offset}`)
		const response = await axios.get(url)
		transactions.value = response.data.transactions
		pagination.value.total = response.data.total
	} catch (e) {
		console.error('Error fetching ledger:', e)
	} finally {
		loading.value = false
	}
}

const nextPage = () => {
	if (pagination.value.offset + pagination.value.limit < pagination.value.total) {
		pagination.value.offset += pagination.value.limit
		fetchLedger()
	}
}

const prevPage = () => {
	if (pagination.value.offset > 0) {
		pagination.value.offset = Math.max(0, pagination.value.offset - pagination.value.limit)
		fetchLedger()
	}
}

const getTypeIcon = (type: string) => {
	switch (type) {
	case 'earned':
		return '&#10133;'
	case 'spent':
		return '&#10134;'
	case 'adjusted':
		return '&#9881;'
	default:
		return '&#8634;'
	}
}

const getTypeClass = (type: string) => {
	return `type-${type}`
}

onMounted(() => {
	fetchLedger()
})

// Following a name from one member's ledger to another's keeps this component alive.
watch(() => props.userId, () => {
	pagination.value.offset = 0
	fetchLedger()
})
</script>

<template>
	<div class="public-ledger">
		<h1>{{ userId ? `Ledger for ${userId}` : 'Public Ledger' }}</h1>
		<p class="subtitle">
			<template v-if="userId">
				Every movement of hours to or from this member.
				<RouterLink :to="{ name: 'PublicLedger' }">
					See the whole ledger
				</RouterLink>
			</template>
			<template v-else>
				Transparent record of all community hour transactions.
			</template>
		</p>

		<NcLoadingIcon v-if="loading" :size="44" />

		<template v-else>
			<table class="ledger-table">
				<thead>
					<tr>
						<th>Date</th>
						<th>Type</th>
						<th>From</th>
						<th>To</th>
						<th>Hours</th>
						<th>Description</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="tx in transactions" :key="tx.id">
						<td>{{ new Date(tx.createdAt).toLocaleDateString() }}</td>
						<td>
							<span class="type-badge" :class="getTypeClass(tx.transactionType)">
								<span v-html="getTypeIcon(tx.transactionType)" />
								{{ tx.transactionType }}
							</span>
						</td>
						<td>
							<RouterLink v-if="tx.fromUserId" :to="{ name: 'UserLedger', params: { userId: tx.fromUserId } }">
								{{ tx.fromUserId }}
							</RouterLink>
							<template v-else>
								System
							</template>
						</td>
						<td>
							<RouterLink v-if="tx.toUserId" :to="{ name: 'UserLedger', params: { userId: tx.toUserId } }">
								{{ tx.toUserId }}
							</RouterLink>
							<template v-else>
								System
							</template>
						</td>
						<td class="hours-cell">
							{{ tx.hours }}
						</td>
						<td class="description-cell">
							{{ tx.description }}
						</td>
					</tr>
				</tbody>
			</table>

			<div class="pagination">
				<NcButton
					:disabled="pagination.offset === 0"
					@click="prevPage">
					Previous
				</NcButton>
				<span>
					{{ pagination.offset + 1 }} -
					{{ Math.min(pagination.offset + pagination.limit, pagination.total) }}
					of {{ pagination.total }}
				</span>
				<NcButton
					:disabled="pagination.offset + pagination.limit >= pagination.total"
					@click="nextPage">
					Next
				</NcButton>
			</div>
		</template>
	</div>
</template>

<style scoped>
.public-ledger {
	padding: 20px;
}

.subtitle {
	color: var(--color-text-lighter);
	margin-bottom: 20px;
}

.ledger-table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 20px;
}

.ledger-table th,
.ledger-table td {
	padding: 12px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.ledger-table th {
	background: var(--color-background-hover);
	font-weight: 600;
}

.ledger-table tr:hover {
	background: var(--color-background-hover);
}

.type-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 12px;
	text-transform: uppercase;
}

.type-badge.type-earned {
	background: var(--color-success);
	color: white;
}

.type-badge.type-spent {
	background: var(--color-primary);
	color: white;
}

.type-badge.type-adjusted {
	background: var(--color-warning);
	color: white;
}

.hours-cell {
	font-weight: 600;
	color: var(--color-primary);
}

.description-cell {
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 20px;
}
</style>
