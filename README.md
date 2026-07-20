# Future Profile — ITIC Paris

Test d'orientation interactif avec intégration HubSpot, développé conformément au
**Cahier des charges v1.3** (15 juillet 2026), au tableau de correspondances
`ITIC_Profils_Diplomes.xlsx` et au document **Propriétés HubSpot Future Profile v4**.

## Architecture

Projet PHP avec **Composer** (autoload PSR-4, framework Slim 4, Guzzle, phpdotenv).

```
public/index.html        Parcours complet (base : maquette dark) — HTML/CSS/JS autonome
public/index.php         Front controller Slim : toutes les routes API
src/Catalog.php          Listes de valeurs HubSpot v4 (27 profils, 23 formations, événements)
src/LeadValidator.php    Validation du lead (EF-03, intégrité des listes)
src/HubSpotClient.php    Client API HubSpot via Guzzle (upsert contact + consentement)
src/LeadQueue.php        File de reprise sur échec (EF-06), rétention 48 h
src/RateLimiter.php      Limitation de débit par IP (§4.2)
src/FunnelLog.php        Tunnel de conversion : enregistrement + agrégats (EF-11)
src/Storage.php          Répertoire data/ + journaux
.htaccess + public/.htaccess   Routage et protections Apache (production)
data/                    Créé automatiquement : queue.json, events.jsonl, leads.log,
                         hubspot-errors.log (jamais servi par le web)
```

Routes : `POST /api/lead`, `POST /api/event`, `GET /api/stats`, `GET /api/retry`,
`GET /healthz` — définies dans [public/index.php](public/index.php).

Prérequis : **PHP ≥ 8.1** (extension curl) et **Composer**.

## Démarrage

```bash
composer install
cp .env.example .env      # puis renseigner HUBSPOT_TOKEN (voir ci-dessous)
composer start            # = php -S localhost:3000 -t public public/index.php
# → http://localhost:3000
```

