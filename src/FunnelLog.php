<?php

declare(strict_types=1);

namespace App;

/**
 * Mesure du tunnel de conversion (EF-11) : événements anonymes (identifiant
 * de session aléatoire, aucune donnée personnelle, pas de cookie) ajoutés à
 * data/events.jsonl, et agrégats pour GET /api/stats.
 */
final class FunnelLog
{
    private const FILE = 'events.jsonl';

    public function __construct(private readonly Storage $storage)
    {
    }

    public function record(array $body): bool
    {
        if (!in_array($body['e'] ?? '', Catalog::EVENTS, true)) {
            return false;
        }
        $record = [
            'ts' => date('c'),
            'e' => $body['e'],
            'sid' => mb_substr(trim((string) ($body['sid'] ?? '')), 0, 64),
        ];
        if (isset($body['q']) && is_int($body['q']) && $body['q'] >= 1 && $body['q'] <= 10) {
            $record['q'] = $body['q'];
        }
        if (!empty($body['level']) && is_string($body['level'])) {
            $record['level'] = mb_substr(trim($body['level']), 0, 20);
        }
        $this->storage->append(self::FILE, json_encode($record, JSON_UNESCAPED_UNICODE));
        return true;
    }

    public function stats(): array
    {
        $seen = [
            'page_view' => [], 'test_started' => [], 'capture_shown' => [],
            'lead_submitted' => [], 'test_completed' => [],
        ];
        $abandons = [];
        $niveaux = [];

        $raw = @file_get_contents($this->storage->path(self::FILE)) ?: '';
        foreach (explode("\n", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            $ev = json_decode($line, true);
            if (!is_array($ev)) {
                continue;
            }
            $e = $ev['e'] ?? '';
            if (isset($seen[$e])) {
                $seen[$e][$ev['sid'] ?? uniqid()] = true;
            }
            if ($e === 'abandon' && !empty($ev['q'])) {
                $abandons['Q' . $ev['q']] = ($abandons['Q' . $ev['q']] ?? 0) + 1;
            }
            if ($e === 'level_selected' && !empty($ev['level'])) {
                $niveaux[$ev['level']] = ($niveaux[$ev['level']] ?? 0) + 1;
            }
        }
        ksort($abandons);

        return [
            'visiteurs' => count($seen['page_view']),
            'tests_demarres' => count($seen['test_started']),
            'captures_affichees' => count($seen['capture_shown']),
            'leads_soumis' => count($seen['lead_submitted']),
            'tests_termines' => count($seen['test_completed']),
            'abandons_par_question' => $abandons ?: new \stdClass(),
            'niveaux_choisis' => $niveaux ?: new \stdClass(),
        ];
    }
}
