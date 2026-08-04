=== Z-Update — Agent ===
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 0.4.22
License: GPLv2 or later

Agent léger de Z-Update pour hébergements WordPress mutualisés.

== Installation ==

1. Installer et activer l’archive sur un site à superviser.
2. Ouvrir Réglages > Z-Update.
3. Saisir l’URL HTTPS du maître, le jeton à usage unique et l’hébergement.
4. Vérifier que la synchronisation, la santé et la sauvegarde UpdraftPlus apparaissent sur le maître.

== Changelog ==

= 0.4.22 =

* Transmet au maître l’état de DISABLE_WP_CRON pour supervision centralisée.

= 0.4.21 =

* Ajoute le diagnostic WP-Cron natif et la détection des appels cron externes.

= 0.4.20 =
* Ajout de la sélection des pools Externe et Ekinox 1/2/3 lors de l’appairage.

= 0.4.19 =
* Datation et expiration du marqueur UpdraftPlus en cours pour éviter les faux blackouts côté maître.

= 0.4.12 =
* Alerte locale si le maître reste injoignable au-delà du délai de secours configuré.
* Affichage du dernier contact maître dans l’écran Réglages > Z-Update.

= 0.4.5 =
* Synchronisation de version avec le maître 0.4.5.

= 0.4.4 =
* Remplacement de la vérification GitHub API par un manifest JSON statique pour éviter les erreurs 403.

= 0.4.3 =
* Les mises à jour WordPress orchestrées ne nécessitent plus de validation manuelle.
* Remontée et exécution des mises à jour cœur marquées upgrade ou autoupdate.

= 0.4.2 =
* Retransmission persistante des résultats jusqu’à leur confirmation par le maître.
* Vérification stricte de la version cible avant et après chaque mise à jour.
* Correction de la version cible remontée pour les mises à jour du cœur WordPress.

= 0.4.1 =
* Plugin Update Checker et vérification RSA-SHA256 des paquets de mise à jour.
