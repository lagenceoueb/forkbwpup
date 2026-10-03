<?php
/**
 * Fournisseurs de stockage retenus et leurs critères.
 *
 * @package Oueb_WP_Backup
 * @since 0.1.0
 */

/**
 * Renvoie les fournisseurs de stockage S3 retenus par le cadrage du projet.
 *
 * Chaque fournisseur remplit les trois critères éliminatoires : siège et
 * centres de données dans l'UE ou en Suisse, maison mère européenne,
 * électricité renouvelable prouvée par une source publique. Les autres
 * critères sont affichés à titre indicatif. Chaque fait porte sa source.
 *
 * Écartés à la vérification du 3 octobre 2026 : Exoscale (maison mère
 * finale América Movil, Mexique ; zone de Sofia à environ 75 % d'électricité
 * renouvelable) et Clever Cloud (énergie « bas carbone » sans preuve
 * d'électricité renouvelable).
 *
 * @since 0.1.0
 *
 * @return array[] Fournisseurs, indexés par identifiant.
 */
function oueb_storage_providers() {
	$providers = array(
		'scaleway'   => array(
			'name'     => 'Scaleway',
			'country'  => 'FR',
			'criteria' => array(
				'location'  => array( 'Siège à Paris. Régions Paris, Amsterdam, Varsovie et Milan.', 'https://www.scaleway.com/en/docs/object-storage/concepts/' ),
				'parent'    => array( 'Filiale du groupe iliad (France).', 'https://en.wikipedia.org/wiki/Iliad_SA' ),
				'renewable' => array( 'Électricité 100 % éolienne ou hydraulique, avec garanties d’origine.', 'https://www.scaleway.com/en/environmental-leadership/' ),
				'pue'       => array( '1,37 en moyenne (2024), 1,25 pour DC5 à Paris.', 'https://www.scaleway.com/en/environmental-leadership/' ),
				'cooling'   => array( 'Refroidissement par l’air extérieur ; DC5 sans climatisation (adiabatique).', 'https://www.scaleway.com/en/environmental-leadership/' ),
				'labels'    => array( 'ISO 14001, ISO 50001, Code de conduite européen des centres de données.', 'https://www-uploads.scaleway.com/Impact_Report2022_A4_EN_8948d81472.pdf' ),
				'security'  => array( 'Object Storage certifié ISO 27001 et HDS. SecNumCloud en cours.', 'https://www.scaleway.com/en/security-and-compliance/' ),
			),
			'regions'  => array(
				'fr-par' => array( 'Paris', 'FR', 'https://s3.fr-par.scw.cloud', false ),
				'nl-ams' => array( 'Amsterdam', 'NL', 'https://s3.nl-ams.scw.cloud', false ),
				'pl-waw' => array( 'Varsovie', 'PL', 'https://s3.pl-waw.scw.cloud', false ),
				'it-mil' => array( 'Milan', 'IT', 'https://s3.it-mil.scw.cloud', false ),
			),
			'notes'    => 'Envoi multipart limité à 1 000 parties : environ 10 Go par archive.',
		),
		'ovhcloud'   => array(
			'name'     => 'OVHcloud',
			'country'  => 'FR',
			'criteria' => array(
				'location'  => array( 'Siège à Roubaix. Régions en France, en Italie, en Allemagne et en Pologne.', 'https://help.ovhcloud.com/csm/en-public-cloud-storage-s3-location?id=kb_article_view&sysparm_article=KB0047384' ),
				'parent'    => array( 'OVH Groupe SA, coté à Paris, contrôlé par la famille fondatrice.', 'https://corporate.ovhcloud.com/en/investor-relations/urd/' ),
				'renewable' => array( '100 % d’électricité renouvelable sur l’exercice 2025 pour les centres de données détenus par OVHcloud.', 'https://corporate.ovhcloud.com/sites/default/files/2025-11/sustainability_statement.pdf' ),
				'pue'       => array( '1,24 en moyenne fin 2025.', 'https://corporate.ovhcloud.com/sites/default/files/2025-11/sustainability_statement.pdf' ),
				'cooling'   => array( 'Refroidissement des serveurs à l’eau, sans climatisation dans la plupart des salles.', 'https://corporate.ovhcloud.com/sites/default/files/2025-11/sustainability_statement.pdf' ),
				'labels'    => array( 'ISO 50001 (centres de données français), ISO 14001 (Gravelines, Roubaix).', 'https://www.ovhcloud.com/en/compliance/iso-50001/' ),
				'security'  => array( 'Object Storage certifié ISO 27001, 27017, 27018 et 27701. HDS disponible.', 'https://docs.ovhcloud.com/en/guides/account-and-service-management/account-information/security-certifications' ),
			),
			'regions'  => array(
				'gra'          => array( 'Gravelines', 'FR', 'https://s3.gra.io.cloud.ovh.net', false ),
				'rbx'          => array( 'Roubaix', 'FR', 'https://s3.rbx.io.cloud.ovh.net', false ),
				'sbg'          => array( 'Strasbourg', 'FR', 'https://s3.sbg.io.cloud.ovh.net', false ),
				'eu-west-par'  => array( 'Paris, 3 zones', 'FR', 'https://s3.eu-west-par.io.cloud.ovh.net', false ),
				'eu-south-mil' => array( 'Milan, 3 zones', 'IT', 'https://s3.eu-south-mil.io.cloud.ovh.net', false ),
				'de'           => array( 'Francfort', 'DE', 'https://s3.de.io.cloud.ovh.net', false ),
				'waw'          => array( 'Varsovie', 'PL', 'https://s3.waw.io.cloud.ovh.net', false ),
			),
			'notes'    => 'La région de Londres est exclue : hors Union européenne. La preuve d’électricité renouvelable ne couvre pas explicitement les régions Paris et Milan (3 zones).',
		),
		'outscale'   => array(
			'name'     => '3DS Outscale',
			'country'  => 'FR',
			'criteria' => array(
				'location'  => array( 'Siège à Saint-Cloud. Centres de données en Île-de-France.', 'https://docs.outscale.com/en/userguide/API-Endpoints-Reference.html' ),
				'parent'    => array( 'Filiale à 100 % de Dassault Systèmes (France).', 'https://www.lemondeinformatique.fr/actualites/lire-dassault-systemes-met-la-main-sur-outscale-68575.html' ),
				'renewable' => array( '100 % d’énergie renouvelable dans les centres de données partenaires en France.', 'https://en.outscale.com/our-sustainable-development-commitments/environment/' ),
				'pue'       => array( '', '' ),
				'cooling'   => array( '', '' ),
				'labels'    => array( 'Centres de données ISO 50001 et ISO 14001, alignés sur le Code de conduite européen.', 'https://en.outscale.com/our-sustainable-development-commitments/environment/' ),
				'security'  => array( 'SecNumCloud 3.2 pour la région cloudgouv-eu-west-1, stockage objet compris.', 'https://support.outscale.com/hc/en-us/articles/4421624240667-What-are-the-OUTSCALE-services-are-available-on-the-cloudgouv-eu-west-1b-AZ' ),
			),
			'regions'  => array(
				'eu-west-2' => array( 'Paris', 'FR', 'https://oos.eu-west-2.outscale.com', false ),
			),
			'notes'    => 'La région SecNumCloud (https://oos.cloudgouv-eu-west-1.outscale.com) est réservée aux contrats SecNumCloud : renseignez-la comme endpoint personnalisé.',
		),
		'hetzner'    => array(
			'name'     => 'Hetzner',
			'country'  => 'DE',
			'criteria' => array(
				'location'  => array( 'Siège à Gunzenhausen. Régions Falkenstein, Nuremberg et Helsinki.', 'https://docs.hetzner.com/storage/object-storage/getting-started/using-s3-api-tools/' ),
				'parent'    => array( 'Entreprise familiale allemande, dirigée par son fondateur, sans investisseur extérieur.', 'https://career.hetzner.com/en/unsere-story/' ),
				'renewable' => array( 'Hydraulique en Allemagne ; hydraulique et éolien en Finlande.', 'https://docs.hetzner.com/general/others/sustainability-faqs/' ),
				'pue'       => array( 'Entre 1,10 et 1,16.', 'https://www.hetzner.com/unternehmen/nachhaltigkeit/' ),
				'cooling'   => array( 'Refroidissement par l’air extérieur jusqu’à 98 % de l’année ; chaleur récupérée pour les bureaux.', 'https://www.hetzner.com/unternehmen/nachhaltigkeit/' ),
				'labels'    => array( 'Pas encore certifié ISO 50001.', 'https://docs.hetzner.com/general/others/sustainability-faqs/' ),
				'security'  => array( 'Centres de données certifiés ISO 27001:2022.', 'https://docs.hetzner.com/general/infrastructure-and-availability/data-centers-and-connection/' ),
			),
			'regions'  => array(
				'fsn1' => array( 'Falkenstein', 'DE', 'https://fsn1.your-objectstorage.com', false ),
				'nbg1' => array( 'Nuremberg', 'DE', 'https://nbg1.your-objectstorage.com', false ),
				'hel1' => array( 'Helsinki', 'FI', 'https://hel1.your-objectstorage.com', false ),
			),
			'notes'    => '',
		),
		'ionos'      => array(
			'name'     => 'IONOS',
			'country'  => 'DE',
			'criteria' => array(
				'location'  => array( 'Siège à Montabaur. Régions Francfort, Berlin et Logroño (Espagne).', 'https://docs.ionos.com/cloud/storage-and-backup/ionos-object-storage/endpoints' ),
				'parent'    => array( 'IONOS Group SE, détenu à 63,8 % par United Internet (Allemagne).', 'https://www.ionos-group.com/fileadmin/Publications/Berichte/FY_2025/IONOS_Consolidated_Financial_Statements_2025.pdf' ),
				'renewable' => array( 'Centres de données alimentés à 100 % en électricité renouvelable (2025).', 'https://www.ionos-group.com/fileadmin/Publications/Berichte/FY_2025/IONOS_Sustainability_Report_2025.pdf' ),
				'pue'       => array( '1,37 en moyenne pondérée (2025).', 'https://www.ionos-group.com/fileadmin/Publications/Berichte/FY_2025/IONOS_Sustainability_Report_2025.pdf' ),
				'cooling'   => array( 'Refroidissement par l’air extérieur.', 'https://www.ionos-group.com/fileadmin/Publications/Berichte/IONOS_Group_SE_Sustainability_Report_2024.pdf' ),
				'labels'    => array( 'ISO 50001 et ISO 14001 sur tous les centres de données en propre (2025).', 'https://www.ionos-group.com/fileadmin/Publications/Berichte/FY_2025/IONOS_Sustainability_Report_2025.pdf' ),
				'security'  => array( 'S3 Object Storage attesté BSI C5 ; ISO 27001.', 'https://www.ionos-group.com/investor-relations/publications/announcements/ionos-receives-c5-certification-for-compute-engine-cloud-cubes-and-s3-object-storage.html' ),
			),
			'regions'  => array(
				'de'           => array( 'Francfort', 'DE', 'https://s3.eu-central-1.ionoscloud.com', true ),
				'eu-central-2' => array( 'Berlin', 'DE', 'https://s3.eu-central-2.ionoscloud.com', true ),
				'eu-south-2'   => array( 'Logroño', 'ES', 'https://s3.eu-south-2.ionoscloud.com', true ),
			),
			'notes'    => 'La région de Francfort s’appelle « de », bien que son adresse contienne eu-central-1.',
		),
		'infomaniak' => array(
			'name'     => 'Infomaniak',
			'country'  => 'CH',
			'criteria' => array(
				'location'  => array( 'Siège à Genève. Centres de données en Suisse.', 'https://www.infomaniak.com/en/support/faq/71/geographic-location-of-infomaniak-servers-and-datacenters' ),
				'parent'    => array( 'Contrôlé majoritairement par la Fondation Infomaniak (Suisse).', 'https://news.infomaniak.com/en/infomaniak-foundation-sovereign-cloud/' ),
				'renewable' => array( 'Activités alimentées à 100 % par de l’électricité renouvelable locale et certifiée.', 'https://www.infomaniak.com/en/ecology' ),
				'pue'       => array( 'Inférieur à 1,1 en moyenne pour D4 à Genève.', 'https://news.infomaniak.com/en/infomaniak-inaugurates-a-revolutionary-data-center-that-recovers-100-of-its-energy-for-building-heating/' ),
				'cooling'   => array( 'D4 restitue toute l’électricité consommée sous forme de chaleur au chauffage urbain de Genève.', 'https://news.infomaniak.com/en/infomaniak-inaugurates-a-revolutionary-data-center-that-recovers-100-of-its-energy-for-building-heating/' ),
				'labels'    => array( 'ISO 14001, ISO 50001, B Corp.', 'https://www.infomaniak.com/en/trust-center' ),
				'security'  => array( 'Centres de données certifiés ISO 27001.', 'https://www.infomaniak.com/en/swiss-backup' ),
			),
			'regions'  => array(
				'dc3-a' => array( 'Public Cloud 1', 'CH', 'https://s3.pub1.infomaniak.cloud', true ),
				'dc4-a' => array( 'Public Cloud 2', 'CH', 'https://s3.pub2.infomaniak.cloud', true ),
			),
			'notes'    => 'Pour Swiss Backup, l’adresse dépend du compte (s3.swiss-backup0N.infomaniak.com, région RegionOne) : renseignez-la comme endpoint personnalisé.',
		),
	);

	/**
	 * Filtre la liste des fournisseurs de stockage proposés.
	 *
	 * @since 0.1.0
	 *
	 * @param array[] $providers Fournisseurs, indexés par identifiant.
	 */
	return apply_filters( 'oueb_storage_providers', $providers );
}

