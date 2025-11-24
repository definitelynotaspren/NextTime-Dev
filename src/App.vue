<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterView, RouterLink, useRoute } from 'vue-router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcContent from '@nextcloud/vue/components/NcContent'
import { useCategoryStore } from './stores/categoryStore'
import { useBalanceStore } from './stores/balanceStore'

const route = useRoute()
const categoryStore = useCategoryStore()
const balanceStore = useBalanceStore()

onMounted(() => {
	categoryStore.fetchCategories()
	balanceStore.fetchMyBalance()
})
</script>

<template>
	<NcContent app-name="timebank">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem
					:name="'Request Board'"
					:to="{ name: 'Board' }"
					:active="route.name === 'Board'"
				>
					<template #icon>
						<span class="nav-icon">&#127974;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'My Requests'"
					:to="{ name: 'MyRequests' }"
					:active="route.name === 'MyRequests'"
				>
					<template #icon>
						<span class="nav-icon">&#128100;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'Submit Earnings'"
					:to="{ name: 'SubmitEarning' }"
					:active="route.name === 'SubmitEarning'"
				>
					<template #icon>
						<span class="nav-icon">&#10133;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'My Balance (' + (balanceStore.myBalance?.balance ?? 0) + ' hrs)'"
					:to="{ name: 'MyBalance' }"
					:active="route.name === 'MyBalance'"
				>
					<template #icon>
						<span class="nav-icon">&#128176;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'Public Ledger'"
					:to="{ name: 'PublicLedger' }"
					:active="route.name === 'PublicLedger'"
				>
					<template #icon>
						<span class="nav-icon">&#128218;</span>
					</template>
				</NcAppNavigationItem>
				<NcAppNavigationItem
					:name="'Admin Approvals'"
					:to="{ name: 'AdminApprovals' }"
					:active="route.name === 'AdminApprovals'"
				>
					<template #icon>
						<span class="nav-icon">&#9989;</span>
					</template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent>
			<RouterView />
		</NcAppContent>
	</NcContent>
</template>

<style scoped>
.nav-icon {
	font-size: 16px;
	width: 24px;
	text-align: center;
}
</style>
