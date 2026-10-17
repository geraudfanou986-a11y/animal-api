# Photos d'animaux : API REST en PHP

Tu tapes le nom d'un animal et le projet affiche une photo existante de cet animal.
Les photos viennent de la banque d'images gratuite **Pixabay** : elles sont téléchargées
une seule fois, puis servies depuis ce serveur.

Réalisé par : [SABALIMATH
, FLORENTIN , GERAUD , ASHLEY]

## Contenu du projet

- une **API publique** : `GET /animaux/{nom}`
- une **partie admin** protégée par une clé : liste et suppression des photos stockées
- une **page web** pour saisir le nom d'un animal et voir la photo

## Prérequis

- PHP 8.1 ou plus, avec les extensions `curl` et `openssl` activées
- une clé API Pixabay gratuite (voir ci-dessous)

## Installation

1. Télécharger ou cloner ce dépôt, puis ouvrir un terminal dans le dossier du projet.
2. Créer un compte gratuit sur https://pixabay.com puis copier ta clé affichée sur
   https://pixabay.com/api/docs/ (une fois connecté).
3. Copier `config.local.example.php` en `config.local.php` et remplir les deux valeurs :
   - `pixabay_key` : la clé Pixabay,
   - `admin_key` : une clé de ton choix pour la partie admin (par exemple une suite de lettres et de chiffres).

## Lancement

```bash
php -S localhost:8001 index.php
```

Puis ouvrir http://localhost:8001/ dans un navigateur.

## Routes

| Méthode | Route | Accès | Rôle |
|---|---|---|---|
| GET | `/` | public | Page web |
| GET | `/animaux/{nom}` | public | Photo de l'animal en JSON (téléchargée depuis Pixabay la 1re fois, puis lue en cache) |
| GET | `/images/{fichier}` | public | Image stockée |
| GET | `/admin/animaux` | clé admin | Liste des animaux stockés |
| DELETE | `/admin/animaux/{slug}` | clé admin | Supprime l'image et l'entrée de l'index |

La clé admin s'envoie dans l'en-tête HTTP `X-Admin-Key`.

Codes de réponse : 200 (succès), 400 (requête invalide), 401 (clé admin manquante ou fausse),
404 (animal ou route introuvable), 405 (méthode non autorisée), 429 (trop de requêtes vers Pixabay),
502 (Pixabay injoignable).

## Exemples de tests

Dans un navigateur ou dans Postman :

- `GET http://localhost:8001/animaux/lion`
- `GET http://localhost:8001/animaux/éléphant`
- `GET http://localhost:8001/animaux/zzzqqqxx` (404 attendu)

Partie admin (remplacer `VOTRE_CLE_ADMIN`) :

```bash
curl -H "X-Admin-Key: VOTRE_CLE_ADMIN" http://localhost:8001/admin/animaux
curl -X DELETE -H "X-Admin-Key: VOTRE_CLE_ADMIN" http://localhost:8001/admin/animaux/lion
```

Sous Windows PowerShell, utiliser `curl.exe` au lieu de `curl`.

## Fonctionnement

1. `GET /animaux/{nom}` cherche d'abord l'animal dans `storage/animaux.json`.
2. S'il n'y est pas, l'API interroge Pixabay, télécharge la photo dans `storage/images/`
   et enregistre l'entrée dans l'index. Pixabay interdit l'usage permanent de ses liens
   directs : c'est pourquoi l'image est stockée puis servie par ce serveur.
3. Les recherches suivantes du même animal n'appellent plus Pixabay.

Le dossier `storage/` est créé automatiquement au premier appel.

## Problème fréquent sous Windows

Si l'API répond `unable to get local issuer certificate`, PHP ne connaît pas les certificats
de confiance. Télécharger https://curl.se/ca/cacert.pem, l'enregistrer par exemple dans
`C:\php-certs\cacert.pem`, puis dans `php.ini` renseigner :

```ini
curl.cainfo = "C:\php-certs\cacert.pem"
openssl.cafile = "C:\php-certs\cacert.pem"
```

Relancer ensuite le serveur PHP.

## Structure

```
index.php                   routeur et logique de l'API
config.php                  lecture des clés (variables d'environnement ou config.local.php)
config.local.example.php    modèle de configuration (à copier en config.local.php)
public/index.html           page web
storage/                    images et index (créé automatiquement, non versionné)
```

Images fournies par [Pixabay](https://pixabay.com).