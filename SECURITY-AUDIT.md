# Security Notes

- Public users have no accounts or sessions.
- Admin uses a separate session cookie and CSRF tokens for state-changing POST requests.
- Admin password uses `password_hash()` / `password_verify()`.
- Tool/SEO HTML is stripped to a small allowlist; link URLs reject javascript/data/vbscript schemes.
- Storage/config/README are blocked by Apache rules. Apply equivalent Nginx rules in production.
- Advertisement code is intentionally trusted-admin HTML and may contain scripts; restrict admin access accordingly.
- Use HTTPS in production.
- Change the default admin password immediately.
