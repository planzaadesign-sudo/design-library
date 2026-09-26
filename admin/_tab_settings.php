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
  <section class="adm-card form-card" id="password">
    <h2>Change your password</h2>
    <form method="post" data-saving autocomplete="off">
      <?= csrf_field() ?><?= return_field() ?>
      <input type="hidden" name="action" value="password_change">
      <div class="form-grid-adm one">
        <label>Current password<input name="current_password" type="password" required autocomplete="current-password"></label>
        <label>New password<input id="newPw" name="new_password" type="password" required minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="200" autocomplete="new-password"></label>
        <?= password_rules_html('newPw', 'newPw2') ?>
        <label>Type the new password again<input id="newPw2" name="password2" type="password" required maxlength="200" autocomplete="new-password"></label>
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

<?php if (security_ready()):
    $failedRecent = q("SELECT email, ip_address, attempted_at FROM login_attempts WHERE success = 0 ORDER BY id DESC LIMIT 20")->fetchAll();
    $lockedEmails = q("SELECT email, COUNT(*) AS n, MAX(attempted_at) AS last_at FROM login_attempts
                       WHERE success = 0 AND cleared = 0 AND attempted_at > (NOW() - INTERVAL ? SECOND)
                       GROUP BY email HAVING n >= ? ORDER BY last_at DESC", [LOGIN_EMAIL_WINDOW, LOGIN_EMAIL_MAX_FAILS])->fetchAll();
    $blockedIps = q("SELECT ip_address, COUNT(*) AS n, MAX(attempted_at) AS last_at FROM login_attempts
                     WHERE success = 0 AND cleared = 0 AND attempted_at > (NOW() - INTERVAL ? SECOND)
                     GROUP BY ip_address HAVING n >= ? ORDER BY last_at DESC", [LOGIN_IP_WINDOW, LOGIN_IP_MAX_FAILS])->fetchAll();
    $mustChange = (int)q("SELECT COUNT(*) FROM staff WHERE must_change_password = 1 AND id <> ?", [$myId])->fetchColumn();
?>
<section class="adm-card" id="security">
  <h2>Security</h2>
  <div class="detail-grid">
    <div>
      <h3>Locked accounts</h3>
      <?php if (!$lockedEmails && !$blockedIps): ?><p class="empty">No one is locked out right now.</p><?php endif; ?>
      <?php if ($lockedEmails): ?>
      <p class="muted small-note">Locked for an hour after <?= LOGIN_EMAIL_MAX_FAILS ?> wrong passwords. Unlock only if you know it was the real person.</p>
      <ul class="lock-list">
        <?php foreach ($lockedEmails as $l): ?><li><div><strong class="break"><?= h($l['email']) ?></strong><span class="muted"><?= (int)$l['n'] ?> failed attempts &#183; last <?= fdate($l['last_at'], true) ?></span></div>
          <form method="post" data-saving><?= csrf_field() ?><input type="hidden" name="action" value="security_unlock"><input type="hidden" name="email" value="<?= h($l['email']) ?>"><button class="btn btn-small" type="submit">Unlock</button></form></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if ($blockedIps): ?>
      <p class="muted small-note">Internet connections waiting 15 minutes after <?= LOGIN_IP_MAX_FAILS ?> wrong passwords.</p>
      <ul class="lock-list">
        <?php foreach ($blockedIps as $l): ?><li><div><strong><?= h($l['ip_address']) ?></strong><span class="muted"><?= (int)$l['n'] ?> failed attempts &#183; last <?= fdate($l['last_at'], true) ?></span></div>
          <form method="post" data-saving><?= csrf_field() ?><input type="hidden" name="action" value="security_unlock"><input type="hidden" name="ip" value="<?= h($l['ip_address']) ?>"><button class="btn btn-small" type="submit">Unblock</button></form></li><?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <h3 class="sec-sub">Team passwords</h3>
      <p class="muted small-note">Everyone except you will have to choose a new password the next time they open their dashboard.<?= $mustChange ? ' Right now ' . $mustChange . ' team member' . ($mustChange === 1 ? ' still has' : 's still have') . ' to do this.' : '' ?></p>
      <form method="post" data-saving data-confirm="Ask every other team member to set a new password? They will not be able to use their dashboard until they do.">
        <?= csrf_field() ?><input type="hidden" name="action" value="force_password_change">
        <button class="btn btn-danger-ghost" type="submit">Force all team members to change their passwords</button>
      </form>
    </div>
    <div>
      <h3>Recent failed sign-in attempts</h3>
      <?php if (!$failedRecent): ?><p class="empty">No failed sign-ins yet.</p><?php else: ?>
      <div class="table-wrap"><table class="adm-table compact">
        <thead><tr><th>Email</th><th>IP address</th><th>When</th></tr></thead><tbody>
        <?php foreach ($failedRecent as $a): ?><tr><td class="break"><?= h($a['email'] !== '' ? $a['email'] : '(empty)') ?></td><td class="nowrap"><?= h($a['ip_address']) ?></td><td class="nowrap"><?= fdate($a['attempted_at'], true) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <p class="muted small-note">The last 20. A few are normal (typos). Many for one email or from one IP address can mean someone is guessing passwords.</p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>
