import { createRouter, createWebHashHistory } from 'vue-router'
import RequestBoard from '../views/RequestBoard.vue'
import RequestDetail from '../views/RequestDetail.vue'
import MyRequests from '../views/MyRequests.vue'
import SubmitEarning from '../views/SubmitEarning.vue'
import AdminApprovals from '../views/AdminApprovals.vue'
import PublicLedger from '../views/PublicLedger.vue'
import MyBalance from '../views/MyBalance.vue'
import OrganizerDesk from '../views/OrganizerDesk.vue'
import SchemaView from '../views/SchemaView.vue'
import ModerationQueue from '../views/ModerationQueue.vue'

const routes = [
	{
		path: '/',
		name: 'Board',
		component: RequestBoard,
	},
	{
		path: '/request/:id',
		name: 'RequestDetail',
		component: RequestDetail,
		props: true,
	},
	{
		path: '/my-requests',
		name: 'MyRequests',
		component: MyRequests,
	},
	{
		path: '/earn',
		name: 'SubmitEarning',
		component: SubmitEarning,
	},
	{
		path: '/admin/approvals',
		name: 'AdminApprovals',
		component: AdminApprovals,
	},
	{
		path: '/ledger',
		name: 'PublicLedger',
		component: PublicLedger,
	},
	{
		path: '/ledger/user/:userId',
		name: 'UserLedger',
		component: PublicLedger,
		props: true,
	},
	{
		path: '/balance',
		name: 'MyBalance',
		component: MyBalance,
	},
	{
		path: '/desk',
		name: 'OrganizerDesk',
		component: OrganizerDesk,
	},
	{
		path: '/moderation',
		name: 'ModerationQueue',
		component: ModerationQueue,
	},
	{
		path: '/schema',
		name: 'SchemaView',
		component: SchemaView,
	},
]

const router = createRouter({
	history: createWebHashHistory(),
	routes,
})

export default router
