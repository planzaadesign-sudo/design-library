<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }
$orderCount = (int)q("SELECT COUNT(*) FROM library_orders")->fetchColumn();
$pdo = getDB();
?>
<section class="adm-card form-card">
  <h2>Orders and notifications</h2>
  <form method="post" data-saving>
    <?= csrf_field() ?><?= return_field() ?>
    <input type="hidden" name="action" value="settings_save">
    <div class="form-grid-adm">
      <label class="span-2">Maximum active orders per team member before auto-assignment considers them overloaded
        <input name="max_active_orders_per_person" type="number" min="1" max="100" required value="<?= h(old('max_active_orders_per_person', getSettingValue($pdo, 'max_active_orders_per_person', 5))) ?>">
        <small>New orders go to the person who approved the design, unless they already have this many active orders.</small></label>
      <label>Admin notification email
        <input name="admin_notification_email" type="email" maxlength="150" value="<?= h(old('admin_notification_email', getSettingValue($pdo, 'admin_notification_email', ''))) ?>" placeholder="you@planzaa.in">
        <small>An email is sent here for every new order. Leave empty to turn it off.</small></label>
      <label>Website address
        <input name="site_url" type="url" maxlength="150" required value="<?= h(old('site_url', getSettingValue($pdo, 'site_url', 'https://test.planzaa.in'))) ?>">
        <small>Used for the link in the email.</small></label>
    </div>
    <div class="adm-actions left"><button class="btn btn-primary" type="submit">Save settings</button></div>
  </form>
</section>
<div class="detail-grid">
  <section class="adm-card form-card">
    <h2>Change your password</h2>
    <form method="post" data-saving autocomplete="off">
      <?= csrf_field() ?><?= return_field() ?>
      <input type="hidden" name="action" value="password_change">
      <div class="form-grid-adm one">
        <label>Current password<input name="current_password" type="password" required autocomplete="current-password"></label>
        <label>New password<input name="new_password" type="password" required minlength="8" autocomplete="new-password"><small>At least 8 characters.</small></label>
        <label>Type the new password again<input name="password2" type="password" required minlength="8" autocomplete="new-password"></label>
      </div>
      <div class="adm-actions left"><button class="btn btn-primary" type="submit">Change password</button></div>
    </form>
  </section>

  <section class="adm-card">
    <h2>Export orders</h2>
    <p>Download all <?= number_format($orderCount) ?> orders as a spreadsheet (CSV) that opens in Excel or Google Sheets.</p>
    <p class="muted small-note">Columns: order code, date, customer name, phone, city, state, district, design, modifications, structural, total price, status, contact preference.</p>
    <a class="btn btn-primary" href="<?= h(url(['tab' => 'settings', 'export' => 'orders'])) ?>">Download CSV</a>
  </section>
</div>
