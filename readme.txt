=== Oueb WP Backup ===
Contributors: lagenceoueb
Tags: backup, restore, s3, sftp, encryption
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up and restore WordPress to European storage providers, with encryption and resumable steps.

== Description ==

Oueb WP Backup saves the database and the files of a WordPress site, sends the archive to one or more storages, and restores it from the administration.

* Storages: European S3 providers (Scaleway, OVHcloud, 3DS Outscale, Hetzner, IONOS, Infomaniak), SFTP, Infomaniak kDrive and a folder on the server.
* Encryption of the archives with XChaCha20-Poly1305, with an offline decryption tool.
* Schedule with WP-Cron, a trigger link or cron-job.org.
* Restore from the list of backups, from a storage or from an uploaded archive, with a backup of the current site first.
* Every step resumes after an interruption, on small hostings too.
* Import of the jobs and settings of BackWPup, without changing them.
* Multisite: one backup for the whole network, managed from the network admin.
* Database maintenance: check, repair and optimize the tables.
* WP-CLI commands: `wp oueb-backup backup`, `restore`, `list`, `db`, and more.

Oueb WP Backup is a fork of BackWPup 4.1.7 by Inpsyde and WP Media, released under the GPL v2 or later.

== Changelog ==

= 0.1.0 =
* New engine, interface, storages, encryption and restore, written from scratch.
* Import of BackWPup jobs and settings.
* Multisite, database maintenance and WP-CLI commands.
