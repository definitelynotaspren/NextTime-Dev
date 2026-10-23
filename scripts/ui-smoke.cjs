#!/usr/bin/env node
// Browser smoke test: drives the real UI the way a person would.
//
// The API smoke test (scripts/smoke-test.sh) posts JSON directly, so it cannot see
// bugs in what the forms actually send (for example an NcSelect handing back an
// option object instead of its id). This script fills the forms in headless
// Chromium and checks what reaches the server.
//
// Usage:  node scripts/ui-smoke.cjs [BASE_URL]
//   BASE_URL defaults to http://127.0.0.1:8081. Needs the dev users and the built
//   frontend (scripts/dev-native.sh setup). Set NC_DIR if the server is not ../nc-server.
// Needs Playwright with a Chromium build; it is not a project dependency:
//   npm i -g playwright   (or set PLAYWRIGHT_BROWSERS_PATH to an existing install)
// Exit code is the number of failed checks.

const { execFileSync } = require('node:child_process')
const path = require('node:path')

function loadPlaywright() {
	for (const id of ['playwright', '/opt/node-tools/node_modules/playwright']) {
		try { return require(id) } catch { /* try the next location */ }
	}
	console.error('Playwright not found. Install it with: npm i -g playwright')
	process.exit(99)
}
const { chromium } = loadPlaywright()

const BASE = process.argv[2] || process.env.BASE_URL || 'http://127.0.0.1:8081'
const APP = `${BASE}/index.php/apps/timebank`
const ROOT = path.resolve(__dirname, '..')
const stamp = Date.now()
let fails = 0

const pass = (label) => console.log(`  \x1b[32mPASS\x1b[0m ${label}`)
const fail = (label, detail = '') => { fails++; console.log(`  \x1b[31mFAIL\x1b[0m ${label}${detail ? ' - ' + detail : ''}`) }
const check = (label, ok, detail) => (ok ? pass(label) : fail(label, detail))

function occ(...args) {
	try {
		execFileSync(path.join(ROOT, 'scripts/dev-native.sh'), ['occ', ...args], { stdio: 'ignore', env: process.env })
	} catch { /* group or membership already exists */ }
}

async function login(browser, user, pass) {
	const page = await (await browser.newContext({ viewport: { width: 1280, height: 1000 } })).newPage()
	page.responses = []
	page.on('response', async (r) => {
		const entry = { method: r.request().method(), url: r.url().replace(BASE, ''), status: r.status(), body: r.request().postData() }
		page.responses.push(entry)
	})
	await page.goto(`${BASE}/index.php/login`, { waitUntil: 'domcontentloaded' })
	await page.fill('input[name=user]', user)
	await page.fill('input[name=password]', pass)
	await page.click('button[type=submit]')
	await page.waitForLoadState('domcontentloaded')
	return page
}

