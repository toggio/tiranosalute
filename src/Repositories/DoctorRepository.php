<?php

// Repository dell'anagrafica medici e delle liste
// usate dalle funzioni operative e amministrative.

declare(strict_types=1);

namespace App\Repositories;

final class DoctorRepository extends BaseRepository
{
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO doctors (first_name, last_name, email, internal_code, active, created_at, updated_at)
             VALUES (:first_name, :last_name, :email, :internal_code, :active, :created_at, :updated_at)'
        );
        $stmt->execute([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => strtolower($data['email']),
            'internal_code' => $data['internal_code'],
            'active' => $data['active'] ?? 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE doctors
             SET first_name=:first_name, last_name=:last_name, email=:email, internal_code=:internal_code, active=:active, updated_at=:updated_at
             WHERE id=:id'
        );
        $stmt->execute([
            'id' => $id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => strtolower($data['email']),
            'internal_code' => $data['internal_code'],
            'active' => $data['active'] ?? 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM doctors WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByInternalCode(string $internalCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM doctors WHERE internal_code = :internal_code LIMIT 1');
        $stmt->execute(['internal_code' => trim($internalCode)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listActive(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM doctors WHERE active = 1 ORDER BY id');
        return $stmt->fetchAll();
    }

    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM doctors ORDER BY last_name, first_name');
        return $stmt->fetchAll();
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map(static fn($id): int => (int)$id, $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT id, first_name, last_name, email, internal_code, active
             FROM doctors
             WHERE id IN (' . $placeholders . ')
             ORDER BY last_name, first_name'
        );
        $stmt->execute($ids);

        return $stmt->fetchAll();
    }
}
