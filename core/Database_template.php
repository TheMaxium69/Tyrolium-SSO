<?php

// Copier ce fichier en Database.php et remplir les credentials réels.
// Ne jamais commiter Database.php (contient les secrets).

class Database
{
    public static function getPdo(): PDO
    {
        return new PDO('mysql:host=localhost;dbname=VOTRE_DB;charset=utf8mb4', 'VOTRE_USER', 'VOTRE_MDP', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        ]);
    }
}
