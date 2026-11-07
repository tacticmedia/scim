# Release

The monorepo is the source of truth. `.github/workflows/split.yml` pushes each package directory to a read-only
split repository, and Packagist reads only the split repositories.

| Directory | Split repository |
| --- | --- |
| `scim-server/` | `tacticmedia/scim-server` |
| `scim-server-bundle/` | `tacticmedia/scim-server-bundle` |

## Split

On each push to `main` and on each `X.Y.Z` or `X.Y.Z-<suffix>` tag of `tacticmedia/scim`, the workflow runs
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
   release, see [AGENTS.md, Version bump](../AGENTS.md#version-bump). For the tag `0.1.2`, they are `0.1.x-dev` and
   `^0.1`. The workflow checks this before it pushes a tag, and fails when they do not match.
2. Make sure that CI passes on `main`.
3. Tag the monorepo `X.Y.Z`, without a `v`. For a pre-release, add a suffix such as `-beta1` or `-RC1`. The
   workflow does not run for a tag that starts with `v`.
4. Push the tag.
5. The workflow pushes the tag to both split repositories. Packagist reads the tag through its GitHub hook.

## Set up

Do these steps one time:

1. Create each split repository empty. If GitHub makes a first commit, for example a README or a license, the
   first push fails as a non-fast-forward.
2. Disable pull requests and issues on each split repository. Add no ruleset or branch protection that blocks a push
   to `main` or a tag of a split repository.
3. Make sure that the personal access token policy of the `tacticmedia` organization allows fine-grained tokens.
4. Make a fine-grained personal access token:
   - Resource owner: `tacticmedia`.
   - Repository access: only `tacticmedia/scim-server` and `tacticmedia/scim-server-bundle`.
   - Repository permissions: Contents, read and write. GitHub adds Metadata, read-only.
   - Expiration: the longest that the organization allows.

   When the organization requires approval, an organization owner approves the token. A pending or expired token
   cannot push, and the split fails.
5. Store the token as the Actions secret `SPLIT_TOKEN` of `tacticmedia/scim`.
6. After the first split, log in to Packagist with GitHub, give the Packagist application access to the
   `tacticmedia` organization, and submit both split repositories. Packagist then sets up the GitHub hooks. Do not
   submit `tacticmedia/scim`.
