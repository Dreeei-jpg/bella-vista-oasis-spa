# Bella Vista Oasis Spa — Phase 5.3

The existing PHP session login used one MySQL `users` table, a unique email index, password hashes, and the `customer` and `admin` roles. Phase 5.3 keeps that structure and adds administrator account status, a last sign-in timestamp, and a session version that the server checks on protected requests.

## Using Account & Security

Open **Management Dashboard → Account & Security**, or follow the link from **Business Settings**.

1. Under **My Account**, choose **Change Email** or **Change Password**. Enter your current password and the new details. The change applies to your own account ID. The current browser receives a new session and other sessions must sign in again.
2. Under **Manage Administrators**, choose **Add Administrator**. Enter the recipient’s name and email, then confirm with your own current password. Copy the invitation from the resulting page and share it privately with the intended recipient. Bella Vista does not send an email.
3. The recipient opens the link in a signed-out browser, chooses a password, and accepts the terms. They then sign in separately. The link can be used once and expires after 60 minutes by default.
4. Create a new link for a pending account, or revoke an unused invitation. Deactivating an active colleague requires confirmation and signs out their sessions. Reactivation restores access with their existing password. Self-deactivation and removal of the final active administrator are blocked.
5. **Account Security** shows the 50 most recent account events, including who made the change and which account it affected.

All active administrators share the existing role. A fresh installation can run `tools/make-admin.php` once to promote its first administrator. Use dashboard invitations for additional accounts.

## Migration and security controls

[Migration 007](../database/migrations/007_admin_account_security.sql) adds status, session version and last sign-in to `users`. Existing accounts start active. An invited account has no password or terms acceptance until the recipient completes setup. MySQL enforces the original unique email index and requires active accounts to have credentials and terms acceptance.

Invitations use 32 random bytes. MySQL stores the SHA-256 digest, expiry, issuer, recipient, and usage or revocation timestamps. The creator sees the raw link in one response with no caching or referrer sharing. Its token is carried in a browser fragment and submitted in the body of the password-setup request. The browser clears the fragment after reading it. The audit table stores event types, account IDs, and timestamps.

Account changes require an active administrator session, CSRF protection and current-password verification. Five incorrect reauthentication attempts within 15 minutes block further account changes for the remainder of that window. Account changes and invitation consumption are serialized and committed with their audit events. Passwords use Argon2id where PHP provides it. The fallback uses PHP’s default hashing with a 72-byte limit.

To upgrade an existing installation, back up the database and run `tools/migrate.php` from the project directory. The migration was applied to the local database and a rerun detected it as already applied. Ten pre/post record comparisons covered the original accounts and password hashes, reservations, therapist assignments and schedules, health declarations, payment attempts and both payment ledgers. They matched.

## Verification

| Check | Result |
|---|---|
| Isolated administrator HTTP suite | **63 passed.** Email change and login, password change and session rotation, pending access denial, invitation hashing and expiration, regeneration and revocation, weak passwords, single-use setup, audit events, role checks, and deactivation safeguards. |
| Local reservation regression on port 8088 | **84 passed.** Includes 83 HTTP checks and verification that original database records were preserved. |
| Isolated account and public route regression | **143 passed.** Includes customer registration, role protections, login and logout, CSRF, password hashing and existing site routes. |
| Desktop and 390px browser checks | **Passed.** Account sections, mobile table scrolling, both themes, accessible show/hide controls and removal of the invitation fragment from the address bar. |

The credential-changing tests used disposable accounts, and the original administrator’s login details were preserved. See the desktop account page (screenshot retained privately), mobile password form (screenshot retained privately), and [exact file changes](PHASE-5.3-FILE-CHANGES.md).

## Limits

- Bella Vista does not verify email ownership or deliver email. New addresses take effect after current-password confirmation. Share invitation links only with the intended recipient.
- Set `admin_invitation_minutes` in the private local configuration to change the default. The accepted range is 5 to 1,440 minutes. Invitation setup requires JavaScript. If the setup page is refreshed before completion, reopen the original link.
- Use the intended HTTPS site address when issuing links beyond localhost. Keep request body logging disabled for authentication routes. No public deployment, automated password recovery, or multi-factor authentication is included in this phase.
