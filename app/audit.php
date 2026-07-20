<?php

declare(strict_types=1);

function audit_event(string $action, string $entityType, string $entityId, array $details = []): void
{
    $user = current_user();

    $statement = db()->prepare(
        'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details)
         VALUES (:user_id, :action, :entity_type, :entity_id, :details)'
    );

    $statement->execute([
        'user_id' => $user['id'] ?? null,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'details' => json_encode($details, JSON_UNESCAPED_SLASHES),
    ]);
}
