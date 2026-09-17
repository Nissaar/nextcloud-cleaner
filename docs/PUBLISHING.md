# Publishing to the Nextcloud app store

Written for whoever maintains releases of this app.

Getting an app into the store is a one-off setup (a certificate and a registered app
id) followed by a repeatable release (tag, and let CI do the rest). The setup half
cannot be automated: it involves a pull request a human has to merge.

## Names, and which of them matter

Three names are in play and only one of them is permanent:

| Name | Value | Changeable? |
|---|---|---|
| **App id** | `nextcloud_cleaner` | **No.** It is the certificate's `CN`, the app store registration, the directory the app must live in, and the URL of every OCS endpoint. Changing it means a new certificate and, to users, a different app. |
| Display name | Nextcloud Cleaner | Yes. `<name>` in `info.xml`, shown in the store and the app menu. |
| Repository | `nextcloud-cleaner` | Yes. GitHub only; Nextcloud never sees it. |

The app store's schema restricts an id to `[a-z]+[a-z0-9_]*[a-z0-9]+` — lowercase
letters, digits and underscores, 32 characters at most. A hyphen is not allowed, which
is why the id is not simply the repository name.

---

## 1. Generate the signing key

The private key is the app's permanent identity. The store accepts an update only if
it is signed by the same key, and **there is no recovery** — a lost key means asking
Nextcloud to revoke the old certificate and issue a new one, which takes another pull
request and leaves every existing install unable to verify the app in the meantime.

```bash
openssl req -nodes -newkey rsa:4096 -keyout nextcloud_cleaner.key -out nextcloud_cleaner.csr \
        -subj "/CN=nextcloud_cleaner"
```

The `CN` **must** be exactly the app id, `nextcloud_cleaner`. The store checks this.

### Back it up before doing anything else

- **A password manager**, as a file attachment. This is the single most useful copy.
- **An offline copy** — a USB drive, or printed base64 in a safe. Something that
  survives losing this machine and your cloud accounts at the same time.
- **Not** in this repository, not in a shared folder, not in a chat message.

`.gitignore` already refuses `*.key` and `*.crt`, but that is a safety net, not a plan.

## 2. Get the certificate signed

Open a pull request against
[nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests)
adding `nextcloud_cleaner/nextcloud_cleaner.csr`, with a link to this repository in the
description. A Nextcloud maintainer reviews it and commits the signed
`nextcloud_cleaner.crt` back to that repository.

This takes days rather than minutes. Start it before you plan to release.

Once merged:

```bash
mkdir -p ~/.nextcloud/certificates
mv nextcloud_cleaner.key ~/.nextcloud/certificates/
curl -sSfL https://raw.githubusercontent.com/nextcloud/app-certificate-requests/master/nextcloud_cleaner/nextcloud_cleaner.crt \
     -o ~/.nextcloud/certificates/nextcloud_cleaner.crt
chmod 600 ~/.nextcloud/certificates/nextcloud_cleaner.key
```

## 3. Register the app id

Sign in at [apps.nextcloud.com](https://apps.nextcloud.com) with a Nextcloud account,
then register `nextcloud_cleaner` under **Developer → Register app**. The id has to match
the certificate's `CN` and `appinfo/info.xml`'s `<id>`.

Take an API token from your account settings — the release workflow uses it.

## 4. Add the CI secrets

Under **Settings → Secrets and variables → Actions**:

| Secret | Value |
|---|---|
| `APP_PRIVATE_KEY` | contents of `nextcloud_cleaner.key` |
| `APP_CERTIFICATE` | contents of `nextcloud_cleaner.crt` |
| `APP_STORE_TOKEN` | your app store API token |

Without them the release workflow still runs, and publishes an **unsigned** tarball
with a warning. That is useful for testing the pipeline; the app store will refuse it.

## 5. Release

```bash
# 1. bump <version> in appinfo/info.xml and "version" in package.json
# 2. commit
git tag v1.0.1
git push origin v1.0.1
```

The workflow then checks the tag against `info.xml` (a mismatch fails the build rather
than shipping a mislabelled release), builds the frontend, assembles the package,
signs its contents with `occ integrity:sign-app`, tars it, attaches it to the GitHub
release, and posts the download URL and a detached signature to the app store API.

### Doing it by hand

```bash
make appstore NEXTCLOUD_ROOT=/path/to/nextcloud
openssl dgst -sha512 -sign ~/.nextcloud/certificates/nextcloud_cleaner.key \
        build/artifacts/nextcloud_cleaner-1.0.1.tar.gz | openssl base64 -A
```

Then upload the tarball somewhere permanent and POST it:

```bash
curl -X POST https://apps.nextcloud.com/api/v1/apps/releases \
     -H "Authorization: Token $APP_STORE_TOKEN" \
     -H 'Content-Type: application/json' \
     -d '{"download": "https://…/nextcloud_cleaner-1.0.1.tar.gz", "signature": "…", "nightly": false}'
```

---

## What the store checks

- `appinfo/info.xml` validates against
  [its schema](https://apps.nextcloud.com/schema/apps/info.xsd). CI validates this on
  every push, because otherwise you discover a malformed `info.xml` at the moment you
  are trying to publish.
- The tarball contains exactly one top-level directory, named `nextcloud_cleaner`.
- The detached signature verifies against the registered certificate.
- `<nextcloud min-version>`/`<max-version>` decide which servers are offered the app.
  **Raise `max-version` when a new Nextcloud comes out**, or the app quietly disappears
  from the store for everyone who upgrades.

## Version support

| Nextcloud | Status |
|---|---|
| 31 – 34 | Supported, declared in `info.xml` |
| ≤ 30 | Not supported. The app uses the FilesMetadata API and PHP 8.1 syntax. |

