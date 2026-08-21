<?php

declare(strict_types=1);

const SHERIFF_TRAINING_DEPARTMENT_SLUG = 'sheriff-training';

function sheriff_training_schema_ready(): bool
{
    foreach (['sheriff_training_requests', 'sheriff_training_divisions'] as $tableName) {
        $statement = db()->query("SHOW TABLES LIKE " . db()->quote($tableName));
        if (!$statement->fetchColumn()) {
            return false;
        }
    }

    return true;
}

function sheriff_training_require_ready(): void
{
    if (sheriff_training_schema_ready()) {
        return;
    }

    page_header('Sheriff Training Setup Needed');
    ?>
    <main class="shell">
        <section class="panel">
            <h1>Sheriff Training Setup Needed</h1>
            <p>The Sheriff Training module needs to be installed before this page can be used.</p>
            <?php if (is_system_admin()): ?>
                <a class="button" href="<?= e(url('admin/setup-sheriff-training-module.php')) ?>">Run Sheriff Training setup</a>
            <?php else: ?>
                <p>Ask an IT system admin to run the Sheriff Training setup page.</p>
            <?php endif; ?>
        </section>
    </main>
    <?php
    page_footer();
    exit;
}

function require_sheriff_training_manager(): void
{
    require_department_manager(SHERIFF_TRAINING_DEPARTMENT_SLUG);
    sheriff_training_require_ready();
}

function sheriff_training_navigation(string $activeKey): void
{
    $items = [
        ['key' => 'home', 'label' => 'Home', 'href' => url('departments/sheriff-training/index.php')],
        ['key' => 'requests', 'label' => 'Requests', 'href' => url('departments/sheriff-training/requests.php')],
        ['key' => 'request-edit', 'label' => 'New Request', 'href' => url('departments/sheriff-training/request-edit.php')],
        ['key' => 'officers', 'label' => 'Officers', 'href' => url('departments/sheriff-training/officers.php')],
        ['key' => 'divisions', 'label' => 'Divisions', 'href' => url('departments/sheriff-training/divisions.php')],
        ['key' => 'budgets', 'label' => 'Fiscal Budgets', 'href' => url('departments/sheriff-training/budgets.php')],
        ['key' => 'reports', 'label' => 'Reports', 'href' => url('departments/sheriff-training/reports.php')],
    ];
    ?>
    <div class="department-nav election-nav">
        <?php foreach ($items as $item): ?>
            <a class="button<?= $activeKey === $item['key'] ? '' : ' secondary' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php
}

function sheriff_training_status_options(): array
{
    return [
        'pending' => 'Pending Review',
        'approved' => 'Approved',
        'denied' => 'Denied',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
}

function sheriff_training_status_label(?string $status): string
{
    return sheriff_training_status_options()[$status ?? ''] ?? 'Pending Review';
}

function sheriff_training_status_badge_class(?string $status): string
{
    return match ($status) {
        'approved', 'completed' => 'badge-success',
        'denied', 'cancelled' => 'badge-muted',
        default => '',
    };
}

function sheriff_training_money(float|int|string|null $amount): string
{
    return '$' . number_format((float) ($amount ?? 0), 2);
}

function sheriff_training_decimal(?string $value): float
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0.0;
    }

    return round((float) str_replace([',', '$'], '', $value), 2);
}

function sheriff_training_fiscal_year_for_date(?string $date = null): int
{
    $date = $date ?: date('Y-m-d');
    $value = new DateTimeImmutable($date);
    $year = (int) $value->format('Y');
    $month = (int) $value->format('n');

    return $month >= 10 ? $year + 1 : $year;
}

function sheriff_training_fiscal_year_dates(int $fiscalYear): array
{
    return [
        'starts_on' => ($fiscalYear - 1) . '-10-01',
        'ends_on' => $fiscalYear . '-09-30',
    ];
}

function sheriff_training_effective_training_cost(array $request): float
{
    return $request['actual_training_cost'] !== null && $request['actual_training_cost'] !== ''
        ? (float) $request['actual_training_cost']
        : (float) $request['estimated_training_cost'];
}

