# Livret de présentation

Un livret de 20 pages A5 qui présente Waumini aux pasteurs et aux responsables d'églises, avec de vraies captures de la communauté de démonstration.

## Produire les PDF

Les captures (`captures/`, sans repères numérotés) se refont sur une base de démonstration fraîche, serveur lancé :

```bash
php artisan migrate:fresh --seed && php artisan serve
node scripts/manuel/captures.mjs --livret
```

Puis le livret :

```bash
php artisan waumini:livret /chemin/livret --site=https://waumini.com --telephone="+243 …"
node scripts/livret/pdf.mjs /chemin/livret
```

`--site` sert au QR code du dos (il ouvre `/demo`) et à l'adresse affichée ; `--telephone`, au numéro d'appel et WhatsApp de Genius ICT. Le texte se modifie dans `resources/views/livret/livret.blade.php`.

Deux fichiers sortent :

- **`livret-waumini.pdf`** : les 20 pages A5 dans l'ordre, à partager par WhatsApp ou e-mail ;
- **`livret-waumini-impression.pdf`** : les pages imposées deux par deux sur 10 faces A4 en paysage.

## Imprimer en livret

1. Imprimer `livret-waumini-impression.pdf` sur du A4, **recto verso, retourner sur le bord court**, à 100 % (sans « ajuster à la page »). Cela donne 5 feuilles.
2. Garder les feuilles dans l'ordre, les plier en deux ensemble.
3. Agrafer deux fois au pli (agrafeuse à long bras), ou faire relier par un imprimeur.

Un papier de 100 à 120 g rend le livret plus agréable ; la couverture peut être imprimée à part sur un papier plus épais (la feuille 1 porte la couverture et le dos). Les imprimantes de bureau laissent une marge blanche de quelques millimètres autour des fonds bleus : pour un fond jusqu'au bord, confier le fichier à un imprimeur.
