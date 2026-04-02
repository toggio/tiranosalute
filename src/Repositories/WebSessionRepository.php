<?php

// Repository delle sessioni web con cookie HttpOnly e token CSRF.

declare(strict_types=1);

namespace App\Repositories;

final class WebSessionRepository extends BaseRepository
{
    public function create(int $userId, string $sessionHash, string $csrfToken, string $expiresAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO web_sessions (user_id, session_hash, csrf_token, created_at, expires_at)
             VALUES (:user_id, :session_hash, :csrf_token, :created_at, :expires_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'session_hash' => $sessionHash,
            'csrf_token' => $csrfToken,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findValidByHash(string $sessionHash): ?array
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'SELECT s.*, u.role, u.email, u.first_name, u.last_name, u.active, u.must_change_password, u.patient_id, u.doctor_id, u.id AS uid
             FROM web_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.session_hash = :session_hash
               AND s.revoked_at IS NULL
               AND s.expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([
            'session_hash' => $sessionHash,
            'now' => $now,
        ]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        return [
            'session' => $row,
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

    public function revokeByHash(string $sessionHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE web_sessions
             SET revoked_at = :revoked_at
             WHERE session_hash = :session_hash
               AND revoked_at IS NULL'
        );
        $stmt->execute([
            'session_hash' => $sessionHash,
            'revoked_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function touchLastUsed(int $sessionId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE web_sessions SET last_used_at = :last_used_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $sessionId,
            'last_used_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
