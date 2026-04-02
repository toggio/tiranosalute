<?php

// Repository dell'anagrafica pazienti, con salvataggio e filtri di ricerca.

declare(strict_types=1);

namespace App\Repositories;

final class PatientRepository extends BaseRepository
{
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO patients (first_name, last_name, tax_code, email, created_at, updated_at)
             VALUES (:first_name, :last_name, :tax_code, :email, :created_at, :updated_at)'
        );
        $stmt->execute([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'tax_code' => strtoupper($data['tax_code']),
            'email' => strtolower($data['email']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE patients
             SET first_name=:first_name, last_name=:last_name, tax_code=:tax_code, email=:email, updated_at=:updated_at
             WHERE id=:id'
        );
        $stmt->execute([
            'id' => $id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'tax_code' => strtoupper($data['tax_code']),
            'email' => strtolower($data['email']),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByTaxCode(string $taxCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM patients WHERE tax_code = :tax_code LIMIT 1');
        $stmt->execute(['tax_code' => strtoupper($taxCode)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listAll(array $filters = []): array
    {
        $where = [];
        $params = [];
        $limit = isset($filters['limit']) ? max(1, (int)$filters['limit']) : 30;

        $name = strtolower(trim((string)($filters['name'] ?? '')));
        if ($name !== '') {
            $where[] = 'LOWER(p.first_name || " " || p.last_name) LIKE :name';
            $params['name'] = '%' . $name . '%';
        }

        $taxCode = strtoupper(trim((string)($filters['tax_code'] ?? '')));
        if ($taxCode !== '') {
            $where[] = 'UPPER(p.tax_code) LIKE :tax_code';
            $params['tax_code'] = '%' . $taxCode . '%';
        }

        $sql = 'SELECT p.*, COALESCE(u.active, 1) AS active
                FROM patients p
                LEFT JOIN users u ON u.patient_id = p.id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.last_name, p.first_name
                  LIMIT :_limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':_limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}

