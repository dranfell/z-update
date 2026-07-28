=== Z-Update — Agent ===
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 0.4.4
License: GPLv2 or later

Agent léger de Z-Update pour hébergements WordPress mutualisés.

== Installation ==

1. Installer et activer l’archive sur un site à superviser.
2. Ouvrir Réglages > Z-Update.
3. Saisir l’URL HTTPS du maître, le jeton à usage unique et l’hébergement.
4. Vérifier que la synchronisation, la santé et la sauvegarde UpdraftPlus apparaissent sur le maître.

== Changelog ==

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
