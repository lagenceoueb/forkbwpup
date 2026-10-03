<?php
/**
 * Exception levée par le client de cron-job.org.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Erreur renvoyée par l'API de cron-job.org, ou erreur de transport.
 *
 * Le code de l'exception porte le statut HTTP, 0 pour une erreur de transport.
 *
 * @since 0.1.0
 */
class Oueb_Cronjob_Org_Exception extends Exception {
}