function sheriff_training_effective_lodging_cost(array $request): float
{
    return $request['actual_lodging_cost'] !== null && $request['actual_lodging_cost'] !== ''
        ? (float) $request['actual_lodging_cost']
        : (float) $request['estimated_lodging_cost'];
}

function sheriff_training_budget_usage(int $fiscalYearId, ?int $excludeRequestId = null): array
{
    $sql = 'SELECT
                COALESCE(SUM(COALESCE(actual_training_cost, estimated_training_cost)), 0) AS training_used,
                COALESCE(SUM(COALESCE(actual_lodging_cost, estimated_lodging_cost)), 0) AS lodging_used
            FROM sheriff_training_requests
            WHERE fiscal_year_id = :fiscal_year_id
              AND status IN ("approved", "completed")';
    $params = ['fiscal_year_id' => $fiscalYearId];

    if ($excludeRequestId !== null && $excludeRequestId > 0) {
        $sql .= ' AND id <> :exclude_request_id';
        $params['exclude_request_id'] = $excludeRequestId;
    }

    $statement = db()->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch() ?: [];

    return [
        'training_used' => (float) ($row['training_used'] ?? 0),
        'lodging_used' => (float) ($row['lodging_used'] ?? 0),
    ];
}

function sheriff_training_budget_summary(int $fiscalYearId, ?int $excludeRequestId = null): ?array
{
    $statement = db()->prepare('SELECT * FROM sheriff_training_fiscal_years WHERE id = :id');
    $statement->execute(['id' => $fiscalYearId]);
    $year = $statement->fetch();

    if (!$year) {
        return null;
    }

    $usage = sheriff_training_budget_usage($fiscalYearId, $excludeRequestId);
    $year['training_used'] = $usage['training_used'];
    $year['lodging_used'] = $usage['lodging_used'];
    $year['training_remaining'] = (float) $year['training_budget'] - $usage['training_used'];
    $year['lodging_remaining'] = (float) $year['lodging_budget'] - $usage['lodging_used'];
    $year['training_used_percent'] = sheriff_training_budget_percent($usage['training_used'], (float) $year['training_budget']);
    $year['lodging_used_percent'] = sheriff_training_budget_percent($usage['lodging_used'], (float) $year['lodging_budget']);

    return $year;
}

function sheriff_training_budget_percent(float $used, float $budget): float
{
    if ($budget <= 0) {
        return $used > 0 ? 100.0 : 0.0;
    }

    return round(($used / $budget) * 100, 1);
}

function sheriff_training_budget_level_class(float $percent): string
{
    if ($percent >= 100) {
        return 'danger';
    }

    if ($percent >= 85) {
        return 'warning';
    }

    return 'ok';
}

function sheriff_training_request_by_id(int $id): ?array
{
    $statement = db()->prepare(
        'SELECT sheriff_training_requests.*,
                sheriff_training_officers.first_name,
                sheriff_training_officers.last_name,
                sheriff_training_officers.email,
                sheriff_training_officers.rank_title,
                sheriff_training_officers.division,
                sheriff_training_fiscal_years.label AS fiscal_year_label,
                sheriff_training_fiscal_years.training_budget,
                sheriff_training_fiscal_years.lodging_budget
         FROM sheriff_training_requests
         INNER JOIN sheriff_training_officers ON sheriff_training_officers.id = sheriff_training_requests.officer_id
         INNER JOIN sheriff_training_fiscal_years ON sheriff_training_fiscal_years.id = sheriff_training_requests.fiscal_year_id
         WHERE sheriff_training_requests.id = :id'
    );
    $statement->execute(['id' => $id]);
    $request = $statement->fetch();

    return $request ?: null;
}

function sheriff_training_date_range(array $request): string
{
    $dates = format_display_date($request['start_date']);
    if (!empty($request['end_date']) && $request['end_date'] !== $request['start_date']) {
        $dates .= ' through ' . format_display_date($request['end_date']);
    }

    return $dates;
}

