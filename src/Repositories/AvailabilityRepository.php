<?php

// Repository delle fasce orarie di disponibilità dei medici.

declare(strict_types=1);

namespace App\Repositories;

final class AvailabilityRepository extends BaseRepository
{
    public function listByDoctor(int $doctorId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM doctor_availability WHERE doctor_id = :doctor_id ORDER BY weekday, start_time'
        );
        $stmt->execute(['doctor_id' => $doctorId]);
        return $stmt->fetchAll();
    }

    public function replaceForDoctor(int $doctorId, array $rows): void
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM doctor_availability WHERE doctor_id = :doctor_id');
            $delete->execute(['doctor_id' => $doctorId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO doctor_availability (doctor_id, weekday, start_time, end_time, valid_from, valid_to, created_at, updated_at)
                 VALUES (:doctor_id, :weekday, :start_time, :end_time, :valid_from, :valid_to, :created_at, :updated_at)'
            );

            $now = date('Y-m-d H:i:s');
            foreach ($rows as $row) {
                $insert->execute([
                    'doctor_id' => $doctorId,
                    'weekday' => $row['weekday'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'valid_from' => $row['valid_from'] ?? null,
                    'valid_to' => $row['valid_to'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function findAvailableDoctorIdsForSlot(string $slotStart, string $slotEnd): array
    {
        $date = substr($slotStart, 0, 10);
        $startTime = substr($slotStart, 11, 5);
        $endTime = substr($slotEnd, 11, 5);
        $weekday = (int)date('N', strtotime($slotStart));

        $sql = <<<SQL
SELECT d.id
FROM doctors d
WHERE d.active = 1
  AND EXISTS (
      SELECT 1
      FROM doctor_availability a
      WHERE a.doctor_id = d.id
        AND a.weekday = :weekday
        AND time(a.start_time) <= time(:start_time)
        AND time(a.end_time) >= time(:end_time)
        AND (a.valid_from IS NULL OR date(:slot_date) >= date(a.valid_from))
        AND (a.valid_to IS NULL OR date(:slot_date) <= date(a.valid_to))
  )
ORDER BY d.id
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'weekday' => $weekday,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'slot_date' => $date,
        ]);

        return array_map(static fn(array $row): int => (int)$row['id'], $stmt->fetchAll());
    }
}
