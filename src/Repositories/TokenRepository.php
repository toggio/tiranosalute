<?php

// Repository dei bearer token usati dalle integrazioni.

declare(strict_types=1);

namespace App\Repositories;

final class TokenRepository extends BaseRepository
{
    public function create(int $userId, string $tokenHash, string $name, string $expiresAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id, token_hash, name, created_at, expires_at)
             VALUES (:user_id, :token_hash, :name, :created_at, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'name' => $name,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findValidByHash(string $tokenHash): ?array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT t.*, u.role, u.email, u.first_name, u.last_name, u.active, u.must_change_password, u.patient_id, u.doctor_id, u.id AS uid
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :token_hash
               AND u.active = 1
               AND t.revoked_at IS NULL
               AND t.expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([
            'token_hash' => $tokenHash,
            'now' => $now,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'token' => $row,
            'user' => [
                'id' => (int)$row['uid'],
                'role' => $row['role'],
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'active' => (int)$row['active'],
                'must_change_password' => (int)$row['must_change_password'],
                'patient_id' => $row['patient_id'] !== null ? (int)$row['patient_id'] : null,
                'doctor_id' => $row['doctor_id'] !== null ? (int)$row['doctor_id'] : null,
            ],
        ];
    }

    public function touchLastUsed(int $tokenId): void
    {
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET last_used_at = :last_used_at WHERE id = :id');
        $stmt->execute([
            'id' => $tokenId,
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function revokeById(int $tokenId): void
    {
        $stmt = $this->pdo->prepare('UPDATE api_tokens SET revoked_at = :revoked_at WHERE id = :id');
        $stmt->execute([
            'id' => $tokenId,
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
