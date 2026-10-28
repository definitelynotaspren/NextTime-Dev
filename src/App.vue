<script setup lang="ts">
import { onMounted, ref } from 'vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { RouterView, useRoute } from 'vue-router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import { useCategoryStore } from './stores/categoryStore'
import { useBalanceStore } from './stores/balanceStore'

const route = useRoute()
const categoryStore = useCategoryStore()
const balanceStore = useBalanceStore()

// Organizer links are a convenience: every endpoint still enforces its own rules.
const isStaff = ref(false)
const isModerator = ref(false)
const isAdmin = ref(false)
const isOrganizer = ref(false)
const isFacilitator = ref(false)
const isChapterMember = ref(false)

const fetchRoles = async () => {
	try {
		const { data } = await axios.get<{ nationalOrganizer: boolean, chapterMember: boolean, facilitator: boolean, facilitatorOf: string[], moderator: boolean, admin: boolean }>(generateUrl('/apps/timebank/api/roles'))
		isStaff.value = data.nationalOrganizer || data.facilitator
		isModerator.value = data.moderator
		isAdmin.value = data.admin
		isOrganizer.value = data.nationalOrganizer
		isFacilitator.value = data.facilitator
		isChapterMember.value = data.chapterMember
	} catch {
		isStaff.value = false
		isModerator.value = false
		isAdmin.value = false
		isOrganizer.value = false
		isFacilitator.value = false
		isChapterMember.value = false
	}
}

onMounted(() => {
	categoryStore.fetchCategories()
	balanceStore.fetchMyBalance()
	fetchRoles()
})
</script>

<template>
	<NcContent app-name="timebank">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					:name="'Request Board'"
					:to="{ name: 'Board' }"
					:active="route.name === 'Board'">
					<template #icon>
						<span class="nav-icon">&#127974;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'My Activity'"
					:to="{ name: 'MyRequests' }"
					:active="route.name === 'MyRequests'">
					<template #icon>
						<span class="nav-icon">&#128100;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'Submit Earnings'"
					:to="{ name: 'SubmitEarning' }"
					:active="route.name === 'SubmitEarning'">
					<template #icon>
						<span class="nav-icon">&#10133;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'My Balance (' + (balanceStore.myBalance?.balance ?? 0) + ' hrs)'"
					:to="{ name: 'MyBalance' }"
					:active="route.name === 'MyBalance'">
					<template #icon>
						<span class="nav-icon">&#128176;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'Public Ledger'"
					:to="{ name: 'PublicLedger' }"
					:active="route.name === 'PublicLedger'">
					<template #icon>
						<span class="nav-icon">&#128218;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'Approvals & Votes'"
					:to="{ name: 'AdminApprovals' }"
					:active="route.name === 'AdminApprovals'">
					<template #icon>
						<span class="nav-icon">&#9989;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'Governance Log'"
					:to="{ name: 'GovernanceLog' }"
					:active="route.name === 'GovernanceLog'">
					<template #icon>
						<span class="nav-icon">&#128220;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="isAdmin || isOrganizer"
					:name="'Form Intake'"
					:to="{ name: 'IntakeView' }"
					:active="route.name === 'IntakeView'">
					<template #icon>
						<span class="nav-icon">&#128229;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="isAdmin"
					:name="'Import'"
					:to="{ name: 'ImportView' }"
					:active="route.name === 'ImportView'">
					<template #icon>
						<span class="nav-icon">&#128230;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'Chapters'"
					:to="{ name: 'ChapterCheck' }"
					:active="route.name === 'ChapterCheck'">
					<template #icon>
						<span class="nav-icon">&#128506;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="isAdmin"
					:name="'Categories'"
					:to="{ name: 'AdminCategories' }"
					:active="route.name === 'AdminCategories'">
					<template #icon>
						<span class="nav-icon">&#127991;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="isAdmin || isFacilitator || isOrganizer || isChapterMember"
					:name="'Adjustments'"
					:to="{ name: 'AdminAdjustments' }"
					:active="route.name === 'AdminAdjustments'">
					<template #icon>
						<span class="nav-icon">&#9878;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					v-if="isModerator"
					:name="'Flagged Comments'"
					:to="{ name: 'ModerationQueue' }"
					:active="route.name === 'ModerationQueue'">
					<template #icon>
						<span class="nav-icon">&#128681;</span>
					</template>
				</NcAppNavigationItem>
				<template v-if="isStaff">
					<NcAppNavigationItem
						:name="'Organizer Desk'"
						:to="{ name: 'OrganizerDesk' }"
						:active="route.name === 'OrganizerDesk'">
						<template #icon>
							<span class="nav-icon">&#128203;</span>
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						:name="'Data Schema'"
						:to="{ name: 'SchemaView' }"
						:active="route.name === 'SchemaView'">
						<template #icon>
							<span class="nav-icon">&#128279;</span>
						</template>
					</NcAppNavigationItem>
				</template>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<div class="timebank-view">
				<RouterView />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
/* Nextcloud draws the navigation toggle over the top-left of the content area. */
.timebank-view {
	padding-inline-start: var(--default-clickable-area, 44px);
}

.nav-icon {
	font-size: 16px;
	width: 24px;
	text-align: center;
}
</style>
