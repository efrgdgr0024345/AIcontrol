# Automatic update checks, loader replacement and progress

After installing this updater-capable loader once, future checked releases can
update **both** the private workspace release and the public `loader.php` from
the panel. Initial upload still consists of one file. PIN/key/location markers
remain unchanged; no server credentials move through the laptop.

## Detection and button behavior

On an installed workspace page the browser asks the authenticated loader for
update status. PHP contacts the fixed GitHub repository itself. Status is cached
for fifteen minutes, signed with the existing installation key, and bound to
the installed SHA and running loader hash. The browser polls its local endpoint
every five minutes; cached checks do not make more GitHub requests. Check again
can refresh after a minimum one-minute interval. Failed/pending checks retry on
a shorter cache; an API failure is never labelled up to date.

The updater compares actual manifest file hashes and the checked bootstrap hash,
not simply the latest commit date. A documentation-only commit with identical
deployed bytes does not require an update. A loader-only change is shown as a
loader update. Required GitHub Actions checks must pass on the exact candidate
commit before an update is offered. Pending checks remain visible as pending.

When an update exists, the navigation badge and **Review update** button appear.
Review opens application updates: Check GitHub → review the exact signed
selection → Install this checked commit. Selections still expire in ten minutes,
are bound to the PIN session identity and can be used for one attempt.

## Saved hashes and trust

`release-manifest.json` retains application-file SHA-256 values and a separate
`bootstrap` entry containing the SHA-256 of `loader.php`. The whole manifest is
fetched from the selected full Git SHA before downloading its archive. Archive
paths/types, every deployed file, bootstrap contents and PHP syntax are checked.

Private `bootstrap-state.json` is a signed baseline for the public loader file
and its filesystem-path digest. Current bytes must match this baseline, or
match the newly checked GitHub bootstrap exactly (an explicit verified manual
upload). Unexpected local edits are preserved and block installation rather
than being overwritten or silently trusted. A saved-hash mismatch is shown as
an integrity review, not a healthy status. The public signing key remains pinned
and missing/damaged signing keys still fail closed.

The first baseline trusts the initial uploaded file, as any bootstrap must.
Signatures prove use of the server key; they do not establish human approval or
protect a compromised hosting account that controls that key. GitHub custom
signature headers are evidence, not GitHub API authentication.

## Activation and recovery

The candidate loader is staged privately as `.bootstrap-loader.php`; it is not
served or executed during download. Prior loader code is checksum-verified and
retained under private `bootstrap-backups/<sha256>.php`. A deployment journal
records both the previous workspace pointer and bootstrap recovery information.

Activation runs the local workspace health check, atomically renames the checked
loader into its public path, verifies its actual bytes and saves the signed new
baseline. Opcode cache is invalidated where supported; incompatible cache
configuration is an error. The public directory must permit PHP to replace its
own loader. No shell/exec/Composer operation is used.

An interrupted or failed activation restores both prior code components, their
matching baseline and code pointer. PINs, signing identity, database/events,
sessions and receipts are **not** rolled back with code. Unexpected recovery
files/hashes require operator review. Old release files are retained for
recovery; “removed files” means absent from the new active release.

## Actual progress and explanation

The enhanced install form streams newline-delimited JSON with nine actual
stages: selection, installed hashes, required checks, download, archive/file
verification, recovery backups, workspace activation/health, loader activation,
and signed result persistence. The modal displays completed stages, not a made-up
time estimate or download percentage. A failure stops progress; 100% is shown
only after a successful completion event. The request may continue server-side
if the browser disconnects; a missing final response means inspect the saved
result before repeating the operation.

Without JavaScript, the ordinary POST installation still works and returns the
final result. Some shared hosts buffer streamed HTTP output; they may deliver
several truthful progress events together despite the no-buffering headers.
Live buffering/opcode behavior on the user's actual cPanel account remains a
hosting validation step, separate from cloud browser tests.

The final screen and private signed `last-update.json` show the exact release,
loader hash, manifest hash, added/changed/removed application paths and retained
private data. The summary is linked to its signed completion receipt. A timeout
or result-persistence error does not claim that no code was activated.

## Development

Edit `ui/Updates.php.inc` for the embedded updater service, `assets/panel.js` for
progress/notification behavior, and `assets/app.css` for styles. Rebuild:

```sh
php bin/build-ui.php
php bin/build-manifest.php
php tests/updates.php
PHP_BINARY=php npm run test:ui
```

Generating UI first is essential: the manifest's bootstrap hash covers the
final, generated standalone loader. Unit tests exercise replacement, stored
baseline verification, mismatch refusal, rollback and interrupted recovery.
Browser fixtures exercise available/up-to-date notices and successful/failed
progress screens without contacting GitHub or a provider. A real GitHub
installation/self-update remains a separate release validation.
