<?php
// public/setup/account.php
// Step 3: create (or later change) the Support console login. Shop owner
// accounts are created from the Support console, not here.

require_once __DIR__ . '/_setup.php';

$exists = SupportAccount::exists();
$errors = [];
$success = '';
$writeFailed = false;
$old = ['email' => $exists ? SupportAccount::email() : ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['email'] = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    } elseif ($password !== (string) ($_POST['password_confirm'] ?? '')) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        if (SupportAccount::save($old['email'], $password)) {
            $success = $exists ? 'Support account updated.' : 'Support account created.';
            $exists = true;
            unset($_SESSION['support_auth']); // sign in again with the new details
        } else {
            $writeFailed = true;
        }
    }
}

setup_render_header('Support account', 'account');
?>
<?php if ($errors): ?>
  <div class="alert err"><strong>Please fix this:</strong><ul><?php foreach ($errors as $e): ?><li><?php echo setup_h($e); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($writeFailed): ?>
  <div class="alert warn">
    <strong>The server wouldn't let us save the file.</strong><br>
    Give the <code>app/config</code> folder write permission (e.g. 775) and submit again.
  </div>
<?php endif; ?>

<?php if ($success): ?>
  <div class="alert ok"><?php echo setup_h($success); ?> Sign in as <code><?php echo setup_h($old['email']); ?></code>, then create shop owner accounts under <strong>Accounts</strong>.</div>
  <div class="actions" style="margin-bottom:16px;">
    <a class="btn" href="<?php echo public_url('support/login.php'); ?>">Go to support login</a>
  </div>
<?php else: ?>
  <div class="alert info">
    <?php if ($exists): ?>
      A support account already exists (<code><?php echo setup_h(SupportAccount::email()); ?></code>). You can change its email or password below.
    <?php else: ?>
      This is the platform login for the Support console. From there you create each shop's owner account.
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" autocomplete="off">
  <div class="card">
    <h2><?php echo $exists ? 'Change support account' : 'Support account'; ?></h2>
    <label for="email">Email</label>
    <input id="email" name="email" type="email" required value="<?php echo setup_h($old['email']); ?>" placeholder="support@example.com">
    <div class="grid">
      <div>
        <label for="password"><?php echo $exists ? 'New password' : 'Password'; ?></label>
        <input id="password" name="password" type="password" required minlength="8" placeholder="Min. 8 characters" autocomplete="new-password">
      </div>
      <div>
        <label for="password_confirm">Confirm password</label>
        <input id="password_confirm" name="password_confirm" type="password" required minlength="8" autocomplete="new-password">
      </div>
    </div>
  </div>
  <button class="btn" type="submit"><?php echo $exists ? 'Save changes' : 'Create support account'; ?></button>
</form>
<?php setup_render_footer(); ?>
