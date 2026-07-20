<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;
use Throwable;

/**
 * Client HubSpot (application privée) via Guzzle. Le jeton d'accès n'existe
 * que côté serveur (variable d'environnement HUBSPOT_TOKEN), conformément
 * au §4.2 : aucun secret dans le code public.
 */
final class HubSpotClient
{
    private readonly Client $http;

    public function __construct(private readonly Storage $storage)
    {
        $this->http = new Client([
            'base_uri' => 'https://api.hubapi.com',
            'timeout' => 10,
            'http_errors' => true,
        ]);
    }

    /**
     * Transmet un lead complet. Jette en cas d'échec de l'upsert
     * (le lead part alors en file de reprise — EF-06).
     */
    public function sendLead(array $lead): void
    {
        $properties = [
            'email' => $lead['email'],
            'profil_test' => $lead['profil'],
            'formation_reco_test' => $lead['formation'],
            'date_test' => $lead['date'],          // AAAA-MM-JJ (« Sélecteur de date »)
            'source_marketing' => 'Test Profil',
        ];
        if (!empty($lead['firstname'])) {
            $properties['firstname'] = $lead['firstname'];
        }
        if (!empty($lead['phone'])) {
            $properties['phone'] = $lead['phone'];
        }
        // EF-08 : paramètres de campagne. Désactivé par défaut car les propriétés
        // utm_* doivent exister dans le portail (à créer par le Pôle Communication).
        if (($_ENV['HUBSPOT_UTM_PROPERTIES'] ?? '') === 'true' && !empty($lead['utm'])) {
            foreach ($lead['utm'] as $k => $v) {
                $properties[$k] = $v;
            }
        }

        $this->upsertContact($properties);

        if (!empty($lead['consent'])) {
            try {
                $this->subscribe($lead['email']);
            } catch (Throwable $e) {
                // Non bloquant : le contact et ses résultats sont déjà enregistrés.
                $this->storage->logError('[consentement] enregistrement en échec : ' . $e->getMessage());
            }
        }
    }

    /**
     * Crée le contact, ou le met à jour s'il existe déjà (déduplication par
     * e-mail : HubSpot répond 409 avec l'identifiant du contact existant).
     */
    private function upsertContact(array $properties): void
    {
        try {
            $this->request('POST', '/crm/v3/objects/contacts', ['properties' => $properties]);
        } catch (BadResponseException $e) {
            $body = (string) $e->getResponse()->getBody();
            if ($e->getResponse()->getStatusCode() === 409 && preg_match('/Existing ID:\s*(\d+)/', $body, $m)) {
                $this->request('PATCH', "/crm/v3/objects/contacts/{$m[1]}", ['properties' => $properties]);
                return;
            }
            throw new RuntimeException($this->describe($e), 0, $e);
        }
    }

    /**
     * Consentement prospection (case unique du formulaire) → statut
     * d'abonnement HubSpot avec base légale « consentement ». Nécessite
     * HUBSPOT_SUBSCRIPTION_ID (identifiant du type d'abonnement du portail).
     */
    private function subscribe(string $email): void
    {
        $subscriptionId = $_ENV['HUBSPOT_SUBSCRIPTION_ID'] ?? '';
        if ($subscriptionId === '') {
            $this->storage->logError('[consentement] reçu mais HUBSPOT_SUBSCRIPTION_ID non configuré — abonnement non enregistré');
            return;
        }
        $this->request('POST', '/communication-preferences/v3/subscribe', [
            'emailAddress' => $email,
            'subscriptionId' => $subscriptionId,
            'legalBasis' => 'CONSENT_WITH_NOTICE',
            'legalBasisExplanation' => 'Case de consentement (non pré-cochée) cochée sur le test Future Profile',
        ]);
    }

    private function request(string $method, string $path, array $json): void
    {
        $token = $_ENV['HUBSPOT_TOKEN'] ?? '';
        if ($token === '') {
            throw new RuntimeException('HUBSPOT_TOKEN manquant — configurez le fichier .env');
        }
        $this->http->request($method, $path, [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => $json,
        ]);
    }

    private function describe(BadResponseException $e): string
    {
        $res = $e->getResponse();
        return sprintf(
            'HubSpot %s : %s',
            $res->getStatusCode(),
            mb_substr((string) $res->getBody(), 0, 500)
        );
    }
}
