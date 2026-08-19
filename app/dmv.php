<?php

declare(strict_types=1);

function dmv_navigation(string $activeKey = ''): void
{
    $groups = [
        'title-work' => [
            'label' => 'Title Work',
            'items' => [
                ['key' => 'title-requests', 'label' => 'Title Requests', 'href' => url('departments/dmv/title-requests.php')],
                ['key' => 'new-title-request', 'label' => 'New Title Request', 'href' => url('departments/dmv/title-request-create.php')],
            ],
        ],
        'lienholders' => [
            'label' => 'Lienholders',
            'items' => [
                ['key' => 'lienholders', 'label' => 'Lienholders', 'href' => url('departments/dmv/lienholders.php')],
                ['key' => 'new-lienholder', 'label' => 'New Lienholder', 'href' => url('departments/dmv/lienholder-create.php')],
            ],
        ],
        'reports' => [
            'label' => 'Reports',
            'items' => [
                ['key' => 'reports', 'label' => 'DMV Reports', 'href' => url('departments/dmv/report.php')],
            ],
        ],
    ];

    if (can_manage_department('dmv')) {
        $groups['lienholders']['items'][] = ['key' => 'merge-lienholders', 'label' => 'Merge Lienholders', 'href' => url('departments/dmv/lienholder-merge.php')];
        $groups['setup'] = [
            'label' => 'Setup',
            'items' => [
                ['key' => 'vehicle-lookups', 'label' => 'Vehicle Lookups', 'href' => url('departments/dmv/vehicle-lookups.php')],
            ],
        ];
    }

    $activeAliases = [
        'title-request-edit' => 'title-requests',
        'title-request-detail' => 'title-requests',
        'letter' => 'title-requests',
        'lienholder-edit' => 'lienholders',
    ];
    $navActiveKey = $activeAliases[$activeKey] ?? $activeKey;

    $breadcrumb = [
        ['label' => 'DMV Home', 'href' => url('departments/dmv/index.php')],
    ];
    if ($navActiveKey !== 'home') {
        foreach ($groups as $groupKey => $group) {
            if ($navActiveKey === $groupKey) {
                $breadcrumb[] = ['label' => $group['label'], 'href' => null];
                break;
            }

            foreach ($group['items'] as $item) {
                if ($item['key'] === $navActiveKey) {
                    $breadcrumb[] = ['label' => $group['label'], 'href' => null];
                    $breadcrumb[] = ['label' => $item['label'], 'href' => null];
                    break 2;
                }
            }
        }
    }

    ?>
    <div class="election-nav-block">
        <nav class="election-nav" aria-label="DMV navigation">
            <a class="button<?= $navActiveKey === 'home' ? '' : ' secondary' ?>" href="<?= e(url('departments/dmv/index.php')) ?>">DMV Home</a>
            <?php foreach ($groups as $groupKey => $group): ?>
                <?php $isActiveGroup = $navActiveKey === $groupKey || (bool) array_filter($group['items'], fn($item) => $item['key'] === $navActiveKey); ?>
                <details class="election-nav-menu">
                    <summary class="<?= $isActiveGroup ? 'active' : '' ?>"><?= e($group['label']) ?></summary>
                    <div class="election-nav-list">
                        <?php foreach ($group['items'] as $item): ?>
                            <a class="<?= $navActiveKey === $item['key'] ? 'active' : '' ?>" href="<?= e($item['href']) ?>"><?= e($item['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </nav>
        <div class="election-breadcrumb" aria-label="DMV breadcrumb">
            <?php foreach ($breadcrumb as $index => $crumb): ?>
                <?php if ($index > 0): ?>
                    <span class="election-breadcrumb-separator">/</span>
                <?php endif; ?>
                <?php if (!empty($crumb['href']) && $index < count($breadcrumb) - 1): ?>
                    <a href="<?= e($crumb['href']) ?>"><?= e($crumb['label']) ?></a>
                <?php else: ?>
                    <span><?= e($crumb['label']) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
