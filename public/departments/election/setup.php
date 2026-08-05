<?php
require_once __DIR__ . '/../../../app/bootstrap.php';
require_election_manager();
election_require_assignment_setup();
election_require_day_checklist_setup();

$setupLinks = [
    [
        'title' => 'Election Periods',
        'description' => 'Add elections, edit election dates, and close completed election periods.',
        'href' => url('departments/election/election-periods.php'),
    ],
    [
        'title' => 'Precincts',
        'description' => 'Manage precinct names, polling locations, addresses, and active status.',
        'href' => url('departments/election/precincts.php'),
    ],
    [
        'title' => 'Positions',
        'description' => 'Manage worker positions and Chief Judge permission flags.',
        'href' => url('departments/election/positions.php'),
    ],
    [
        'title' => 'Election Day Setup',
        'description' => 'Build checklist templates and copy checklist tasks from a previous election.',
        'href' => url('departments/election/election-day-setup.php'),
    ],
];

page_header('Election Setup');
?>
<main class="shell">
    <section class="panel">
        <h1>Election Setup</h1>
        <p>Choose the setup area you need to manage.</p>
        <?php election_navigation('setup'); ?>
    </section>

    <section class="grid setup-link-grid" style="margin-top: 18px;">
        <?php foreach ($setupLinks as $link): ?>
            <article class="card setup-link-card">
                <h2><?= e($link['title']) ?></h2>
                <p><?= e($link['description']) ?></p>
                <div class="actions">
                    <a class="button secondary" href="<?= e($link['href']) ?>">Open</a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</main>
<?php page_footer(); ?>
