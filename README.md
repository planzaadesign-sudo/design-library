# Planzaa Design Library — Customer, Admin, In-house & Freelancer

A real, working PHP + MySQL application tested end-to-end against a real
MariaDB database before delivery. See "What was tested" at the bottom.

## What's included

- **Customer** (`/`) — browse and filter designs with a live plot-match
  check (`index.php`), design detail with the structural add-on
  (`design.php`), a modification configurator with live pricing
  (`customize.php`), the order form (`order.php`), and order tracking by
  order code + phone (`track.php`). No login required.
- **Admin** (`/admin/`) — dashboard with stats and a to-do list; orders
  (search, filters, room-level detail, final price for manual-review and
  call-back orders, stages, assignment); designs and their rooms; the
  modification price catalog; briefs; submission review and publishing;
  team; freelancers; password change and CSV export. `admin/index.php`
  handles login and routing; each tab is an `admin/_tab_*.php` file that
  refuses to run on its own, and every form carries a CSRF token.
- **In-house team** (`/inhouse/`) — assigned orders, review queue for
  freelancer submissions (approve or send back), standardize approved
  submissions into published designs, post new briefs.
- **Freelancer** (`/freelancer/`) — open briefs to claim (first come first
  served, race-safe), submit designs with file upload, submission history
  with review feedback, earnings view.

## Design similarity checks (no duplicate designs)

`includes/similarity.php` scores two sets of design parameters from 0 to 100
(24 weighted parameters: layout-changing ones count most, looks count least).
It is used at four points:

1. **Posting a brief** (admin and in-house use the same form, in
   `includes/brief_ui.php`): before saving, `api/similarity-check.php` lists
   similar designs and briefs. At 70% or more the poster must confirm; the
   server refuses unconfirmed near-duplicates even if the browser check is skipped.
2. **Freelancer's claimed brief**: shows the 3 closest library designs
   (`api/brief-rooms.php`) and how many similar briefs are in progress.
3. **Reviewing a submission**: side-by-side with the 3 closest designs, a
   parameter table, a required "different enough" confirmation, and
   ready-made "too similar to …" notes.
4. **Publishing**: the new design copies every parameter from its brief
   (`includes/briefs.php`).

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
2. In phpMyAdmin, import `schema.sql` first, then `schema-phase2.sql`,
   then `schema-phase3.sql` (modifications menu + new order columns).
   On a database that already ran phase 3, do not re-import the file —
   run only the `UPDATE modifications ...` block at the bottom of
   `schema-phase3.sql` (it switches the change labels to simple wording),
   then the "Phase 3b" section at the very bottom, once (room lists for
   the 6 sample designs, room-by-room order details, two new changes),
   then the "Phase 3c" section, once (columns for "call me" requests),
   then `schema-phase4.sql`, once (admin dashboard: when an order was assigned),
   then `schema-phase5.sql`, once (design similarity checks: 19 design
   parameters on briefs and designs, plus floors/BHK on briefs),
   then `seed-design-params.sql` (real parameters for the 6 sample designs,
   so the similarity check does not see them as identical; safe to re-run),
   then `schema-phase6.sql`, once (preview image for designer submissions;
   the in-house and designer dashboards show a setup message until it is run).
   If anything is missing, the admin dashboard lists exactly what to run.
3. Fill in `config.php` with real database credentials.
4. Upload everything except the `.sql` files into the `test` folder.
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
