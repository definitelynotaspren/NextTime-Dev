<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useBalanceStore } from '../stores/balanceStore'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcButton from '@nextcloud/vue/components/NcButton'

interface Transaction {
	id: number
	fromUserId: string | null
	toUserId: string | null
	hours: number
	description: string
	transactionType: string
	createdAt: string
}

interface Pagination {
	total: number
	limit: number
	offset: number
}

const balanceStore = useBalanceStore()

const transactions = ref<Transaction[]>([])
const pagination = ref<Pagination>({ total: 0, limit: 20, offset: 0 })
const loadingTx = ref(true)

const fetchMyTransactions = async () => {
	loadingTx.value = true
	try {
		const url = generateUrl(`/apps/timebank/api/ledger/my?limit=${pagination.value.limit}&offset=${pagination.value.offset}`)
		const response = await axios.get(url)
		transactions.value = response.data.transactions
		pagination.value.total = response.data.total
	} catch (e) {
		console.error('Error fetching transactions:', e)
	} finally {
		loadingTx.value = false
	}
}

const nextPage = () => {
	if (pagination.value.offset + pagination.value.limit < pagination.value.total) {
		pagination.value.offset += pagination.value.limit
		fetchMyTransactions()
	}
}

const prevPage = () => {
	if (pagination.value.offset > 0) {
		pagination.value.offset = Math.max(0, pagination.value.offset - pagination.value.limit)
		fetchMyTransactions()
	}
}

onMounted(() => {
	balanceStore.fetchMyBalance()
	fetchMyTransactions()
})
</script>

<template>
	<div class="my-balance">
		<div class="balance-card">
			<NcLoadingIcon v-if="balanceStore.loading" :size="44" />
			<template v-else>
				<h1>My Balance</h1>
				<div class="balance-amount">
					{{ balanceStore.myBalance?.balance ?? 0 }}
					<span class="unit">hours</span>
				</div>
				<p class="last-updated" v-if="balanceStore.myBalance?.updatedAt">
					Last updated: {{ new Date(balanceStore.myBalance.updatedAt).toLocaleString() }}
				</p>
			</template>
		</div>

		<div class="transactions-section">
			<h2>My Transaction History</h2>

			<NcLoadingIcon v-if="loadingTx" :size="32" />

			<div v-else-if="transactions.length === 0" class="no-transactions">
				No transactions yet. Start earning or spending hours!
			</div>

			<template v-else>
				<div class="transaction-list">
					<div
						v-for="tx in transactions"
						:key="tx.id"
						class="transaction-item"
						:class="tx.transactionType"
					>
						<div class="tx-main">
							<span class="tx-type">
								{{ tx.transactionType === 'earned' ? '+' : tx.transactionType === 'spent' ? '-' : '' }}
								{{ tx.hours }} hrs
							</span>
							<span class="tx-description">{{ tx.description }}</span>
						</div>
						<div class="tx-meta">
							<span v-if="tx.fromUserId && tx.toUserId">
								{{ tx.fromUserId }} &rarr; {{ tx.toUserId }}
							</span>
							<span class="tx-date">{{ new Date(tx.createdAt).toLocaleDateString() }}</span>
						</div>
					</div>
				</div>

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
	</div>
</template>

<style scoped>
.my-balance {
	padding: 20px;
}

.balance-card {
	background: linear-gradient(135deg, var(--color-primary), var(--color-primary-element));
	color: white;
	padding: 40px;
	border-radius: 16px;
	text-align: center;
	margin-bottom: 30px;
}

.balance-card h1 {
	margin: 0 0 10px 0;
	font-size: 24px;
	opacity: 0.9;
}

.balance-amount {
	font-size: 64px;
	font-weight: 700;
}

.balance-amount .unit {
	font-size: 24px;
	font-weight: 400;
	opacity: 0.8;
}

.last-updated {
	margin-top: 10px;
	opacity: 0.7;
	font-size: 14px;
}

.transactions-section h2 {
	margin-bottom: 16px;
}

.no-transactions {
	text-align: center;
	padding: 40px;
	color: var(--color-text-lighter);
	background: var(--color-background-hover);
	border-radius: 8px;
}

.transaction-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 20px;
}

.transaction-item {
	background: var(--color-background-hover);
	padding: 16px;
	border-radius: 8px;
	border-left: 4px solid var(--color-border);
}

.transaction-item.earned {
	border-left-color: var(--color-success);
}

.transaction-item.spent {
	border-left-color: var(--color-primary);
}

.transaction-item.adjusted {
	border-left-color: var(--color-warning);
}

.tx-main {
	display: flex;
	gap: 12px;
	margin-bottom: 8px;
}

.tx-type {
	font-weight: 600;
	min-width: 80px;
}

.transaction-item.earned .tx-type {
	color: var(--color-success);
}

.transaction-item.spent .tx-type {
	color: var(--color-primary);
}

.tx-description {
	flex: 1;
}

.tx-meta {
	display: flex;
	justify-content: space-between;
	font-size: 12px;
	color: var(--color-text-lighter);
}

.pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 20px;
}
</style>
