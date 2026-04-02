<?php

// Servizio delle anagrafiche e delle credenziali di pazienti, medici e staff,
// compreso il reset password con attivazione di must_change_password.

declare(strict_types=1);

namespace App\Services;

use App\Core\AuthContext;
use App\Core\HttpException;
use App\Repositories\DoctorRepository;
use App\Repositories\PatientRepository;
use App\Repositories\UserRepository;
use App\Support\PublicApi;

final class UserManagementService
{
    private const STAFF_ROLES = ['RECEPTION'];
    private const RECEPTION_ROLE = 'RECEPTION';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly UserRepository $users,
        private readonly PatientRepository $patients,
        private readonly DoctorRepository $doctors
    ) {
    }

    public function changePassword(AuthContext $auth, string $currentPassword, string $newPassword): void
    {
        $currentPassword = trim($currentPassword);
        $newPassword = trim($newPassword);

        if (strlen($newPassword) < 8) {
            throw new HttpException('La nuova password deve avere almeno 8 caratteri', 422, 'VALIDATION_ERROR');
        }

        $user = $this->users->findById($auth->id());
        if (!$user) {
            throw new HttpException('Utente non trovato', 404, 'NOT_FOUND');
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new HttpException('Password corrente non valida', 401, 'UNAUTHORIZED');
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->users->updatePassword($auth->id(), $hash, false);
    }

    public function createPatient(array $payload): array
    {
        $firstName = trim((string)$this->payloadValue($payload, ['first_name'], ''));
        $lastName = trim((string)$this->payloadValue($payload, ['last_name'], ''));
        $taxCode = strtoupper(trim((string)$this->payloadValue($payload, ['tax_code'], '')));
        $email = strtolower(trim((string)$this->payloadValue($payload, ['email'], '')));
        $password = trim((string)$this->payloadValue($payload, ['password'], ''));
        $isActive = $this->payloadHasAny($payload, ['active'])
            ? (int)$this->payloadBool($payload, ['active'])
            : 1;

        if ($firstName === '' || $lastName === '' || $taxCode === '' || $email === '' || $password === '') {
            throw new HttpException(
                'first_name, last_name, tax_code, email e password sono obbligatori',
                422,
                'VALIDATION_ERROR'
            );
        }
        if (strlen($password) < 8) {
            throw new HttpException('La password iniziale deve avere almeno 8 caratteri', 422, 'VALIDATION_ERROR');
        }
        $this->assertValidEmail($email);

        if ($this->users->findByEmail($email)) {
            throw new HttpException('Email già in uso', 409, 'CONFLICT');
        }
        if ($this->patients->findByTaxCode($taxCode)) {
            throw new HttpException('Codice fiscale già in uso', 409, 'CONFLICT');
        }

        $this->pdo->beginTransaction();
        try {
            $patientId = $this->patients->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'tax_code' => $taxCode,
                'email' => $email,
            ]);

            $this->users->create([
                'role' => 'PATIENT',
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'active' => $isActive,
                'must_change_password' => true,
                'patient_id' => $patientId,
                'doctor_id' => null,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->rethrowPersistenceException($e);
        }

        $created = $this->patients->find($patientId) ?? [];
        $created['active'] = $isActive;
        return PublicApi::patient($created);
    }

    public function updatePatient(int $patientId, array $payload): array
    {
        $existing = $this->patients->find($patientId);
        if (!$existing) {
            throw new HttpException('Paziente non trovato', 404, 'NOT_FOUND');
        }

        $data = [
            'first_name' => trim((string)$this->payloadValue($payload, ['first_name'], $existing['first_name'])),
            'last_name' => trim((string)$this->payloadValue($payload, ['last_name'], $existing['last_name'])),
            'tax_code' => strtoupper(trim((string)$this->payloadValue($payload, ['tax_code'], $existing['tax_code']))),
            'email' => strtolower(trim((string)$this->payloadValue($payload, ['email'], $existing['email']))),
        ];
        $this->assertValidEmail($data['email']);

        $user = $this->users->findByPatientId($patientId);
        if ($user && $data['email'] !== $user['email'] && $this->users->findByEmail($data['email'])) {
            throw new HttpException('Email già in uso', 409, 'CONFLICT');
        }

        if ($data['tax_code'] !== $existing['tax_code']) {
            $otherPatient = $this->patients->findByTaxCode($data['tax_code']);
            if ($otherPatient && (int)$otherPatient['id'] !== $patientId) {
                throw new HttpException('Codice fiscale già in uso', 409, 'CONFLICT');
            }
        }

        $isActive = $this->payloadHasAny($payload, ['active'])
            ? (int)$this->payloadBool($payload, ['active'])
            : (int)($user['active'] ?? 1);
        $password = trim((string)$this->payloadValue($payload, ['password'], ''));
        if ($password !== '' && strlen($password) < 8) {
            throw new HttpException('La password di reset deve avere almeno 8 caratteri', 422, 'VALIDATION_ERROR');
        }

        $this->pdo->beginTransaction();
        try {
            $this->patients->update($patientId, $data);
            if ($user) {
                $this->users->updateUserProfile(
                    (int)$user['id'],
                    $data['first_name'],
                    $data['last_name'],
                    $data['email'],
                    $isActive
                );

                if ($password !== '') {
                    $this->users->updatePassword(
                        (int)$user['id'],
                        password_hash($password, PASSWORD_DEFAULT),
                        true
                    );
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->rethrowPersistenceException($e);
        }

        $updated = $this->patients->find($patientId) ?? [];
        $updated['active'] = $isActive;
        return PublicApi::patient($updated);
    }

    public function createDoctor(array $payload): array
    {
        $firstName = trim((string)$this->payloadValue($payload, ['first_name'], ''));
        $lastName = trim((string)$this->payloadValue($payload, ['last_name'], ''));
        $email = strtolower(trim((string)$this->payloadValue($payload, ['email'], '')));
        $internalCode = trim((string)$this->payloadValue($payload, ['internal_code'], ''));
        $password = trim((string)$this->payloadValue($payload, ['password'], ''));
        $isActive = $this->payloadHasAny($payload, ['active'])
            ? (int)$this->payloadBool($payload, ['active'])
            : 1;

        if ($firstName === '' || $lastName === '' || $email === '' || $internalCode === '' || $password === '') {
            throw new HttpException(
                'first_name, last_name, email, internal_code e password sono obbligatori',
                422,
                'VALIDATION_ERROR'
            );
        }
        if (strlen($password) < 8) {
            throw new HttpException('La password iniziale deve avere almeno 8 caratteri', 422, 'VALIDATION_ERROR');
        }
        $this->assertValidEmail($email);

        if ($this->users->findByEmail($email)) {
            throw new HttpException('Email già in uso', 409, 'CONFLICT');
        }
        if ($this->doctors->findByInternalCode($internalCode)) {
            throw new HttpException('Codice interno già in uso', 409, 'CONFLICT');
        }

        $this->pdo->beginTransaction();
        try {
            $doctorId = $this->doctors->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'internal_code' => $internalCode,
                'active' => $isActive,
            ]);

            $this->users->create([
                'role' => 'DOCTOR',
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'active' => $isActive,
                'must_change_password' => true,
                'patient_id' => null,
                'doctor_id' => $doctorId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->rethrowPersistenceException($e);
        }

        $created = $this->doctors->find($doctorId) ?? [];
        return PublicApi::doctor($created);
    }

    public function updateDoctor(int $doctorId, array $payload): array
    {
        $existing = $this->doctors->find($doctorId);
        if (!$existing) {
            throw new HttpException('Medico non trovato', 404, 'NOT_FOUND');
        }

        $data = [
            'first_name' => trim((string)$this->payloadValue($payload, ['first_name'], $existing['first_name'])),
            'last_name' => trim((string)$this->payloadValue($payload, ['last_name'], $existing['last_name'])),
            'email' => strtolower(trim((string)$this->payloadValue($payload, ['email'], $existing['email']))),
            'internal_code' => trim((string)$this->payloadValue($payload, ['internal_code'], $existing['internal_code'])),
            'active' => $this->payloadHasAny($payload, ['active'])
                ? (int)$this->payloadBool($payload, ['active'])
                : (int)$existing['active'],
        ];
        $this->assertValidEmail($data['email']);

        $user = $this->users->findByDoctorId($doctorId);
        if ($user && $data['email'] !== $user['email'] && $this->users->findByEmail($data['email'])) {
            throw new HttpException('Email già in uso', 409, 'CONFLICT');
        }
        if ($data['internal_code'] !== $existing['internal_code']) {
            $otherDoctor = $this->doctors->findByInternalCode($data['internal_code']);
            if ($otherDoctor && (int)$otherDoctor['id'] !== $doctorId) {
                throw new HttpException('Codice interno già in uso', 409, 'CONFLICT');
            }
        }

        $password = trim((string)$this->payloadValue($payload, ['password'], ''));
        if ($password !== '' && strlen($password) < 8) {
            throw new HttpException('La password di reset deve avere almeno 8 caratteri', 422, 'VALIDATION_ERROR');
        }

        $this->pdo->beginTransaction();
        try {
            $this->doctors->update($doctorId, $data);
            if ($user) {
                $this->users->updateUserProfile(
                    (int)$user['id'],
                    $data['first_name'],
                    $data['last_name'],
                    $data['email'],
                    $data['active']
                );

                if ($password !== '') {
                    $this->users->updatePassword(
                        (int)$user['id'],
                        password_hash($password, PASSWORD_DEFAULT),
                        true
                    );
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->rethrowPersistenceException($e);
        }

        return PublicApi::doctor($this->doctors->find($doctorId) ?? []);
    }

    public function listPatients(array $filters = []): array
    {
        return array_map(static fn(array $row): array => PublicApi::patient($row), $this->patients->listAll($filters));
    }

    public function listDoctors(): array
    {
        return array_map(static fn(array $row): array => PublicApi::doctor($row), $this->doctors->listAll());
    }

    public function listStaff(): array
    {
        return array_map(static fn(array $row): array => PublicApi::user($row), $this->users->listStaff(self::STAFF_ROLES));
    }

    public function createStaff(array $payload): array
    {
        $role = strtoupper(trim((string)$this->payloadValue($payload, ['role'], self::RECEPTION_ROLE)));
        $firstName = trim((string)$this->payloadValue($payload, ['first_name'], ''));
        $lastName = trim((string)$this->payloadValue($payload, ['last_name'], ''));
        $email = strtolower(trim((string)$this->payloadValue($payload, ['email'], '')));
        $password = trim((string)$this->payloadValue($payload, ['password'], ''));
        $isActive = $this->payloadHasAny($payload, ['active'])
            ? (int)$this->payloadBool($payload, ['active'])
            : 1;

        if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
            throw new HttpException(
                'first_name, last_name, email e password sono obbligatori',
                422,
                'VALIDATION_ERROR'
            );
        }
        if (strlen($password) < 8) {
            throw new HttpException('La password iniziale deve avere almeno 8 caratteri', 422, 'VALIDATION_ERROR');
        }

        if ($role !== self::RECEPTION_ROLE) {
            throw new HttpException(
                'Da questa API puoi creare solo utenti RECEPTION',
                422,
                'VALIDATION_ERROR'
            );
        }
        $this->assertValidEmail($email);

        if ($this->users->findByEmail($email)) {
            throw new HttpException('Email già in uso', 409, 'CONFLICT');
        }

        $userId = $this->users->create([
            'role' => $role,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'active' => $isActive,
            'must_change_password' => true,
            'patient_id' => null,
            'doctor_id' => null,
        ]);

        return PublicApi::user($this->users->findById($userId) ?? []);
    }

    public function updateStaff(int $userId, array $payload): array
    {
        $existing = $this->users->findById($userId);
        if (!$existing || !in_array((string)$existing['role'], self::STAFF_ROLES, true)) {
            throw new HttpException('Utente staff non trovato', 404, 'NOT_FOUND');
        }

        $existingRole = (string)$existing['role'];
        $requestedRole = $this->payloadHasAny($payload, ['role'])
            ? strtoupper(trim((string)$this->payloadValue($payload, ['role'], '')))
            : $existingRole;
        $firstName = trim((string)$this->payloadValue($payload, ['first_name'], $existing['first_name']));
        $lastName = trim((string)$this->payloadValue($payload, ['last_name'], $existing['last_name']));
        $email = strtolower(trim((string)$this->payloadValue($payload, ['email'], $existing['email'])));
        $requestedActive = $this->payloadHasAny($payload, ['active'])
            ? (int)$this->payloadBool($payload, ['active'])
            : (int)$existing['active'];
        $password = trim((string)$this->payloadValue($payload, ['password'], ''));

        if ($firstName === '' || $lastName === '' || $email === '') {
            throw new HttpException(
                'first_name, last_name ed email sono obbligatori',
                422,
                'VALIDATION_ERROR'
            );
        }

        if ($this->payloadHasAny($payload, ['role']) && $requestedRole !== self::RECEPTION_ROLE) {
            throw new HttpException(
                'Il ruolo dello staff gestibile da questa API è solo RECEPTION',
                422,
                'VALIDATION_ERROR'
            );
        }
        $this->assertValidEmail($email);

        $role = $existingRole;
        $isActive = $requestedActive;

        if ($password !== '' && strlen($password) < 8) {
            throw new HttpException('La password di reset deve avere almeno 8 caratteri', 422, 'VALIDATION_ERROR');
        }

        $otherUser = $this->users->findByEmail($email);
        if ($otherUser && (int)$otherUser['id'] !== $userId) {
            throw new HttpException('Email già in uso', 409, 'CONFLICT');
        }

        $this->pdo->beginTransaction();
        try {
            $this->users->updateStaffUser($userId, $role, $firstName, $lastName, $email, $isActive);

            if ($password !== '') {
                $this->users->updatePassword(
                    $userId,
                    password_hash($password, PASSWORD_DEFAULT),
                    true
                );
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->rethrowPersistenceException($e);
        }

        return PublicApi::user($this->users->findById($userId) ?? []);
    }

    private function assertValidEmail(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException('Email non valida', 422, 'VALIDATION_ERROR');
        }
    }

    private function payloadValue(array $payload, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return $default;
    }

    private function payloadHasAny(array $payload, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    private function payloadBool(array $payload, array $keys): bool
    {
        $value = $this->payloadValue($payload, $keys, false);
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? ((int)$value === 1);
    }

    private function rethrowPersistenceException(\Throwable $e): never
    {
        if ($e instanceof \PDOException) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'patients.tax_code')) {
                throw new HttpException('Codice fiscale già in uso', 409, 'CONFLICT');
            }
            if (str_contains($message, 'doctors.internal_code')) {
                throw new HttpException('Codice interno già in uso', 409, 'CONFLICT');
            }
            if (str_contains($message, 'users.email') || str_contains($message, 'patients.email') || str_contains($message, 'doctors.email')) {
                throw new HttpException('Email già in uso', 409, 'CONFLICT');
            }
        }

        throw $e;
    }
}

