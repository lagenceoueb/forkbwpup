<?php

declare(strict_types=1);

/**
 * S3 Downloader.
 *
 * @since   3.5.0
 */
final class BackWPup_Destination_S3_Downloader implements BackWPup_Destination_Downloader_Interface
{
    private const OPTION_BASE_URL = 's3base_url';
    private const OPTION_REGION = 's3region';
    private const OPTION_BUCKET = 's3bucket';
    private const OPTION_ACCESS_KEY = 's3accesskey';
    private const OPTION_SECRET_KEY = 's3secretkey';

    /**
     * @var BackWpUp_Destination_Downloader_Data
     */
    private $data;

    /**
     * @var Oueb_S3_Client
     */
    private $s3Client;

    /**
     * @var resource
     */
    private $localHandle;

    /**
     * BackWPup_Destination_S3_Downloader constructor.
     */
    public function __construct(BackWpUp_Destination_Downloader_Data $data)
    {
        $this->data = $data;
        $this->initializeS3Client();
    }

    /**
     * Clean stuffs.
     */
    public function __destruct()
    {
        if (is_resource($this->localHandle)) {
            fclose($this->localHandle);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function download_chunk($start_byte, $end_byte): void
    {
        $body = $this->s3Client->get_object_range(
            (string) BackWPup_Option::get($this->data->job_id(), self::OPTION_BUCKET),
            $this->data->source_file_path(),
            (int) $start_byte,
            (int) $end_byte
        );

        if ($body === '') {
            throw new RuntimeException(__('Could not write data to file. Empty source file.', 'backwpup'));
        }

        $this->openLocalHandle((int) $start_byte);

        $bytes = (int) fwrite($this->localHandle, $body);
        if ($bytes === 0) {
            throw new RuntimeException(__('Could not write data to file.', 'backwpup'));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function calculate_size(): int
    {
        // A HEAD request reads the size without downloading the archive.
        $headers = $this->s3Client->head_object(
            (string) BackWPup_Option::get($this->data->job_id(), self::OPTION_BUCKET),
            $this->data->source_file_path()
        );

        return (int) ($headers['content-length'] ?? 0);
    }

    private function openLocalHandle(int $start_byte): void
    {
        if (is_resource($this->localHandle)) {
            return;
        }

        $this->localHandle = fopen($this->data->local_file_path(), $start_byte === 0 ? 'wb' : 'ab');

        if (!is_resource($this->localHandle)) {
            throw new RuntimeException(__('File could not be opened for writing.', 'backwpup'));
        }
    }

    /**
     * Build S3 Client.
     */
    private function initializeS3Client(): void
    {
        if ($this->s3Client) {
            return;
        }

        if (empty(BackWPup_Option::get($this->data->job_id(), self::OPTION_BASE_URL))) {
            $aws_destination = BackWPup_S3_Destination::fromOption(
                (string) BackWPup_Option::get($this->data->job_id(), self::OPTION_REGION)
            );
        } else {
            $aws_destination = BackWPup_S3_Destination::fromJobId($this->data->job_id());
        }

        $this->s3Client = $aws_destination->client(
            BackWPup_Option::get($this->data->job_id(), self::OPTION_ACCESS_KEY),
            BackWPup_Option::get($this->data->job_id(), self::OPTION_SECRET_KEY)
        );
    }
}
