<?php

// Definizione dei payload pubblici dell'API,
// usata per esporre campi coerenti verso frontend e integrazioni.

declare(strict_types=1);

namespace App\Support;

final class PublicApi
{
    public static function user(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'role' => (string)($row['role'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name' => (string)($row['last_name'] ?? ''),
            'active' => self::toBool(self::value($row, ['active'], 0)),
            'must_change_password' => self::toBool($row['must_change_password'] ?? false),
            'patient_id' => self::toNullableInt($row['patient_id'] ?? null),
            'doctor_id' => self::toNullableInt($row['doctor_id'] ?? null),
        ];
    }

    public static function patient(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name' => (string)($row['last_name'] ?? ''),
            'tax_code' => (string)($row['tax_code'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'active' => self::toBool(self::value($row, ['active'], 0)),
            'created_at' => self::toNullableString($row['created_at'] ?? null),
            'updated_at' => self::toNullableString($row['updated_at'] ?? null),
        ];
    }

    public static function doctor(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'first_name' => (string)($row['first_name'] ?? ''),
            'last_name' => (string)($row['last_name'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'internal_code' => (string)($row['internal_code'] ?? ''),
            'active' => self::toBool(self::value($row, ['active'], 0)),
            'created_at' => self::toNullableString($row['created_at'] ?? null),
            'updated_at' => self::toNullableString($row['updated_at'] ?? null),
        ];
    }


    public static function appointment(array $row): array
    {
        $appointment = [
            'id' => (int)($row['id'] ?? 0),
            'patient_id' => (int)($row['patient_id'] ?? 0),
            'doctor_id' => (int)($row['doctor_id'] ?? 0),
            'visit_category' => (string)self::value($row, ['visit_category', 'category'], ''),
            'visit_reason' => (string)self::value($row, ['visit_reason', 'reason'], ''),
            'notes' => self::toNullableString($row['notes'] ?? null),
            'scheduled_start' => (string)($row['scheduled_start'] ?? ''),
            'scheduled_end' => (string)($row['scheduled_end'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'started_at' => self::toNullableString($row['started_at'] ?? null),
            'ended_at' => self::toNullableString($row['ended_at'] ?? null),
            'canceled_at' => self::toNullableString($row['canceled_at'] ?? null),
            'created_at' => self::toNullableString($row['created_at'] ?? null),
            'updated_at' => self::toNullableString($row['updated_at'] ?? null),
            'patient_first_name' => self::toNullableString($row['patient_first_name'] ?? null),
            'patient_last_name' => self::toNullableString($row['patient_last_name'] ?? null),
            'patient_email' => self::toNullableString($row['patient_email'] ?? null),
            'patient_tax_code' => self::toNullableString($row['patient_tax_code'] ?? null),
            'doctor_first_name' => self::toNullableString($row['doctor_first_name'] ?? null),
            'doctor_last_name' => self::toNullableString($row['doctor_last_name'] ?? null),
            'doctor_email' => self::toNullableString($row['doctor_email'] ?? null),
            'report_id' => self::toNullableInt($row['report_id'] ?? null),
            'has_report' => self::toBool($row['has_report'] ?? (($row['report_id'] ?? null) !== null)),
        ];

        if (array_key_exists('cancellation_reason', $row)) {
            $appointment['cancellation_reason'] = self::toNullableString($row['cancellation_reason']);
        }

        if (array_key_exists('history', $row) && is_array($row['history'])) {
            $appointment['history'] = array_map([self::class, 'appointmentHistory'], $row['history']);
        }

        return $appointment;
    }

    public static function appointmentHistory(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'appointment_id' => isset($row['appointment_id']) ? (int)$row['appointment_id'] : null,
            'from_status' => self::toNullableString($row['from_status'] ?? null),
            'to_status' => (string)($row['to_status'] ?? ''),
            'changed_by_user_id' => self::toNullableInt($row['changed_by_user_id'] ?? null),
            'changed_at' => (string)($row['changed_at'] ?? ''),
            'note' => self::toNullableString($row['note'] ?? null),
            'first_name' => self::toNullableString($row['first_name'] ?? null),
            'last_name' => self::toNullableString($row['last_name'] ?? null),
            'role' => self::toNullableString($row['role'] ?? null),
        ];
    }

    public static function report(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'appointment_id' => (int)($row['appointment_id'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'visit_category' => (string)self::value($row, ['visit_category', 'category'], ''),
            'visit_reason' => self::toNullableString(self::value($row, ['visit_reason', 'reason'], null)),
            'scheduled_start' => self::toNullableString($row['scheduled_start'] ?? null),
            'started_at' => self::toNullableString($row['started_at'] ?? null),
            'ended_at' => self::toNullableString($row['ended_at'] ?? null),
            'patient_first_name' => self::toNullableString($row['patient_first_name'] ?? null),
            'patient_last_name' => self::toNullableString($row['patient_last_name'] ?? null),
            'patient_tax_code' => self::toNullableString($row['patient_tax_code'] ?? null),
            'doctor_first_name' => self::toNullableString($row['doctor_first_name'] ?? null),
            'doctor_last_name' => self::toNullableString($row['doctor_last_name'] ?? null),
            'report' => $row['report'] ?? null,
        ];
    }

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return (int)$value === 1;
    }

    private static function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string)$value;
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int)$value;
    }

    private static function value(array $row, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return $default;
    }
}

