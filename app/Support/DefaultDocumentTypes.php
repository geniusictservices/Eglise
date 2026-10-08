<?php

namespace App\Support;

/** Les modèles fournis à chaque nouvelle communauté (à son siège) ; elle les adapte librement. */
class DefaultDocumentTypes
{
    private const FOOTER = 'En foi de quoi, la présente attestation lui est délivrée pour servir et valoir ce que de droit.';

    public static function all(): array
    {
        $intro = 'Je soussigné(e), **{signataire}**, {qualite_signataire} de {communaute}, ';

        return [
            ['key' => 'membership', 'show_photo' => true, 'name' => 'Attestation d’appartenance', 'title' => 'Attestation d’appartenance', 'code' => 'ATM', 'subject' => 'member',
                'signatory_title' => 'Pasteur', 'body' => $intro."atteste que **{civilite} {nom_officiel}**, {né} le {date_naissance} à {lieu_naissance}, est membre de notre communauté sous le numéro {numero_membre}, depuis le {date_adhesion}.\n\n{Il} y est inscrit{e} avec le statut : {statut}.\n\n".self::FOOTER],
            ['key' => 'baptism', 'name' => 'Attestation de baptême', 'title' => 'Attestation de baptême', 'code' => 'ABA', 'subject' => 'entry', 'life_event_type' => 'baptism',
                'signatory_title' => 'Pasteur', 'body' => $intro."atteste que **{civilite} {nom_officiel}**, {né} le {date_naissance} à {lieu_naissance}, a reçu le baptême le **{evenement_date}** à {evenement_lieu}, des mains de {evenement_officiant}.\n\nCe baptême est inscrit au registre des baptêmes sous la référence {evenement_registre}.\n\n".self::FOOTER],
            ['key' => 'marriage', 'name' => 'Certificat de mariage religieux', 'title' => 'Certificat de mariage religieux', 'code' => 'CMR', 'subject' => 'entry', 'life_event_type' => 'marriage',
                'signatory_title' => 'Pasteur', 'body' => $intro."certifie que **{nom_complet}** et **{conjoint}** ont été unis par le mariage religieux le **{evenement_date}** à {evenement_lieu}, devant {evenement_officiant}, en présence de {evenement_temoins}.\n\nCe mariage est inscrit au registre des mariages sous la référence {evenement_registre}.\n\nEn foi de quoi, le présent certificat est délivré pour servir et valoir ce que de droit."],
            ['key' => 'child_presentation', 'name' => 'Attestation de présentation d’enfant', 'title' => 'Attestation de présentation d’enfant', 'code' => 'APE', 'subject' => 'entry', 'life_event_type' => 'child_presentation',
                'signatory_title' => 'Pasteur', 'body' => $intro."atteste que l’enfant **{nom_officiel}**, {né} le {date_naissance} à {lieu_naissance}, enfant de {parents}, a été présenté{e} au Seigneur le **{evenement_date}** à {evenement_lieu}, par {evenement_officiant}.\n\n".self::FOOTER],
            ['key' => 'recommendation', 'show_photo' => true, 'name' => 'Lettre de recommandation', 'title' => 'Lettre de recommandation', 'code' => 'LRE', 'subject' => 'member',
                'signatory_title' => 'Pasteur', 'fields' => [['key' => 'destinataire', 'label' => 'Communauté d’accueil', 'type' => 'text', 'required' => true]],
                'body' => "À {destinataire},\n\nBien-aimés dans le Seigneur,\n\nNous vous recommandons **{civilite} {nom_officiel}**, membre de notre communauté sous le numéro {numero_membre} depuis le {date_adhesion}, qui se rend chez vous.\n\n{Il} a toujours été fidèle au milieu de nous. Nous vous prions de l’accueillir fraternellement et de l’accompagner dans sa marche avec le Seigneur.\n\nQue la grâce du Seigneur soit avec vous."],
            ['key' => 'service', 'show_photo' => true, 'name' => 'Attestation de service', 'title' => 'Attestation de service', 'code' => 'ASE', 'subject' => 'member',
                'signatory_title' => 'Pasteur', 'body' => $intro."atteste que **{civilite} {nom_officiel}**, membre de notre communauté, y exerce la fonction de **{fonction}** depuis le {fonction_depuis}.\n\n".self::FOOTER],
            ['key' => 'mission', 'show_photo' => true, 'name' => 'Ordre de mission', 'title' => 'Ordre de mission', 'code' => 'ODM', 'subject' => 'member', 'signatory_title' => 'Pasteur',
                'fields' => [['key' => 'destination', 'label' => 'Destination', 'type' => 'text', 'required' => true], ['key' => 'objet', 'label' => 'Objet de la mission', 'type' => 'text', 'required' => true],
                    ['key' => 'du', 'label' => 'Du', 'type' => 'date', 'required' => true], ['key' => 'au', 'label' => 'Au', 'type' => 'date', 'required' => true]],
                'body' => "{communaute} charge **{civilite} {nom_officiel}**, {fonction}, de se rendre à **{destination}** du {du} au {au}, pour : {objet}.\n\nLes autorités civiles, militaires et religieuses sont priées de lui faciliter l’accomplissement de sa mission."],
            ['key' => 'summons', 'name' => 'Convocation', 'title' => 'Convocation', 'code' => 'CON', 'subject' => 'member', 'signatory_title' => 'Pasteur',
                'fields' => [['key' => 'objet', 'label' => 'Objet', 'type' => 'text', 'required' => true], ['key' => 'le', 'label' => 'Date', 'type' => 'date', 'required' => true],
                    ['key' => 'heure', 'label' => 'Heure', 'type' => 'text', 'required' => false], ['key' => 'lieu', 'label' => 'Lieu', 'type' => 'text', 'required' => true]],
                'body' => "**{civilite} {nom_officiel}** est prié{e} de se présenter le **{le}** à {heure}, à {lieu}, pour : {objet}.\n\nSa présence est indispensable."],
            ['key' => 'letter', 'name' => 'Lettre', 'title' => 'Lettre', 'code' => 'LET', 'subject' => 'free', 'signatory_title' => 'Pasteur',
                'fields' => [['key' => 'objet', 'label' => 'Objet', 'type' => 'text', 'required' => true], ['key' => 'message', 'label' => 'Message', 'type' => 'long', 'required' => true]],
                'body' => "Objet : **{objet}**\n\n{message}"],
        ];
    }
}
