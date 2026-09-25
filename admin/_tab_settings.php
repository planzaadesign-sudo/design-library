<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }
$orderCount = (int)q("SELECT COUNT(*) FROM library_orders")->fetchColumn();
?>
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
