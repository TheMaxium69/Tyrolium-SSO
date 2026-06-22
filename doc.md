# Tyrolium SSO — Documentation

Service de synchronisation cross-domaine de l'écosystème Tyrolium.  
Hébergé sur `sso.tyrolium.fr`.

---

## Pourquoi ce service existe

Les navigateurs modernes (Chrome 115+, Firefox 103+, Safari) isolent le stockage
(localStorage, cookies) des iframes cross-origin. Il n'est donc plus possible de
partager des données entre `gamenium.fr` et `tyrolium.fr` via une iframe.

Tyrolium SSO contourne ce blocage en utilisant la **navigation top-level** :
le navigateur redirige brièvement vers `sso.tyrolium.fr` (contexte first-party),
qui lit/crée un identifiant unique (UUID), puis renvoie l'utilisateur sur le site
d'origine avec cet UUID dans l'URL. Les sites utilisent ensuite cet UUID pour
interroger l'API d'état partagé.

---

## Table de base de données

```sql
CREATE TABLE sso_sync (
    uuid      CHAR(32)    NOT NULL PRIMARY KEY,
    theme     VARCHAR(10) DEFAULT NULL,
    lang      CHAR(2)     DEFAULT NULL,
    token     TEXT        DEFAULT NULL,
    last_seen DATETIME    NOT NULL
);
```

- `uuid` — identifiant unique du navigateur (32 hex chars, généré serveur)
- `theme` — préférence de thème (`dark`, `light`, `auto`)
- `lang` — langue (`fr`, `en`)
- `token` — webToken Useritium si l'utilisateur est connecté
- `last_seen` — dernière activité (utilisé pour le TTL)

---

## Routes

| Méthode | Chemin | Rôle |
|---|---|---|
| `GET` | `/hub` | Crée ou retrouve l'UUID du navigateur, redirige vers le site |
| `GET` | `/state` | Lit l'état complet d'un UUID (theme, lang, token) |
| `POST` | `/state` | Met à jour une valeur de l'état |
| `OPTIONS` | `/state` | Preflight CORS (géré automatiquement) |
| `*` | `/*` | Tout autre chemin → redirect `https://tyrolium.fr` |

---

### `GET /hub?return=URL`

Point d'entrée de la synchronisation. Appelé en redirect quand un site
n'a pas encore d'UUID en localStorage.

**Paramètres GET** :
| Paramètre | Requis | Description |
|---|---|---|
| `return` | Oui | URL complète de retour (doit être un domaine autorisé) |

**Fonctionnement** :
1. Vérifie que `return` appartient à un domaine autorisé → sinon redirect tyrolium.fr
2. Lit le cookie first-party `tyro_sso_browser`
3. Si aucun UUID ou UUID inconnu en DB → génère un nouvel UUID, pose le cookie (1 an)
4. Met à jour `last_seen` en DB
5. Redirige vers `return?_tyro_uuid=<UUID>`

**Cookie posé** : `tyro_sso_browser` — HttpOnly, Secure, SameSite=Lax, TTL 1 an

**Exemple** :
```
GET https://sso.tyrolium.fr/hub?return=https://gamenium.fr
→ 302 https://gamenium.fr?_tyro_uuid=a1b2c3d4e5f6...
```

Si l'URL de retour a déjà des paramètres :
```
GET https://sso.tyrolium.fr/hub?return=https://gamenium.fr?page=home
→ 302 https://gamenium.fr?page=home&_tyro_uuid=a1b2c3d4e5f6...
```

---

### `GET /state?uuid=UUID`

Lit l'état complet associé à un UUID. Appelé au démarrage de chaque app Angular
et en polling toutes les X secondes pour détecter les changements.

**Paramètres GET** :
| Paramètre | Requis | Description |
|---|---|---|
| `uuid` | Oui | UUID à 32 caractères hexadécimaux |

**Réponse succès** :
```json
{
  "status": "ok",
  "data": {
    "theme": "dark",
    "lang": "fr",
    "token": "abc123..."
  }
}
```
Les valeurs non définies sont `null`.

**Réponses erreur** :
```json
{ "status": "err", "why": "invalid uuid" }     // UUID mal formé
{ "status": "err", "why": "uuid not found" }   // UUID inexistant en DB
```

**Headers CORS** : présents pour tous les domaines de l'écosystème.

**Exemple** :
```
GET https://sso.tyrolium.fr/state?uuid=a1b2c3d4e5f6...
```

---

### `POST /state`

Met à jour une valeur de l'état pour un UUID donné. Appelé à chaque changement
de thème, de langue, à la connexion ou à la déconnexion.

