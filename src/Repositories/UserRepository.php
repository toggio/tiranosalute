<?php

// Repository degli utenti applicativi:
// credenziali, ruoli, collegamenti con pazienti o medici e stato must_change_password.

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends BaseRepository
{
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower($email)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listByRole(string $role): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE role = :role ORDER BY last_name, first_name');
        $stmt->execute(['role' => $role]);
        return $stmt->fetchAll();
    }

    public function listStaff(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($roles) as $index => $role) {
            $key = ':role_' . $index;
            $placeholders[] = $key;
            $params[substr($key, 1)] = $role;
        }

        $sql = 'SELECT id, role, email, first_name, last_name, active, must_change_password, patient_id, doctor_id, created_at, updated_at
                FROM users
                WHERE role IN (' . implode(', ', $placeholders) . ')
                ORDER BY role, last_name, first_name, id';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, \PDO::PARAM_STR);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function updatePassword(int $userId, string $hash, ?bool $mustChangePassword = null): void
    {
        $sql = 'UPDATE users SET password_hash = :hash, updated_at = :updated_at';
        $params = [
            'hash' => $hash,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $userId,
        ];

        if ($mustChangePassword !== null) {
            $sql .= ', must_change_password = :must_change_password';
            $params['must_change_password'] = $mustChangePassword ? 1 : 0;
        }

        $sql .= ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (role, email, password_hash, first_name, last_name, active, must_change_password, patient_id, doctor_id, created_at, updated_at)
             VALUES (:role, :email, :password_hash, :first_name, :last_name, :active, :must_change_password, :patient_id, :doctor_id, :created_at, :updated_at)'
        );

        $now = date('Y-m-d H:i:s');
        $stmt->execute([
            'role' => $data['role'],
            'email' => strtolower($data['email']),
            'password_hash' => $data['password_hash'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'active' => $data['active'] ?? 1,
            'must_change_password' => !empty($data['must_change_password']) ? 1 : 0,
            'patient_id' => $data['patient_id'] ?? null,
            'doctor_id' => $data['doctor_id'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateUserProfile(int $id, string $firstName, string $lastName, string $email, int $active = 1): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET first_name=:first_name, last_name=:last_name, email=:email, active=:active, updated_at=:updated_at
             WHERE id=:id'
        );
        $stmt->execute([
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => strtolower($email),
            'active' => $active,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function updateStaffUser(
        int $id,
        string $role,
        string $firstName,
        string $lastName,
        string $email,
        int $active = 1
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE users
             SET role=:role, first_name=:first_name, last_name=:last_name, email=:email, active=:active, updated_at=:updated_at
             WHERE id=:id'
        );
        $stmt->execute([
            'id' => $id,
            'role' => $role,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => strtolower($email),
            'active' => $active,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findByPatientId(int $patientId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE patient_id = :patient_id LIMIT 1');
        $stmt->execute(['patient_id' => $patientId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByDoctorId(int $doctorId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE doctor_id = :doctor_id LIMIT 1');
        $stmt->execute(['doctor_id' => $doctorId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listIntegrators(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM users WHERE role = 'INTEGRATOR' AND active = 1");
        return $stmt->fetchAll();
    }
}

