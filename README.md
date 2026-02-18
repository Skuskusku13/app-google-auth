# GDox Manager

Application Symfony 8 permettant :
- l'authentification Google OAuth2
- la création de documents Google Docs depuis un éditeur riche (Quill)
- la consultation et la mise à jour de documents Google Docs

## Lancer le projet en local (sans Docker)

### 1) Prérequis

- PHP `>= 8.4`
- Composer `>= 2`
- Une base de données MySQL/MariaDB locale
- Accès à un projet Google Cloud (OAuth 2.0 activé pour Google Docs/Drive)

Vérifications rapides :

```bash
php -v
composer -V
```

### 2) Installer les dépendances

Depuis la racine du projet :

```bash
composer install
```

### 3) Configurer les variables d'environnement

Créer un fichier `.env.local` (non versionné) :

```dotenv
APP_ENV=dev
APP_SECRET=change_me

DATABASE_URL="mysql://USER:PASSWORD@127.0.0.1:3306/google-auth?serverVersion=11.4.7-MariaDB&charset=utf8mb4"

GOOGLE_CLIENT_ID=xxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=xxx
GOOGLE_API_KEY=xxx
```

Notes :
- Adaptez `DATABASE_URL` à votre instance locale.
- Les secrets Google ne doivent pas être commités.
- Si vous utilisez uniquement OAuth2 utilisateur, `GOOGLE_API_KEY` n'est pas bloquant pour le flux principal, mais il est référencé dans la configuration.

### 4) Créer la base et appliquer les migrations

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

### 5) Démarrer le serveur Symfony

Option A (Symfony CLI si installée) :

```bash
symfony server:start
```

Option B (serveur PHP natif) :

```bash
php -S 127.0.0.1:8000 -t public
```

Application accessible sur :
- `http://127.0.0.1:8000`

### 6) Configurer Google OAuth (obligatoire)

Dans Google Cloud Console :
- Activez les API Google Docs et Google Drive.
- Créez des identifiants OAuth2 (type Application Web).
- Ajoutez l'URL de redirection autorisée :
  - `http://127.0.0.1:8000/connect/google/check`

Scopes utilisés par l'application :
- `email`
- `profile`
- `https://www.googleapis.com/auth/documents`
- `https://www.googleapis.com/auth/drive.file`

### 7) Vérifier que tout fonctionne

- Ouvrir `http://127.0.0.1:8000`
- Cliquer sur la connexion Google
- Accéder au dashboard
- Créer un document et vérifier l'ouverture du lien Google Docs

---

## Commandes utiles (dev)

```bash
# Liste des routes
php bin/console debug:router

# Vider le cache
php bin/console cache:clear

# Exécuter les tests
php bin/phpunit

# Analyse statique
vendor/bin/phpstan analyse -c phpstan.dist.neon
```

---

## Architecture technique

### Stack

- Backend : Symfony 8 (PHP)
- Auth : `knpuniversity/oauth2-client-bundle` + Google OAuth2
- API Google : `google/apiclient`
- ORM : Doctrine ORM + Doctrine Migrations
- Front : Twig + Bootstrap + Quill.js + AssetMapper/importmap

### Structure du projet

- `src/Controller/`
  - `HomeController.php` : page d'accueil
  - `GoogleController.php` : démarrage OAuth, callback, succès, logout
  - `DashboardController.php` : espace utilisateur connecté
  - `DocsController.php` : create/view/edit de documents
- `src/Security/GoogleAuthenticator.php`
  - échange du code OAuth
  - création/rattachement utilisateur
  - stockage des tokens Google
- `src/Service/GoogleDocsService.php`
  - encapsule les appels Google Docs API
  - refresh automatique du token expiré
- `src/Entity/User.php`
  - données utilisateur + tokens Google persistés
- `migrations/`
  - historique du schéma SQL
- `config/packages/`
  - sécurité, doctrine, OAuth2, service Google API

### Flux applicatif

1. L'utilisateur arrive sur `/`.
2. Il se connecte via `/connect/google`.
3. Le callback `/connect/google/check` est traité par `GoogleAuthenticator`.
4. L'utilisateur authentifié accède à `/dashboard`.
5. Il crée un document via `/docs/create`.
6. Le service `GoogleDocsService` crée/met à jour le document côté Google.

---

## Base de données

Les migrations créent principalement :
- `user`
  - email, rôles
  - `google_id`, `name`, `avatar`
  - `google_access_token`, `google_refresh_token`, `google_token_expires_at`
- `messenger_messages`
  - table standard Symfony Messenger (transport Doctrine)

---

## Sécurité

- Authentification par OAuth2 Google.
- Session Symfony côté serveur.
- Les actions sensibles sur les documents sont protégées par utilisateur connecté.
- Les tokens Google sont stockés en base et renouvelés via refresh token quand possible.

---

## Dépannage rapide

- Erreur OAuth redirect URI mismatch :
  - vérifier `http://127.0.0.1:8000/connect/google/check` dans Google Cloud Console.
- Erreur DB de connexion :
  - vérifier `DATABASE_URL` puis relancer migration.
- Pas de refresh token Google :
  - reconnecter le compte (Google peut ne pas renvoyer le refresh token à chaque login).
