# EzGpao sur [DOLIBARR ERP CRM](https://dolibarr.org)

GPAO simplifiée : relie les kits, les nomenclatures (BOM), les commandes client et les ordres
de fabrication (OF) du cœur Dolibarr, sans objet propre ni dépendance à Saturne.

## Informations

- Numéro du module : 436370
- Éditeur : [Evarisk](https://evarisk.com)
- Licence : GPLv3
- Compatibilité : Dolibarr 23.0 et suivants, PHP 7.4 et suivants
- Dépendances : modules Produits, Nomenclatures (BOM) et Fabrication (MRP), activés
  automatiquement avec EzGpao ; Commandes client pour la partie commande → OF

## Fonctionnalités

### Kit → nomenclature

Un kit assemblé à la main devient une nomenclature autonome :

- bouton **Convertir le kit en nomenclature** sur la fiche d'un produit kit ;
- action de masse **Convertir les kits en nomenclatures** sur la liste des produits.

Pour chaque kit, une nomenclature de fabrication **validée** est créée avec les composants et
quantités du kit (quantité produite : 1). Un composant marqué « sans mouvement de stock » dans
le kit garde ce réglage dans la nomenclature. La composition du kit est ensuite **supprimée** du
produit, et la nomenclature devient sa nomenclature par défaut s'il n'en avait pas.

La nomenclature n'est **pas synchronisée** avec l'ancien kit. Supprimer le kit est indispensable :
Dolibarr refuse de créer un ordre de fabrication sur un produit qui est encore un kit.

Un kit dont le produit a déjà une nomenclature validée n'est pas converti. Tout se fait dans une
transaction : en cas d'échec, le kit reste intact.

### Commande → ordres de fabrication

- bouton **Créer les ordres de fabrication** sur une commande validée ou en cours de livraison ;
- action de masse du même nom sur la liste des commandes.

Un OF **brouillon** est créé pour chaque ligne de produit dont le produit a une nomenclature de
fabrication validée (la nomenclature par défaut du produit d'abord, sinon la plus récente), pour
la quantité commandée. L'OF reprend le tiers, le projet et la date de livraison prévue de la
commande, ainsi que l'entrepôt de la nomenclature, et il est lié à la commande.

Les lignes sans nomenclature sont signalées. Relancer l'action ne crée pas de doublon : une ligne
déjà couverte par un OF lié à la commande (et non annulé) est ignorée.

Le stock n'est pas contrôlé : toute la quantité commandée est mise en fabrication. Le contrôle
du stock est prévu pour une version ultérieure.

## Droits

EzGpao n'ajoute aucun droit et s'appuie sur ceux du cœur :

- conversion d'un kit : créer/modifier les nomenclatures **et** créer/modifier les produits ;
- création des OF : créer/modifier les ordres de fabrication **et** lire les commandes.
