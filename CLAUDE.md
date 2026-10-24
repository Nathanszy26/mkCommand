# mkCommand

## Deployment layout (globportal.com)

`mkCommand_API/` is flat in this repo, but on the live server the files are split
across folders under `/mnt/LiveHDD/sportal_v2/accounts/mkPortal/`:

| Server folder | Files |
|---|---|
| `hr/` | `staffPoolCore.php`, `staffPoolAPI.php`, `staffPoolTree.php`, `staffHeadcountReport.php` |
| `mkCommand/` | `staffOverview.php`, `jobspecReport.php` |

- Any page in `mkCommand/` that needs the staff pool must load the core from
  `__DIR__ . '/../hr/staffPoolCore.php'`, falling back to
  `__DIR__ . '/staffPoolCore.php'` for the repo layout. A plain
  `require_once __DIR__ . '/staffPoolCore.php'` gives HTTP 500 on the server.
- When `staffPoolCore.php` changes, upload it to `hr/` as well as the pages
  that use it.
- No PHP CLI on the dev machine and no DB access, so changes can't be linted or
  run locally. Verify on globportal (PHP error log; `staffOverview.php` also
  accepts `&debug=1`).

## Staff pool = the one head count

`staffPoolCore.php` defines who is on the staff list (the pool, the overrides,
the counting). `staffPoolAPI.php`, `staffHeadcountReport.php`,
`jobspecReport.php` and `staffOverview.php` all count with it, so their totals
must match: if `staffPoolAPI.php?comp_id=1` says `company_total_staff` is 665
(people an override has taken out of the pool are excluded), every report shows
665 too. Don't give a page its own population query — use
`StaffPoolRepository` + `inPoolRows()`, and `homeCompId()` for lookups
(Trillium, comp 16, is stored under comp 1).
