<?php

declare(strict_types=1);

namespace App;

/**
 * Accès au répertoire data/ (jamais servi par le web) et journalisation
 * minimale (§4.3) : erreurs HubSpot et leads transmis.
 */
final class Storage
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public function path(string $file): string
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0750, true);
            // data/ ne doit jamais être servi par Apache
            file_put_contents($this->dataDir . '/.htaccess', "Require all denied\n");
        }
        return $this->dataDir . '/' . $file;
    }

    public function append(string $file, string $line): void
    {
        file_put_contents($this->path($file), $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function logError(string $msg): void
    {
        $this->append('hubspot-errors.log', date('c') . " $msg");
    }

    public function logLead(string $msg): void
    {
        $this->append('leads.log', date('c') . " $msg");
    }
}
