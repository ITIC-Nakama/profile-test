<?php

declare(strict_types=1);

namespace App;

use Throwable;

/**
 * File de reprise (EF-06) : un lead dont la transmission HubSpot échoue est
 * conservé sur disque (data/queue.json) et rejoué — de façon opportuniste à
 * chaque requête API (au plus une fois par minute), ou immédiatement via
 * GET /api/retry (cron). Rétention courte : 48 h (§4.1), puis journalisation
 * et abandon.
 */
final class LeadQueue
{
    private const MAX_AGE_SEC = 48 * 3600;
    private const FLUSH_EVERY_SEC = 60;

    public function __construct(
        private readonly Storage $storage,
        private readonly HubSpotClient $hubspot,
    ) {
    }

    public function add(array $lead, string $reason): void
    {
        $q = $this->read();
        $q[] = ['lead' => $lead, 'enqueuedAt' => time(), 'attempts' => 0];
        $this->write($q);
        $this->storage->logError("lead mis en file de reprise ({$lead['email']}) : $reason");
    }

    public function size(): int
    {
        return count($this->read());
    }

    /** Rejoue la file ; retourne le nombre de leads transmis. */
    public function flush(bool $force = false): int
    {
        $fp = fopen($this->storage->path('queue.lock'), 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            return 0; // reprise déjà en cours
        }
        $sent = 0;
        try {
            if (!$force && !$this->throttleExpired()) {
                return 0;
            }
            $remaining = [];
            foreach ($this->read() as $item) {
                if (time() - $item['enqueuedAt'] > self::MAX_AGE_SEC) {
                    $this->storage->logError("ABANDON après {$item['attempts']} tentatives et 48 h : {$item['lead']['email']}");
                    continue;
                }
                try {
                    $this->hubspot->sendLead($item['lead']);
                    $this->storage->logLead("lead rejoué avec succès : {$item['lead']['email']}");
                    $sent++;
                } catch (Throwable $e) {
                    $item['attempts']++;
                    $remaining[] = $item;
                    $this->storage->logError("tentative {$item['attempts']} en échec ({$item['lead']['email']}) : {$e->getMessage()}");
                }
            }
            $this->write($remaining);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $sent;
    }

    private function throttleExpired(): bool
    {
        $marker = $this->storage->path('last-flush');
        if (is_file($marker) && time() - filemtime($marker) < self::FLUSH_EVERY_SEC) {
            return false;
        }
        touch($marker);
        return true;
    }

    private function read(): array
    {
        $q = json_decode(@file_get_contents($this->storage->path('queue.json')) ?: '', true);
        return is_array($q) ? $q : [];
    }

    private function write(array $q): void
    {
        file_put_contents(
            $this->storage->path('queue.json'),
            json_encode($q, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