function sheriff_training_email_template(string $status): array
{
    return match ($status) {
        'approved' => [
            'subject' => 'Training request approved: [Training Name]',
            'body' => "Hello [Officer Name],\n\n"
                . "Your request to attend [Training Name] has been approved.\n\n"
                . "Training dates: [Training Dates]\n"
                . "Location: [Location]\n\n"
                . "[Supervisor Comment]\n\n"
                . "Thank you,\n\n"
                . "[Supervisor Name]",
        ],
        'denied' => [
            'subject' => 'Training request denied: [Training Name]',
            'body' => "Hello [Officer Name],\n\n"
                . "Your request to attend [Training Name] has been denied.\n\n"
                . "Training dates: [Training Dates]\n\n"
                . "Reason:\n"
                . "[Supervisor Comment]\n\n"
                . "Thank you,\n\n"
                . "[Supervisor Name]",
        ],
        'completed' => [
            'subject' => 'Training marked complete: [Training Name]',
            'body' => "Hello [Officer Name],\n\n"
                . "[Training Name] has been marked complete in the Sheriff Training system.\n\n"
                . "Training dates: [Training Dates]\n\n"
                . "[Supervisor Comment]\n\n"
                . "Thank you,\n\n"
                . "[Supervisor Name]",
        ],
        'cancelled' => [
            'subject' => 'Training request cancelled: [Training Name]',
            'body' => "Hello [Officer Name],\n\n"
                . "Your training request for [Training Name] has been cancelled.\n\n"
                . "Training dates: [Training Dates]\n\n"
                . "[Supervisor Comment]\n\n"
                . "Thank you,\n\n"
                . "[Supervisor Name]",
        ],
        default => [
            'subject' => 'Training request update: [Training Name]',
            'body' => "Hello [Officer Name],\n\n"
                . "Your training request status has been updated.\n\n"
                . "Training: [Training Name]\n"
                . "Training dates: [Training Dates]\n"
                . "Status: [Status]\n\n"
                . "[Supervisor Comment]\n\n"
                . "Thank you,\n\n"
                . "[Supervisor Name]",
        ],
    };
}

function sheriff_training_render_email_template(array $template, array $request, string $supervisorName, string $comment): array
{
    $comment = trim($comment);
    if ($comment === '') {
        $comment = match ($request['status']) {
            'denied' => 'No reason was provided.',
            default => '',
        };
    }

    $replacements = [
        '[Officer Name]' => trim($request['first_name'] . ' ' . $request['last_name']),
        '[Training Name]' => (string) $request['class_name'],
        '[Training Dates]' => sheriff_training_date_range($request),
        '[Location]' => trim((string) ($request['location'] ?? '')) ?: 'Not provided',
        '[Provider]' => trim((string) ($request['provider'] ?? '')) ?: 'Not provided',
        '[Payment Fiscal Year]' => trim((string) ($request['fiscal_year_label'] ?? '')) ?: 'Not set',
        '[Status]' => sheriff_training_status_label((string) $request['status']),
        '[Supervisor Comment]' => $comment,
        '[Supervisor Name]' => $supervisorName,
    ];

    $subject = str_replace(array_keys($replacements), array_values($replacements), $template['subject']);
    $body = str_replace(array_keys($replacements), array_values($replacements), $template['body']);

    $body = preg_replace("/\n{3,}/", "\n\n", trim($body));

    return [
        'subject' => $subject,
        'body' => $body,
    ];
}

function sheriff_training_send_status_email(array $request, array $supervisor, string $comment = ''): bool
{
    $to = trim((string) ($request['email'] ?? ''));
    if ($to === '') {
        return false;
    }

    $supervisorName = trim(($supervisor['first_name'] ?? '') . ' ' . ($supervisor['last_name'] ?? '')) ?: 'Sheriff Training Supervisor';
    $fromEmail = trim((string) ($supervisor['email'] ?? ''));
    $from = $fromEmail !== '' ? $supervisorName . ' <' . $fromEmail . '>' : 'Sheriff Training <no-reply@jeffersoncounty.local>';
    $template = sheriff_training_email_template((string) $request['status']);
    $email = sheriff_training_render_email_template($template, $request, $supervisorName, $comment);

    $headers = [
        'From: ' . $from,
    ];
    if ($fromEmail !== '') {
        $headers[] = 'Reply-To: ' . $from;
    }

    return @mail($to, $email['subject'], $email['body'], implode("\r\n", $headers));
}
