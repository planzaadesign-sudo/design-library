# Planzaa Design Library — Customer, Admin, In-house & Freelancer

A real, working PHP + MySQL application tested end-to-end against a real
MariaDB database before delivery. See "What was tested" at the bottom.

## What's included

- **Customer** (`/`) — browse designs, live plot-match check, structural
  drawings add-on, order placement. No login required.
- **Admin** (`/admin/`) — every order, every brief, the full design library,
  the whole team. Full control.
- **In-house team** (`/inhouse/`) — assigned orders, review queue for
  freelancer submissions (approve or send back), standardize approved
  submissions into published designs, post new briefs.
- **Freelancer** (`/freelancer/`) — open briefs to claim (first come first
  served, race-safe), submit designs with file upload, submission history
  with review feedback, earnings view.

## Test login accounts

Created by `seed_accounts.php` — delete that file after running it once.
Change these passwords before going to production.

| Role       | Email              | Password   |
|------------|--------------------|------------|
| Admin      | admin@planzaa.in   | admin123   |
| In-house   | aarav@planzaa.in   | inhouse123 |
| Freelancer | aman@example.com   | free123    |

## Deploy to Hostinger — step by step

1. Create the database in hPanel -> Databases, note host/name/user/password.
2. In phpMyAdmin, import `schema.sql` first, then `schema-phase2.sql`.
3. Fill in `config.php` with real database credentials.
4. Upload everything except the two `.sql` files into the `test` folder.
5. Visit `test.planzaa.in/seed_accounts.php` once to create logins and
   sample briefs. Then DELETE `seed_accounts.php` from the server
   immediately — a script with known passwords must never stay live.
6. Test each dashboard with the accounts above.

## Security measures

- Prices always recalculated server-side, never trusted from the browser.
- All queries use prepared statements (PDO with bound parameters).
- Uploaded files blocked from executing as PHP via `uploads/.htaccess`.
- Admin dashboard requires admin role specifically (not just any staff).
- Sessions gate every dashboard page — no data shown without login.
- `config.php`, `db.php`, and `.sql` files blocked from direct browser access.
- Passwords hashed with `password_hash()`, never stored in plain text.
- Order codes are random and namespaced (`PZL-XXXXXX`).

## What was actually tested

- Customer browse, plot-match, structural add-on, order placement.
- A simulated price-tampering attack (sent total_price: 1) was blocked.
- Freelancer login, viewing briefs, claiming one.
- Two concurrent claim requests on the same brief — only one succeeded.
- Full submission flow with real file upload.
- In-house approving a submission with notes.
- In-house rejecting a submission — brief returns to needs_revision.
- Standardizing an approved submission into a published design — it
  appeared on the customer library page and credited freelancer earnings.
- Admin assigning an order and advancing its stage.
- Wrong password rejected; unauthenticated access redirected to login.
- In-house staff blocked from admin dashboard (bug found and fixed).
- Currency symbol bug found and fixed (PHP unicode escape issue).
- Full fresh-install test from empty database using only delivered files.

## Still to decide before production

- Royalty split (currently placeholder 10% of resale price).
- Auto-return briefs to "open" if deadline passes with no submission.
- Password reset flow for real users.
