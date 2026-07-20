<?php

declare(strict_types=1);

namespace App;

/**
 * Validation du lead (EF-03) : e-mail obligatoire, profil et formation
 * strictement dans les listes HubSpot v4, champs facultatifs bornés.
 */
final class LeadValidator
{
    /** @return array{ok: true, lead: array}|array{ok: false, error: string} */
    public function validate(array $body): array
    {
        $email = mb_strtolower($this->clean($body['email'] ?? '', 254));
        $profil = $this->clean($body['profil'] ?? '', 100);
        $formation = $this->clean($body['formation'] ?? '', 150);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'E-mail invalide'];
        }
        if (!in_array($profil, Catalog::PROFILS, true)) {
            return ['ok' => false, 'error' => 'Profil inconnu'];
        }
        if (!in_array($formation, Catalog::FORMATIONS, true)) {
            return ['ok' => false, 'error' => 'Formation inconnue'];
        }

        $utm = [];
        if (isset($body['utm']) && is_array($body['utm'])) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
                if (!empty($body['utm'][$k]) && is_string($body['utm'][$k])) {
                    $utm[$k] = mb_substr($body['utm'][$k], 0, 255);
                }
            }
        }

        return ['ok' => true, 'lead' => [
            'email' => $email,
            'firstname' => $this->clean($body['firstname'] ?? '', 100),
            'phone' => $this->clean($body['phone'] ?? '', 30),
            'consent' => ($body['consent'] ?? false) === true,
            'profil' => $profil,
            'formation' => $formation,
            'utm' => $utm,
            'date' => date('Y-m-d'), // date_test
        ]];
    }

    private function clean(mixed $v, int $max): string
    {
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }
}
