# Mtaani database setup

## Runtime requirement

The current WAMP installation is PHP 5.3.0 with MySQL 5.1.36. Both are obsolete and do not support the secure password handling and `utf8mb4` schema used by this backend. Upgrade WAMP to GPHP 8.1 or later and MySQL 8 before running the API. The API returns a clear `503` response on PHP versions below 8.1.

## Install locally

1. Install a supported WAMP release and start Apache and MySQL from the WAMP tray menu.
2. Open phpMyAdmin at `http://localhost/phpmyadmin/` and import `database.sql`.
3. Set the database host, name, username, and password in `db_config.php`. Create a dedicated database user rather than using `root` outside a local development machine.
4. Open `http://localhost/Mtaa%20Conect/api.php?action=health`. A successful response is `{"ok":true,"database":"connected"}`.
5. Open the site through `http://localhost/Mtaa%20Conect/`. Do not open the HTML files with `file://`; PHP endpoints need Apache.
6. Register an account on the site. To grant admin access to the requested addresses, run this once in phpMyAdmin after both accounts have registered:

```sql
UPDATE users
SET role = 'admin'
WHERE email IN ('jose@gmail.com', 'mose@gmail.com');
```

Admin access uses the account's own password. There is no shared admin password in the API.

## API overview

- `GET api.php?action=public_snapshot`: published providers, jobs, promotions, and community updates.
- `POST api.php?action=register` / `login` / `logout`: account and session management.
- `POST api.php?action=job_create`: create a public job post.
- `POST api.php?action=contact_create`: save a customer message.
- `POST api.php?action=skill_submit`: submit a signed-in member's profile for admin review.
- `GET api.php?action=admin_dashboard`: admin-only sign-in, message, job, provider, skill, promotion, and update records.

All request bodies are JSON. Authenticated write requests require the `X-CSRF-Token` returned by `login` or `session`.