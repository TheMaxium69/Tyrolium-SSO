<?php

namespace Model;

class SyncSession extends Model
{
    private const TABLE = 'sso_sync';

    /**
     * Crée un nouvel UUID en DB et le retourne.
     */
    public function create(): string
    {
        $uuid = bin2hex(random_bytes(16)); // 32 chars hex
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (uuid, last_seen) VALUES (:uuid, NOW())'
        );
        $stmt->execute(['uuid' => $uuid]);
        return $uuid;
    }

    /**
     * Retrouve une session par UUID.
     */
    public function findByUuid(string $uuid)
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ' . self::TABLE . ' WHERE uuid = :uuid LIMIT 1'
        );
        $stmt->execute(['uuid' => $uuid]);
        return $stmt->fetch();
    }

    /**
     * Met à jour last_seen pour garder la session vivante.
     */
    public function touch(string $uuid): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET last_seen = NOW() WHERE uuid = :uuid'
        );
        $stmt->execute(['uuid' => $uuid]);
    }

    /**
     * Met à jour une clé d'état (theme, lang, token).
     */
    public function setState(string $uuid, string $key, ?string $value): void
    {
        $allowedColumns = ['theme', 'lang', 'token'];
        if (!in_array($key, $allowedColumns, true)) return;

        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . ' SET `' . $key . '` = :value, last_seen = NOW() WHERE uuid = :uuid'
        );
        $stmt->execute(['value' => $value, 'uuid' => $uuid]);
    }
}
