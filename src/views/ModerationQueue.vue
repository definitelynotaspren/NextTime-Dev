<!--
SPDX-FileCopyrightText: 2026 NextTime contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { getCurrentUser } from '@nextcloud/auth'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CommentItem from '../components/CommentItem.vue'
import { errorMessage, type RequestComment } from '../stores/requestStore'

const currentUserId = getCurrentUser()?.uid ?? null
const comments = ref<RequestComment[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const busyId = ref<number | null>(null)

const load = async () => {
	try {
		const { data } = await axios.get<RequestComment[]>(generateUrl('/apps/timebank/api/comments/flagged'))
		comments.value = data
		error.value = null
	} catch (e) {
		error.value = errorMessage(e, 'Failed to load flagged comments')
	} finally {
		loading.value = false
	}
}

const act = async (comment: RequestComment, task: () => Promise<unknown>) => {
	busyId.value = comment.id
	try {
		await task()
		await load()
	} catch (e) {
		error.value = errorMessage(e)
	} finally {
		busyId.value = null
	}
}

const remove = (comment: RequestComment) => act(comment, () => axios.delete(generateUrl(`/apps/timebank/api/comments/${comment.id}`)))
const dismiss = (comment: RequestComment) => act(comment, () => axios.post(generateUrl(`/apps/timebank/api/comments/${comment.id}/dismiss`)))

onMounted(load)
</script>

<template>
	<div class="moderation-queue">
		<h1>Flagged comments</h1>
		<p class="subtitle">
			Comments that members have flagged. Keep a comment to clear its flags, or remove it. Removals are
			kept on record with your name.
		</p>

		<NcLoadingIcon v-if="loading" :size="44" />
		<p v-else-if="error" class="error" role="alert">
			{{ error }}
		</p>
		<NcEmptyContent v-else-if="comments.length === 0" name="Nothing flagged">
			<template #description>
				No comments have counting flags right now.
			</template>
		</NcEmptyContent>
		<div v-for="comment in comments"
			v-else
			:key="comment.id"
			class="queue-item">
			<RouterLink :to="{ name: 'RequestDetail', params: { id: comment.requestId } }">
				On request #{{ comment.requestId }}
			</RouterLink>
			<CommentItem
				:comment="comment"
				:current-user-id="currentUserId"
				can-moderate
				:busy="busyId === comment.id"
				@remove="remove"
				@dismiss="dismiss" />
		</div>
	</div>
</template>

<style scoped>
.moderation-queue {
	padding: 20px;
	max-width: 800px;
}

.subtitle {
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error-text);
}

.queue-item {
	margin-bottom: 16px;
}
</style>
