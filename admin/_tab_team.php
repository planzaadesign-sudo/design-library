<?php
if (!defined('PLANZAA_ADMIN')) { http_response_code(403); exit; }

$staff = q("SELECT st.*, (SELECT COUNT(*) FROM library_orders o WHERE o.assigned_to = st.id AND o.status <> 'delivered') AS active_orders
            FROM staff st ORDER BY st.role, st.name")->fetchAll();
$editId = (int)($_GET['edit'] ?? 0);
$adding = !empty($_GET['new']);
$editing = null;
foreach ($staff as $s) if ((int)$s['id'] === $editId) $editing = $s;
$v = function ($key, $default = '') use ($editing) { $o = old($key, null); return $o !== null ? $o : ($editing ? $editing[$key] : $default); };
?>
<div class="toolbar">
  <p class="muted">Admins can use this dashboard. In-house staff use the in-house dashboard (reviews, their assigned orders, posting briefs).</p>
  <a class="btn btn-primary" href="<?= h(url(['tab' => 'team', 'new' => 1])) ?>#staffForm">+ Add team member</a>
</div>

<?php if ($adding || $editing): ?>
<section class="adm-card form-card" id="staffForm">
  <h2><?= $editing ? 'Edit ' . h($editing['name']) : 'Add a team member' ?></h2>
  <form method="post" data-saving autocomplete="off">
    <?= csrf_field() ?><?= return_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'staff_edit' : 'staff_add' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <div class="form-grid-adm">
      <label>Name<input name="name" required maxlength="100" value="<?= h($v('name')) ?>"></label>
      <label>Email<input name="email" type="email" required maxlength="150" value="<?= h($v('email')) ?>"></label>
      <label>Role<select name="role"<?= $editing && (int)$editing['id'] === $myId ? ' disabled' : '' ?>>
        <option value="inhouse" <?= $v('role', 'inhouse') === 'inhouse' ? 'selected' : '' ?>>In-house team</option>
        <option value="admin" <?= $v('role') === 'admin' ? 'selected' : '' ?>>Admin</option>
      </select><?php if ($editing && (int)$editing['id'] === $myId): ?><input type="hidden" name="role" value="admin"><small>You cannot change your own role.</small><?php endif; ?></label>
      <?php if (!$editing || (int)$editing['id'] !== $myId): ?>
        <label><?= $editing ? 'New temporary password <span class="muted">(optional)</span>' : 'Temporary password' ?><input id="tmpPw" name="password" type="password"<?= $editing ? '' : ' required' ?> minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="200" autocomplete="new-password">
          <small><?= $editing ? 'Only if they cannot sign in. ' : '' ?>They will be asked to choose their own password when they first sign in.</small></label>
        <label>Type the password again<input id="tmpPw2" name="password2" type="password"<?= $editing ? '' : ' required' ?> maxlength="200" autocomplete="new-password"></label>
      <?php endif; ?>
    </div>
    <?php if (!$editing || (int)$editing['id'] !== $myId): ?><?= $editing ? '' : password_rules_html('tmpPw', 'tmpPw2') ?><?php endif; ?>
    <div class="adm-actions">
      <a class="btn" href="<?= h(url(['tab' => 'team'])) ?>">Cancel</a>
      <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Add team member' ?></button>
    </div>
  </form>
</section>
<?php endif; ?>

<div class="table-wrap"><table class="adm-table">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Active orders</th><th>Added</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($staff as $s): $me = (int)$s['id'] === $myId; ?>
    <tr>
      <td><strong><?= h($s['name']) ?></strong><?= $me ? ' <span class="badge badge-neutral">You</span>' : '' ?><?= !empty($s['must_change_password']) ? ' <span class="badge b-amber">Must set a new password</span>' : '' ?></td>
      <td><?= h($s['email']) ?></td>
      <td><?= $s['role'] === 'admin' ? '<span class="badge b-blue">Admin</span>' : '<span class="badge badge-neutral">In-house</span>' ?></td>
      <td><?= (int)$s['active_orders'] ?></td>
      <td class="nowrap"><?= fdate($s['created_at']) ?></td>
      <td class="row-actions">
        <a class="btn btn-small" href="<?= h(url(['tab' => 'team', 'edit' => $s['id']])) ?>#staffForm">Edit</a>
        <?php if (!$me): ?>
        <form method="post" data-confirm="Remove <?= h($s['name']) ?> from the team? Are you sure? This cannot be undone." data-saving>
          <?= csrf_field() ?><?= return_field() ?>
          <input type="hidden" name="action" value="staff_delete"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="btn btn-small btn-danger-ghost" type="submit">Remove</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
