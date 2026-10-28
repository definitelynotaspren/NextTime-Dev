<!--
SPDX-FileCopyrightText: 2026 NextTime contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, type PropType } from 'vue'
import { RouterLink } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import type { RequestComment } from '../stores/requestStore'

const props = defineProps({
	comment: {
		type: Object as PropType<RequestComment>,
		required: true,
	},
	currentUserId: {
		type: String as PropType<string | null>,
		default: null,
	},
	/** Nextcloud admins and project leads: they see hidden text, reasons, and can remove. */
	canModerate: {
		type: Boolean,
		default: false,
	},
	busy: {
		type: Boolean,
		default: false,
	},
})

const emit = defineEmits<{
	(e: 'flag', comment: RequestComment): void
	(e: 'remove', comment: RequestComment): void
	(e: 'dismiss', comment: RequestComment): void
}>()

const isMine = computed(() => props.comment.userId === props.currentUserId)
const canFlag = computed(() => !isMine.value && props.comment.state !== 'removed' && !props.comment.flaggedByMe)
</script>

<template>
	<div class="comment" :data-state="comment.state">
		<div class="comment-header">
			<strong>
				<RouterLink v-if="comment.userId" :to="{ name: 'UserLedger', params: { userId: comment.userId } }">{{ comment.userId }}</RouterLink>
				<template v-else>
					{{ comment.authorLabel ?? 'Requester' }}
				</template>
			</strong>
			<span class="comment-date">{{ new Date(comment.createdAt).toLocaleString() }}</span>
		</div>

		<p v-if="comment.state === 'removed' && comment.comment === null" class="comment-note">
			Removed by a moderator.
		</p>
		<p v-else-if="comment.state === 'hidden' && comment.comment === null" class="comment-note">
			Hidden by community flags ({{ comment.flagCount }}). Moderators will review it.
		</p>
		<template v-else>
			<p v-if="comment.state !== 'visible'" class="comment-note">
				{{ comment.state === 'removed' ? `Removed by ${comment.removedBy}. Only moderators see this text.` : `Hidden from members by ${comment.flagCount} flags.` }}
			</p>
			<p>{{ comment.comment }}</p>
		</template>

		<div v-if="comment.flagCount > 0 && comment.state === 'visible'" class="comment-note">
			Flagged by {{ comment.flagCount }} {{ comment.flagCount === 1 ? 'member' : 'members' }}
			({{ 3 - comment.flagCount }} more would hide it).
		</div>

		<ul v-if="canModerate && comment.flags?.length" class="flag-list">
			<li v-for="flag in comment.flags" :key="flag.id">
				<strong>{{ flag.userId }}</strong>: {{ flag.reason || 'no reason given' }}
			</li>
		</ul>

		<div class="comment-actions">
			<NcButton v-if="canFlag"
				variant="tertiary"
				:disabled="busy"
				@click="emit('flag', comment)">
				Flag
			</NcButton>
			<span v-else-if="comment.flaggedByMe" class="comment-note">You flagged this</span>
			<template v-if="canModerate && comment.state !== 'removed'">
				<NcButton v-if="comment.flagCount > 0" :disabled="busy" @click="emit('dismiss', comment)">
					Keep (dismiss flags)
				</NcButton>
				<NcButton variant="error" :disabled="busy" @click="emit('remove', comment)">
					Remove
				</NcButton>
			</template>
		</div>
	</div>
</template>

<style scoped>
.comment {
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.comment[data-state="hidden"],
.comment[data-state="removed"] {
	opacity: 0.8;
}

.comment-header {
	display: flex;
	justify-content: space-between;
}

.comment-date,
.comment-note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.flag-list {
	margin: 6px 0;
	padding-inline-start: 18px;
	font-size: 13px;
}

.comment-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}
</style>
