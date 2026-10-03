<?php

declare(strict_types=1);

/**
 * Class BackWPup_S3_Destination.
 */
final class BackWPup_S3_Destination
{
    /**
     * @var array
     */
    private $options;

    /**
     * BackWPup_S3_Destination constructor.
     */
    private function __construct(array $options)
    {
        $defaults = [
            'label' => __('Custom S3 destination', 'backwpup'),
            'endpoint' => '',
            'region' => '',
            'multipart' => true,
            'only_path_style_bucket' => false,
            'removed' => false,
        ];

        $this->options = array_merge($defaults, $options);
    }

    /**
     * Get list of S3 destinations.
     *
     * This list can be extended by using the `backwpup_s3_destination` filter.
     */
    public static function options(): array
    {
        $options = [];

        foreach (oueb_storage_providers() as $provider_id => $provider) {
            foreach ($provider['regions'] as $region_id => $region) {
                [$city, $country, $endpoint, $path_style] = $region;
                $options[$provider_id . '-' . $region_id] = [
                    'label' => sprintf('%1$s, %2$s (%3$s)', $provider['name'], $city, $country),
                    'region' => $region_id,
                    'endpoint' => $endpoint,
                    'only_path_style_bucket' => $path_style,
                ];
            }
        }

        return apply_filters('backwpup_s3_destination', $options);
    }

    /**
     * Get the AWS destination of the passed id or base url.
     *
     * @param string $idOrUrl Destination id or endpoint
     */
    public static function fromOption(string $idOrUrl): self
    {
        $destinations = self::options();
        $id = self::normalizeId($idOrUrl);

        if (isset($destinations[$id])) {
            return new self($destinations[$id]);
        }

        // An unknown id comes from a job saved with a removed service, such as Amazon S3.
        return new self(['removed' => $id !== '']);
    }

    /**
     * Translate a service id saved by BackWPup into the id of the same service here.
     *
     * Only Scaleway is offered under another id. The other BackWPup services
     * (Amazon S3, Google Cloud Storage, DigitalOcean, DreamHost) are removed.
     *
     * @param string $id Service id saved in the job.
     */
    public static function normalizeId(string $id): string
    {
        $legacy = [
            'scaleway-par' => 'scaleway-fr-par',
            'scaleway-ams' => 'scaleway-nl-ams',
        ];

        return $legacy[$id] ?? $id;
    }

    /**
     * Whether the job was saved with a service this plugin no longer offers.
     */
    public function isRemoved(): bool
    {
        return !empty($this->options['removed']);
    }

    /**
     * Get the AWS destination class from options array.
     *
     * @param array $optionsArr S3 options
     */
    public static function fromOptionArray(array $optionsArr): self
    {
        return new self($optionsArr);
    }

    /**
     * Get the AWS destination class from job ID.
     *
     * @param int $jobId The job ID to get options from
     */
    public static function fromJobId(int $jobId): self
    {
        $options = [
            'label' => __('Custom S3 destination', 'backwpup'),
            'endpoint' => BackWPup_Option::get($jobId, 's3base_url'),
            'region' => BackWPup_Option::get($jobId, 's3base_region'),
            'multipart' => !empty(BackWPup_Option::get($jobId, 's3base_multipart')),
            'only_path_style_bucket' => !empty(BackWPup_Option::get($jobId, 's3base_pathstylebucket')),
        ];

        return self::fromOptionArray($options);
    }

    /**
     * Get the S3 client.
     *
     * @param string $accessKey Access key
     * @param string $secretKey Encrypted secret key
     *
     * @throws Oueb_S3_Exception If no endpoint is configured
     */
    public function client($accessKey, $secretKey): Oueb_S3_Client
    {
        if ($this->isRemoved()) {
            throw new Oueb_S3_Exception(esc_html__('The S3 service saved in this job is no longer offered. Choose a European provider in the job settings.', 'oueb-wp-backup'));
        }

        if ($this->endpoint() === '') {
            throw new Oueb_S3_Exception(esc_html__('Choose an S3 service or enter its endpoint.', 'backwpup'));
        }

        return new Oueb_S3_Client(
            $this->endpoint(),
            $this->region(),
            (string) $accessKey,
            (string) BackWPup_Encryption::decrypt((string) $secretKey),
            $this->onlyPathStyleBucket()
        );
    }

    /**
     * The label of the destination.
     */
    public function label(): string
    {
        return $this->options['label'];
    }

    /**
     * The region of the destination.
     */
    public function region(): string
    {
        return $this->options['region'];
    }

    /**
     * The endpoint of the service, such as https://s3.fr-par.scw.cloud.
     */
    public function endpoint(): string
    {
        return $this->options['endpoint'];
    }

    /**
     * Destination supports multipart uploads.
     */
    public function supportsMultipart(): bool
    {
        return (bool) $this->options['multipart'];
    }

    /**
     * Destination support only path style buckets.
     */
    public function onlyPathStyleBucket(): bool
    {
        return (bool) $this->options['only_path_style_bucket'];
    }
}
