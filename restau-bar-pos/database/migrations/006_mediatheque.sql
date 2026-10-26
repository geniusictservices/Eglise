-- Médiathèque : toutes les images de l'instance (photos des produits, logos et couvertures),
-- réutilisables à plusieurs endroits. Une image n'est supprimée que depuis la médiathèque,
-- et seulement si elle n'est plus utilisée nulle part.
CREATE TABLE IF NOT EXISTS medias (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chemin     VARCHAR(255) NOT NULL,          -- chemin public : /uploads/<instance>/mediatheque/… ou /assets/…
    nom        VARCHAR(160) NOT NULL,          -- nom lisible, tiré du nom du fichier envoyé
    type_mime  VARCHAR(64)  NULL,
    largeur    INT UNSIGNED NULL,              -- NULL : complété au premier affichage si le fichier existe
    hauteur    INT UNSIGNED NULL,
    octets     INT UNSIGNED NULL,
    alt_fr     VARCHAR(255) NULL,              -- texte alternatif
    alt_en     VARCHAR(255) NULL,
    credit     VARCHAR(255) NULL,              -- auteur et licence, proposé quand on choisit l'image
    cree_par   INT UNSIGNED NULL,
    cree_le    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_medias_chemin (chemin),
    KEY idx_medias_cree (cree_le),
    CONSTRAINT fk_media_utilisateur FOREIGN KEY (cree_par) REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Images déjà utilisées : une ligne par chemin, nommée d'après ce qu'elle illustre,
-- avec le crédit existant quand il y en a un (dimensions et poids complétés plus tard).
INSERT IGNORE INTO medias (chemin, nom, credit)
SELECT DISTINCT u.chemin,
       (SELECT MIN(u2.nom) FROM (
            SELECT photo AS chemin, nom_fr AS nom FROM produits WHERE photo IS NOT NULL AND photo <> ''
            UNION ALL SELECT logo, CONCAT(nom, ' · logo') FROM etablissements WHERE logo IS NOT NULL AND logo <> ''
            UNION ALL SELECT couverture, CONCAT(nom, ' · couverture') FROM etablissements WHERE couverture IS NOT NULL AND couverture <> ''
        ) u2 WHERE u2.chemin = u.chemin),
       (SELECT MAX(u3.credit) FROM (
            SELECT photo AS chemin, NULLIF(photo_credit, '') AS credit FROM produits WHERE photo IS NOT NULL AND photo <> ''
            UNION ALL SELECT couverture, NULLIF(couverture_credit, '') FROM etablissements WHERE couverture IS NOT NULL AND couverture <> ''
        ) u3 WHERE u3.chemin = u.chemin)
FROM (
    SELECT photo AS chemin FROM produits WHERE photo IS NOT NULL AND photo <> ''
    UNION ALL SELECT logo FROM etablissements WHERE logo IS NOT NULL AND logo <> ''
    UNION ALL SELECT couverture FROM etablissements WHERE couverture IS NOT NULL AND couverture <> ''
) u;
