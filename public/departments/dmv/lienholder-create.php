<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_department_access('dmv');

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statement = db()->prepare(
        'INSERT INTO dmv_lienholders
            (created_by, company_name, contact_name, mailing_address, city, state, zip_code, phone, email, notes)
         VALUES
            (:created_by, :company_name, :contact_name, :mailing_address, :city, :state, :zip_code, :phone, :email, :notes)'
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
        'email' => trim($_POST['email'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
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

        <form class="form" method="post">
            <label>
                Company / lienholder name
                <input name="company_name" required>
            </label>
            <label>
                Contact name
                <input name="contact_name">
            </label>
            <label>
                Mailing address
                <input name="mailing_address" required>
            </label>
            <label>
                City
                <input name="city" required>
            </label>
            <label>
                State
                <input name="state" required>
            </label>
            <label>
                ZIP code
                <input name="zip_code" required>
            </label>
            <label>
                Phone
                <input name="phone">
            </label>
            <label>
                Email
                <input type="email" name="email">
            </label>
            <label>
                Notes
                <textarea name="notes"></textarea>
            </label>
            <div class="actions">
                <button type="submit">Save lienholder</button>
                <a class="button secondary" href="<?= e(url('departments/dmv/index.php')) ?>">Cancel</a>
            </div>
        </form>
    </section>
</main>
<?php page_footer(); ?>
