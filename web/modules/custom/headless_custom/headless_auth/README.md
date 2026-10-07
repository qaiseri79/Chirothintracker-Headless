# Portal password recovery

Login stays with Drupal core (email-or-name lookup, password verification,
flood control, session regeneration). Next.js forwards the session via its
HttpOnly cookie; roles are restored from `/api/headless/session`.

The login page now links to `/forgot-password`. Anonymous recovery endpoints
send a core `user_pass_rehash()` token to the registered email and accept a new
password only with a valid, unexpired token. Saving through the user entity
hashes the password, invalidates outstanding reset links and closes existing
Drupal sessions. Recovery never changes roles and does not automatically log in.

Configure the trusted **frontend base URL** at
`/admin/config/people/headless-password-reset` (requires core's administer account
settings permission). This URL must point to Next.js, without query/fragment or
credentials. Production should use HTTPS. Local development: `http://localhost:3000`.
Do not derive email links from browser-supplied Host/Origin headers.

Existing installations need `drush cr` to discover routes/services; the config
form works even before the settings config exists. Example:
`drush cset headless_auth.settings frontend_base_url https://portal.example.com -y`.
Deploy the config value for your environment, not the local development URL.

Mail uses `headless_mail` and the configured Drupal mail transport. Known,
unknown, blocked and address-throttled accounts all receive the same response;
mail failures are recorded server-side. Request limits are 5 per email per hour
and 500 per backend-observed IP per hour; reset attempts are 100 per IP per hour.
Behind the Next.js proxy, that IP can be shared. Configure trusted reverse-proxy
IP handling in Drupal before raising limits or forwarding client IPs. Untrusted
browser forwarding headers are not used here.

Reset links always expire according to `user.settings:password_reset_timeout`,
including accounts that have never logged in. Account/email/password changes
invalidate the signed link. A per-account lock prevents concurrent use during
reset. Passwords require at least 8 characters and at most 512 UTF-8 bytes, and
Drupal password field constraints also apply.
