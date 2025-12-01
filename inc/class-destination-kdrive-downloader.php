<?php
/**
 * BackWPup destination downloader: Infomaniak kDrive via WebDAV
 *
 * @since 1.0.0
 */

/**
 * Class BackWPup_Destination_KDrive_Downloader
 *
 * Handles downloading backup files from Infomaniak kDrive using WebDAV.
 */
final class BackWPup_Destination_KDrive_Downloader implements BackWPup_Destination_Downloader_Interface
{
    /**
     * @var BackWpUp_Destination_Downloader_Data
     */
    private $data;

    /**
     * Constructor.
     *
     * @param BackWpUp_Destination_Downloader_Data $data Downloader data
     */
    public function __construct(BackWpUp_Destination_Downloader_Data $data)
    {
        $this->data = $data;
    }

    /**
     * Download file in chunks (required by interface).
     *
     * @param int $start_byte Starting byte position
     * @param int $end_byte   Ending byte position
     * @throws RuntimeException On download errors
     */
    public function download_chunk($start_byte, $end_byte): void
    {
        // For WebDAV, we download the entire file at once
        // Chunked download is not easily supported with WebDAV without Range headers
        if ($start_byte === 0) {
            $this->download_full_file();
        }
    }

    /**
     * Calculate the size of the remote file.
     *
     * @return int File size in bytes
     * @throws RuntimeException On errors
     */
    public function calculate_size(): int
    {
        // Get configuration
        $job_id = $this->data->job_id();
        $kdrive_id = BackWPup_Option::get($job_id, 'kdriveid');
        $email = BackWPup_Option::get($job_id, 'kdriveemail');
        $password = BackWPup_Encryption::decrypt(BackWPup_Option::get($job_id, 'kdrivepassword'));
        $dir = BackWPup_Option::get($job_id, 'kdrivedir');

        if (empty($kdrive_id) || empty($email) || empty($password)) {
            throw new RuntimeException('kDrive configuration is incomplete');
        }

        // Build WebDAV URL
        $url = sprintf(
            'https://%s.connect.kdrive.infomaniak.com%s%s',
            $kdrive_id,
            $dir,
            basename($this->data->source_file_path())
        );

        // Get file size using HEAD request
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_HEADER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $email . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        curl_exec($ch);
        $size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || $size < 0) {
            throw new RuntimeException('Cannot determine file size');
        }

        return (int)$size;
    }

    /**
     * Download the full file from kDrive.
     *
     * @throws RuntimeException On download errors
     */
    private function download_full_file(): void
    {
        // Get configuration
        $job_id = $this->data->job_id();
        $kdrive_id = BackWPup_Option::get($job_id, 'kdriveid');
        $email = BackWPup_Option::get($job_id, 'kdriveemail');
        $password = BackWPup_Encryption::decrypt(BackWPup_Option::get($job_id, 'kdrivepassword'));
        $dir = BackWPup_Option::get($job_id, 'kdrivedir');

        if (empty($kdrive_id) || empty($email) || empty($password)) {
            throw new RuntimeException('kDrive configuration is incomplete');
        }

        // Build WebDAV URL
        $url = sprintf(
            'https://%s.connect.kdrive.infomaniak.com%s%s',
            $kdrive_id,
            $dir,
            basename($this->data->source_file_path())
        );

        $local_file = $this->data->local_file_path();

        $fp = fopen($local_file, 'wb');
        if (!$fp) {
            throw new RuntimeException(sprintf('Cannot create local file: %s', $local_file));
        }

        $ch = curl_init($url);
        if (!$ch) {
            fclose($fp);
            throw new RuntimeException('Cannot initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_USERPWD => $email . ':' . $password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 3600, // 1 hour timeout
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        if ($curl_error) {
            @unlink($local_file);
            throw new RuntimeException(sprintf('cURL error: %s', $curl_error));
        }

        if ($http_code !== 200) {
            @unlink($local_file);
            throw new RuntimeException(sprintf('HTTP error %d', $http_code));
        }

        // Verify file was downloaded
        if (!file_exists($local_file) || filesize($local_file) === 0) {
            @unlink($local_file);
            throw new RuntimeException('Downloaded file is empty or does not exist');
        }
    }
}
