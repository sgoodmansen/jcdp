<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statement = db()->prepare(
        'INSERT INTO dmv_lienholders
            (created_by, company_name, contact_name, mailing_address, city, state, zip_code, phone, fax, email, notes)
         VALUES
            (:created_by, :company_name, :contact_name, :mailing_address, :city, :state, :zip_code, :phone, :fax, :email, :notes)'
    );

    $statement->execute([
        'created_by' => $user['id'],
        'company_name' => trim($_POST['company_name'] ?? ''),
        'contact_name' => trim($_POST['contact_name'] ?? ''),
        'mailing_address' => trim($_POST['mailing_address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'zip_code' => trim($_POST['zip_code'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'fax' => trim($_POST['fax'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
    ]);

    $lienholderId = (int) db()->lastInsertId();
    audit_event('created', 'dmv_lienholder', (string) $lienholderId, [
        'company_name' => trim($_POST['company_name'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
    ]);

    flash('success', 'Lienholder created.');
    redirect_to('departments/dmv/lienholders.php');
}

page_header('New Lienholder');
?>
<main class="shell">
    <section class="panel">
        <h1>New Lienholder</h1>
        <p>Add a lienholder contact that can be reused for title request letters.</p>

        <div class="actions" style="margin-bottom: 18px;">
            <a class="button secondary" href="<?= e(url('departments/dmv/lienholders.php')) ?>">Lienholders</a>
            <a class="button secondary" href="<?= e(url('departments/dmv/index.php')) ?>">DMV home</a>
        </div>

        <form class="form compact-form" method="post">
            <label class="span-2">
                Company / lienholder name
                <input name="company_name" required>
            </label>
            <label>
                ATTN
                <input name="contact_name">
            </label>
            <label class="span-2">
                Mailing address
                <input name="mailing_address" required>
            </label>
            <label>
                City
                <input name="city" required>
            </label>
            <label>
                State
                <select name="state" required>
                    <?php state_options('ID'); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="zip_code" required>
            </label>
            <label>
                Email address
                <input type="email" name="email">
            </label>
            <label>
                Phone
                <input name="phone" class="phone-input" inputmode="tel" placeholder="(208) 555-1234">
            </label>
            <label>
                Fax
                <input name="fax" class="phone-input" inputmode="tel" placeholder="(208) 555-1234">
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions span-2">
                <button type="submit">Save lienholder</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<script src="<?= e(url('assets/forms.js?v=20260720')) ?>"></script>
<?php page_footer(); ?>