/**
 * Renvoie la date de la dernière vérification des fournisseurs.
 *
 * @since 0.1.0
 *
 * @return string Date au format AAAA-MM-JJ.
 */
function oueb_storage_providers_checked_on() {
	return '2026-10-03';
}

/**
 * Affiche la fiche de chaque fournisseur, critères et sources compris.
 *
 * @since 0.1.0
 */
function oueb_storage_providers_cards() {
	$labels = array(
		'location'  => __( 'Location', 'oueb-wp-backup' ),
		'parent'    => __( 'Parent company', 'oueb-wp-backup' ),
		'renewable' => __( 'Renewable electricity', 'oueb-wp-backup' ),
		'pue'       => __( 'PUE (energy efficiency)', 'oueb-wp-backup' ),
		'cooling'   => __( 'Cooling and heat reuse', 'oueb-wp-backup' ),
		'labels'    => __( 'Environmental labels', 'oueb-wp-backup' ),
		'security'  => __( 'Security certifications', 'oueb-wp-backup' ),
	);
	?>
	<details class="oueb-providers">
		<summary><?php esc_html_e( 'How were these providers chosen?', 'oueb-wp-backup' ); ?></summary>
		<p>
			<?php esc_html_e( 'Each provider has its headquarters and data centers in the European Union or Switzerland, has no parent company outside Europe, and proves that its electricity is renewable. The other information is given for reference, with its source.', 'oueb-wp-backup' ); ?>
			<?php
			/* translators: %s: date of the last check. */
			echo esc_html( sprintf( __( 'Last checked on %s.', 'oueb-wp-backup' ), date_i18n( get_option( 'date_format' ), strtotime( oueb_storage_providers_checked_on() ) ) ) );
			?>
		</p>
		<?php foreach ( oueb_storage_providers() as $provider ) : ?>
			<h4><?php echo esc_html( $provider['name'] . ' (' . $provider['country'] . ')' ); ?></h4>
			<table class="widefat striped">
				<caption class="screen-reader-text">
					<?php
					/* translators: %s: provider name. */
					echo esc_html( sprintf( __( 'Criteria for %s', 'oueb-wp-backup' ), $provider['name'] ) );
					?>
				</caption>
				<tbody>
				<?php foreach ( $labels as $key => $label ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $label ); ?></th>
						<td>
							<?php
							$criterion = $provider['criteria'][ $key ];
							if ( '' === $criterion[0] ) {
								esc_html_e( 'Not published.', 'oueb-wp-backup' );
							} else {
								echo esc_html( $criterion[0] ) . ' ';
								/* translators: %s: criterion name. */
								echo '<a href="' . esc_url( $criterion[1] ) . '">' . esc_html__( 'Source', 'oueb-wp-backup' ) . '<span class="screen-reader-text"> ' . esc_html( sprintf( __( 'for: %s', 'oueb-wp-backup' ), $label ) ) . '</span></a>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( '' !== $provider['notes'] ) : ?>
				<p class="description"><?php echo esc_html( $provider['notes'] ); ?></p>
			<?php endif; ?>
		<?php endforeach; ?>
	</details>
	<?php
}
