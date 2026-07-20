<?php

/**
 * Future Profile — ITIC Paris · front controller (Slim 4)
 *
 *   GET  /            → parcours du test (index.html)
 *   POST /api/lead    → transmission HubSpot (EF-05), reprise sur échec (EF-06)
 *   POST /api/event   → mesure du tunnel de conversion (EF-11)
 *   GET  /api/stats   → agrégats du tunnel (protégé par STATS_KEY)
 *   GET  /api/retry   → rejoue la file de reprise (cron)
 *   GET  /healthz     → supervision
 *
 * Dev local : composer start  (php -S localhost:3000 -t public public/index.php)
 * Production Apache : .htaccess fournis (racine et public/).
 */

declare(strict_types=1);

use App\Catalog;
use App\FunnelLog;
use App\HubSpotClient;
use App\LeadQueue;
use App\LeadValidator;
use App\RateLimiter;
use App\Storage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// Serveur PHP intégré : laisser les fichiers statiques existants au serveur
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && basename($file) !== 'index.php') {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Europe/Paris');
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$storage = new Storage(dirname(__DIR__) . '/data');
$hubspot = new HubSpotClient($storage);
$queue = new LeadQueue($storage, $hubspot);
$limiter = new RateLimiter($storage);
$funnel = new FunnelLog($storage);
$validator = new LeadValidator();

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware((($_ENV['APP_DEBUG'] ?? '') === 'true'), true, true);

$json = function (Response $response, int $status, array $data): Response {
    $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $response
        ->withStatus($status)
        ->withHeader('Content-Type', 'application/json; charset=utf-8')
        ->withHeader('Cache-Control', 'no-store');
};

$clientIp = function (Request $request): string {
    // Derrière un reverse proxy, l'IP réelle est dans X-Forwarded-For ;
    // TRUST_PROXY=true doit alors être configuré (§4.2).
    if (($_ENV['TRUST_PROXY'] ?? '') === 'true' && $request->hasHeader('X-Forwarded-For')) {
        return trim(explode(',', $request->getHeaderLine('X-Forwarded-For'))[0]);
    }
    return $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
};

$checkStatsKey = function (Request $request): bool {
    $key = $_ENV['STATS_KEY'] ?? '';
    return $key === '' || ($request->getQueryParams()['key'] ?? '') === $key;
};

// ─── Page du test ───
$app->get('/', function (Request $request, Response $response) {
    $response->getBody()->write((string) file_get_contents(__DIR__ . '/index.html'));
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

// ─── POST /api/lead ───
$app->post('/api/lead', function (Request $request, Response $response) use ($json, $clientIp, $limiter, $validator, $hubspot, $queue, $storage) {
    if ($limiter->tooMany($clientIp($request), 'lead', 10, 600)) {
        return $json($response, 429, ['ok' => false, 'error' => 'Trop de requêtes']);
    }
    $body = $request->getParsedBody();
    if (!is_array($body)) {
        return $json($response, 400, ['ok' => false, 'error' => 'JSON invalide']);
    }

    // Honeypot : un humain ne remplit jamais ce champ. On accuse réception
    // sans rien transmettre pour ne pas renseigner les robots.
    if (!empty($body['hp'])) {
        $storage->logError('honeypot déclenché (ip ' . $clientIp($request) . ')');
        return $json($response, 202, ['ok' => true]);
    }

    $result = $validator->validate($body);
    if (!$result['ok']) {
        return $json($response, 422, ['ok' => false, 'error' => $result['error']]);
    }
    $lead = $result['lead'];

    // EF-06 : le frontend n'attend pas cette réponse (envoi fire-and-forget) —
    // les résultats s'affichent quoi qu'il arrive ; échec → file de reprise.
    try {
        $hubspot->sendLead($lead);
        $storage->logLead("transmis à HubSpot : {$lead['email']} → {$lead['profil']} / {$lead['formation']}");
    } catch (Throwable $e) {
        $queue->add($lead, $e->getMessage());
    }
    $queue->flush(); // reprise opportuniste (au plus une fois par minute)

    return $json($response, 202, ['ok' => true]);
});

// ─── POST /api/event ───
$app->post('/api/event', function (Request $request, Response $response) use ($json, $clientIp, $limiter, $funnel, $queue) {
    if ($limiter->tooMany($clientIp($request), 'event', 120, 60)) {
        return $json($response, 429, ['ok' => false]);
    }
    $body = $request->getParsedBody();
    if (!is_array($body)) {
        return $json($response, 400, ['ok' => false]);
    }
    if (!$funnel->record($body)) {
        return $json($response, 422, ['ok' => false]);
    }
    $queue->flush(); // reprise opportuniste (au plus une fois par minute)
    return $json($response, 202, ['ok' => true]);
});

// ─── GET /api/stats ───
$app->get('/api/stats', function (Request $request, Response $response) use ($json, $checkStatsKey, $funnel, $queue) {
    if (!$checkStatsKey($request)) {
        return $json($response, 403, ['ok' => false, 'error' => 'Clé invalide']);
    }
    return $json($response, 200, $funnel->stats() + ['file_de_reprise' => $queue->size()]);
});

// ─── GET /api/retry — rejoue la file immédiatement (cron recommandé) ───
$app->get('/api/retry', function (Request $request, Response $response) use ($json, $checkStatsKey, $queue) {
    if (!$checkStatsKey($request)) {
        return $json($response, 403, ['ok' => false, 'error' => 'Clé invalide']);
    }
    $sent = $queue->flush(true);
    return $json($response, 200, ['ok' => true, 'rejoues' => $sent, 'file_de_reprise' => $queue->size()]);
});

// ─── GET /healthz — supervision minimale (§4.3) ───
$app->get('/healthz', function (Request $request, Response $response) use ($json, $queue) {
    return $json($response, 200, ['ok' => true, 'file_de_reprise' => $queue->size()]);
});

$app->run();
