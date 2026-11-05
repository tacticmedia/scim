# Release

The monorepo is the source of truth. `.github/workflows/split.yml` pushes each package directory to a read-only
split repository, and Packagist reads only the split repositories.

| Directory | Split repository |
| --- | --- |
| `scim-server/` | `tacticmedia/scim-server` |
| `scim-server-bundle/` | `tacticmedia/scim-server-bundle` |

## Split

On each push to `main` and on each `v*` tag of `tacticmedia/scim`, the workflow runs
`git subtree split --prefix=<directory> HEAD` for each package. It pushes the result to `main` of the split
repository, or for a tag, as the same tag. The workflow does not run in a fork.

- `git subtree split` keeps each commit and gives deterministic SHAs, so each push is a fast-forward. Never
  rewrite `main`.
- splitsh-lite gives the same SHAs. Change to it if the split becomes slow. No force-push is necessary.
- The checkout uses `fetch-depth: 0`, because a split needs the full history.
- Do not move or rename a package directory after the first split. The split follows the prefix, so a move gives
  new SHAs and needs a force-push.

## Release a version

Versions are lock-step. One monorepo tag releases both packages with the same version.

1. Make sure that the root `versions` options and the bundle constraint on the library name the line that you
   release, see [AGENTS.md, Version bump](../AGENTS.md#version-bump).
2. Make sure that CI passes on `main`.
3. Tag the monorepo `vX.Y.Z`.
4. Push the tag.
5. The workflow pushes `vX.Y.Z` to both split repositories. Packagist reads the tag through its GitHub hook.

## Set up

Do these steps one time:

1. Create each split repository empty. If GitHub makes a first commit, for example a README or a license, the
   first push fails as a non-fast-forward.
2. Disable pull requests and issues on each split repository.
3. Make a fine-grained personal access token with Contents read and write on the two split repositories only.
   Store it as the secret `SPLIT_TOKEN` of `tacticmedia/scim`.
4. After the first split, submit both split repositories to Packagist and connect the GitHub hook. Do not submit
   `tacticmedia/scim`.