**Body** (`application/x-www-form-urlencoded`) :
| Champ | Requis | Valeurs acceptées |
|---|---|---|
| `uuid` | Oui | UUID à 32 caractères hex |
| `key` | Oui | `theme` · `lang` · `token` |
| `value` | Oui | Valeur à stocker — chaîne vide pour effacer |

**Réponse succès** :
```json
{ "status": "ok" }
```

**Exemples** :
```
# Changer le thème
POST /state   uuid=a1b2...&key=theme&value=dark

# Changer la langue
POST /state   uuid=a1b2...&key=lang&value=en

# Stocker le token après connexion
POST /state   uuid=a1b2...&key=token&value=<webToken>

# Effacer le token après déconnexion
POST /state   uuid=a1b2...&key=token&value=
```

---

### `OPTIONS /state`

Preflight CORS géré automatiquement par le serveur. Réponse `204 No Content`
avec les headers `Access-Control-Allow-*` appropriés. Aucune action nécessaire
côté client (les navigateurs l'envoient automatiquement).

---

### `* /*` — Tout autre chemin

N'importe quel chemin non reconnu (accès direct, URL inconnue, bot) déclenche
une redirection vers `https://tyrolium.fr`.

```
GET https://sso.tyrolium.fr/         → 302 https://tyrolium.fr
GET https://sso.tyrolium.fr/unknown  → 302 https://tyrolium.fr
```

---

## Flux complet côté Angular

### Premier chargement (pas encore d'UUID)

```
1. App Angular démarre → vérifie localStorage['_tyro_uuid']
2. Pas d'UUID → redirect vers sso.tyrolium.fr/hub?return=https://monsite.fr
3. SSO pose le cookie, redirige vers monsite.fr?_tyro_uuid=XXX
4. Angular récupère UUID dans l'URL → stocke en localStorage → nettoie l'URL
5. Angular appelle GET /state?uuid=XXX → applique theme, lang, token
```

### Retour (UUID déjà connu)

```
1. App démarre → localStorage['_tyro_uuid'] = "XXX"
2. Angular appelle directement GET /state?uuid=XXX (pas de redirect)
3. Applique l'état
```

### Changement de thème / langue

```
POST /state  →  uuid=XXX&key=theme&value=dark
```

### Login

```
1. api.useritium.fr retourne un webToken
2. POST /state  →  uuid=XXX&key=token&value=<webToken>
3. Les autres sites détectent le token au prochain polling
```

### Logout

```
POST /state  →  uuid=XXX&key=token&value=
(valeur vide = suppression du token)
```

---

## Domaines autorisés

- `tyrolium.fr`
- `solidserv.fr`
- `tyrociel.fr`
- `gamenium.fr`
- `influnias.fr`
- `vturias.fr`
- `nexiumiacrm.fr`
- `useritium.fr`
- `tyroserv.fr`
- `dashboard.useritium.fr`
- Réseau local `192.168.1.81` et `localhost` (développement)

Pour ajouter un domaine : modifier `ALLOWED_ORIGINS` et `ALLOWED_RETURN_DOMAINS`
dans `core/Actions.php`.

---

## Ajouter une nouvelle route

1. Ajouter la méthode `public static function maRoute(): void` dans `core/Actions.php`
2. Ajouter `'maRoute'` dans le tableau `ACTIONS` de `core/App.php`
3. La route est accessible sur `https://sso.tyrolium.fr/maRoute`

---

## Cron de nettoyage

Fichier : `cron/cleanup.php`

À planifier une fois par jour :

```
0 3 * * * php /var/www/Tyrolium-SSO/cron/cleanup.php >> /var/log/tyrolium-sso-cleanup.log 2>&1
```

Règles :
- UUID **sans token** non vus depuis **90 jours** → supprimés
- UUID **avec token** non vus depuis **365 jours** → supprimés

---

## Structure du projet

```
Tyrolium-SSO/
├── index.php                  Point d'entrée
├── .htaccess                  Routing Apache + HTTPS forcé
├── doc.md                     Cette documentation
├── core/
│   ├── autoloading.php        Autoloader
│   ├── App.php                Router (lit le chemin URL)
│   ├── Actions.php            Toutes les actions (hub, state, ...)
│   ├── Database.php           Credentials DB (ne pas commiter)
│   ├── Database_template.php  Template à copier en Database.php
│   └── Model/
│       ├── Model.php          Classe abstraite (PDO)
│       └── SyncSession.php    Opérations sur tyro_sync_sessions
└── cron/
    └── cleanup.php            Nettoyage des sessions expirées
```
