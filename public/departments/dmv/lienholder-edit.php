<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$statement = db()->prepare('SELECT * FROM dmv_lienholders WHERE id = :id');
$statement->execute(['id' => $id]);
$lienholder = $statement->fetch();

if (!$lienholder) {
    http_response_code(404);
    page_header('Lienholder not found');
    echo '<main class="shell"><section class="panel"><h1>Lienholder not found</h1><p>The selected lienholder could not be found.</p></section></main>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statement = db()->prepare(
        'UPDATE dmv_lienholders
         SET company_name = :company_name,
             contact_name = :contact_name,
             mailing_address = :mailing_address,
             city = :city,
             state = :state,
             zip_code = :zip_code,
             phone = :phone,
             phone_extension = :phone_extension,
             fax = :fax,
             email = :email,
             notes = :notes,
             is_active = :is_active
         WHERE id = :id'
    );

    $statement->execute([
        'id' => $id,
        'company_name' => title_case_company($_POST['company_name'] ?? ''),
        'contact_name' => trim($_POST['contact_name'] ?? ''),
        'mailing_address' => title_case_address($_POST['mailing_address'] ?? ''),
        'city' => title_case_name($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'zip_code' => trim($_POST['zip_code'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'phone_extension' => trim($_POST['phone_extension'] ?? ''),
        'fax' => trim($_POST['fax'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ]);

    audit_event('updated', 'dmv_lienholder', (string) $id, [
        'company_name' => title_case_company($_POST['company_name'] ?? ''),
        'previous_company_name' => $lienholder['company_name'],
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'previous_is_active' => (int) ($lienholder['is_active'] ?? 1),
    ]);

    flash('success', 'Lienholder updated.');
    redirect_to('departments/dmv/lienholders.php');
}

$actions = [
    ['label' => 'Lienholders', 'href' => url('departments/dmv/lienholders.php'), 'primary' => true],
    ['label' => 'DMV home', 'href' => url('departments/dmv/index.php')],
];

page_header('Edit Lienholder');
?>
<main class="shell">
    <section class="panel">
        <h1>Edit Lienholder</h1>
        <p>Update lienholder contact information used on title request letters.</p>

        <?php page_actions($actions); ?>

        <form class="form compact-form" method="post">
            <input type="hidden" name="id" value="<?= e((string) $lienholder['id']) ?>">
            <label class="span-2">
                Company / lienholder name
                <input name="company_name" value="<?= e($lienholder['company_name']) ?>" required>
            </label>
            <label>
                ATTN
                <input name="contact_name" value="<?= e($lienholder['contact_name']) ?>">
            </label>
            <label class="span-2">
                Mailing address
                <input name="mailing_address" value="<?= e($lienholder['mailing_address']) ?>" required>
            </label>
            <label>
                City
                <input name="city" value="<?= e($lienholder['city']) ?>" required>
            </label>
            <label>
                State
                <select name="state" required>
                    <?php state_options($lienholder['state']); ?>
                </select>
            </label>
            <label>
                ZIP code
                <input name="zip_code" value="<?= e($lienholder['zip_code']) ?>" required>
            </label>
            <label>
                Email address
                <input type="email" name="email" value="<?= e($lienholder['email']) ?>">
            </label>
            <label>
                Phone
                <input name="phone" class="phone-input" inputmode="tel" placeholder="(208) 555-1234" value="<?= e($lienholder['phone']) ?>">
            </label>
            <label>
                Phone extension
                <input name="phone_extension" value="<?= e($lienholder['phone_extension']) ?>">
            </label>
            <label>
                Fax
                <input name="fax" class="phone-input" inputmode="tel" placeholder="(208) 555-1234" value="<?= e($lienholder['fax']) ?>">
            </label>
            <label class="span-2">
                Notes
                <textarea name="notes"><?= e($lienholder['notes']) ?></textarea>
            </label>
            <label class="check-label checkbox-row span-2">
                <input type="checkbox" name="is_active" value="1" <?= (int) ($lienholder['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                Active lienholder
            </label>
            <div class="actions span-2">
                <button type="submit">Save changes</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/lienholders.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<script src="<?= e(url('assets/forms.js?v=20260720')) ?>"></script>
<?php page_footer(); ?>
