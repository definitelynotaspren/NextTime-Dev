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
const go = async (page, route) => { await page.goto(`${APP}/#${route}`, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(1500) }
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
