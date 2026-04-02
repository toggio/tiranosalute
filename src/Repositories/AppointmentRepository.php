<?php

// Repository per l'accesso ai dati delle visite,
// con query su storico stati, conflitti orari e statistiche usate dal ranking.

declare(strict_types=1);

namespace App\Repositories;

final class AppointmentRepository extends BaseRepository
{
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO appointments (
                patient_id, doctor_id, visit_category_id, visit_reason, notes,
                scheduled_start, scheduled_end, status,
                created_by_user_id, created_at, updated_at
             ) VALUES (
                :patient_id, :doctor_id, :visit_category_id, :visit_reason, :notes,
                :scheduled_start, :scheduled_end, :status,
                :created_by_user_id, :created_at, :updated_at
             )'
        );

        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'patient_id' => $data['patient_id'],
            'doctor_id' => $data['doctor_id'],
            'visit_category_id' => $data['visit_category_id'],
            'visit_reason' => $data['visit_reason'],
            'notes' => $data['notes'] ?? null,
            'scheduled_start' => $data['scheduled_start'],
            'scheduled_end' => $data['scheduled_end'],
            'status' => $data['status'] ?? 'PRENOTATA',
            'created_by_user_id' => $data['created_by_user_id'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function addStatusHistory(
        int $appointmentId,
        ?string $fromStatus,
        string $toStatus,
        ?int $changedByUserId,
        ?string $note = null,
        ?string $changedAt = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO appointment_status_history (
                appointment_id, from_status, to_status, changed_by_user_id, changed_at, note
             ) VALUES (
                :appointment_id, :from_status, :to_status, :changed_by_user_id, :changed_at, :note
             )'
        );

        $stmt->execute([
            'appointment_id' => $appointmentId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_by_user_id' => $changedByUserId,
            'changed_at' => $changedAt ?? date('Y-m-d H:i:s'),
            'note' => $note,
        ]);
    }

    public function findById(int $id): ?array
    {
        $sql = <<<SQL
SELECT a.id,
       a.patient_id,
       a.doctor_id,
       a.visit_category_id,
       cv.name AS visit_category,
       a.visit_reason,
       a.notes,
       a.scheduled_start,
       a.scheduled_end,
       a.status,
       a.created_by_user_id,
       a.cancellation_by_role,
       a.cancellation_by_user_id,
       a.cancellation_reason,
       a.canceled_at,
       a.started_at,
       a.ended_at,
       a.created_at,
       a.updated_at,
       p.first_name AS patient_first_name,
       p.last_name AS patient_last_name,
       p.email AS patient_email,
       p.tax_code AS patient_tax_code,
       d.first_name AS doctor_first_name,
       d.last_name AS doctor_last_name,
       d.email AS doctor_email,
       r.id AS report_id,
       du.id AS doctor_user_id,
       pu.id AS patient_user_id
FROM appointments a
JOIN category_visits cv ON cv.id = a.visit_category_id
JOIN patients p ON p.id = a.patient_id
JOIN doctors d ON d.id = a.doctor_id
LEFT JOIN reports r ON r.appointment_id = a.id
LEFT JOIN users du ON du.doctor_id = d.id
LEFT JOIN users pu ON pu.patient_id = p.id
WHERE a.id = :id
LIMIT 1
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        $statuses = [];
        $limit = isset($filters['limit']) ? (int)$filters['limit'] : 0;

        if (!empty($filters['patient_id'])) {
            $where[] = 'a.patient_id = :patient_id';
            $params['patient_id'] = (int)$filters['patient_id'];
        }
        if (!empty($filters['doctor_id'])) {
            $where[] = 'a.doctor_id = :doctor_id';
            $params['doctor_id'] = (int)$filters['doctor_id'];
        }
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status'])
                ? $filters['status']
                : explode(',', (string)$filters['status']);
            $statuses = array_values(array_filter(array_map('trim', $statuses)));
        }

        $visitCategory = trim((string)($filters['visit_category'] ?? ''));
        if ($visitCategory !== '') {
            $where[] = 'cv.name = :visit_category';
            $params['visit_category'] = $visitCategory;
        }
        if (!empty($filters['from_date'])) {
            $where[] = 'date(a.scheduled_start) >= date(:from_date)';
            $params['from_date'] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $where[] = 'date(a.scheduled_start) <= date(:to_date)';
            $params['to_date'] = $filters['to_date'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(LOWER(a.visit_reason) LIKE :q
                     OR LOWER(COALESCE(a.notes, \'\')) LIKE :q
                     OR LOWER(p.first_name || \' \' || p.last_name) LIKE :q
                     OR LOWER(d.first_name || \' \' || d.last_name) LIKE :q)';
            $params['q'] = '%' . strtolower(trim((string)$filters['q'])) . '%';
        }

        $allowedStatuses = ['PRENOTATA', 'IN_CORSO', 'CONCLUSA', 'ANNULLATA'];
        $statuses = array_values(array_intersect($allowedStatuses, $statuses));

        $mode = (string)($filters['mode'] ?? '');
        if ($statuses === [] && $mode === 'todo') {
            $statuses = ['PRENOTATA', 'IN_CORSO'];
        } elseif ($statuses === [] && $mode === 'history') {
            $statuses = ['CONCLUSA', 'ANNULLATA'];
        }

        if ($statuses !== []) {
            $statusParams = [];
            foreach ($statuses as $idx => $status) {
                $key = ':status_' . $idx;
                $statusParams[] = $key;
                $params[substr($key, 1)] = $status;
            }
            $where[] = 'a.status IN (' . implode(', ', $statusParams) . ')';
        }

        $sql = <<<SQL
SELECT a.id,
       a.patient_id,
       a.doctor_id,
       a.visit_category_id,
       cv.name AS visit_category,
       a.visit_reason,
       a.notes,
       a.scheduled_start,
       a.scheduled_end,
       a.status,
       a.created_by_user_id,
       a.cancellation_by_role,
       a.cancellation_by_user_id,
       a.cancellation_reason,
       a.canceled_at,
       a.started_at,
       a.ended_at,
       a.created_at,
       a.updated_at,
       p.first_name AS patient_first_name,
       p.last_name AS patient_last_name,
       p.email AS patient_email,
       p.tax_code AS patient_tax_code,
       d.first_name AS doctor_first_name,
       d.last_name AS doctor_last_name,
       d.email AS doctor_email,
       r.id AS report_id
FROM appointments a
JOIN category_visits cv ON cv.id = a.visit_category_id
JOIN patients p ON p.id = a.patient_id
JOIN doctors d ON d.id = a.doctor_id
LEFT JOIN reports r ON r.appointment_id = a.id
SQL;

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if ($mode === 'todo') {
            $sql .= ' ORDER BY datetime(a.scheduled_start) ASC';
        } elseif ($mode === 'history') {
            $sql .= ' ORDER BY datetime(a.scheduled_start) DESC';
        } else {
            $sql .= ' ORDER BY
                CASE WHEN a.status IN ("PRENOTATA", "IN_CORSO") THEN 0 ELSE 1 END ASC,
                CASE WHEN a.status IN ("PRENOTATA", "IN_CORSO") THEN datetime(a.scheduled_start) END ASC,
                CASE WHEN a.status NOT IN ("PRENOTATA", "IN_CORSO") THEN datetime(a.scheduled_start) END DESC';
        }

        if ($limit > 0) {
            $sql .= ' LIMIT :_limit';
            $params['_limit'] = $limit;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $paramType = in_array($key, ['patient_id', 'doctor_id', '_limit'], true)
                ? \PDO::PARAM_INT
                : \PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $paramType);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function listStatusHistory(int $appointmentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT h.*, u.first_name, u.last_name, u.role
             FROM appointment_status_history h
             LEFT JOIN users u ON u.id = h.changed_by_user_id
             WHERE h.appointment_id = :appointment_id
             ORDER BY h.changed_at ASC, h.id ASC'
        );
        $stmt->execute(['appointment_id' => $appointmentId]);
        return $stmt->fetchAll();
    }

    public function listExpiredBookedAppointments(string $expiredBefore): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id
             FROM appointments a
             WHERE a.status = "PRENOTATA"
               AND datetime(a.scheduled_start) <= datetime(:expired_before)
             ORDER BY datetime(a.scheduled_start) ASC, a.id ASC'
        );
        $stmt->execute(['expired_before' => $expiredBefore]);

        return $stmt->fetchAll();
    }

    public function findActiveAppointmentForPatient(int $patientId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, scheduled_start
             FROM appointments
             WHERE patient_id = :patient_id
               AND status IN ("PRENOTATA", "IN_CORSO")
             ORDER BY
                CASE WHEN status = "PRENOTATA" THEN 0 ELSE 1 END ASC,
                datetime(scheduled_start) ASC,
                id ASC
             LIMIT 1'
        );
        $stmt->execute(['patient_id' => $patientId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function existsDoctorConflict(int $doctorId, string $start, string $end, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM appointments
                WHERE doctor_id = :doctor_id
                  AND status <> "ANNULLATA"
                  AND scheduled_start < :end
                  AND scheduled_end > :start';
        $params = [
            'doctor_id' => $doctorId,
            'start' => $start,
            'end' => $end,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function existsPatientConflict(int $patientId, string $start, string $end, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM appointments
                WHERE patient_id = :patient_id
                  AND status <> "ANNULLATA"
                  AND scheduled_start < :end
                  AND scheduled_end > :start';
        $params = [
            'patient_id' => $patientId,
            'start' => $start,
            'end' => $end,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function updateStatus(int $appointmentId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE appointments SET status = :status, updated_at = :updated_at WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $appointmentId,
        ]);
    }

    public function markStarted(int $appointmentId, string $startedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE appointments
             SET status = "IN_CORSO", started_at = :started_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $appointmentId,
            'started_at' => $startedAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markCompleted(int $appointmentId, string $endedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE appointments
             SET status = "CONCLUSA", ended_at = :ended_at, updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $appointmentId,
            'ended_at' => $endedAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markCancelled(
        int $appointmentId,
        string $byRole,
        int $byUserId,
        string $reason,
        string $cancelledAt
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE appointments
             SET status = "ANNULLATA",
                 cancellation_by_role = :by_role,
                 cancellation_by_user_id = :by_user_id,
                 cancellation_reason = :reason,
                 canceled_at = :canceled_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $appointmentId,
            'by_role' => $byRole,
            'by_user_id' => $byUserId,
            'reason' => $reason,
            'canceled_at' => $cancelledAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markCancelledIfBooked(
        int $appointmentId,
        ?string $byRole,
        ?int $byUserId,
        string $reason,
        string $cancelledAt
    ): bool {
        $stmt = $this->pdo->prepare(
            'UPDATE appointments
             SET status = "ANNULLATA",
                 cancellation_by_role = :by_role,
                 cancellation_by_user_id = :by_user_id,
                 cancellation_reason = :reason,
                 canceled_at = :canceled_at,
                 updated_at = :updated_at
             WHERE id = :id
               AND status = "PRENOTATA"'
        );
        $stmt->execute([
            'id' => $appointmentId,
            'by_role' => $byRole,
            'by_user_id' => $byUserId,
            'reason' => $reason,
            'canceled_at' => $cancelledAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount() > 0;
    }

    public function countDoctorWeeklyLoad(int $doctorId, string $weekStartDate): int
    {
        $start = $weekStartDate . ' 00:00:00';
        $end = date('Y-m-d 23:59:59', strtotime($weekStartDate . ' +6 days'));

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM appointments
             WHERE doctor_id = :doctor_id
               AND status <> "ANNULLATA"
               AND scheduled_start BETWEEN :start AND :end'
        );
        $stmt->execute([
            'doctor_id' => $doctorId,
            'start' => $start,
            'end' => $end,
        ]);

        return (int)$stmt->fetchColumn();
    }

    public function getDoctorCategoryRankingStats(int $doctorId, int $visitCategoryId): array
    {
        $sql = <<<SQL
SELECT COUNT(*) AS samples,
       AVG((julianday(ended_at) - julianday(started_at)) * 24 * 60) AS avg_duration_minutes,
       AVG(CASE WHEN (julianday(started_at) - julianday(scheduled_start)) * 24 * 60 > 0
                THEN (julianday(started_at) - julianday(scheduled_start)) * 24 * 60
                ELSE 0 END) AS avg_delay_minutes
FROM appointments
WHERE doctor_id = :doctor_id
  AND visit_category_id = :visit_category_id
  AND status = 'CONCLUSA'
  AND started_at IS NOT NULL
  AND ended_at IS NOT NULL
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'doctor_id' => $doctorId,
            'visit_category_id' => $visitCategoryId,
        ]);
        $row = $stmt->fetch();
        return [
            'samples' => (int)($row['samples'] ?? 0),
            'avg_duration_minutes' => $row['avg_duration_minutes'] !== null ? (float)$row['avg_duration_minutes'] : null,
            'avg_delay_minutes' => $row['avg_delay_minutes'] !== null ? (float)$row['avg_delay_minutes'] : null,
        ];
    }

    public function getGlobalCategoryRankingStats(int $visitCategoryId): array
    {
        $sql = <<<SQL
SELECT AVG((julianday(ended_at) - julianday(started_at)) * 24 * 60) AS avg_duration_minutes,
       AVG(CASE WHEN (julianday(started_at) - julianday(scheduled_start)) * 24 * 60 > 0
                THEN (julianday(started_at) - julianday(scheduled_start)) * 24 * 60
                ELSE 0 END) AS avg_delay_minutes
FROM appointments
WHERE visit_category_id = :visit_category_id
  AND status = 'CONCLUSA'
  AND started_at IS NOT NULL
  AND ended_at IS NOT NULL
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['visit_category_id' => $visitCategoryId]);
        $row = $stmt->fetch();

        return [
            'avg_duration_minutes' => $row['avg_duration_minutes'] !== null ? (float)$row['avg_duration_minutes'] : null,
            'avg_delay_minutes' => $row['avg_delay_minutes'] !== null ? (float)$row['avg_delay_minutes'] : null,
        ];
    }

    public function countByDoctorInDay(int $doctorId, string $date): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM appointments
             WHERE doctor_id = :doctor_id
               AND status <> "ANNULLATA"
               AND date(scheduled_start) = :d'
        );
        $stmt->execute([
            'doctor_id' => $doctorId,
            'd' => $date,
        ]);

        return (int)$stmt->fetchColumn();
    }
}
