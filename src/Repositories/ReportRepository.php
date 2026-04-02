<?php

// Repository dei referti cifrati e delle chiavi associate
// ai soggetti autorizzati alla lettura.

declare(strict_types=1);

namespace App\Repositories;

final class ReportRepository extends BaseRepository
{
    public function create(
        int $appointmentId,
        int $doctorId,
        string $cipherText,
        string $iv,
        string $tag,
        string $algorithm
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO reports (appointment_id, created_by_doctor_id, cipher_text, iv, tag, algorithm, created_at)
             VALUES (:appointment_id, :created_by_doctor_id, :cipher_text, :iv, :tag, :algorithm, :created_at)'
        );

        $stmt->execute([
            'appointment_id' => $appointmentId,
            'created_by_doctor_id' => $doctorId,
            'cipher_text' => $cipherText,
            'iv' => $iv,
            'tag' => $tag,
            'algorithm' => $algorithm,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function addEncryptedDek(
        int $reportId,
        int $recipientUserId,
        string $cipherText,
        string $iv,
        string $tag,
        string $kekVersion
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO report_keys (report_id, recipient_user_id, encrypted_dek, iv, tag, wrapped_by_kek_version, created_at)
             VALUES (:report_id, :recipient_user_id, :encrypted_dek, :iv, :tag, :wrapped_by_kek_version, :created_at)'
        );
        $stmt->execute([
            'report_id' => $reportId,
            'recipient_user_id' => $recipientUserId,
            'encrypted_dek' => $cipherText,
            'iv' => $iv,
            'tag' => $tag,
            'wrapped_by_kek_version' => $kekVersion,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findByAppointmentId(int $appointmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*,
                    a.patient_id,
                    a.doctor_id,
                    cv.name AS visit_category,
                    a.visit_reason,
                    a.scheduled_start,
                    a.started_at,
                    a.ended_at,
                    p.first_name AS patient_first_name,
                    p.last_name AS patient_last_name,
                    p.tax_code AS patient_tax_code,
                    d.first_name AS doctor_first_name,
                    d.last_name AS doctor_last_name
             FROM reports r
             JOIN appointments a ON a.id = r.appointment_id
             JOIN category_visits cv ON cv.id = a.visit_category_id
             JOIN patients p ON p.id = a.patient_id
             JOIN doctors d ON d.id = a.doctor_id
             WHERE r.appointment_id = :appointment_id
             LIMIT 1'
        );
        $stmt->execute(['appointment_id' => $appointmentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $reportId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*,
                    a.patient_id,
                    a.doctor_id,
                    cv.name AS visit_category,
                    a.visit_reason,
                    a.scheduled_start,
                    a.started_at,
                    a.ended_at,
                    p.first_name AS patient_first_name,
                    p.last_name AS patient_last_name,
                    p.tax_code AS patient_tax_code,
                    d.first_name AS doctor_first_name,
                    d.last_name AS doctor_last_name
             FROM reports r
             JOIN appointments a ON a.id = r.appointment_id
             JOIN category_visits cv ON cv.id = a.visit_category_id
             JOIN patients p ON p.id = a.patient_id
             JOIN doctors d ON d.id = a.doctor_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $reportId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findRecipientKey(int $reportId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM report_keys WHERE report_id = :report_id AND recipient_user_id = :recipient_user_id LIMIT 1'
        );
        $stmt->execute([
            'report_id' => $reportId,
            'recipient_user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listVisibleForUser(
        int $userId,
        string $role,
        ?int $patientId = null,
        ?int $doctorId = null,
        string $patientTaxCode = ''
    ): array {
        if ($role === 'INTEGRATOR') {
            $sql = 'SELECT r.id,
                           r.appointment_id,
                           r.created_at,
                           cv.name AS visit_category,
                           a.scheduled_start,
                           a.visit_reason,
                           a.started_at,
                           a.ended_at,
                           p.first_name AS patient_first_name,
                           p.last_name AS patient_last_name,
                           p.tax_code AS patient_tax_code,
                           d.first_name AS doctor_first_name,
                           d.last_name AS doctor_last_name
                    FROM reports r
                    JOIN appointments a ON a.id = r.appointment_id
                    JOIN category_visits cv ON cv.id = a.visit_category_id
                    JOIN patients p ON p.id = a.patient_id
                    JOIN doctors d ON d.id = a.doctor_id';

            $params = [];
            if ($patientTaxCode !== '') {
                $sql .= ' WHERE UPPER(p.tax_code) = :tax_code';
                $params['tax_code'] = strtoupper($patientTaxCode);
            }

            $sql .= ' ORDER BY r.created_at DESC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        }

        if ($role === 'PATIENT' && $patientId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT r.id,
                        r.appointment_id,
                        r.created_at,
                        cv.name AS visit_category,
                        a.scheduled_start,
                        a.visit_reason,
                        a.started_at,
                        a.ended_at,
                        d.first_name AS doctor_first_name,
                        d.last_name AS doctor_last_name
                 FROM reports r
                 JOIN appointments a ON a.id = r.appointment_id
                 JOIN category_visits cv ON cv.id = a.visit_category_id
                 JOIN doctors d ON d.id = a.doctor_id
                 WHERE a.patient_id = :patient_id
                 ORDER BY r.created_at DESC'
            );
            $stmt->execute(['patient_id' => $patientId]);
            return $stmt->fetchAll();
        }

        if ($role === 'DOCTOR' && $doctorId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT r.id,
                        r.appointment_id,
                        r.created_at,
                        cv.name AS visit_category,
                        a.scheduled_start,
                        a.visit_reason,
                        a.started_at,
                        a.ended_at,
                        p.first_name AS patient_first_name,
                        p.last_name AS patient_last_name,
                        p.tax_code AS patient_tax_code
                 FROM reports r
                 JOIN appointments a ON a.id = r.appointment_id
                 JOIN category_visits cv ON cv.id = a.visit_category_id
                 JOIN patients p ON p.id = a.patient_id
                 WHERE a.doctor_id = :doctor_id
                 ORDER BY r.created_at DESC'
            );
            $stmt->execute(['doctor_id' => $doctorId]);
            return $stmt->fetchAll();
        }

        return [];
    }
}
