#!/bin/sh
# Runs .github/workflows/ci.yaml through act, one job at a time. Every job starts on a fresh
# snapshot of the working tree, what `git add --all` would commit, and on an emptied Docker daemon,
# the way GitHub gives every job a new virtual machine. Usage: run.sh [job ...] ({@see docs/ci.md}).
set -eu

root=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
ACT_PROJECT=$(basename "$root")
export ACT_PROJECT
workflow=.github/workflows/ci.yaml
workspace=/home/runner/work/$ACT_PROJECT/$ACT_PROJECT
logs="$root/var/ci/logs"

runner_compose() {
    docker compose --project-directory "$root/.github/act" --file "$root/.github/act/compose.yaml" "$@"
}

runner() {
    runner_compose exec -T runner "$@"
}

snapshot() {
    tree=$(mktemp -d)
    (
        cd "$root"
        GIT_INDEX_FILE="$tree.index"
        export GIT_INDEX_FILE
        git read-tree HEAD
        git add --all
        git checkout-index --all --prefix="$tree/"
    )
    runner find . -mindepth 1 -delete
    runner_compose cp "$tree/." "runner:$workspace"
    rm -rf "$tree" "$tree.index"
    # A checkout without a remote has no origin to copy.
    origin=$(git -C "$root" remote get-url origin 2>/dev/null || true)
    runner sh -c "git init --quiet --initial-branch=main \
        && { [ -z '$origin' ] || git remote add origin '$origin'; } \
        && git add --all \
        && git -c user.name=act -c user.email=act@localhost commit --quiet --message='Working tree snapshot'"
}

reset_daemon() {
    runner sh -c 'docker ps --all --quiet | xargs -r docker rm --force --volumes >/dev/null
        docker volume prune --all --force >/dev/null
        docker network prune --force >/dev/null'
}

docker run --rm --volume "$root:/repo:ro" --workdir /repo rhysd/actionlint:1.7.12 -color

mkdir -p "$logs"
rm -f "$logs"/*.log "$logs"/*.status
runner_compose up --detach --build --wait runner

if [ $# -eq 0 ]; then
    # act --list orders jobs unpredictably; the workflow's own order reads better and is stable.
    # shellcheck disable=SC2046 # one job id per word
    set -- $(awk '/^jobs:/ {inside = 1; next} inside && /^  [A-Za-z0-9_-]+:[[:space:]]*$/ {gsub(/[: ]/, ""); print}' "$root/$workflow")
fi

passed=
failed=
for job in "$@"; do
    printf '\n==> %s\n' "$job"
    snapshot
    reset_daemon
    # act runs a job's needs in the same workspace and daemon; this loop runs each job on its own.
    runner sed -i '/^    needs:/d' "$workflow"
    {
        status=0
        runner act push --workflows "$workflow" --job "$job" --eventpath .github/act/event.json 2>&1 || status=$?
        echo "$status" > "$logs/$job.status"
    } | tee "$logs/$job.log"
    if [ "$(cat "$logs/$job.status")" -eq 0 ]; then
        passed="$passed $job"
    else
        failed="$failed $job"
    fi
done

printf '\nPassed:%s\nFailed:%s\nLogs: %s\n' "${passed:- none}" "${failed:- none}" "$logs"
[ -z "$failed" ]
