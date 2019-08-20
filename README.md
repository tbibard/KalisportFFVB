# KalisportFFVB

Application en mode console permettant d'adapter des fichiers d'export de la Fédération Française de Volley-Ball (FFVB) en vue d'un import vers la plateforme Kalisport.
L'application gère les imports de clubs adverses et les fichiers de programme (calendrier).

## Installation


Pré-requis : 
- PHP (cli) et [composer][1].
- Instance [Kalisport][2] fonctionnelle.

```
$ mkdir [Votre-Dossier] && cd [Votre-Dossier]
$ git clone https://github.com/tbibard/KalisportFFVB.git .
$ composer install
```

Créer un fichier .env (à partir du .env.dist) afin d'indiquer l'identifiant FFVB de votre CLUB, exemple pour le SC Sélestat Volley-Ball : "0673909".

## Usage

### Liste des commandes disponibles et aide

```
$ bin/console
$ bin/console [command] --help
```

### Importer un programme/calendrier pour une équipe

1. Créer votre équipe sur la plateforme Kalisport et notez le champ abbréviation.
2. Récupérer un fichier d'export d'une équipe depuis le site de la fédération française de Volley-Ball (en haut de chaque page compétition / logo xls) 
3. Placer le fichier export de la FFVB dans le dossier input du projet (facultatif)
4. Générer les fichiers d'import vers Kalisport :
    ```
    $ bin/console ffvbcalendrier:build input/[votre-export-ffvb] ABBREVIATION-EQU-KALISPORT
    ```
5. Vous devrier obtenir dans le dossier output :
   - fichier des clubs adverses : import-clubs-adverses.csv
   - fichier de programme/calendrier pour l'équipe désignée : import-calendrier-[ABBREVIATION-EQU-KALISPORT].csv
6. Depuis votre instance Kalisport, importer le fichier de clubs adverses puis le fichier de programme/calendrier.

A noter, au cas où deux équipes de votre club figureraient dans le même championnat l'option --ffvb-equipe="VOTRE EQUIPE" permet d'indiquer le nom, côté FFVB, que vous souhaitez gérer (le nom est celui-ci utilisé dans le fichier de calendrier de la FFVB).


[1]: https://getcomposer.org
[2]: https://www.kalisport.com