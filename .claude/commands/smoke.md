---
description: Make sure the dev server is up with the latest code, then run the end-to-end API smoke test
---

1. If `../nc-server` doesn't exist, run `scripts/dev-native.sh setup`.
2. If `js/timebank-main.mjs` is older than any file in `src/`, run `npm run build`.
3. If `appinfo/info.xml` version or `lib/Migration/` changed since the last run,
   run `scripts/dev-native.sh occ upgrade`.
4. `scripts/dev-native.sh start` (no-op if already running).
5. `scripts/smoke-test.sh $ARGUMENTS`

For any FAIL, read the newest entries in `../nc-server/data/nextcloud.log`
(`tail -5 ../nc-server/data/nextcloud.log | jq -r '.message'`) and explain the
likely cause in plain language before proposing a fix.
