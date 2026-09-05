# Google Docs Public Reader

This package is a beta public-reader for Google Docs.

- No API key required because public Google Docs expose a direct export endpoint
- Public docs only
- Read-only beta release
- Supports `txt` and `html` exports
- Main service: `hexa_package_google_docs\\Services\\GoogleDocsService`


## Multiple write accounts

Settings → Google Docs supports separate Google account profiles. The existing
connection remains the initial default (`legacy`) without moving its credentials.
Add another email, select that profile, and save its OAuth credentials using the
protected credential fields. OAuth authorization must be performed as that Google
account. Verify write identity; **Make default** verifies the selected identity
before saving the default. Account selection for editing settings does not change
the default. Public read settings remain global; credentials, write mode, connected
identity, sharing email, and default folder are isolated per account. New accounts
do not inherit the old account's folder, sharing recipient, or Drive credentials.

Existing service calls use the configured default. For a specific account, use
`$writer->forAccount($accountId)`; this returns a clone without changing the shared
service or global default. Creating a document also accepts an optional fourth
`$accountId` argument after the optional folder ID. Retain the account ID with a
document in calling workflows that need to edit or delete it after a default change.
The target owner/share email grants access; it does not select the creating account.
