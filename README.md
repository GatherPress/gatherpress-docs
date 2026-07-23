# GatherPress Docs

Mirror a GitHub repository's Markdown documentation as hierarchical pages on
your WordPress site, kept in sync automatically.

Point the plugin at a repository, a branch, and a path (for example
`GatherPress/gatherpress`, `main`, `docs`), pick a WordPress page as the root,
and the plugin recreates the directory tree as nested pages: directories become
parent pages, `.md` files become child pages, and a directory's `README.md`
becomes that directory page's own content. Documents render exactly as they do
on github.com — the plugin uses GitHub's own Markdown renderer, so tables, task
lists, code fences, and `> [!NOTE]`-style alerts all come through.

Built by the [GatherPress](https://gatherpress.org/) community to publish its
own documentation, but generic on purpose: it works with any public GitHub
repository (and private ones, with a token).

## How it works

- **One post type, machine-owned.** Documents live in a hierarchical
  `gatherpress_doc` post type with no admin UI — the sync engine owns every
  post, because edits belong in the source repository.
- **Permalinks nest beneath your root page.** A root page at `/docs/` yields
  `/docs/contributor/release-process/`, mirroring the repository layout.
- **Cheap steady-state syncs.** A single GitHub *trees* API call lists every
  file with its blob SHA. A file whose SHA matches the last synced one is
  skipped untouched; only changed files are fetched, rendered, and saved. The
  date of each file's last commit is stored as post meta.
- **Scheduled and manual.** WP-Cron runs the sync hourly, every 6 hours, twice
  daily, or daily (your choice), and a **Sync now** button runs it on demand.
- **Links and images just work.** Relative links between Markdown files are
  rewritten to the corresponding local permalinks (fragments preserved).
  Relative images are rewritten to `raw.githubusercontent.com` and served from
  GitHub — nothing is sideloaded. Other relative file links point at the file
  on github.com.
- **Deletions mirror too.** When a file or directory disappears from the
  repository, its page is trashed on the next full sync.
- **Budgeted runs.** If a run hits the GitHub API rate limit or a time guard,
  it stops cleanly, keeps what it finished, and schedules a resume — the SHA
  skip makes re-entry free. An optional token raises the API limit from 60 to
  5,000 requests per hour, which matters mostly for the first sync of a large
  docs tree.

## Setup

1. Install and activate the plugin.
2. Create (or choose) a page to serve as the documentation root — for
   example a page titled "Docs" at `/docs/`.
3. Go to **Settings → GitHub Docs** and configure:
   - **Repository** — `owner/name`, e.g. `GatherPress/gatherpress`.
   - **Branch** — e.g. `main`.
   - **Path** — the directory to mirror, e.g. `docs` (empty mirrors the whole
     repository).
   - **Root page** — the page documents nest beneath.
   - **Update frequency** — hourly through daily.
   - **GitHub token** — optional; recommended for the first sync of large
     trees and required for private repositories.
4. Click **Sync now**, or wait for the schedule.

## Notes

- Rendered HTML is passed through WordPress's `wp_kses_post` sanitizer before
  saving. GitHub alert boxes keep their text and structure; their inline SVG
  icons are stripped by the sanitizer.
- A `README.md` at the top of the configured path is skipped — the root page
  you selected is the human-owned front door.
- Your theme renders the documents with its ordinary single-post template. A
  directory page without a README shows an automatically generated list of its
  children.
- Uninstalling deletes the plugin's settings and all mirrored documents; they
  are a generated mirror, so nothing original is lost.

## Requirements

- WordPress 6.4+
- PHP 7.4+

## License

GPL v2 or later.
