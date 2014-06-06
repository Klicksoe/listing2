listing2
========

Cette appli web est juste un TEST, un paliatif à un changement dans la structure de couchpotato ne fournissant plus de date correcte sur l'ajout de films
En attendant une version plus complète avec plus d'options...

Installer composer https://getcomposer.org/download/

Puis lancer l'install des paquets via composer 
```
composer install
```

Installer les packages python nécesaires :
```
apt-get install python-mysqldb python-yaml
```

Importer le schéma de la DB

Configurer le fichier config.yml sur la base de config.yml.default

Configurer le fichier web/.htaccess sur la base de web/.htaccess.default

####TODO :
* modifier les scripts python pour n'update qu'une fois chaque instance et non chaque fois que celle-ci est renseignée
* partie administration
* ajout de headphones
* ajout d'un gestionnaire de BD
