<?php

class Actions
{
    private const ALLOWED_ORIGINS = [
        'https://tyrolium.fr',
        'https://solidserv.fr',
        'https://tyrociel.fr',
        'https://gamenium.fr',
        'https://influnias.fr',
        'https://vturias.fr',
        'https://nexiumiacrm.fr',
        'https://useritium.fr',
        'https://tyroserv.fr',
        'https://dashboard.useritium.fr',
        'https://app.gamenium.fr',
        'https://sso.tyrolium.fr',
    ];

    private const ALLOWED_RETURN_DOMAINS = [
        'tyrolium.fr',
        'solidserv.fr',
        'tyrociel.fr',
        'gamenium.fr',
        'influnias.fr',
        'vturias.fr',
        'nexiumiacrm.fr',
        'useritium.fr',
        'tyroserv.fr',
        'dashboard.useritium.fr',
        'app.gamenium.fr',
    ];

    private const ALLOWED_KEYS  = ['theme', 'lang', 'token'];
    private const COOKIE_NAME   = 'tyro_sso_browser';
    private const COOKIE_TTL    = 365 * 24 * 3600;

    // -------------------------------------------------------------------------
    // GET /hub?return=URL
    // -------------------------------------------------------------------------

    public static function hub(): void
    {
        $return = $_GET['return'] ?? '';

        if (!self::isAllowedReturnUrl($return)) {
            header('Location: https://tyrolium.fr/404', true, 302);
            exit;
        }

        $uuid    = $_COOKIE[self::COOKIE_NAME] ?? null;
        $session = new Model\SyncSession();

        if ($uuid) {
            $existing = $session->findByUuid($uuid);
            if (!$existing) {
                $uuid = null;
            } else {
                $session->touch($uuid);
            }
        }

        if (!$uuid) {
            $uuid = $session->create();
            self::setBrowserCookie($uuid);
        }

        $sep = strpos($return, '?') !== false ? '&' : '?';
        header('Location: ' . $return . $sep . '_tyro_uuid=' . urlencode($uuid), true, 302);
        exit;
    }

    // -------------------------------------------------------------------------
    // GET  /state?uuid=XXX
    // POST /state   body: uuid=XXX&key=theme&value=dark
    // -------------------------------------------------------------------------

    public static function state(): void
    {
        self::setCorsHeaders();

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $session = new Model\SyncSession();

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {

            $uuid = $_GET['uuid'] ?? '';

            if (empty($uuid)) {
                self::json(['status' => 'err', 'why' => 'missing uuid'], 400);
                return;
            }

            if (!self::isValidUuid($uuid)) {
                self::json(['status' => 'err', 'why' => 'invalid uuid format', 'received' => $uuid], 400);
                return;
            }

            $row = $session->findByUuid($uuid);

            if (!$row) {
                self::json(['status' => 'err', 'why' => 'uuid not found'], 404);
                return;
            }

            $session->touch($uuid);
            self::json(['status' => 'ok', 'data' => [
                'theme' => $row->theme,
                'lang'  => $row->lang,
                'token' => $row->token,
            ]]);

        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

            $post = $_POST;
            if (empty($post)) {
                parse_str(file_get_contents('php://input'), $post);
            }

            $uuid  = $post['uuid']  ?? '';
            $key   = $post['key']   ?? '';
            $value = $post['value'] ?? '';

            if (empty($uuid)) {
                self::json(['status' => 'err', 'why' => 'missing uuid'], 400);
                return;
            }

            if (!self::isValidUuid($uuid)) {
                self::json(['status' => 'err', 'why' => 'invalid uuid format', 'received' => $uuid], 400);
                return;
            }

            if (empty($key)) {
                self::json(['status' => 'err', 'why' => 'missing key'], 400);
                return;
            }

            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                self::json(['status' => 'err', 'why' => 'invalid key', 'allowed' => self::ALLOWED_KEYS], 400);
                return;
            }

            if (!$session->findByUuid($uuid)) {
                self::json(['status' => 'err', 'why' => 'uuid not found'], 404);
                return;
            }

            $session->setState($uuid, $key, $value ?: null);
            self::json(['status' => 'ok']);

        } else {
            self::json(['status' => 'err', 'why' => 'method not allowed'], 405);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers privés
    // -------------------------------------------------------------------------

    private static function setCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (
            in_array($origin, self::ALLOWED_ORIGINS, true)
            || strpos($origin, 'http://192.168.1.81') === 0
            || strpos($origin, 'http://localhost') === 0
        ) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
        }
    }

    private static function isAllowedReturnUrl(string $url): bool
    {
        if (empty($url)) return false;

        if (strpos($url, 'http://192.168.1.81') === 0 || strpos($url, 'http://localhost') === 0) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;

        foreach (self::ALLOWED_RETURN_DOMAINS as $domain) {
            $suffix = '.' . $domain;
            if ($host === $domain || substr($host, -strlen($suffix)) === $suffix) return true;
        }

        return false;
    }

    private static function isValidUuid(string $uuid): bool
    {
        return (bool) preg_match('/^[0-9a-f]{32}$/', $uuid);
    }

    private static function setBrowserCookie(string $uuid): void
    {
        setcookie(self::COOKIE_NAME, $uuid, [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
