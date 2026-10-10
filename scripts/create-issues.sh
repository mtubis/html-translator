#!/usr/bin/env bash
# Creates GitHub issues from docs/issues/<milestone>/*.md (first line "# Title" = issue title).
# Usage: scripts/create-issues.sh core-0.1 ["Core 0.1"]
# Requires: gh CLI authenticated, run from repository root.
set -euo pipefail

dir="docs/issues/${1:?milestone directory, e.g. core-0.1}"
milestone="${2:-}"

[ -d "$dir" ] || { echo "No such directory: $dir" >&2; exit 1; }

if [ -n "$milestone" ] && ! gh api "repos/{owner}/{repo}/milestones" --jq '.[].title' | grep -qxF "$milestone"; then
  gh api "repos/{owner}/{repo}/milestones" -f title="$milestone" >/dev/null
  echo "Created milestone: $milestone"
fi

for file in "$dir"/*.md; do
  title="$(head -n1 "$file" | sed 's/^# *//')"
  body="$(tail -n +2 "$file")"
  args=(--title "$title" --body "$body")
  [ -n "$milestone" ] && args+=(--milestone "$milestone")
  gh issue create "${args[@]}"
done
