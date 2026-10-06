# Traductions de Waumini

L'interface est écrite en **français**. Chaque autre langue a son fichier `lang/{code}.json` :
`sw` (kiswahili), `ln` (lingála), `kg` (kikongo), `lua` (tshiluba).
Tant qu'un texte n'est pas traduit, il s'affiche en français.

## Pour un traducteur

1. Un développeur exporte les textes : `php artisan waumini:traductions sw`
2. Ouvrez `lang/a-traduire/sw.csv` dans Excel ou LibreOffice.
3. Remplissez la colonne **traduction** : ne touchez pas à la colonne **francais**.
   Les mots qui commencent par `:` (par exemple `:name`, `:count`) doivent rester tels quels.
   Les textes avec `|` ont une forme au singulier puis au pluriel.
4. Enregistrez en CSV (séparateur point-virgule, UTF-8) et renvoyez le fichier.
5. Le développeur l'importe : `php artisan waumini:traductions sw --importer`

## État

| Langue | État |
|---|---|
| Kiswahili | Navigation et écrans principaux traduits (première version à relire par un locuteur) |
| Lingála | À traduire |
| Kikongo | À traduire |
| Tshiluba | À traduire |
