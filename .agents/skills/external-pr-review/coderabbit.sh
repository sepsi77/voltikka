#!/usr/bin/env bash
# CodeRabbit helpers for one PR. Run from the repo root.
#   coderabbit.sh wait <pr> [min]   wait (default 45 min) until CodeRabbit has finished its review of
#                                   the PR head commit (its commit status or check run is no longer
#                                   pending)
#   coderabbit.sh show <pr>         print the newest CodeRabbit review on the PR head: the review body
#                                   (actionable count, nitpicks, comments outside the diff) and its
#                                   inline comments
#   coderabbit.sh say <pr> <text>   post "@coderabbitai <text>" as a top-level PR comment
#                                   (pause, resume, review, resolve, rate limit, ...)
#   coderabbit.sh react <pr> <comment-id> up|down
#                                   add 👍 or 👎 to a CodeRabbit inline comment
# CodeRabbit reviews on its own when the PR opens and on every push (incremental review).
set -euo pipefail

cmd=${1:?usage: coderabbit.sh wait|show|say|react <pr> ...}
pr=${2:?pr number required}
repo=$(gh repo view --json nameWithOwner -q .nameWithOwner)
head=$(gh pr view "$pr" --json headRefOid -q .headRefOid)
bot='coderabbitai[bot]'

# Prints "pending", "done <state>" or "none" for CodeRabbit's status on the head commit.
state() {
  local status run
  status=$(gh api "repos/$repo/commits/$head/statuses" -q \
    '[.[] | select(.context | test("coderabbit"; "i"))] | first | .state // empty' 2>/dev/null || true)
  run=$(gh api "repos/$repo/commits/$head/check-runs" -q \
    '[.check_runs[] | select(.name | test("coderabbit"; "i"))] | sort_by(.started_at) | last
     | select(. != null) | "\(.status) \(.conclusion)"' 2>/dev/null || true)
  case "$status" in
  pending) echo pending; return ;;
  success | failure | error) echo "done status=$status"; return ;;
  esac
  case "$run" in
  completed*) echo "done check=$run" ;;
  "") echo none ;;
  *) echo pending ;;
  esac
}

case "$cmd" in
wait)
  minutes=${3:-45}
  for _ in $(seq 1 "$minutes"); do
    s=$(state)
    case "$s" in
    done*) echo "CodeRabbit done on ${head:0:7}: ${s#done }"; exit 0 ;;
    esac
    sleep 60
  done
  echo "timeout: CodeRabbit not finished on ${head:0:7} after $minutes min (last: $(state));"
  echo "check 'coderabbit.sh say $pr rate limit' and the PR's CodeRabbit walkthrough comment"
  exit 1
  ;;
show)
  # Old inline comments are moved onto new commits by GitHub, so select by the newest review on
  # the head commit, never by a comment's commit_id.
  review=$(gh api "repos/$repo/pulls/$pr/reviews" --paginate -q \
    "[.[] | select(.user.login == \"$bot\") | select(.commit_id == \"$head\")] | last | .id // empty" |
    tail -1)
  echo "## Newest CodeRabbit review on ${head:0:7}"
  if [ -z "$review" ]; then
    # A clean incremental review posts no review object; its result is in the walkthrough.
    # Use only the walkthrough that names this head commit, never an earlier round's result.
    walkthrough=$(gh api "repos/$repo/issues/$pr/comments" --paginate -q \
      ".[] | select(.user.login == \"$bot\") | select(.body | contains(\"$head\")) | .body" |
      sed 's/<[^>]*>//g' || true)
    if [ -z "$walkthrough" ]; then
      echo "no review object and no walkthrough that names ${head:0:7}: no current result"
      exit 0
    fi
    echo "no review object on this commit; its walkthrough says:"
    printf '%s\n' "$walkthrough" |
      grep -E "actionable comments|Reviewing files that changed|reviews per hour" || true
    exit 0
  fi
  # Print everything: a truncating pipe (head) can also stop the script under pipefail.
  gh api "repos/$repo/pulls/$pr/reviews/$review" -q .body |
    sed 's/<[^>]*>//g' | grep -v '^[[:space:]]*$' || true
  echo "## Inline comments of review $review"
  gh api "repos/$repo/pulls/$pr/comments" --paginate |
    jq -r --argjson r "$review" '.[] | select(.pull_request_review_id == $r)
      | "=== \(.id) \(.path):\(.start_line // .line)-\(.line)\n\(.body | split("<summary>🤖 Prompt for AI Agents")[0])"' |
    sed 's/<[^>]*>//g'
  ;;
say)
  text=${3:?command text required, e.g. pause|resume|review|resolve|"rate limit"}
  gh pr comment "$pr" --body "@coderabbitai $text" >/dev/null
  echo "posted: @coderabbitai $text"
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
