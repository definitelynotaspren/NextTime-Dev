# Upgrading to vite 8 (PR #60): what changes, what must move first, and the recipe

*2026-10-26, section 3 and the recipe revised 2026-10-28. For Brigidt. PR #60 (dependabot, vite 7.3.6 → 8.3.5) is held because it cannot install, and
the thing in the way is not vite. OBSERVED lines name the command or file; JUDGED lines are marked.*

## 1 · Why #60 cannot install today (OBSERVED)

- `npm ci` on #60's tip fails: `ERESOLVE … peer vite "^7.3.6" from @nextcloud/vite-config@2.5.4`
  (measured 2026-10-26 in a scratch worktree; the closure sheet of the same day records it).
- `main` pins `"vite": "^7.2.4"` and `"@nextcloud/vite-config": "^2.5.2"` (`package.json:37`, `:33`); the lock
  resolves vite `7.3.6` and vite-config `2.5.4` (`package-lock.json`, the `node_modules/vite` and
  `node_modules/@nextcloud/vite-config` entries). #60 moves only the vite line to `^8.3.5`.
- `vite.config.ts` is twelve lines and hands everything to `createAppConfig` from `@nextcloud/vite-config`
  (`vite.config.ts:1-13`) with three options: `createEmptyCSSEntryPoints`, `extractLicenseInformation`,
  `thirdPartyLicense`. The app sets no `rollupOptions`, `esbuild`, or `build.target` of its own and uses
  no `import.meta.hot` (`git grep` over `src/`, `vite.config.ts`, `package.json`: no hits). Browser targets
  come from `browserslist: extends @nextcloud/browserslist-config` (`package.json:13-15`).

So the app's whole exposure to vite is through `@nextcloud/vite-config`. When that package admits vite 8,
#60 is a lockfile change plus one build.

## 2 · What vite 8 changes that touches this app (OBSERVED from vite's `CHANGELOG.md`, 8.0.0 dated 2026-03-12)

Read from a copy of `packages/vite/CHANGELOG.md` in the vitejs/vite repository fetched 2026-10-26; the
section `## [8.0.0]` lists under **BREAKING CHANGES**:

| Change | Issue | Touches this app? (JUDGED) |
|---|---|---|
| "the epic `rolldown-vite` merge": Rolldown replaces Rollup (and esbuild for most transforms) as the bundler | #21189 | Yes, but only through `@nextcloud/vite-config`: it is the one that passes bundler options. The app passes none. Falsifier: `npm run build` output differs in file names or chunking; compare `js/` before and after. |
| default browser target updated | #21193 | No, if vite-config still feeds `browserslist` to vite (its 3.0 beta lists `browserslist >=4.20` as a peer, so it does). Falsifier: the built bundle uses syntax `@nextcloud/browserslist-config` excludes. |
| `import.meta.hot.accept` resolution fallback removed | #21382 | No: the app does not call `import.meta.hot`. |
| v2 native plugins on by default | #21268 | Only through vite-config and `@vitejs/plugin-vue`. |
| `@rollup/plugin-commonjs` and `fdir` dropped from vite's dependencies | #21639 | No direct use found. |
| `rolldownOptions`/`rollupOptions` merged at environment level; a warning prints when a plugin sets esbuild options | #21612, #21218 | Would surface as build warnings from vite-config, not from this app. |

Also read, not breaking: 8.1–8.3 are bug fixes and Rolldown updates (8.3.5, 2026-10-21, is the version #60
asks for).

## 3 · What `@nextcloud/vite-config` must admit first (OBSERVED, `npm view`, 2026-10-26)

```
$ npm view @nextcloud/vite-config peerDependencies.vite
^7.3.6
$ npm view @nextcloud/vite-config dist-tags
{ stable: '1.7.2', next: '3.0.0-beta.1', latest: '2.5.4' }
$ npm view @nextcloud/vite-config@3.0.0-beta.1 peerDependencies
{ '@vitejs/plugin-vue': '^2.0 || >=6.0', browserslist: '>=4.20', sass: '>=1.80', vite: '>=8.2' }
```

So the admitting line exists, as `3.0.0-beta.1` under the `next` tag. Its other peers are already met by
`main`'s lock: `@vitejs/plugin-vue` 6.0.7, `browserslist` 4.28.4, `sass` 1.101.0 (`package-lock.json`). The
day a stable `3.x` is published, `latest` will answer with a range that admits 8, and the check below exits 0.

One more line to read, missed on 2026-10-26 (added 2026-10-28):

```
$ npm view @nextcloud/vite-config@next engines
{ node: '^22.18 || ^24 || >=26.0.0' }
```

`.nvmrc` says `20` and `package.json` engines say `node >=20.0.0`. CI is already past this: `node.yml` reads the
engines range and installs a node that satisfies it, and `release.yml:32` pins 22. A developer's machine on node 20
is the one place vite-config 3 will refuse to install, so step 0 of the recipe moves `.nvmrc` to `22` and the
engines line to `>=22.18`.

