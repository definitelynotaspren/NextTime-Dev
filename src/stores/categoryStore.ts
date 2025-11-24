import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

interface Category {
	id: number
	name: string
	description: string | null
	earnRate: number
	icon: string | null
	createdAt: string | null
}

interface CategoryState {
	categories: Category[]
	loading: boolean
	error: string | null
}

export const useCategoryStore = defineStore('categories', {
	state: (): CategoryState => ({
		categories: [],
		loading: false,
		error: null,
	}),

	getters: {
		getCategoryById: (state) => (id: number) => {
			return state.categories.find((c) => c.id === id)
		},
	},

	actions: {
		async fetchCategories() {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl('/apps/timebank/api/categories')
				const response = await axios.get(url)
				this.categories = response.data
			} catch (error: unknown) {
				const err = error as { response?: { data?: { error?: string } } }
				this.error = err.response?.data?.error || 'Failed to fetch categories'
				console.error('Error fetching categories:', error)
			} finally {
				this.loading = false
			}
		},
	},
})
