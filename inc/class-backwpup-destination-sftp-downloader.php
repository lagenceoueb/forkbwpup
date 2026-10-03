<?php
/**
 * Téléchargement des sauvegardes depuis un serveur SFTP.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Rapatrie une sauvegarde SFTP par morceaux dans le dossier temporaire.
 *
 * @since 0.1.0
 */
final class BackWPup_Destination_Sftp_Downloader implements BackWPup_Destination_Downloader_Interface {

	/**
	 * Données du téléchargement.
	 *
	 * @since 0.1.0
	 * @var BackWpUp_Destination_Downloader_Data
	 */
	private $data;

	/**
	 * Connexion SFTP, ouverte au premier usage.
	 *
	 * @since 0.1.0
	 * @var Oueb_Sftp_Client|null
	 */
	private $client = null;

	/**
	 * Construit le téléchargement.
	 *
	 * @since 0.1.0
	 *
	 * @param BackWpUp_Destination_Downloader_Data $data Données du téléchargement.
	 */
	public function __construct( BackWpUp_Destination_Downloader_Data $data ) {
		$this->data = $data;
	}

	/**
	 * Renvoie la connexion SFTP, ouverte au premier appel.
	 *
	 * Ouvrir la connexion dans le constructeur plaçait ses erreurs (serveur
	 * hors ligne, clé du serveur changée) hors du try/catch du téléchargement :
	 * l'administrateur voyait une fenêtre bloquée au lieu du message.
	 *
	 * @since 0.1.0
	 *
	 * @return Oueb_Sftp_Client Connexion ouverte.
	 *
	 * @throws Oueb_Sftp_Exception Si la connexion échoue.
	 */
	private function client() {
		if ( null === $this->client ) {
			$this->client = ( new BackWPup_Destination_Sftp() )->connect( $this->data->job_id() );
		}

		return $this->client;
	}

	/**
	 * Télécharge une plage d'octets et l'ajoute au fichier local.
	 *
	 * @since 0.1.0
	 *
	 * @param int $start_byte Premier octet, inclus.
	 * @param int $end_byte   Dernier octet, inclus.
	 *
	 * @throws RuntimeException Si l'écriture locale échoue.
	 */
	public function download_chunk( $start_byte, $end_byte ) {
		$data = $this->client()->read( $this->data->source_file_path(), (int) $start_byte, (int) $end_byte - (int) $start_byte + 1 );

		// Le premier morceau recrée le fichier, les suivants l'allongent.
		$written = file_put_contents( $this->data->local_file_path(), $data, 0 === (int) $start_byte ? 0 : FILE_APPEND );

		if ( false === $written || strlen( $data ) !== $written ) {
			throw new RuntimeException( esc_html__( 'Could not write data to file.', 'oueb-wp-backup' ) );
		}
	}

	/**
	 * Renvoie la taille du fichier distant.
	 *
	 * @since 0.1.0
	 *
	 * @return int Taille en octets.
	 */
	public function calculate_size() {
		return $this->client()->size( $this->data->source_file_path() );
	}
}
