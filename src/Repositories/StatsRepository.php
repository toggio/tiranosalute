<?php

// Repository delle statistiche di dashboard,
// separate dallo score operativo usato per assegnare le visite.

declare(strict_types=1);

namespace App\Repositories;

final class StatsRepository extends BaseRepository
{
    private const PERFORMANCE_DURATION_BASELINE_MINUTES = 12;
    private const PERFORMANCE_DURATION_WEIGHT = 1;
    private const PERFORMANCE_DELAY_WEIGHT = 8;

    public function globalSummary(): array
    {
        $summary = [];

        $summary['total_appointments'] = (int)$this->pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
        $summary['cancelled_appointments'] = (int)$this->pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'ANNULLATA'")->fetchColumn();
        $summary['total_patients'] = (int)$this->pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn();
        $summary['total_doctors'] = (int)$this->pdo->query('SELECT COUNT(*) FROM doctors')->fetchColumn();

        $avgDuration = $this->pdo->query(
            "SELECT AVG((julianday(ended_at) - julianday(started_at)) * 24 * 60)
             FROM appointments
             WHERE status = 'CONCLUSA' AND started_at IS NOT NULL AND ended_at IS NOT NULL"
        )->fetchColumn();
        $summary['avg_visit_duration_minutes'] = $avgDuration !== null ? (int)round((float)$avgDuration) : null;

        $avgDelay = $this->pdo->query(
            "SELECT AVG(CASE WHEN (julianday(started_at) - julianday(scheduled_start)) * 24 * 60 > 0
                             THEN (julianday(started_at) - julianday(scheduled_start)) * 24 * 60
                             ELSE 0 END)
             FROM appointments
             WHERE status = 'CONCLUSA' AND started_at IS NOT NULL"
        )->fetchColumn();
        $summary['avg_delay_minutes'] = $avgDelay !== null ? (int)round((float)$avgDelay) : null;

        return $summary;
    }

    public function byDoctor(): array
    {
        $sql = <<<SQL
SELECT d.id AS doctor_id,
       d.first_name,
       d.last_name,
       SUM(CASE WHEN a.status = 'CONCLUSA' THEN 1 ELSE 0 END) AS total_visits,
       SUM(CASE WHEN a.status = 'ANNULLATA' THEN 1 ELSE 0 END) AS cancelled_visits,
       AVG(CASE WHEN a.status = 'CONCLUSA' AND a.started_at IS NOT NULL AND a.ended_at IS NOT NULL
                THEN (julianday(a.ended_at) - julianday(a.started_at)) * 24 * 60
                ELSE NULL END) AS avg_duration_minutes,
       AVG(CASE WHEN a.status = 'CONCLUSA' AND a.started_at IS NOT NULL
                THEN CASE WHEN (julianday(a.started_at) - julianday(a.scheduled_start)) * 24 * 60 > 0
                          THEN (julianday(a.started_at) - julianday(a.scheduled_start)) * 24 * 60
                          ELSE 0 END
                ELSE NULL END) AS avg_delay_minutes
FROM doctors d
LEFT JOIN appointments a ON a.doctor_id = d.id
GROUP BY d.id, d.first_name, d.last_name
ORDER BY d.last_name, d.first_name
SQL;
		$rows = $this->pdo->query($sql)->fetchAll();

		$penalties = [];
		foreach ($rows as &$row) {
			$row['doctor_id'] = (int)$row['doctor_id'];
			$row['total_visits'] = (int)$row['total_visits'];
			$row['cancelled_visits'] = (int)$row['cancelled_visits'];
			$row['avg_duration_minutes'] = $row['avg_duration_minutes'] !== null ? (int)round((float)$row['avg_duration_minutes']) : null;
			$row['avg_delay_minutes'] = $row['avg_delay_minutes'] !== null ? (int)round((float)$row['avg_delay_minutes']) : null;

			if ($row['avg_duration_minutes'] !== null && $row['avg_delay_minutes'] !== null) {
				$row['raw_penalty'] =
					(max(0, $row['avg_duration_minutes'] - self::PERFORMANCE_DURATION_BASELINE_MINUTES) * self::PERFORMANCE_DURATION_WEIGHT)
					+ (max(0, $row['avg_delay_minutes']) * self::PERFORMANCE_DELAY_WEIGHT);
				$penalties[] = $row['raw_penalty'];
			} else {
				$row['raw_penalty'] = null;
			}
		}
		unset($row);

		if ($penalties === []) {
			foreach ($rows as &$row) {
				$row['performance_score'] = null;
				unset($row['raw_penalty']);
			}
			unset($row);

			return $rows;
		}

		$minPenalty = min($penalties);
		$maxPenalty = max($penalties);
		$penaltySpread = max(1, $maxPenalty - $minPenalty);

		foreach ($rows as &$row) {
			if ($row['raw_penalty'] === null) {
				$row['performance_score'] = null;
			} else {
				// Score assoluto: penalizza soprattutto il ritardo, mantenendo 100 come valore migliore.
				$absoluteScore = 100 - $row['raw_penalty'];
				$absoluteScore = max(0, min(100, $absoluteScore));

				// Score relativo: confronto interno tra i medici della dashboard.
				$relativeScore = 100 - ((($row['raw_penalty'] - $minPenalty) / $penaltySpread) * 50);
				$relativeScore = max(50, min(100, $relativeScore));

				// Mix 70% assoluto, 30% relativo.
				$score = round(($absoluteScore * 0.7) + ($relativeScore * 0.3));
				$row['performance_score'] = max(0, min(100, (int)$score));
			}

			unset($row['raw_penalty']);
		}
		unset($row);

		return $rows;
	}

    public function byCategory(): array
    {
        $sql = <<<SQL
SELECT cv.name AS visit_category,
       SUM(CASE WHEN status = 'CONCLUSA' THEN 1 ELSE 0 END) AS total_visits,
       SUM(CASE WHEN status = 'ANNULLATA' THEN 1 ELSE 0 END) AS cancelled_visits,
       AVG(CASE WHEN status = 'CONCLUSA' AND started_at IS NOT NULL AND ended_at IS NOT NULL
                THEN (julianday(ended_at) - julianday(started_at)) * 24 * 60
                ELSE NULL END) AS avg_duration_minutes
FROM appointments a
JOIN category_visits cv ON cv.id = a.visit_category_id
GROUP BY cv.id, cv.name
ORDER BY cv.name
SQL;
        $rows = $this->pdo->query($sql)->fetchAll();

        foreach ($rows as &$row) {
            $row['total_visits'] = (int)$row['total_visits'];
            $row['cancelled_visits'] = (int)$row['cancelled_visits'];
            $row['avg_duration_minutes'] = $row['avg_duration_minutes'] !== null ? (int)round((float)$row['avg_duration_minutes']) : null;
        }

        return $rows;
    }
}
