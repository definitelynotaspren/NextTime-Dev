import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

interface Request {
	id: number
	requesterId: string
	title: string
	description: string
	categoryId: number
	hoursBudget: number
	status: string
	priority: string
	deadline: string | null
	location: string | null
	createdAt: string
	updatedAt: string
	volunteerCount?: number
	commentCount?: number
}

interface RequestFilters {
	status: string | null
	categoryId: number | null
	priority: string | null
}

interface Pagination {
	total: number
	limit: number
	offset: number
}

interface RequestState {
	requests: Request[]
	currentRequest: {
		request: Request
		volunteers: unknown[]
		comments: unknown[]
	} | null
	filters: RequestFilters
	pagination: Pagination
	loading: boolean
	error: string | null
}

export const useRequestStore = defineStore('requests', {
	state: (): RequestState => ({
		requests: [],
		currentRequest: null,
		filters: {
			status: null,
			categoryId: null,
			priority: null,
		},
		pagination: {
			total: 0,
			limit: 50,
			offset: 0,
		},
		loading: false,
		error: null,
	}),

	getters: {
		hasMore: (state) =>
			state.pagination.offset + state.pagination.limit < state.pagination.total,
	},

	actions: {
		async fetchRequests() {
			this.loading = true
			this.error = null

			try {
				const params = new URLSearchParams()
				if (this.filters.status) params.append('status', this.filters.status)
				if (this.filters.categoryId)
					params.append('categoryId', this.filters.categoryId.toString())
				if (this.filters.priority) params.append('priority', this.filters.priority)
				params.append('limit', this.pagination.limit.toString())
				params.append('offset', this.pagination.offset.toString())

				const url = generateUrl('/apps/timebank/api/requests?' + params.toString())
				const response = await axios.get(url)

				this.requests = response.data.requests
				this.pagination.total = response.data.total
			} catch (error: unknown) {
				const err = error as { response?: { data?: { error?: string } } }
				this.error = err.response?.data?.error || 'Failed to fetch requests'
				console.error('Error fetching requests:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchRequestDetails(id: number) {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl(`/apps/timebank/api/requests/${id}`)
				const response = await axios.get(url)
				this.currentRequest = response.data
			} catch (error: unknown) {
				const err = error as { response?: { data?: { error?: string } } }
				this.error = err.response?.data?.error || 'Failed to fetch request details'
				console.error('Error fetching request:', error)
			} finally {
				this.loading = false
			}
		},

		async createRequest(data: Partial<Request>) {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl('/apps/timebank/api/requests')
				const response = await axios.post(url, data)
				this.requests.unshift(response.data)
				return response.data
			} catch (error: unknown) {
				const err = error as { response?: { data?: { error?: string } } }
				this.error = err.response?.data?.error || 'Failed to create request'
				console.error('Error creating request:', error)
				throw error
			} finally {
				this.loading = false
			}
		},

		setFilter(key: keyof RequestFilters, value: string | number | null) {
			(this.filters as Record<string, unknown>)[key] = value
			this.pagination.offset = 0
			this.fetchRequests()
		},

		clearFilters() {
			this.filters = {
				status: null,
				categoryId: null,
				priority: null,
			}
			this.pagination.offset = 0
			this.fetchRequests()
		},

		nextPage() {
			if (this.hasMore) {
				this.pagination.offset += this.pagination.limit
				this.fetchRequests()
			}
		},

		prevPage() {
			if (this.pagination.offset > 0) {
				this.pagination.offset = Math.max(0, this.pagination.offset - this.pagination.limit)
				this.fetchRequests()
			}
		},
	},
})
