#!/usr/bin/env bash
set -euo pipefail

BRANCH=main
BARE_REPO="${MENU_DEPLOY_GIT_DIR:-$HOME/repos/NewMenuAPI.git}"
WORK_TREE="${MENU_DEPLOY_WORK_TREE:-$HOME/new_menu/NewMenuAPI}"

# Git exports its bare-repository environment to hooks. Clear it before
# operating on the separate deployment checkout.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_PREFIX

while read -r oldrev newrev ref; do
  if [[ "$ref" != "refs/heads/$BRANCH" ]]; then
    continue
  fi

  git --work-tree="$WORK_TREE" --git-dir="$BARE_REPO" checkout -f "$BRANCH"
  git -C "$WORK_TREE" fetch --no-tags "$BARE_REPO" "$BRANCH"
  git -C "$WORK_TREE" update-ref "refs/heads/$BRANCH" "$newrev"
  git -C "$WORK_TREE" symbolic-ref HEAD "refs/heads/$BRANCH"
  git -C "$WORK_TREE" read-tree "$newrev"

  cd "$WORK_TREE"
  docker compose -f docker-compose.prod.yml up -d --build
done
