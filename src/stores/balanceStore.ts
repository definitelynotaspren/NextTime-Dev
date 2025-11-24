import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

interface Balance {
	userId: string
	balance: number
	updatedAt: string | null
}

interface BalanceState {
	myBalance: Balance | null
	allBalances: Balance[]
	loading: boolean
	error: string | null
}

export const useBalanceStore = defineStore('balance', {
	state: (): BalanceState => ({
		myBalance: null,
		allBalances: [],
		loading: false,
		error: null,
	}),

	actions: {
		async fetchMyBalance() {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl('/apps/timebank/api/balance/my')
				const response = await axios.get(url)
				this.myBalance = response.data
			} catch (error: unknown) {
				const err = error as { response?: { data?: { error?: string } } }
				this.error = err.response?.data?.error || 'Failed to fetch balance'
				console.error('Error fetching balance:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchAllBalances() {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl('/apps/timebank/api/balance/all')
				const response = await axios.get(url)
				this.allBalances = response.data
			} catch (error: unknown) {
				const err = error as { response?: { data?: { error?: string } } }
				this.error = err.response?.data?.error || 'Failed to fetch balances'
				console.error('Error fetching balances:', error)
			} finally {
				this.loading = false
			}
		},
	},
})
