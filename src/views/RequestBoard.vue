<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useRequestStore } from '../stores/requestStore'
import { useCategoryStore } from '../stores/categoryStore'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import RequestCard from '../components/RequestCard.vue'
import NewRequestForm from '../components/NewRequestForm.vue'

const router = useRouter()
const requestStore = useRequestStore()
const categoryStore = useCategoryStore()

const showNewRequestModal = ref(false)
const selectedStatus = ref<string | null>(null)
const selectedCategory = ref<number | null>(null)
const selectedPriority = ref<string | null>(null)

const statusOptions = [
	{ id: '', label: 'All Status' },
	{ id: 'open', label: 'Open' },
	{ id: 'in_progress', label: 'In Progress' },
	{ id: 'completed', label: 'Completed' },
]

const priorityOptions = [
	{ id: '', label: 'All Priorities' },
	{ id: 'urgent', label: 'Urgent' },
	{ id: 'normal', label: 'Normal' },
	{ id: 'flexible', label: 'Flexible' },
]

const categoryOptions = computed(() => {
	return [
		{ id: '', label: 'All Categories' },
		...categoryStore.categories.map((c) => ({
			id: c.id.toString(),
			label: c.name,
		})),
	]
})

const applyFilters = () => {
	requestStore.setFilter('status', selectedStatus.value || null)
	requestStore.setFilter('categoryId', selectedCategory.value ? Number(selectedCategory.value) : null)
	requestStore.setFilter('priority', selectedPriority.value || null)
}

const clearFilters = () => {
	selectedStatus.value = null
	selectedCategory.value = null
	selectedPriority.value = null
	requestStore.clearFilters()
}

const viewRequest = (id: number) => {
	router.push({ name: 'RequestDetail', params: { id } })
}

const onRequestCreated = () => {
	showNewRequestModal.value = false
	requestStore.fetchRequests()
}

onMounted(() => {
	requestStore.fetchRequests()
})
</script>

<template>
	<div class="request-board">
		<div class="board-header">
			<h1>Community Time Bank</h1>
			<NcButton type="primary" @click="showNewRequestModal = true">
				+ Post New Need
			</NcButton>
		</div>

		<div class="board-filters">
			<NcSelect
				v-model="selectedStatus"
				:options="statusOptions"
				label-outside
				placeholder="Status"
				@update:model-value="applyFilters"
			/>
			<NcSelect
				v-model="selectedCategory"
				:options="categoryOptions"
				label-outside
				placeholder="Category"
				@update:model-value="applyFilters"
			/>
			<NcSelect
				v-model="selectedPriority"
				:options="priorityOptions"
				label-outside
				placeholder="Priority"
				@update:model-value="applyFilters"
			/>
			<NcButton @click="clearFilters">Clear Filters</NcButton>
		</div>

		<NcLoadingIcon v-if="requestStore.loading" :size="44" />

		<NcEmptyContent v-else-if="requestStore.error" name="Error">
			<template #description>
				{{ requestStore.error }}
			</template>
		</NcEmptyContent>

		<NcEmptyContent v-else-if="requestStore.requests.length === 0" name="No Requests">
			<template #description>
				No requests found. Post the first one!
			</template>
		</NcEmptyContent>

		<div v-else class="board-grid">
			<RequestCard
				v-for="request in requestStore.requests"
				:key="request.id"
				:request="request"
				@click="viewRequest(request.id)"
			/>
		</div>

		<div v-if="!requestStore.loading && requestStore.requests.length > 0" class="board-pagination">
			<NcButton
				:disabled="requestStore.pagination.offset === 0"
				@click="requestStore.prevPage()"
			>
				Previous
			</NcButton>
			<span>
				{{ requestStore.pagination.offset + 1 }} -
				{{ Math.min(
					requestStore.pagination.offset + requestStore.pagination.limit,
					requestStore.pagination.total
				) }}
				of {{ requestStore.pagination.total }}
			</span>
			<NcButton
				:disabled="!requestStore.hasMore"
				@click="requestStore.nextPage()"
			>
				Next
			</NcButton>
		</div>

		<NcModal
			v-if="showNewRequestModal"
			size="large"
			@close="showNewRequestModal = false"
		>
			<NewRequestForm @created="onRequestCreated" @cancel="showNewRequestModal = false" />
		</NcModal>
	</div>
</template>

<style scoped>
.request-board {
	padding: 20px;
}

.board-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.board-header h1 {
	margin: 0;
}

.board-filters {
	display: flex;
	gap: 10px;
	margin-bottom: 20px;
	flex-wrap: wrap;
}

.board-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
	gap: 20px;
}

.board-pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 20px;
	margin-top: 20px;
	padding: 20px;
}
</style>
