<?php

// Repository del catalogo categorie visita,
// usato da prenotazioni, seed e statistiche.

declare(strict_types=1);

namespace App\Repositories;

final class CategoryRepository extends BaseRepository
{
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT name FROM category_visits ORDER BY id');
        return array_map(static fn(array $row): string => $row['name'], $stmt->fetchAll());
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM category_visits WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => trim($name)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function mapNamesToIds(): array
    {
        $stmt = $this->pdo->query('SELECT id, name FROM category_visits ORDER BY id');
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['name']] = (int)$row['id'];
        }

        return $map;
    }
}
