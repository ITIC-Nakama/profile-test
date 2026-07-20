<?php

declare(strict_types=1);

namespace App;

/**
 * Limitation de débit par IP (§4.2 — protection contre les soumissions
 * automatisées). Compteurs sur disque avec verrou exclusif, adaptés à un
 * hébergement PHP sans processus résident.
 */
final class RateLimiter
{
    public function __construct(private readonly Storage $storage)
    {
    }

    public function tooMany(string $ip, string $key, int $max, int $windowSec): bool
    {
        $fp = fopen($this->storage->path('ratelimit.json'), 'c+');
        if (!$fp) {
            return false; // ne jamais bloquer un humain sur une erreur interne
        }
        flock($fp, LOCK_EX);
        $data = json_decode(stream_get_contents($fp) ?: '', true) ?: [];
        $now = time();
        $k = "$key:$ip";
        $bucket = $data[$k] ?? null;
        if (!$bucket || $now > $bucket['reset']) {
            $bucket = ['n' => 0, 'reset' => $now + $windowSec];
        }
        $bucket['n']++;
        $data[$k] = $bucket;
        foreach ($data as $kk => $b) {
            if ($now > $b['reset']) {
                unset($data[$kk]);
            }
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);
        return $bucket['n'] > $max;
    }
}
