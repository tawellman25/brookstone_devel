<?php

/**
 * @file
 * Upload a single file to S3 using the AWS SDK already present in vendor/
 * (installed as an s3fs dependency) — no AWS CLI required.
 *
 * Credentials + target come from the ENVIRONMENT (exported by the caller from
 * an off-git env file — never hard-coded here, so this file is safe to commit):
 *   AWS_ACCESS_KEY_ID
 *   AWS_SECRET_ACCESS_KEY
 *   BOS_S3_BACKUP_BUCKET
 *   BOS_S3_BACKUP_REGION   (default us-east-1)
 *
 * Usage:
 *   php s3_backup_upload.php /path/to/file.sql.gz [object-key]
 * Object key defaults to the file's basename. Exit 0 on success, non-zero on
 * failure. Uses a single PutObject (needs only s3:PutObject — matches the
 * least-privilege backup IAM policy; no multipart Abort permission required).
 */

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
  fwrite(STDERR, "autoload not found: $autoload\n");
  exit(2);
}
require $autoload;

$file = $argv[1] ?? '';
if ($file === '' || !is_file($file)) {
  fwrite(STDERR, "file not found: " . var_export($file, TRUE) . "\n");
  exit(2);
}
$key = $argv[2] ?? basename($file);

$bucket = getenv('BOS_S3_BACKUP_BUCKET') ?: '';
$region = getenv('BOS_S3_BACKUP_REGION') ?: 'us-east-1';
$akid   = getenv('AWS_ACCESS_KEY_ID') ?: '';
$secret = getenv('AWS_SECRET_ACCESS_KEY') ?: '';

if ($bucket === '' || $akid === '' || $secret === '') {
  fwrite(STDERR, "missing BOS_S3_BACKUP_BUCKET / AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY in env\n");
  exit(2);
}

if (!class_exists('Aws\\S3\\S3Client')) {
  fwrite(STDERR, "Aws\\S3\\S3Client not found — is aws/aws-sdk-php installed in vendor/?\n");
  exit(2);
}

try {
  $s3 = new Aws\S3\S3Client([
    'version' => 'latest',
    'region' => $region,
    'credentials' => ['key' => $akid, 'secret' => $secret],
  ]);
  $s3->putObject([
    'Bucket' => $bucket,
    'Key' => $key,
    'SourceFile' => $file,
    // Bucket default encryption already applies SSE-S3; set it explicitly too.
    'ServerSideEncryption' => 'AES256',
  ]);
  fwrite(STDOUT, "uploaded s3://$bucket/$key (" . number_format(filesize($file)) . " bytes)\n");
  exit(0);
}
catch (\Throwable $e) {
  fwrite(STDERR, "S3 upload failed: " . $e->getMessage() . "\n");
  exit(1);
}