Les leads transmis avec succès sont tracés dans `data/leads.log` ; le détail
complet du contact se consulte dans HubSpot (CRM → Contacts → rechercher l'e-mail).

## Parcours utilisateur (conforme §2.1 + arbitrages)

1. **Intro** → 2. **Choix du niveau** « Tu veux faire… un BTS / un Bachelor / un Mastère » (EF-12)
→ 3. **10 questions** (7 simples, 3 multiples limitées à 2 choix)
→ 4. **Capture** : e-mail obligatoire, prénom et téléphone facultatifs (EF-03),
case de consentement unique non pré-cochée + mention d'information RGPD (EF-04)
→ 5. **Analyse** (~4 s) → 6. **Résultats** + carte formation + partage/refaire (EF-09).

### Moteur (EF-02)

- 10 dimensions, **17 profils combinés** (secondaire ≥ 60 % de la dominante) et
  **10 profils individuels** renommés selon la maquette v2 (Q12) : Game Changer,
  Storyteller, The Deal, Digital Native, The Founder, World Player, Wild Card,
  All-Terrain, The Captain, Data Mind — soit 27 profils.
- La formation recommandée = croisement **profil × niveau choisi** selon le tableau
  de correspondances ; en cas d'alternatives (« ou »), la **première** formation est
  retenue (Q13). Catalogue : **23 formations** présentielles — PGE et e-learning exclus (Q10).
- Intitulé SIO officiel : « BTS Services Informatiques aux Organisations » (Q14).
- Le profil « Code Maker » présent dans le tableau Excel ne fait pas partie des
  27 profils du moteur (reliquat) : il n'est pas utilisé.

## Intégration HubSpot (EF-05, §3.2)

À la soumission, le navigateur appelle `POST /api/lead` (aucun secret côté client).
Le serveur crée ou met à jour le contact (déduplication par e-mail) avec :

| Donnée | Propriété | Valeur |
|---|---|---|
| E-mail | `email` (standard) | saisie validée |
| Prénom | `firstname` (standard) | si renseigné |
| Téléphone | `phone` (standard) | si renseigné |
| Profil affiché | `profil_test` | l'une des 27 valeurs exactes de la liste v4 |
| Formation recommandée | `formation_reco_test` | l'une des 23 valeurs exactes (niveau inclus) |
| Date de passage | `date_test` | AAAA-MM-JJ |
| Source marketing | `source_marketing` | « Test Profil » |
| Consentement | statut d'abonnement | base légale `CONSENT_WITH_NOTICE` si case cochée |

Le serveur **valide les libellés** contre les listes v4 avant transmission : aucune
valeur hors liste ne peut être écrite dans HubSpot. Les scores détaillés des
10 dimensions ne sont **pas** transmis (Q8).

- **EF-06** : le serveur accuse réception immédiatement (les résultats s'affichent
  toujours) puis transmet en arrière-plan ; en cas d'échec HubSpot, le lead part en
  **file de reprise** (`data/queue.json`), rejouée de façon opportuniste à chaque
  requête API (au plus 1×/min) et à la demande via `/api/retry`, pendant 48 h max.
  Erreurs journalisées dans `data/hubspot-errors.log`.
- **EF-08** : les paramètres `utm_*` sont capturés et transmis au serveur. Leur envoi
  vers HubSpot est activé par `HUBSPOT_UTM_PROPERTIES=true` **après** création des
  propriétés correspondantes dans le portail.
- **Anti-bots (§4.2)** : limitation de débit par IP (10 leads / 10 min) + champ honeypot.

### Application privée HubSpot (prérequis §6)

À créer dans le portail ITIC Paris (Paramètres → Intégrations → Applications privées) :
périmètres `crm.objects.contacts.read`, `crm.objects.contacts.write` et
`communication_preferences.read_write`. Reporter le jeton dans `.env` (`HUBSPOT_TOKEN`).
Pour le consentement, renseigner aussi `HUBSPOT_SUBSCRIPTION_ID` (identifiant du type
d'abonnement marketing : Paramètres → Marketing → E-mail → Types d'abonnement) —
sans lui, l'opt-in coché n'est **pas** enregistré (une trace est écrite dans les logs).

Les propriétés personnalisées (`profil_test`, `formation_reco_test`, `date_test`,
option « Test Profil » de `source_marketing`) sont créées par le **Pôle Communication**
à partir du document « Propriétés HubSpot Future Profile v4 » (§7.2).

## Mesure du tunnel (EF-11)

Événements **anonymes** (identifiant de session aléatoire, aucune donnée personnelle,
pas de cookie — pas de bannière nécessaire) envoyés à `POST /api/event` :
`page_view`, `level_selected`, `test_started`, `q_answered` (n° de question),
`abandon` (question d'abandon, via `pagehide`/sendBeacon), `capture_shown`,
`lead_submitted`, `test_completed`, `share_clicked`, `restart_clicked`.

Agrégats : `GET /api/stats?key=<STATS_KEY>` → visiteurs, tests démarrés, abandons
par question, tests terminés, leads soumis, choix de niveau, taille de la file de reprise.

Supervision : `GET /healthz`.

## Recette (phase 4 des livrables)

1. Démarrer avec un `.env` complet (jeton du portail de test ou de production).
2. Dérouler un parcours complet : niveau → 10 questions → formulaire (e-mail réel
   de test, case cochée) → vérifier l'affichage des résultats.
3. Dans HubSpot : vérifier le contact créé avec `profil_test`, `formation_reco_test`,
   `date_test`, `source_marketing` = « Test Profil » et le statut d'abonnement.
4. Rejouer avec le même e-mail et un autre niveau : le contact doit être **mis à jour**
   (déduplication), pas dupliqué.
5. Retirer temporairement le jeton, soumettre un lead : les résultats s'affichent,
   le lead apparaît dans `data/queue.json`, puis est transmis après rétablissement
   (`/api/retry` ou automatiquement à la requête suivante).
6. Consulter `GET /api/stats` et vérifier la cohérence du tunnel.

## Déploiement (§4.3 — sous-domaine dédié retenu)

Tout hébergement PHP classique convient (mutualisé inclus) :

1. Déployer le projet (`composer install --no-dev` sur le serveur, ou uploader
   le dossier `vendor/` généré localement si l'hébergeur n'a pas Composer).
2. Pointer le DocumentRoot de `future-profile.iticparis.com` sur **`public/`**
   (recommandé). Si l'hébergement impose la racine du projet, le `.htaccess`
   racine fourni redirige tout vers `public/` et **bloque** `.env`, `src/`,
   `vendor/`, `data/` et les documents projet.
3. Créer `.env` sur le serveur (jamais dans un dépôt).
4. Activer HTTPS (certificat inclus chez la plupart des hébergeurs).
5. Ajouter le cron de reprise : `*/5 * * * * curl -s "https://future-profile.iticparis.com/api/retry?key=<STATS_KEY>"`.
6. Derrière un reverse proxy, mettre `TRUST_PROXY=true`.

## Points à faire valider par le Pôle Communication

- **Thème** : le CDC (EF-01) mentionne un thème **clair** ; cette version est construite
  sur la maquette **dark** à la demande du développeur — `future-profile-itic-brand.html`
  (thème clair) reste disponible à la racine si l'arbitrage évolue. Le contraste du
  thème sombre a été conservé (§4.2 accessibilité).
- **URLs de fiches formations** (extraites du sitemap iticparis.com, deux à confirmer) :
  « Bachelor Chargé de Développement des RH » → page `bachelor-ressources-humaines`,
  et « Mastère Expert Développeur FullStack » → page `expert-lead-developpeur-fullstack`.
- **Lien de politique de confidentialité** : la page publique existante est
  `…/tech-school/politique-de-confidentialite` ; formulation du consentement et
  politique à valider par le référent RGPD (§4.1, public mineur possible).
