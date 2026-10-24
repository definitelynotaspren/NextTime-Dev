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
import AdminCategories from '../views/AdminCategories.vue'
import AdminAdjustments from '../views/AdminAdjustments.vue'
import GovernanceLog from '../views/GovernanceLog.vue'
import ChapterCheck from '../views/ChapterCheck.vue'
import IntakeView from '../views/IntakeView.vue'

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
		path: '/admin/categories',
		name: 'AdminCategories',
		component: AdminCategories,
	},
	{
		path: '/admin/adjustments',
		name: 'AdminAdjustments',
		component: AdminAdjustments,
	},
	{
		path: '/intake',
		name: 'IntakeView',
		component: IntakeView,
	},
	{
		path: '/chapters',
		name: 'ChapterCheck',
		component: ChapterCheck,
	},
	{
		path: '/governance',
		name: 'GovernanceLog',
		component: GovernanceLog,
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
