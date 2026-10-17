<?php
// Priorité aux variables d'environnement (hébergeur), sinon config.local.php (poste local).
$local = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];

return [
    'pixabay_key' => getenv('PIXABAY_KEY') ?: ($local['pixabay_key'] ?? ''),
    'admin_key'   => getenv('ADMIN_KEY')   ?: ($local['admin_key']   ?? ''),
];