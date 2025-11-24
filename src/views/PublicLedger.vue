<script setup lang="ts">
import { ref, onMounted } from 'vue'
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

const transactions = ref<Transaction[]>([])
const pagination = ref<Pagination>({ total: 0, limit: 50, offset: 0 })
const loading = ref(true)

const fetchLedger = async () => {
	loading.value = true
	try {
		const url = generateUrl(`/apps/timebank/api/ledger?limit=${pagination.value.limit}&offset=${pagination.value.offset}`)
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
</script>

<template>
	<div class="public-ledger">
		<h1>Public Ledger</h1>
		<p class="subtitle">
			Transparent record of all community hour transactions.
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
								<span v-html="getTypeIcon(tx.transactionType)"></span>
								{{ tx.transactionType }}
							</span>
						</td>
						<td>{{ tx.fromUserId || 'System' }}</td>
						<td>{{ tx.toUserId || 'System' }}</td>
						<td class="hours-cell">{{ tx.hours }}</td>
						<td class="description-cell">{{ tx.description }}</td>
					</tr>
				</tbody>
			</table>

			<div class="pagination">
				<NcButton
					:disabled="pagination.offset === 0"
					@click="prevPage"
				>
					Previous
				</NcButton>
				<span>
					{{ pagination.offset + 1 }} -
					{{ Math.min(pagination.offset + pagination.limit, pagination.total) }}
					of {{ pagination.total }}
				</span>
				<NcButton
					:disabled="pagination.offset + pagination.limit >= pagination.total"
					@click="nextPage"
				>
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
	text-align: left;
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
