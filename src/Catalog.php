<?php

declare(strict_types=1);

namespace App;

/**
 * Valeurs autorisées — garantissent l'intégrité des listes déroulantes
 * HubSpot (document « Propriétés HubSpot Future Profile » v4) :
 * aucune valeur hors liste ne peut être écrite dans le portail.
 */
final class Catalog
{
    /** 17 profils combinés + 10 profils individuels (noms maquette v2) = 27. */
    public const PROFILS = [
        'Architecte du Futur', 'Intelligence Augmentée', 'Digital Transformer', 'Builder',
        'Creative Engineer', 'Tech Leader', 'Visionnaire', 'Global Creative', 'Stratège Créatif',
        'Directeur Créatif', 'Orchestrateur', 'Strategic Mind', 'Global Player', 'Founder',
        'Disrupteur', 'Ambassadeur Global', 'Shapeshifter',
        'Digital Native', 'Game Changer', 'The Deal', 'The Founder', 'Storyteller',
        'World Player', 'The Captain', 'All-Terrain', 'Wild Card', 'Data Mind',
    ];

    /** 23 formations présentielles (11 BTS, 6 Bachelors, 6 Mastères) — PGE exclu. */
    public const FORMATIONS = [
        'BTS Services Informatiques aux Organisations',
        'BTS Conseil et Commercialisation de Solutions Techniques',
        'BTS Communication',
        'BTS Management Commercial Opérationnel',
        'BTS Négo et Digitalisation de la Relation Client',
        "BTS Support à l'Action Managériale",
        'BTS Commerce International',
        'BTS Gestion de la PME',
        'BTS Comptabilité et Gestion',
        'BTS Banque',
        'BTS Assurance',
        'Bachelor Ingénierie CyberSécurité Cloud Infrastructures Sécurisées',
        'Bachelor Concepteur Développeur FullStack',
        'Bachelor Chargé de Marketing Digital et e-Commerce',
        'Bachelor Commerce International',
        'Bachelor Chargé de Développement des RH',
        'Bachelor Finance Contrôle et Gestion',
        'Mastère Expert CyberSécurité Cloud Computing',
        'Mastère Expert Développeur FullStack',
        'Mastère Manager des Stratégies Marketing et Com',
        'Mastère Manager Développement Commercial',
        'Mastère Management Ressources Humaines',
        'Mastère Management Stratégique et Financier des Organisations',
    ];

    /** Événements du tunnel de conversion (EF-11). */
    public const EVENTS = [
        'page_view', 'level_screen', 'level_selected', 'test_started', 'q_answered',
        'capture_shown', 'lead_submitted', 'test_completed', 'abandon',
        'share_clicked', 'restart_clicked',
    ];
}
