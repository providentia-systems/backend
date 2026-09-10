# Post-release acceptance checklist

Run this checklist against the **published backend release** and the matching
released Client/Admin builds. Unit, integration and CI tests are prerequisites;
they do not replace this deployed journey. Record the release versions, image
digests, device/build identifiers, date, tester and result for every section.

## Release and deployment evidence

- [ ] The backend came from one verified GitHub release bundle. Its
  `images.env`, archive, manifest and checksums belong to that same release.
- [ ] The API, web and media-worker containers use the three digests in that
  `images.env`; no `edge`, `latest` or locally built image is running.
- [ ] Only `compose.production.yaml` and, when required, the official
  `compose.production.bind.yaml` are active. No source-code hotfix override is
  included and the application root remains read-only.
- [ ] The protected env file and persistent data are backed up together; the
  restore procedure has not been replaced with a copy of a live SQL directory.
- [ ] Public liveness, readiness and system information succeed through the
  actual TLS proxy, and a real email code arrives through production SMTP.

For a bind-mounted deployment, collect non-secret evidence with these complete
commands from the release directory:

```bash
curl --fail-with-body https://api.example.net/health/live
curl --fail-with-body https://api.example.net/health/ready
curl --fail-with-body https://api.example.net/api/v1/system/info
docker compose --env-file /etc/providentia/production.env \
  -f compose.production.yaml -f compose.production.bind.yaml \
  ps
docker inspect "$(docker compose --env-file /etc/providentia/production.env \
  -f compose.production.yaml -f compose.production.bind.yaml ps --quiet api)" \
  --format '{{.Config.Image}}{{println}}{{range .Mounts}}{{println .Destination .RW}}{{end}}'
```

The API mount evidence may include `/app/var`; it must not include an individual
PHP source file or a writable `/app` replacement.

## Required owner-to-invitee journey on one Client installation

Use one installed household Client throughout this section. Do not uninstall,
clear application data, change its compiled API origin or use separate Client
installations for the owner and invitee. Admin may run separately.

Prepare:

- [ ] The owner account is fully onboarded and can open an existing home.
- [ ] That home uses the intended released home group. `members.invite` is
  enabled, the manager quota has capacity, and the manager role retains the
  intended permissions, including the BYOK permissions when AI is enabled.
- [ ] Choose an invitee email that has never completed onboarding. Choose a
  non-default account group that Admin will preassign, and record its group ID.

Execute this exact order:

1. [ ] Sign in as the owner on the Client installation and open the existing
   home. Confirm it opens without “Home data could not be read safely.”
2. [ ] Send a **manager** invitation to the new invitee email. Confirm the
   pending invitation remains visible.
3. [ ] Sign the owner out. Sign in as the invitee on that same Client
   installation using the newly delivered email code.
4. [ ] Confirm the blank backend display name is treated only as “not supplied
   yet”: the Client reaches **Set up your account** without reporting invalid
   account data. Do not submit setup yet.
5. [ ] In Admin, locate this now-created invitee account. Assign the selected
   account-level group, then record the assignment's group ID and revision.
   This is not the manager role carried by the home invitation.
6. [ ] Return to the Client. Enter a valid display name, choose the published
   country, review/accept the current privacy notice and complete setup once.
7. [ ] In Admin, reload the invitee. Confirm the preassigned account-group ID
   and its revision are unchanged. No “This subject already has a group” error
   may occur, and the account must not be replaced with a country default.
8. [ ] In the Client, confirm the pending invitation still exists, accept it as
   manager, select the existing home and open it.
9. [ ] Confirm the home returns each effective permission at most once and the
   manager can use every intended manager surface. In particular, the Client
   must not reject the home payload as unsafe.
10. [ ] Sign the invitee out, close/reopen the same Client, sign in again with a
    fresh email code and open the same home. Account setup must not repeat and
    the accepted invitation/membership must remain active.
11. [ ] Sign out and sign the original owner back in on the same installation.
    Confirm the owner can still open the home and sees the invitee as manager.

Fail the release if any step needs a manual database edit, writable container,
source-file mount, local client patch or temporary image.

## Profile location read/write journey

Run this for an ordinary user and, separately, for an administrator profile if
both apps expose the profile editor:

1. [ ] Open profile editing and select a country. Confirm only regions for that
   country are offered and their real names are shown.
2. [ ] Leave region and city empty, save, close the editor and reopen it. Save
   must succeed and both optional fields must remain empty.
3. [ ] Select a region, confirm the city choices belong to it, then select a
   city. Save, navigate away, restart the app and reopen the profile.
4. [ ] Confirm the exact selected country, region and city names—not “Region
   selected” or “City selected”—are restored from server data.
5. [ ] Clear the city and save/reopen. Then clear the region and save/reopen.
   Both fields must be independently clearable and remain clear.
6. [ ] Change country with region/city clear. Save must not require either
   optional field; old choices must not leak into the new country's lists.

## Separate private-product cache investigation

This is an investigation checklist, not evidence that the separately reported
private-product cache defect has been diagnosed or resolved. The current Client
does not expose private home-category lifecycle controls. Until that product
surface exists, create and later tombstone the synthetic category/product only
through the supported API with an authorized test fixture—never through a
database edit, writable container or source hotfix. Do not count these steps as
released-Client creation/deletion acceptance.

Use private test names and no handover media:

1. [ ] In a disposable manager-owned home, use the authorized API fixture to
   create a private home category and a private home product assigned to it.
   Confirm the API accepts both writes before opening the Client.
2. [ ] Change its quantity through the ordinary stock flow, synchronize, close
   the home and reopen it. The home, product, category and quantity must load
   without an item-master cache parsing error.
3. [ ] Sign into the same home on a second clean Client installation and
   synchronize. Confirm the private product and category arrive from the
   authoritative home synchronization stream with the same identifiers.
4. [ ] Tombstone the private test product through the authorized API fixture,
   synchronize both Client installations, close/reopen both homes and confirm
   a stale catalog cache does not resurrect it.
5. [ ] Confirm a public catalog item still opens and can be selected normally;
   private rows must not corrupt the pack-backed public catalog cache.

Record the original reproduction, server/client versions and sanitized sync
evidence before specifying a permanent correction for this separate defect.

## Admin usability journey

Test the released Linux Admin app at a narrow supported window, its default
size and a wide desktop window:

- [ ] Account-group, home-group and country forms keep borders, floating
  labels, counters, helper text and validation messages separated.
- [ ] Long validation messages wrap without covering the next control.
- [ ] User, group, home and country tables give essential columns sensible
  widths and retain complete identifiers.
- [ ] When all columns do not fit, horizontal scrolling is visible and
  discoverable with mouse/touchpad/keyboard; the last column and full
  identifier can be reached.
- [ ] Resizing back and forth does not lose selected rows or edited values.

Capture screenshots for the release record at each tested window size, but do
not put real email addresses, home data, credentials or private identifiers in
public artifacts.

## AI acceptance

Manual stock control must pass with AI disabled. If Secure server AI is part of
this release, run the complete [AI BYOK acceptance](ai-byok.md) for every
enabled provider using synthetic media. Confirm BYOK permission behavior,
encrypted credential read-back, explicit transmission consent, human review,
revocation and content-safe logs. Do not mark platform-funded AI complete:
`ai.platform.use` is a separate, currently disabled future boundary.

## Sign-off

The release is complete only when all applicable boxes pass on the published
artifacts. Record failures as defects with the exact release/build and safe
reproduction details. A local hotfix may diagnose a problem; it is not release
acceptance evidence.
