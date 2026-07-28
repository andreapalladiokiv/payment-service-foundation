#!/usr/bin/env bash
# Subsplit each src/* subtree into its own remote repository.
#
# Usage:
#   bin/split.sh                 # split every package
#   bin/split.sh stripe paynet   # split a subset
#   DRY_RUN=1 bin/split.sh       # show what would happen
#   BRANCH=feature/x bin/split.sh  # override branch (default: current)
#   TAG=v1.2.3 bin/split.sh      # also push tag to each split remote

set -euo pipefail

# src/<dir>  ->  remote URL
declare -A REMOTES=(
  [Common]="git@github.com:andreapalladiokiv/payment-service-common.git"
  [Domain]="git@github.com:andreapalladiokiv/payment-service-domain.git"
  [Gateway]="git@github.com:andreapalladiokiv/payment-service-gateway.git"
  [Firewall]="git@github.com:andreapalladiokiv/payment-service-firewall.git"
  [Forter]="git@github.com:andreapalladiokiv/payment-service-forter.git"
  [Neutrino]="git@github.com:andreapalladiokiv/payment-service-neutrino.git"
  [Laravel]="git@github.com:andreapalladiokiv/payment-service-laravel.git"
  [Stripe]="git@github.com:andreapalladiokiv/payment-service-stripe.git"
  [Paynet]="git@github.com:andreapalladiokiv/payment-service-paynet.git"
  [Nuvei]="git@github.com:andreapalladiokiv/payment-service-nuvei.git"
  [ConnexPay]="git@github.com:andreapalladiokiv/payment-service-connexpay.git"
  [Revolut]="git@github.com:andreapalladiokiv/payment-service-revolut.git"
)

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

BRANCH="${BRANCH:-$(git symbolic-ref --short HEAD)}"
TAG="${TAG:-}"
DRY_RUN="${DRY_RUN:-0}"

if [[ -n "$(git status --porcelain)" ]]; then
  echo "error: working tree is dirty; commit or stash first" >&2
  exit 1
fi

selected=("$@")
if [[ ${#selected[@]} -eq 0 ]]; then
  selected=("${!REMOTES[@]}")
fi

run() {
  if [[ "$DRY_RUN" == "1" ]]; then
    echo "DRY: $*"
  else
    echo "+ $*"
    "$@"
  fi
}

for pkg in "${selected[@]}"; do
  remote_url="${REMOTES[$pkg]:-}"
  if [[ -z "$remote_url" ]]; then
    echo "skip: unknown package '$pkg'" >&2
    continue
  fi
  prefix="src/$pkg"
  if [[ ! -d "$prefix" ]]; then
    echo "skip: $prefix does not exist" >&2
    continue
  fi

  echo
  echo "==> split $prefix -> $remote_url ($BRANCH)"

  sha="$(git subtree split --prefix="$prefix" HEAD)"
  echo "    split sha: $sha"

  remote_name="split-$pkg"
  if ! git remote get-url "$remote_name" >/dev/null 2>&1; then
    run git remote add "$remote_name" "$remote_url"
  else
    run git remote set-url "$remote_name" "$remote_url"
  fi

  run git push --force "$remote_name" "$sha:refs/heads/$BRANCH"

  if [[ -n "$TAG" ]]; then
    run git tag -f "$TAG" "$sha"
    run git push --force "$remote_name" "refs/tags/$TAG"
  fi
done

echo
echo "done."
