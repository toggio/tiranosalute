<?php

// Servizio di gestione delle disponibilità dei medici,
// con controlli di accesso e validazione delle fasce orarie.

declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\HttpException;
use App\Repositories\AvailabilityRepository;
use App\Repositories\DoctorRepository;

final class AvailabilityService
{
    public function __construct(
        private readonly AvailabilityRepository $availabilities,
        private readonly DoctorRepository $doctors
    ) {
    }

    public function getDoctorAvailability(AuthContext $auth, int $doctorId): array
    {
        if (!$this->doctors->find($doctorId)) {
            throw new HttpException('Medico non trovato', 404, 'NOT_FOUND');
        }

        if ($auth->role() === 'DOCTOR' && (int)$auth->user['doctor_id'] !== $doctorId) {
            throw new HttpException('Puoi vedere solo le tue disponibilità', 403, 'FORBIDDEN');
        }

        if (!in_array($auth->role(), ['DOCTOR', 'RECEPTION', 'INTEGRATOR'], true)) {
            throw new HttpException('Ruolo non autorizzato', 403, 'FORBIDDEN');
        }

        return $this->availabilities->listByDoctor($doctorId);
    }

    public function setDoctorAvailability(AuthContext $auth, int $doctorId, array $rows): array
    {
        $doctor = $this->doctors->find($doctorId);
        if (!$doctor) {
            throw new HttpException('Medico non trovato', 404, 'NOT_FOUND');
        }

        if ($auth->role() === 'DOCTOR' && (int)$auth->user['doctor_id'] !== $doctorId) {
            throw new HttpException('Puoi modificare solo le tue disponibilità', 403, 'FORBIDDEN');
        }

        if (!in_array($auth->role(), ['DOCTOR', 'RECEPTION', 'INTEGRATOR'], true)) {
            throw new HttpException('Ruolo non autorizzato', 403, 'FORBIDDEN');
        }

        $normalized = [];
        foreach ($rows as $index => $row) {
            $weekday = (int)($row['weekday'] ?? 0);
            $startTime = (string)($row['start_time'] ?? '');
            $endTime = (string)($row['end_time'] ?? '');
            $validFrom = $this->normalizeOptionalDate($row['valid_from'] ?? null);
            $validTo = $this->normalizeOptionalDate($row['valid_to'] ?? null);

            if ($weekday < 1 || $weekday > 7 || !preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
                throw new HttpException('Disponibilità non valida alla riga ' . ($index + 1), 422, 'VALIDATION_ERROR');
            }

            if ($startTime >= $endTime) {
                throw new HttpException('Intervallo orario non valido alla riga ' . ($index + 1), 422, 'VALIDATION_ERROR');
            }

            if (
                (($row['valid_from'] ?? null) !== null && $validFrom === null)
                || (($row['valid_to'] ?? null) !== null && $validTo === null)
                || ($validFrom !== null && $validTo !== null && $validTo < $validFrom)
            ) {
                throw new HttpException('Disponibilità non valida alla riga ' . ($index + 1), 422, 'VALIDATION_ERROR');
            }

            $normalized[] = [
                'weekday' => $weekday,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
            ];
        }

        $this->availabilities->replaceForDoctor($doctorId, $normalized);
        return $this->availabilities->listByDoctor($doctorId);
    }

    private function normalizeOptionalDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date === false) {
            return null;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if (
            is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)
        ) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }
}
