=== Oueb WP Backup ===
Tags: backup, restore, sftp, s3, europe
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up and restore WordPress to European or Swiss hosts, or to your own server. Fork of BackWPup 4.1.7.

== Description ==

Oueb WP Backup backs up the database and files of a WordPress site, then sends the archive to a storage chosen on sovereignty and renewable energy criteria. It is edited by L'agence Oueb (https://lagenceoueb.tech) for site administrators who manage their backups themselves.

This version is under development. Do not install it on a production site.

= Storage =

* S3 compatible providers with headquarters and data centers in the European Union or Switzerland: Scaleway, OVHcloud, 3DS Outscale, Hetzner, IONOS, Infomaniak.
* Infomaniak kDrive, through WebDAV.
* Your own SFTP or FTP server.
* A folder on the site server, as an extra copy only.

= Scheduling =

* WP-Cron, which runs only when the site gets visits.
* A trigger link to call from the service of your choice.
* cron-job.org, a free and open source service hosted in Germany. The plugin creates and updates the remote job with your API key.

= Kept from BackWPup =

Database and file backups, restore from the admin, multisite, WP-CLI commands, database check, repair and optimization. Archive encryption still works for jobs set up with it in BackWPup, but it has no setting in this plugin yet.

= Origin =

Oueb WP Backup is a fork of BackWPup 4.1.7, developed by Inpsyde then WP Media, and distributed under the same license. It is neither affiliated with nor endorsed by the BackWPup editors.

== Installation ==

No release is published yet. Build the archive from the sources:

1. Clone https://github.com/lagenceoueb/forkbwpup.
2. Run `bin/build.sh`, which writes `build/oueb-wp-backup.zip`.
3. In WordPress, open Plugins > Add New Plugin > Upload Plugin and send that file.

Do not activate Oueb WP Backup on a site where BackWPup is active: both plugins use the same option names.

== Frequently Asked Questions ==

= Can I import my BackWPup jobs? =

The jobs and settings saved by BackWPup are read as they are. Jobs that used Dropbox, Amazon S3, Google Cloud Storage, Azure, Rackspace, SugarSync or email no longer send their backups: choose another storage in the job settings. Jobs that used Scaleway keep working.

= Why is my host not in the list? =

A provider is listed only if its headquarters and data centers are in the European Union or Switzerland, if it has no parent company outside Europe, and if a public source proves that its electricity is renewable. The sources are in `inc/oueb-providers.php`.

== Changelog ==

= 0.0.1 =
* Fork of BackWPup 4.1.7.
* Removed the storages hosted outside Europe, the Pro code and the calls to external services.
* Replaced the AWS SDK with a light S3 client.
* Added the SFTP and Infomaniak kDrive storages, and scheduling with cron-job.org.
* French translation of the texts added by the fork.

The changelog of BackWPup up to version 4.1.7 is in `changelog.txt`.