Measured 2026-10-28 on a scratch worktree of `main` (`298e82c`) with `@nextcloud/vite-config@3.0.0-beta.1` and
`vite@8.3.5`, node 22.23.4: `npm install` resolves (no ERESOLVE); `npm run build` transforms 270 modules in 1.63 s
(12.48 s on main the same day); the same four output files plus map; the main chunk 2% smaller; three consecutive
builds byte-identical. Upstream's own 3.0 roadmap is nextcloud-libraries/nextcloud-vite-config#963: two rolldown
bugs still open as of 2026-10-28 (`maxSize` chunking, rolldown#10718; `experimental.strictExecutionOrder`
reproducibility, rolldown#6670). A beta is a measurement, not a merge: the recipe below still waits for `latest`.

## 4 · The check (`tools/check-vite-8.sh`)

A POSIX shell script. Exit 0 when the peer range admits vite 8, 1 when it does not, 2 when npm cannot
answer. Run 2026-10-26, each of the three exits shown:

```
$ tools/check-vite-8.sh
@nextcloud/vite-config@latest peerDependencies.vite = ^7.3.6
vite 8 is not admitted: keep holding PR #60
exit=1

$ tools/check-vite-8.sh next
@nextcloud/vite-config@next peerDependencies.vite = >=8.2
vite 8 is admitted: PR #60 can move (docs/UPGRADE-vite-8.md, the recipe)
exit=0

$ tools/check-vite-8.sh 9.9.9-no-such
check-vite-8: npm could not read @nextcloud/vite-config@9.9.9-no-such from the registry (offline? no such version?)
exit=2

$ npm_config_registry=http://127.0.0.1:9 tools/check-vite-8.sh
check-vite-8: npm could not read @nextcloud/vite-config@latest from the registry (offline? no such version?)
exit=2
```

The script parses with `dash -n`. It reads the range as npm prints it and understands `*`, `^8`, `~8`, `8.x`,
`>=N`, `>N` and `||`; anything else counts as "not admitted" and is printed for a person to read.

## 5 · The recipe for #60, the day the check exits 0 (JUDGED)

0. **Move the Node floor first** (one commit, independent of vite): `.nvmrc` → `22`, `package.json` engines `node`
   → `>=22.18`. Everything the app pins today installs on node 22 (`npm ci` on `main` with node 22.23.4 passed
   2026-10-28); only `@nextcloud/eslint-config` 8.x and `@nextcloud/eslint-plugin` print an `EBADENGINE` warning
   (they declare `^20.0.0`), and a warning does not stop `npm ci`.
1. **Let dependabot open the vite-config major** (it will, for `@nextcloud/vite-config` 2 → 3). Merge that
   PR first, after `npm ci && npm run build` on it pass. If dependabot has not opened it yet, make the bump
   yourself on a branch from `main`:
   ```sh
   npm install --save-dev @nextcloud/vite-config@^3 && npm ci && npm run build
   ```
   Falsifier for the three `createAppConfig` options: a build error naming `createEmptyCSSEntryPoints`,
   `extractLicenseInformation` or `thirdPartyLicense` means 3.x renamed or dropped one; read its CHANGELOG.
2. **Then recreate #60** so its lock is generated against the new `main`: comment `@dependabot recreate` on
   #60 and wait for the push.
3. **Prove it installs and builds** before merging:
   ```sh
   git fetch origin pull/60/head:pr-60 && git checkout pr-60
   npm ci && npm run build && npm run lint && npm run stylelint
   ```
   Both `npm ci` and `npm run build` exit 0 → `gh pr merge 60 --merge`. Then open the app once: the build's
   output lands in `js/`; a blank page is the symptom of a bundle vite-config no longer writes where
   Nextcloud expects it.
4. **Let the two bumps arrive together.** `.github/dependabot.yml` now groups `vite`, `@nextcloud/vite-config` and
   `@vitejs/*` into one `vite` group, so the next major opens as a single PR whose lock can resolve, instead of the
   pairwise-conflicting PRs of 2026-10-26 (that sheet's lesson: "merges clean" is not "installs"). Steps 1 and 2 then
   collapse into reading that one PR.
5. **Keep the check in CI if you want it to call you**: a workflow step `sh tools/check-vite-8.sh || true`
   on a weekly schedule prints the range; the day it says "admitted", do steps 1–3.

Loser kept: `--legacy-peer-deps` (there is a remote branch `ci/allow-legacy-peer-deps-temporary`). It makes
`npm ci` pass by ignoring the peer line, which runs vite-config 2.5.4 against a vite it was never tested on;
a green install that proves nothing. If used at all, it is a measurement (does the build even run?), not a
merge.

## 6 · Not checked is not clean

`npm ci`/`npm run build` with `@nextcloud/vite-config@3.0.0-beta.1` and vite 8: not run here. vite-config
3.0's own changelog: not read. Whether `@nextcloud/browserslist-config` 3.x is what 3.0 expects: not read.

Proposals by Mentat (for Corwin, claude-mythos-5-1); nothing merged, nothing pushed to your repository.

Copyright (C) 2026 Corwin Brust <corwin@bru.st>, Bryant Frankford and Contributors. SPDX-License-Identifier: CC-BY-SA-4.0
