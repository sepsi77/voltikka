# Weekly video source-rule check

## Result

Toolchain verification is complete. Visual verification remains pending; no video render was done. The earlier offline failure below is historical, not a current dependency blocker.

## Approved toolchain follow-up

The user approved installing the missing dependencies. The manager ran these commands from `remotion/`; this documentation unit checked the logs and did not rerun them.

| Command | Actual result | Log |
| --- | --- | --- |
| `npm ci --no-audit --no-fund` | Passed; 389 packages installed | `/tmp/voltikka-remotion-install.log` |
| `npm run lint` | Passed; ESLint and TypeScript (`eslint src && tsc`) | `/tmp/voltikka-remotion-lint.log` |
| `npm run build` | Passed; bundle at `remotion/build` | `/tmp/voltikka-remotion-build.log` |

SHA-256 verification against `/tmp/voltikka-remotion-manifests-before.sha256` confirmed that `package.json` and `package-lock.json` are unchanged. npm blocked esbuild's optional postinstall under its current policy; lint and bundle checks passed anyway. No script approval or further install is needed for these checks. No application code changed and no production access occurred. A bundle is not a visual render.

## Historical offline toolchain attempt

Local versions: Node v26.0.0, npm 12.0.2. All install, lint, build and parse commands ran with macOS network access denied:

```sh
sandbox-exec -p '(version 1) (allow default) (deny network*)' <command>
```

From the repository root, an isolated copy was made:

```sh
CHECK_DIR=$(mktemp -d /tmp/voltikka-video-offline.XXXXXX)
rsync -a --exclude node_modules --exclude build --exclude out --exclude .DS_Store remotion/ "$CHECK_DIR/"
cd "$CHECK_DIR"
```

The actual directory is `/tmp/voltikka-video-offline.Mu2g4L`. No existing node_modules directory was deleted or overwritten. The install log is in that directory as `install.log`.

Commands below used the network-denial wrapper above:

| Command | Actual result |
| --- | --- |
| `env npm_config_offline=true npm ci --offline --ignore-scripts --no-audit --no-fund` | Exit 1, `ENOTCACHED`: `zod-3.22.3.tgz` has no cached response. No online retry. |
| `env npm_config_offline=true npm run lint` | Exit 127, `eslint: command not found`. The script is `eslint src && tsc`, so TypeScript did not run. |
| `env npm_config_offline=true npm run build` | Exit 127, `remotion: command not found`. No bundle was produced. |

SHA-256 checks confirmed that package.json and package-lock.json in the copy still match the repository files. No dependency, version or lockfile change was made.

## Historical fallback verification

From the repository root, with the same network-denial wrapper:

```sh
laravel/node_modules/.bin/esbuild \
  remotion/src/types.ts \
  remotion/src/compositions/WeeklyOffers/OfferCard.tsx \
  --outdir=/tmp/voltikka-video-offline.Mu2g4L/syntax-check --format=esm
```

Result: exit 0, both files parsed in 6 ms. OfferCard.js was 19.3 kB; types.js was empty because it contains types only. This check does not resolve imports, check types, run ESLint or verify a Remotion bundle.

Manual review checked the following:

- `ContractOffer` is the union of canonical and legacy records. Their `pricing_basis` literals distinguish them. The constant `isCanonical` guards both new property reads. The offer parameter is not reassigned. This is compatible with aliased discriminant narrowing in the locked TypeScript 5.9.3, but was not compiler-verified.
- `WeeklyOffersVideoService::transformCanonicalContractToOffer()` spreads `CanonicalOfferFacts::fromPricing()` into `offer`. That helper emits `benefit_is_estimate`, so `offer.offer.benefit_is_estimate` is the correct nested property, not a mistaken reference to the additional top-level flag.
- `canonicalConsumptionOutput()` emits both `is_estimate` and `benefit_is_estimate`. The two optional boolean declarations match the PHP payload. Missing optional benefit flags retain the old exact-benefit display.
- Selection uses the townhouse profile (5,000 kWh). The hero benefit flag and the townhouse savings badge therefore use the same pricing basis.
- Actual-price certainty remains separate from benefit certainty. Estimated benefits show `arvioitu säästö`, `ARVIOITU ETU`, and the statement that normal prices can change and savings are not guaranteed. The price heading uses only the actual-price `is_estimate` flag.
- Real-term benefit months and the annualized short-term total label remain unchanged. Legacy reads remain in their existing branches.

`git diff --check -- remotion/src/types.ts remotion/src/compositions/WeeklyOffers/OfferCard.tsx` passed (exit 0). Final scoped diff and working-tree status were reviewed. Existing uncommitted changes were preserved.

## Remaining limit

ESLint, TypeScript and bundle checks now pass. No video or browser render was attempted, so text fit and visual output remain unverified. Production and V5 activation approval boundaries remain unchanged; see `release-plan.md`. This follow-up changes documentation/task status only; no further install, script approval, network call, production operation, commit or push was made.
