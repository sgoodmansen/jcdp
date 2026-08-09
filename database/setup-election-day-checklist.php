<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$statements = [
    "CREATE TABLE IF NOT EXISTS election_day_checklist_tasks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        election_period_id INT UNSIGNED NOT NULL,
        task_title VARCHAR(190) NOT NULL,
        instructions TEXT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        chief_can_complete TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_election_day_tasks_period_order (election_period_id, is_active, sort_order, task_title),
        CONSTRAINT fk_election_day_tasks_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_day_tasks_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS election_day_checklist_completions (
        election_period_id INT UNSIGNED NOT NULL,
        precinct_id INT UNSIGNED NOT NULL,
        task_id INT UNSIGNED NOT NULL,
        completed_at TIMESTAMP NULL,
        completed_by_user_id INT UNSIGNED NULL,
        completed_by_assignment_id INT UNSIGNED NULL,
        notes TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (election_period_id, precinct_id, task_id),
        CONSTRAINT fk_election_day_completions_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_day_completions_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_day_completions_task FOREIGN KEY (task_id) REFERENCES election_day_checklist_tasks(id) ON DELETE CASCADE,
        CONSTRAINT fk_election_day_completions_user FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_election_day_completions_assignment FOREIGN KEY (completed_by_assignment_id) REFERENCES election_worker_assignments(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS election_day_equipment_schedules (
        election_period_id INT UNSIGNED NOT NULL,
        precinct_id INT UNSIGNED NOT NULL,
        delivery_date DATE NULL,
        delivery_time TIME NULL,
        pickup_date DATE NULL,
        pickup_time TIME NULL,
        notes TEXT NULL,
        updated_by_user_id INT UNSIGNED NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (election_period_id, precinct_id),
        CONSTRAINT fk_election_day_equipment_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_day_equipment_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_day_equipment_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS election_precinct_notes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        election_period_id INT UNSIGNED NOT NULL,
        precinct_id INT UNSIGNED NOT NULL,
        note_type VARCHAR(40) NOT NULL DEFAULT 'other',
        note_text TEXT NOT NULL,
        is_resolved TINYINT(1) NOT NULL DEFAULT 0,
        created_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_election_precinct_notes_lookup (election_period_id, precinct_id, is_resolved, created_at),
        CONSTRAINT fk_election_precinct_notes_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_precinct_notes_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_precinct_notes_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS election_debrief_questions (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        election_period_id INT UNSIGNED NOT NULL,
        question_text VARCHAR(255) NOT NULL,
        help_text TEXT NULL,
        response_type VARCHAR(30) NOT NULL DEFAULT 'long_text',
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_election_debrief_questions_period_order (election_period_id, is_active, sort_order),
        CONSTRAINT fk_election_debrief_questions_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_debrief_questions_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS election_debrief_responses (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        election_period_id INT UNSIGNED NOT NULL,
        precinct_id INT UNSIGNED NOT NULL,
        submitted_at TIMESTAMP NULL,
        submitted_by_user_id INT UNSIGNED NULL,
        submitted_by_assignment_id INT UNSIGNED NULL,
        other_comments TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_election_debrief_response (election_period_id, precinct_id),
        CONSTRAINT fk_election_debrief_responses_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_debrief_responses_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_debrief_responses_user FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_election_debrief_responses_assignment FOREIGN KEY (submitted_by_assignment_id) REFERENCES election_worker_assignments(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS election_debrief_answers (
        response_id BIGINT UNSIGNED NOT NULL,
        question_id INT UNSIGNED NOT NULL,
        answer_text TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (response_id, question_id),
        CONSTRAINT fk_election_debrief_answers_response FOREIGN KEY (response_id) REFERENCES election_debrief_responses(id) ON DELETE CASCADE,
        CONSTRAINT fk_election_debrief_answers_question FOREIGN KEY (question_id) REFERENCES election_debrief_questions(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS election_chief_feedback (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        election_period_id INT UNSIGNED NOT NULL,
        precinct_id INT UNSIGNED NOT NULL,
        chief_assignment_id INT UNSIGNED NOT NULL,
        category VARCHAR(40) NOT NULL DEFAULT 'other',
        message_text TEXT NOT NULL,
        created_by_user_id INT UNSIGNED NULL,
        updated_by_user_id INT UNSIGNED NULL,
        acknowledged_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_election_chief_feedback_lookup (election_period_id, precinct_id, chief_assignment_id, acknowledged_at),
        CONSTRAINT fk_election_chief_feedback_period FOREIGN KEY (election_period_id) REFERENCES election_periods(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_chief_feedback_precinct FOREIGN KEY (precinct_id) REFERENCES election_precincts(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_chief_feedback_assignment FOREIGN KEY (chief_assignment_id) REFERENCES election_worker_assignments(id) ON DELETE RESTRICT,
        CONSTRAINT fk_election_chief_feedback_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_election_chief_feedback_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    )",
];

foreach ($statements as $statement) {
    db()->exec($statement);
}

echo "Election Day tables ready.\n";
