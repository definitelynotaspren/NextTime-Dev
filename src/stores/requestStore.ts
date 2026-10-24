import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export interface Request {
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

export interface Volunteer {
	id: number
	requestId: number
	volunteerId: string
	proposedHours: number
	message: string | null
	status: string
	createdAt: string
	completedInCategory?: number
	totalHoursProvided?: number
}

export interface Withdrawal {
	id: number
	requestId: number
	volunteerId: string
	proposedHours: number
	reason: string | null
	createdAt: string
}

export interface CommentFlag {
	id: number
	userId: string
	reason: string | null
	createdAt: string
}

export interface RequestComment {
	id: number
	requestId: number
	userId: string
	/** null when the comment is hidden by flags or removed, unless you are a moderator */
	comment: string | null
	createdAt: string
	state: 'visible' | 'hidden' | 'removed'
	flagCount: number
	flaggedByMe: boolean
	removedBy?: string
	/** Only sent to moderators. */
	flags?: CommentFlag[]
}

/**
 * The server's plain-language message for a failed action, or a fallback.
 * @param error
 * @param fallback
 */
export function errorMessage(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
	const err = error as { response?: { data?: { error?: string } } }
	return err.response?.data?.error || fallback
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
		volunteers: Volunteer[]
		comments: RequestComment[]
		/** Only sent to the requester: why people withdrew their help. */
		withdrawals?: Withdrawal[]
		canModerate?: boolean
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
				if (this.filters.categoryId) { params.append('categoryId', this.filters.categoryId.toString()) }
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

		/**
		 * @param id the request
		 * @param quiet refresh in place without swapping the page for a spinner
		 */
		async fetchRequestDetails(id: number, quiet = false) {
			if (!quiet) {
				this.loading = true
			}
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
				if (!quiet) {
					this.loading = false
				}
			}
		},

		// The actions below throw on failure so the caller can show the server's message,
		// and refresh the request quietly on success.

		async offerHelp(requestId: number, proposedHours: number, message: string | null) {
			await axios.post(generateUrl(`/apps/timebank/api/requests/${requestId}/volunteer`), {
				proposedHours,
				message,
			})
			await this.fetchRequestDetails(requestId, true)
		},

		async acceptOffer(offerId: number, requestId: number) {
			await axios.post(generateUrl(`/apps/timebank/api/volunteers/${offerId}/accept`))
			await this.fetchRequestDetails(requestId, true)
		},

		async declineOffer(offerId: number, requestId: number) {
			await axios.post(generateUrl(`/apps/timebank/api/volunteers/${offerId}/decline`))
			await this.fetchRequestDetails(requestId, true)
		},

		/**
		 * Accepted help needs a reason; it goes into the log of withdrawn help.
		 * @param offerId
		 * @param requestId
		 * @param reason
		 */
		async withdrawOffer(offerId: number, requestId: number, reason: string | null) {
			await axios.delete(generateUrl(`/apps/timebank/api/volunteers/${offerId}`), { data: { reason } })
			await this.fetchRequestDetails(requestId, true)
		},

		/**
		 * Completes every accepted volunteer; hours are still claimed separately.
		 * @param requestId
		 */
		async completeRequest(requestId: number) {
			await axios.post(generateUrl(`/apps/timebank/api/requests/${requestId}/complete`))
			await this.fetchRequestDetails(requestId, true)
		},

		// Only while the request is open; the server refuses anything else.
		async updateRequest(requestId: number, fields: Partial<Request>) {
			await axios.put(generateUrl(`/apps/timebank/api/requests/${requestId}`), fields)
			await this.fetchRequestDetails(requestId, true)
		},

		async cancelRequest(requestId: number) {
			await axios.post(generateUrl(`/apps/timebank/api/requests/${requestId}/cancel`))
			await this.fetchRequestDetails(requestId, true)
		},

		async flagComment(commentId: number, requestId: number, reason: string | null) {
			await axios.post(generateUrl(`/apps/timebank/api/comments/${commentId}/flag`), { reason })
			await this.fetchRequestDetails(requestId, true)
		},

		// Moderators only.
		async removeComment(commentId: number, requestId: number) {
			await axios.delete(generateUrl(`/apps/timebank/api/comments/${commentId}`))
			await this.fetchRequestDetails(requestId, true)
		},

		// Moderators only: the flags stop counting.
		async dismissFlags(commentId: number, requestId: number) {
			await axios.post(generateUrl(`/apps/timebank/api/comments/${commentId}/dismiss`))
			await this.fetchRequestDetails(requestId, true)
		},

		async addComment(requestId: number, comment: string) {
			await axios.post(generateUrl(`/apps/timebank/api/requests/${requestId}/comments`), { comment })
			await this.fetchRequestDetails(requestId, true)
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
