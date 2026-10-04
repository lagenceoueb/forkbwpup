<?php
/**
 * Échec définitif d'une étape.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

declare( strict_types=1 );

namespace Oueb\WpBackup\Engine;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Erreur qu'un nouvel essai ne corrigerait pas : l'exécution échoue tout de suite.
 *
 * @since 0.1.0
 */
final class Step_Failure extends RuntimeException {
}
