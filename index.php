<?php
declare(strict_types=1);

const STORAGE_DIR = __DIR__ . '/storage';
const IMAGES_DIR  = STORAGE_DIR . '/images';
const INDEX_FILE  = STORAGE_DIR . '/animaux.json';

$config = require __DIR__ . '/config.php';

/* ---------- Réponses ---------- */

function jsonResponse(int $status, array $data, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function baseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $_SERVER['HTTP_HOST'];
}

/* ---------- Outils ---------- */

/** "Éléphant d'Afrique" -> "elephant-d-afrique" (sert de nom de fichier). */
function slugify(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = strtr($text, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae',
    ]);
    $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function ensureStorage(): void
{
    if (!is_dir(IMAGES_DIR)) {
        mkdir(IMAGES_DIR, 0777, true);
    }
}

function loadIndex(): array
{
    if (!is_file(INDEX_FILE)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(INDEX_FILE), true);
    return is_array($data) ? $data : [];
}

function saveIndex(array $index): void
{
    file_put_contents(
        INDEX_FILE,
        json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

/** Télécharge une URL. Retourne [code HTTP, corps] ou répond en erreur. */
function httpGet(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'animal-api/1.0 (projet etudiant)',
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        jsonResponse(502, ['error' => 'Impossible de joindre le serveur distant', 'detail' => $err]);
    }
    return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), (string) $body];
}

/* ---------- Sécurité admin ---------- */

function requireAdmin(array $config): void
{
    $expected = (string) ($config['admin_key'] ?? '');
    if ($expected === '' || $expected === 'COLLE_ICI_LA_CLE_GENEREE') {
        jsonResponse(500, ['error' => 'Clé admin non configurée dans config.php']);
    }

    $given = (string) ($_SERVER['HTTP_X_ADMIN_KEY'] ?? '');
    if ($given === '' || !hash_equals($expected, $given)) {
        jsonResponse(401, ['error' => 'Clé admin manquante ou incorrecte']);
    }
}

/* ---------- Routage ---------- */

$method = $_SERVER['REQUEST_METHOD'];
$path   = trim((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts  = $path === '' ? [] : array_map('rawurldecode', explode('/', $path));
$route  = $parts[0] ?? '';

/* ===================== PARTIE ADMIN ===================== */
if ($route === 'admin') {
    requireAdmin($config);

    if (($parts[1] ?? '') !== 'animaux' || count($parts) > 3) {
        jsonResponse(404, ['error' => 'Route admin inconnue. Utilise /admin/animaux']);
    }

    /* ----- GET /admin/animaux : liste ----- */
    if (count($parts) === 2) {
        if ($method !== 'GET') {
            jsonResponse(405, ['error' => 'Méthode non autorisée'], ['Allow: GET']);
        }

        $index = loadIndex();
        $liste = [];
        foreach ($index as $e) {
            $chemin = IMAGES_DIR . '/' . $e['fichier'];
            $liste[] = [
                'animal'         => $e['animal'],
                'slug'           => $e['slug'],
                'tags'           => $e['tags'],
                'photographe'    => $e['photographe'],
                'date'           => $e['date'],
                'fichier'        => $e['fichier'],
                'taille_octets'  => is_file($chemin) ? filesize($chemin) : null,
                'image_url'      => baseUrl() . '/images/' . $e['fichier'],
            ];
        }
        jsonResponse(200, ['total' => count($liste), 'animaux' => $liste]);
    }

    /* ----- DELETE /admin/animaux/{slug} : suppression ----- */
    if ($method !== 'DELETE') {
        jsonResponse(405, ['error' => 'Méthode non autorisée'], ['Allow: DELETE']);
    }

    $slug = $parts[2];
    if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
        jsonResponse(400, ['error' => 'Slug invalide (lettres minuscules, chiffres et tirets)']);
    }

    $index = loadIndex();
    if (!isset($index[$slug])) {
        jsonResponse(404, ['error' => "Aucun animal « $slug » dans le stock"]);
    }

    $chemin = IMAGES_DIR . '/' . $index[$slug]['fichier'];
    if (is_file($chemin)) {
        unlink($chemin);
    }
    unset($index[$slug]);
    saveIndex($index);

    jsonResponse(200, ['message' => 'Animal supprimé', 'slug' => $slug]);
}

/* ===================== PARTIE PUBLIQUE ===================== */
if ($method !== 'GET') {
    jsonResponse(405, ['error' => 'Méthode non autorisée (GET uniquement)'], ['Allow: GET']);
}

/* ----- GET / : la page web ----- */
if ($route === '') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/public/index.html');
    exit;
}