const findResponse = (page, method, pattern) => [...page.responses].reverse().find((r) => r.method === method && pattern.test(r.url))
// Going to the route a page is already on does not remount it, so reload to see current data.
const go = async (page, route) => {
	const same = page.url().endsWith(`#${route}`)
	await page.goto(`${APP}/#${route}`, { waitUntil: 'domcontentloaded' })
	if (same) await page.reload({ waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(1500)
}
const pickFirstOption = async (page, placeholder) => {
	await page.getByPlaceholder(placeholder).first().click()
	await page.waitForTimeout(300)
	await page.locator('[role=option]').first().click()
	await page.waitForTimeout(500)
}

;(async () => {
	console.log(`NextTime UI smoke test against ${BASE}`)
	occ('group:add', 'timebank-organizer')
	occ('group:adduser', 'timebank-organizer', 'alice')

	// WebGL is needed by the map; software rendering works without a GPU.
	const browser = await chromium.launch({ args: ['--no-sandbox', '--use-angle=swiftshader', '--enable-unsafe-swiftshader', '--ignore-gpu-blocklist'] })
	const title = `UI smoke ${stamp}`

	console.log('- Navigation by role')
	const alice = await login(browser, 'alice', 'alice-pass-123')
	await go(alice, '/')
	check('organizer sees the Organizer Desk link', await alice.getByText('Organizer Desk', { exact: true }).count() > 0)
	const carol = await login(browser, 'carol', 'carol-pass-123')
	await go(carol, '/')
	check('member does not see the Organizer Desk link', await carol.getByText('Organizer Desk', { exact: true }).count() === 0)

	console.log('- Post a need through the form')
	await alice.getByText('+ Post New Need').click()
	await alice.waitForTimeout(800)
	await alice.fill('#title', title)
	await alice.fill('#description', 'Need two people to carry a couch up one flight of stairs.')
	await pickFirstOption(alice, 'Select category')
	await alice.fill('#hours', '2')
	await alice.fill('#location-text', '45.5231, -122.6765')
	await alice.locator('#location-text').blur()
	await alice.getByRole('button', { name: 'Post Request' }).click()
	await alice.waitForTimeout(2000)
	const post = findResponse(alice, 'POST', /\/api\/requests$/)
	check('form submission is accepted (201)', post?.status === 201, post && `${post.status} ${post.body}`)
	const list = await (await alice.request.get(`${APP}/api/requests`, { headers: { 'OCS-APIRequest': 'true' } })).json()
	const created = list.requests?.find((r) => r.title === title)
	check('request has a category', created && Number.isInteger(created.categoryId) && created.categoryId > 0, JSON.stringify(created))
	check('request has coordinates from the location text', created?.latitude === 45.5231 && created?.longitude === -122.6765)

	console.log('- Board filter')
	await go(alice, '/')
	await alice.getByPlaceholder('Status').first().click()
	await alice.waitForTimeout(300)
	await alice.getByRole('option', { name: 'Open', exact: true }).click()
	await alice.waitForTimeout(1200)
	const filtered = findResponse(alice, 'GET', /\/api\/requests\?.*status=/)
	check('status filter sends "open", not an object', filtered && /status=open(&|$)/.test(filtered.url), filtered?.url)

	console.log('- Map')
	await alice.getByRole('button', { name: 'Map View' }).click()
	let markers = 0
	for (let i = 0; i < 20 && markers === 0; i++) {
		await alice.waitForTimeout(500)
		markers = await alice.locator('.custom-marker').count()
	}
	check('map loads its style and draws markers', markers > 0, 'no markers; check the Content-Security-Policy and the style URL')

	console.log('- Volunteer, then earn')
	const bob = await login(browser, 'bob', 'bob-pass-123')
	await go(bob, `/request/${created?.id}`)
	await bob.getByPlaceholder('Hours you can contribute').fill('2')
	await bob.locator('textarea').last().fill('I have a truck and gloves.')
	await bob.getByRole('button', { name: 'Volunteer', exact: true }).click()
	await bob.waitForTimeout(1500)
	const vol = findResponse(bob, 'POST', /\/volunteer$/)
	check('volunteering is accepted (201)', vol?.status === 201, vol && `${vol.status} ${vol.body}`)

	await go(bob, '/earn')
	await pickFirstOption(bob, 'Select category')
	await bob.getByPlaceholder('How many hours did you work?').fill('1')
	await bob.locator('textarea').first().fill('Helped a neighbour carry groceries up the stairs.')
	await bob.getByRole('button', { name: 'Submit Claim' }).click()
	await bob.waitForTimeout(1500)
	const claim = findResponse(bob, 'POST', /\/earnings\/claim$/)
	check('earning claim is accepted (201)', claim?.status === 201, claim && `${claim.status} ${claim.body}`)

	console.log('- Several volunteers: accept, decline, withdraw, complete')
	const detail = async (page) => (await page.request.get(`${APP}/api/requests/${created?.id}`, { headers: { 'OCS-APIRequest': 'true' } })).json()
	const card = (page, who) => page.locator('.volunteer-card', { hasText: who })

	await go(carol, `/request/${created?.id}`)
	check('someone who has not offered sees the offer form', await carol.getByText('Offer to Help').count() > 0)
	await carol.getByPlaceholder('Hours you can contribute').fill('3')
	await carol.locator('textarea').last().fill('Happy to carry too.')
	await carol.getByRole('button', { name: 'Volunteer', exact: true }).click()
	await carol.waitForTimeout(1200)
	check('a second person can offer on the same request', (await detail(carol)).volunteers?.some((v) => v.volunteerId === 'carol'))
	check('once offered, the offer form is gone', await carol.getByText('Offer to Help').count() === 0)

	const admin = await login(browser, 'admin', 'admin')
	await go(admin, `/request/${created?.id}`)
	await admin.getByPlaceholder('Hours you can contribute').fill('1')
	await admin.getByRole('button', { name: 'Volunteer', exact: true }).click()
	await admin.waitForTimeout(1200)

	await go(alice, `/request/${created?.id}`)
	check('the requester does not see the offer form', await alice.getByText('Offer to Help').count() === 0)
	check('the requester cannot complete before accepting anyone', await alice.getByRole('button', { name: 'Mark complete', exact: true }).isDisabled())
	await card(alice, 'bob').getByRole('button', { name: 'Accept' }).click()
	await alice.waitForTimeout(1000)
	await card(alice, 'carol').getByRole('button', { name: 'Accept' }).click()
	await alice.waitForTimeout(1000)
	await card(alice, 'admin').getByRole('button', { name: 'Decline' }).click()
	await alice.waitForTimeout(1000)
	let state = await detail(alice)
	const statusOf = (who) => state.volunteers?.find((v) => v.volunteerId === who)?.status
	check('bob and carol are accepted, admin is declined', statusOf('bob') === 'accepted' && statusOf('carol') === 'accepted' && statusOf('admin') === 'declined', JSON.stringify(state.volunteers?.map((v) => [v.volunteerId, v.status])))
	check('the request is in progress', state.request?.status === 'in_progress')
	check('a banner names everyone helping', await alice.getByText(/In progress with/).count() > 0)

	await go(carol, `/request/${created?.id}`)
	await card(carol, 'carol').getByRole('button', { name: 'Withdraw' }).click()
	const confirmWithdraw = carol.getByRole('dialog').getByRole('button', { name: 'Withdraw', exact: true })
	check('withdrawing accepted help is blocked until a reason is given', await confirmWithdraw.isDisabled())
	await carol.getByRole('dialog').locator('textarea').fill('Family emergency')
	await confirmWithdraw.click()
	await carol.waitForTimeout(1500)
	state = await detail(alice)
	check('carol is out, bob keeps the request in progress', statusOf('carol') === undefined && state.request?.status === 'in_progress')
	await go(alice, `/request/${created?.id}`)
	check('the requester sees why she withdrew', await alice.getByText('Family emergency').count() > 0)

	await alice.getByRole('button', { name: 'Mark complete', exact: true }).first().click()
	const dialog = alice.getByRole('dialog')
	check('the confirmation lists who will be completed', await dialog.getByText('bob').count() > 0 && await dialog.getByText('Hours are not credited automatically').count() > 0)
	await dialog.getByRole('button', { name: 'Mark complete' }).click()
	await alice.waitForTimeout(1500)
	state = await detail(alice)
	check('completing finishes the request and bob', state.request?.status === 'completed' && statusOf('bob') === 'completed')
	await go(bob, `/request/${created?.id}`)
	check('bob is pointed to claim his hours', await bob.getByRole('link', { name: 'Claim your hours' }).count() > 0)

	console.log('- Edit, cancel, My offers')
	const editTitle = `UI edit ${stamp}`
	await go(alice, '/')
	await alice.getByText('+ Post New Need').click()
	await alice.waitForTimeout(800)
	await alice.fill('#title', editTitle)
	await alice.fill('#description', 'A second request used to try editing and cancelling.')
	await pickFirstOption(alice, 'Select category')
	await alice.fill('#hours', '1')
	await alice.getByRole('button', { name: 'Post Request' }).click()
	await alice.waitForTimeout(2000)
	const list2 = await (await alice.request.get(`${APP}/api/requests`, { headers: { 'OCS-APIRequest': 'true' } })).json()
	const second = list2.requests?.find((r) => r.title === editTitle)
	check('second request is posted', !!second)

	await go(alice, `/request/${second?.id}`)
	await alice.getByRole('button', { name: 'Edit', exact: true }).click()
	await alice.waitForTimeout(600)
	const editDialog = alice.getByRole('dialog')
	check('the edit form is filled with the current title', await editDialog.locator('#title input, input#title').first().inputValue() === editTitle)
	await editDialog.locator('#title input, input#title').first().fill(`${editTitle} (edited)`)
	await editDialog.getByRole('button', { name: 'Save changes' }).click()
	await alice.waitForTimeout(1500)
	const put = findResponse(alice, 'PUT', /\/api\/requests\/\d+$/)
	check('saving an edit is accepted (200)', put?.status === 200, put && `${put.status} ${put.body}`)
	check('the new title is shown', await alice.getByRole('heading', { name: `${editTitle} (edited)` }).count() > 0)

	await go(bob, `/request/${second?.id}`)
	await bob.getByPlaceholder('Hours you can contribute').fill('1')
	await bob.getByRole('button', { name: 'Volunteer', exact: true }).click()
	await bob.waitForTimeout(1500)
	await go(bob, '/my-requests')
	await bob.getByRole('tab', { name: /My offers/ }).click()
	check('bob sees his offer under My offers', await bob.locator('.offer-row', { hasText: `${editTitle} (edited)` }).count() === 1)

	await go(alice, `/request/${second?.id}`)
	await alice.getByRole('button', { name: 'Cancel request' }).first().click()
	await alice.getByRole('dialog').getByRole('button', { name: 'Cancel request' }).click()
	await alice.waitForTimeout(1500)
	const cancelled = await (await alice.request.get(`${APP}/api/requests/${second?.id}`, { headers: { 'OCS-APIRequest': 'true' } })).json()
	check('cancelling through the dialog finishes the request', cancelled.request?.status === 'cancelled')
	await go(bob, '/my-requests')
	await bob.getByRole('tab', { name: /My offers/ }).click()
	check('a cancelled request can no longer be withdrawn from', await bob.locator('.offer-row', { hasText: 'edited' }).getByRole('button', { name: 'Withdraw' }).count() === 0)

	console.log('- Comments: flag, moderate, ledger link')
	await go(bob, `/request/${created?.id}`)
	await bob.getByPlaceholder('Add a comment...').fill(`Comment ${stamp} to be flagged`)
	await bob.getByRole('button', { name: 'Post Comment' }).click()
	await bob.waitForTimeout(1500)
	check('bob cannot flag or remove his own comment', await bob.locator('.comment', { hasText: `Comment ${stamp}` }).getByRole('button', { name: /Flag|Remove/ }).count() === 0)

	await go(carol, `/request/${created?.id}`)
	await carol.locator('.comment', { hasText: `Comment ${stamp}` }).getByRole('button', { name: 'Flag' }).click()
	await carol.getByRole('dialog').locator('textarea').fill('Off topic')
	await carol.getByRole('dialog').getByRole('button', { name: 'Flag comment' }).click()
	await carol.waitForTimeout(1500)
	const flag = findResponse(carol, 'POST', /\/api\/comments\/\d+\/flag$/)
	check('flagging is accepted (201)', flag?.status === 201, flag && `${flag.status} ${flag.body}`)
	check('carol is told she flagged it and cannot remove it', await carol.getByText('You flagged this').count() > 0 && await carol.getByRole('button', { name: 'Remove' }).count() === 0)

	await go(alice, '/')
	check('moderators see the Flagged Comments link', await alice.getByText('Flagged Comments', { exact: true }).count() > 0)
	check('members do not', await carol.getByText('Flagged Comments', { exact: true }).count() === 0)
	await go(alice, '/moderation')
	const queued = alice.locator('.queue-item', { hasText: `Comment ${stamp}` })
	check('the flagged comment is in the queue with the reason', await queued.count() === 1 && await queued.getByText('Off topic').count() > 0)
	await queued.getByRole('button', { name: 'Remove' }).click()
	await alice.waitForTimeout(1500)
	check('removing it clears the queue', await alice.locator('.queue-item', { hasText: `Comment ${stamp}` }).count() === 0)
	await go(carol, `/request/${created?.id}`)
	check('members then see "Removed by a moderator"', await carol.getByText('Removed by a moderator').count() > 0 && await carol.getByText(`Comment ${stamp}`).count() === 0)

	await carol.locator('.volunteer-card a', { hasText: 'bob' }).first().click()
	await carol.waitForTimeout(1500)
	check('a member name opens that member\'s ledger', /#\/ledger\/user\/bob/.test(carol.url()) && await carol.getByRole('heading', { name: 'Ledger for bob' }).count() > 0)

	console.log('- Admin tools: categories, adjustments, second approval')
	occ('group:add', 'chapter-mt-hood')
	occ('group:adduser', 'chapter-mt-hood', 'bob')
	occ('group:adduser', 'chapter-mt-hood', 'alice')
	const catName = `UI cat ${stamp}`
	await go(admin, '/admin/categories')
	await admin.getByRole('button', { name: 'New category' }).click()
	await admin.getByLabel('Name').fill(catName)
	await admin.getByRole('button', { name: 'Save', exact: true }).click()
	await admin.waitForTimeout(1500)
	const catRow = admin.locator('tr', { hasText: catName })
	check('admin adds a category', await catRow.count() === 1)
	check('an unused category can be deleted', await catRow.getByRole('button', { name: 'Delete' }).isEnabled())
	check('a category with past work cannot be deleted', await admin.locator('tr', { hasText: 'Administrative' }).getByRole('button', { name: 'Delete' }).isDisabled())
	await catRow.getByRole('button', { name: 'Archive' }).click()
	await admin.waitForTimeout(1200)
	check('archiving marks it', await admin.locator('tr', { hasText: catName }).getByText('archived').count() > 0)
	await go(alice, '/')
	await alice.getByText('+ Post New Need').click()
	await alice.getByPlaceholder('Select category').first().click()
	await alice.waitForTimeout(400)
	check('an archived category is not offered for new requests', await alice.getByRole('option', { name: catName }).count() === 0)
	await go(admin, '/admin/categories')
	admin.once('dialog', (d) => d.accept())
	await admin.locator('tr', { hasText: catName }).getByRole('button', { name: 'Delete' }).click()
	await admin.waitForTimeout(1500)
	check('deleting it removes it', await admin.locator('tr', { hasText: catName }).count() === 0)

	await go(admin, '/admin/adjustments')
	await admin.getByLabel('Member (Nextcloud user name)').fill('alice')
	await admin.getByLabel('Hours (negative to deduct)').fill('12')
	await admin.getByLabel('Reason (public, 10+ characters)').fill(`UI smoke ${stamp}: large adjustment`)
	await admin.getByRole('button', { name: 'Submit' }).click()
	await admin.waitForTimeout(1500)
	const pendingAdj = admin.locator('.item[data-status="pending"]', { hasText: `UI smoke ${stamp}` })
	check('a large adjustment is pending', await pendingAdj.count() === 1)
	check('the proposer cannot approve it, only withdraw', await pendingAdj.getByRole('button', { name: 'Approve' }).count() === 0 && await pendingAdj.getByRole('button', { name: 'Withdraw' }).count() === 1)
	await go(bob, '/admin/adjustments')
	const forBob = bob.locator('.item[data-status="pending"]', { hasText: `UI smoke ${stamp}` })
	check('a facilitator of the member\'s chapter sees it and can approve', await forBob.getByRole('button', { name: 'Approve' }).count() === 1)
	await forBob.getByRole('button', { name: 'Approve' }).click()
	await bob.waitForTimeout(1500)
	check('approving applies it', await bob.locator('.item[data-status="approved"]', { hasText: `UI smoke ${stamp}` }).count() === 1)
	await go(bob, '/ledger')
	check('the ledger names both people', await bob.getByText(new RegExp(`proposed by admin, approved by bob: UI smoke ${stamp}`)).count() > 0)
	await go(bob, '/governance')
	check('members can read the governance log', await bob.getByText(/applied an adjustment for/).count() > 0)

	occ('group:removeuser', 'chapter-mt-hood', 'alice')
	await go(admin, '/admin/approvals')
	check('an admin sees no approve button on their own claim', await admin.locator('.claim-card', { hasText: 'This is your claim' }).getByRole('button', { name: 'Approve' }).count() === 0)

	console.log('- Organizer desk')
	await go(alice, '/desk')
	await alice.getByRole('button', { name: 'Add a row by hand' }).click()
	await alice.waitForTimeout(400)
	const select = (name, value) => alice.locator('label.field', { hasText: name }).first().locator('select').selectOption(value)
	const text = (name, value) => alice.locator('label.field', { hasText: name }).first().locator('input').fill(value)
	await select('need_or_offer', 'offer')
	await select('category', 'tech_help')
	await text('summary', `Desk ${stamp}`)
	await text('zip', '97055')
	await select('urgency', 'ongoing')
	await text('posted', '2026-10-23')
	await text('expires', '2099-12-01')
	await select('contact_route', 'chapter_organizer')
	await select('status', 'open')
	await alice.getByRole('button', { name: 'Save row' }).click()
	await alice.waitForTimeout(1500)
	const row = alice.locator('.row', { hasText: `Desk ${stamp}` })
	check('manual row is saved and listed', await row.count() === 1)
	await row.getByRole('button', { name: 'Assign to me' }).click()
	await alice.waitForTimeout(1000)
	await alice.getByRole('button', { name: 'Assigned to me' }).click()
	await alice.waitForTimeout(1000)
	check('assigned row shows under "Assigned to me"', await alice.locator('.row', { hasText: `Desk ${stamp}` }).count() === 1)

	await go(carol, '/desk')
	check('member sees "Not available" and no desk controls', await carol.getByText('Not available').count() > 0 && await carol.locator('.controls').count() === 0)

	await browser.close()
	console.log(fails === 0 ? '\nAll UI checks passed.' : `\n${fails} UI check(s) failed.`)
	process.exit(fails)
})().catch((e) => { console.error('UI smoke test crashed:', String(e).slice(0, 500)); process.exit(98) })
