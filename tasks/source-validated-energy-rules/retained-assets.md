# Retained build asset — local release preparation

## Result and scope

The local Vite build now retains one actual prior CSS file after its normal clean build.
No production write, network request, commit, push, deploy, cache-policy change or purge ran.
Unrelated work and local `.cache/` files remain. Root `.gitignore` now ignores `/.cache/`.
Shared task status and root context remain owned by the documentation agent.

## Source and behavior

- Source: `laravel/resources/retained-build-assets/app-BE-AUgaZ.css`.
- Output: `laravel/public/build/assets/app-BE-AUgaZ.css`.
- Size: 110597 bytes.
- SHA-256: `e27279f29da59bdfc18093c71ee05e6d08d237bd673e9b618a58f4f9e5335528`.
- Manager-supplied provenance: fetched from the public production asset, then verified against
  the deployed filesystem through read-only SSH. Production source was still
  `917de212fdc4ef4862903268c3d33ba5cd886e73`. The executor verified the supplied local file's
  size and SHA-256 before copying it. This is not a rebuild of old CSS.
- `laravel/vite.config.js` has a native-Node `closeBundle` hook with an explicit filename list.
  It accepts only safe CSS/JS names and regular files, copies exclusively, and rejects an
  existing filename with different bytes or a symlink. Equal bytes are safe on repeat calls.
  It does not copy documentation, change the manifest or replace current generated filenames.
- The current CSS remains `app-CXFUrNKw.css`. Current JS remains `app-Pr_ebGQi.js` and
  `contract-price-statistics-C235penD.js`; statistics CSS is `contract-price-statistics-BqTUOKJh.css`.
- The source directory is not public. There is no second public URL for the retained source.
  Retention has no time limit or cleanup command. Later releases must explicitly retain other
  changed prior assets and all needed imports. This one file does not cover all future releases.
- Dockerfile review confirms `COPY laravel/ .` includes the source and hook before the existing
  `npm ci && npm run build`. No Dockerfile change or extra command is needed. No Docker image
  build was run in this unit.

## Offline verification

All commands below passed:

1. `shasum -a 256 /tmp/voltikka-release-retained-app-BE-AUgaZ.css` and `wc -c`:
   exact SHA-256 and 110597 bytes as above.
2. From `laravel/`, `node --test tests/JavaScript/retained-build-assets.test.js`: 1 passed.
   The test checks the pinned source hash and size, exact copied bytes, repeat calls, unchanged
   manifest, exclusion of README, different-byte collision rejection without overwrite, and
   source/target symlink rejection. Collision fixtures are private temporary files, not build files.
3. `npm run test:js`: 12 passed, 0 failed.
4. `npm run build` twice, followed by manifest `cmp`: both passed; manifests equal.
5. `node /tmp/voltikka-check-retained-build.mjs`: two additional clean `npm run build` runs passed.
   Each run used a disposable output marker to prove Vite cleaned the directory. Each then
   verified every manifest file/CSS/asset reference and import key, the current CSS filename,
   exact retained CSS bytes/size/hash, and equal manifests across builds. The check is private
   and is not an extra deployment command.
6. `git diff --check`: passed. Final scoped diff and worktree status reviewed.
7. `git check-ignore .cache/`: returned `.cache/`. No local cache file was deleted.

Builds reported an existing stale Browserslist-data warning. No dependency update was made.
PHP tests were not run: this unit changes only build logic, one archived asset, tests and docs.
