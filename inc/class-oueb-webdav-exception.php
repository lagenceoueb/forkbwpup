<?php
/**
 * Exception levée par le client WebDAV.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Erreur renvoyée par un serveur WebDAV, ou erreur de transport.
 *
 * Le code de l'exception porte le statut HTTP, 0 pour une erreur de transport.
 *
 * @since 0.1.0
 */
class Oueb_Webdav_Exception extends Exception {
}