/* ----- GET /images/{fichier} : sert une image stockée ----- */
if ($route === 'images' && count($parts) === 2) {
    $file = $parts[1];
    if (!preg_match('/^[a-z0-9-]+\.jpg$/', $file) || !is_file(IMAGES_DIR . '/' . $file)) {
        jsonResponse(404, ['error' => 'Image introuvable']);
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize(IMAGES_DIR . '/' . $file));
    readfile(IMAGES_DIR . '/' . $file);
    exit;
}

/* ----- GET /animaux/{nom} : photo d'un animal ----- */
if ($route === 'animaux' && count($parts) === 2) {
    $animal = trim($parts[1]);
    $slug   = slugify($animal);

    if ($slug === '') {
        jsonResponse(400, ['error' => 'Nom d\'animal invalide']);
    }
    if (mb_strlen($animal) > 100) {
        jsonResponse(400, ['error' => 'Nom trop long (100 caractères maximum)']);
    }

    ensureStorage();
    $index  = loadIndex();
    $source = 'cache';

    // Déjà en stock ? On ne rappelle pas Pixabay.
    if (!isset($index[$slug]) || !is_file(IMAGES_DIR . '/' . $index[$slug]['fichier'])) {
        $source = 'pixabay';

        // 1) Recherche
        $url = 'https://pixabay.com/api/?' . http_build_query([
            'key'        => $config['pixabay_key'],
            'q'          => $animal,
            'lang'       => 'fr',
            'image_type' => 'photo',
            'category'   => 'animals',
            'safesearch' => 'true',
            'per_page'   => 3,
        ]);
        [$status, $body] = httpGet($url);

        if ($status === 429) {
            jsonResponse(429, ['error' => 'Trop de requêtes vers Pixabay, réessaie dans une minute']);
        }
        if ($status !== 200) {
            jsonResponse(502, ['error' => "Pixabay a répondu avec le code $status"]);
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['hits'])) {
            jsonResponse(404, ['error' => "Aucune image trouvée pour « $animal »"]);
        }
        $hit = $data['hits'][0];

        // 2) Téléchargement de l'image
        [$imgStatus, $imgBody] = httpGet($hit['webformatURL']);
        if ($imgStatus !== 200 || strlen($imgBody) < 1000) {
            jsonResponse(502, ['error' => "Téléchargement de l'image impossible (code $imgStatus)"]);
        }

        // 3) Enregistrement sur le disque + dans l'index
        $fileName = $slug . '.jpg';
        file_put_contents(IMAGES_DIR . '/' . $fileName, $imgBody, LOCK_EX);

        $index[$slug] = [
            'animal'      => $animal,
            'slug'        => $slug,
            'image_id'    => $hit['id'],
            'tags'        => $hit['tags'],
            'photographe' => $hit['user'],
            'page_source' => $hit['pageURL'],
            'fichier'     => $fileName,
            'date'        => date('c'),
        ];
        saveIndex($index);
    }

    $e = $index[$slug];
    jsonResponse(200, [
        'animal'      => $e['animal'],
        'source'      => $source,
        'image_url'   => baseUrl() . '/images/' . $e['fichier'],
        'tags'        => $e['tags'],
        'photographe' => $e['photographe'],
        'page_source' => $e['page_source'],
        'attribution' => 'Images by Pixabay',
    ]);
}

if ($route === 'animaux') {
    jsonResponse(400, ['error' => 'Nom manquant. Utilise /animaux/{nom}, par exemple /animaux/lion']);
}

jsonResponse(404, ['error' => 'Route inconnue. Utilise /animaux/{nom} ou /images/{fichier}']);