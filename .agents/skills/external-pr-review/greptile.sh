#!/usr/bin/env bash
# Greptile helpers for one PR. Run from the repo root.
#   greptile.sh size <pr>                   print the reviewable size of the PR and the tier to use
#                                           for its first review (plus or base)
#   greptile.sh request <pr> <tier>         post "@greptileai review this at <tier>" (base|plus|apex);
#                                           prints the UTC request time
#   greptile.sh wait <pr> <since> [min]     wait until a "Greptile Review" check run on the PR head,
#                                           started at or after <since> (UTC ISO time), completes
#   greptile.sh show <pr>                   print the summary (score + findings) and the inline
#                                           comments of the newest review on the PR head
#   greptile.sh react <pr> <comment-id> up|down
#                                           add 👍 or 👎 to a Greptile inline comment (the only
#                                           reactions Greptile learns from)
set -euo pipefail

cmd=${1:?usage: greptile.sh size|request|wait|show|react <pr> ...}
pr=${2:?pr number required}
repo=$(gh repo view --json nameWithOwner -q .nameWithOwner)
head=$(gh pr view "$pr" --json headRefOid -q .headRefOid)

# Files Greptile does not review (mirror of ignorePatterns in .greptile/config.json).
ignored='^(tasks/|legacy/|\.impeccable/|\.autoclaude/|design/|videos/|remotion/|media/|research/|market-review/editions/|laravel/public/images/|laravel/public/build/)|(package-lock\.json|composer\.lock|uv\.lock)$'
# Paths where a mistake changes published prices, stored history or production: a first review there is worth Plus.
risky='^(laravel/database/migrations/|laravel/app/Services/(CanonicalPricing|ContractStatistics|ContractImport|ContractInterpretation|Caching|PriceForecasting)/|laravel/routes/console\.php|laravel/config/|orchestration/src/|Dockerfile|docker-[a-z-]+\.sh|supervisord\.conf|scripts/(deploy-image|build-and-push-image|sync-production-database)\.sh)'

case "$cmd" in
size)
  # gh lists at most 100 files; a PR that large is a Plus review anyway.
  files=$(gh pr view "$pr" --json files -q '.files[] | "\(.additions + .deletions) \(.path)"' |
    awk -v re="$ignored" '$2 !~ re')
  count=$(printf '%s\n' "$files" | grep -c . || true)
  lines=$(printf '%s\n' "$files" | awk '{s += $1} END {print s + 0}')
  risk=$(printf '%s\n' "$files" | awk '{print $2}' | grep -E "$risky" | grep -Ev '\.md$' | head -5 || true)
  echo "reviewable: $count files, $lines changed lines (task records, content, design, video, built images and lockfiles left out)"
  [ -n "$risk" ] && printf 'risky paths:\n%s\n' "$risk"
  if [ "$lines" -ge 800 ] || [ "$count" -ge 25 ] || [ -n "$risk" ]; then
    echo "first review: plus"
  else
    echo "first review: base"
  fi
  ;;
request)
  tier=${3:?tier required: base|plus|apex}
  case "$tier" in base | plus | apex) ;; *) echo "tier must be base, plus or apex" >&2; exit 2 ;; esac
  date -u +%Y-%m-%dT%H:%M:%SZ
  gh pr comment "$pr" --body "@greptileai review this at $tier" >/dev/null
  ;;
wait)
  since=${3:?since (UTC ISO time from request) required}
  minutes=${4:-45}
  for _ in $(seq 1 "$minutes"); do
    run=$(gh api "repos/$repo/commits/$head/check-runs" -q \
      "[.check_runs[] | select(.name == \"Greptile Review\" and .started_at >= \"$since\")]
       | sort_by(.started_at) | last | select(. != null) | \"\(.status) \(.conclusion)\"" 2>/dev/null || true)
    case "$run" in
    completed*) echo "review done on ${head:0:7}: $run"; exit 0 ;;
    esac
    sleep 60
  done
  echo "timeout: no completed Greptile Review on ${head:0:7} after $minutes min"
  exit 1
  ;;
show)
  echo "## Summary (PR description, rewritten by Greptile on every review)"
  gh pr view "$pr" --json body -q .body | sed -n '/greptile_comment/,$p' |
    sed 's/<[^>]*>//g' | grep -v '^[[:space:]]*$' |
    sed -n '/Confidence Score/,/^Summary/p'
  # Old inline comments are moved onto new commits by GitHub, so filter by the newest review
  # on the head commit, never by commit_id. A clean round may post no review object at all.
  review=$(gh api "repos/$repo/pulls/$pr/reviews" -q \
    "[.[] | select(.user.login | startswith(\"greptile\")) | select(.commit_id == \"$head\")] | last | .id // empty")
  echo "## Inline comments of the newest review on ${head:0:7}"
  if [ -z "$review" ]; then
    echo "none (no review object on this commit)"
    exit 0
  fi
  gh api "repos/$repo/pulls/$pr/comments" --paginate |
    jq -r --argjson r "$review" '.[] | select(.pull_request_review_id == $r)
      | "=== \(.id) \(.path):\(.start_line // .line)-\(.line)\n\(.body | split("<details>")[0])"' |
    sed 's/<[^>]*>//g'
  ;;
react)
  id=${3:?comment id required}
  case "${4:-}" in
  up) content="+1" ;;
  down) content="-1" ;;
  *) echo "reaction must be up or down" >&2; exit 2 ;;
  esac
  gh api -X POST "repos/$repo/pulls/comments/$id/reactions" -f content="$content" >/dev/null
  echo "reacted $content on comment $id"
  ;;
*)
  echo "unknown command: $cmd" >&2
  exit 2
  ;;
esac
