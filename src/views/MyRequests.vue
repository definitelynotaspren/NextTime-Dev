<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
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

const router = useRouter()

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

const viewRequest = (id: number) => {
	router.push({ name: 'RequestDetail', params: { id } })
}

onMounted(() => {
	fetchMyRequests()
})
</script>

<template>
	<div class="my-requests">
		<h1>My Requests</h1>

		<NcLoadingIcon v-if="loading" :size="44" />

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
				<NcButton type="primary" @click="router.push({ name: 'Board' })">
					Post a Request
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="requests-grid">
			<RequestCard
				v-for="request in requests"
				:key="request.id"
				:request="request"
				@click="viewRequest(request.id)"
			/>
		</div>
	</div>
</template>

<style scoped>
.my-requests {
	padding: 20px;
}

.my-requests h1 {
	margin-bottom: 20px;
}

.requests-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
	gap: 20px;
}
</style>
