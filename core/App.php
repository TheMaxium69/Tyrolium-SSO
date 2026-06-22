<?php

class App
{
    private const ACTIONS = ['hub', 'state'];

    public static function process(): void
    {
        $path = basename(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

        if (!in_array($path, self::ACTIONS, true)) {
            header('Location: https://tyrolium.fr/404', true, 302);
            exit;
        }

        Actions::$path();
    }
}
