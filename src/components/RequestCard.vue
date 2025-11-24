<script setup lang="ts">
import { computed } from 'vue'
import { useCategoryStore } from '../stores/categoryStore'

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
	volunteerCount?: number
	commentCount?: number
}

const props = defineProps<{
	request: Request
}>()

const categoryStore = useCategoryStore()

const category = computed(() => {
	return categoryStore.getCategoryById(props.request.categoryId)
})

const categoryName = computed(() => category.value?.name || 'Other')

const priorityClass = computed(() => `priority-${props.request.priority}`)

const statusClass = computed(() => `status-${props.request.status}`)

const truncatedDescription = computed(() => {
	const maxLength = 120
	if (props.request.description.length <= maxLength) {
		return props.request.description
	}
	return props.request.description.substring(0, maxLength) + '...'
})

const formatDate = (dateString: string | null) => {
	if (!dateString) return null
	const date = new Date(dateString)
	return date.toLocaleDateString('en-US', {
		month: 'short',
		day: 'numeric',
	})
}
</script>

<template>
	<div class="request-card" :class="priorityClass">
		<div class="card-header">
			<span class="category-badge">{{ categoryName }}</span>
			<span class="status-badge" :class="statusClass">
				{{ request.status.replace('_', ' ') }}
			</span>
		</div>

		<h3 class="card-title">{{ request.title }}</h3>

		<p class="card-description">{{ truncatedDescription }}</p>

		<div class="card-meta">
			<div class="meta-item">
				<span class="icon">&#9201;</span>
				<span>{{ request.hoursBudget }} hours</span>
			</div>
			<div v-if="request.location" class="meta-item">
				<span class="icon">&#128205;</span>
				<span>{{ request.location }}</span>
			</div>
			<div v-if="request.deadline" class="meta-item">
				<span class="icon">&#128197;</span>
				<span>{{ formatDate(request.deadline) }}</span>
			</div>
		</div>

		<div class="card-footer">
			<div class="volunteer-count">
				<span>&#128100; {{ request.volunteerCount ?? 0 }} volunteer(s)</span>
			</div>
			<div class="comment-count">
				&#128172; {{ request.commentCount ?? 0 }}
			</div>
		</div>
	</div>
</template>

<style scoped>
.request-card {
	background: var(--color-main-background);
	border: 2px solid var(--color-border);
	border-radius: 8px;
	padding: 16px;
	cursor: pointer;
	transition: all 0.2s ease;
}

.request-card:hover {
	border-color: var(--color-primary);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
	transform: translateY(-2px);
}

.request-card.priority-urgent {
	border-left: 4px solid var(--color-error);
}

.request-card.priority-normal {
	border-left: 4px solid var(--color-primary);
}

.request-card.priority-flexible {
	border-left: 4px solid var(--color-success);
}

.card-header {
	display: flex;
	justify-content: space-between;
	margin-bottom: 12px;
}

.category-badge {
	background: var(--color-background-hover);
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 12px;
}

.status-badge {
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 12px;
	text-transform: uppercase;
}

.status-badge.status-open {
	background: var(--color-success);
	color: white;
}

.status-badge.status-in_progress {
	background: var(--color-warning);
	color: white;
}

.status-badge.status-completed {
	background: var(--color-background-dark);
	color: white;
}

.card-title {
	font-size: 18px;
	font-weight: 600;
	margin: 8px 0;
}

.card-description {
	color: var(--color-text-lighter);
	margin: 8px 0;
	line-height: 1.5;
}

.card-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	margin: 12px 0;
}

.meta-item {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 14px;
	color: var(--color-text-lighter);
}

.card-footer {
	display: flex;
	justify-content: space-between;
	margin-top: 12px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.volunteer-count {
	display: flex;
	align-items: center;
	gap: 6px;
}

.comment-count {
	color: var(--color-text-lighter);
}
</style>
