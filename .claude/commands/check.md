---
description: Run every fast quality gate (PHP lint, code style, ESLint, stylelint, PHPUnit, build) and report
---

Run these from the repo root, continuing past failures, then give a short
pass/fail table and the first few errors of anything that failed:

1. `composer lint`
2. `composer cs:check`
3. `npm run lint`
4. `npm run stylelint`
5. `NEXTCLOUD_ROOT=../nc-server composer test:unit` (if ../nc-server is missing, say to run `scripts/dev-native.sh setup` instead of failing)
6. `npm run build`

Don't fix anything unless I ask. $ARGUMENTS
