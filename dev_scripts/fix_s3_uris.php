<?php
/**
 * Drush script to remove s3fs-public/ from URIs and set to public://.
 * Run with: drush scr dev_scripts/fix_s3fs_public_uris.php
 */

use Drupal\file\Entity\File;

// Load all files from file_managed table
$files = \Drupal::entityTypeManager()->getStorage('file')->loadMultiple();

$count = 0;
$updated = 0;
$to_verify = [];

foreach ($files as $file) {
  $uri = $file->getFileUri();
  
  // Target URIs containing s3fs-public/
  if (strpos($uri, 's3fs-public/') !== false) {
    // Remove s3://bucket and s3fs-public/
    $relative_path = preg_replace('#^(s3://[^/]+/s3fs-public/|public://s3fs-public/)#', '', $uri);
    
    // Determine encoding based on filename
    $filename = basename($relative_path);
    if (strpos($filename, '%20') !== false) {
      // Files with %20 in S3 (e.g., 1806%20Pop%20Up.jpg) need %2520 in URI
      $new_uri = 'public://' . str_replace('%20', '%2520', $relative_path);
    } elseif (strpos($filename, '+') !== false) {
      // Files with + in S3 (e.g., 2+in.+Potato+Cobble+-+River+Rock_decorative_rock.jpg)
      $new_uri = 'public://' . $relative_path;
    } else {
      // Files with no spaces or literal spaces (e.g., 501-14th-st-grass_0.jpg)
      $new_uri = 'public://' . str_replace('%20', ' ', $relative_path);
    }
    
    // Update the file entity
    $file->setFileUri($new_uri);
    $file->save();
    
    // Log for verification
    $to_verify[] = [
      'fid' => $file->id(),
      'old_uri' => $uri,
      'new_uri' => $new_uri,
      's3_path' => 's3fs-public/' . str_replace('%2520', '%20', $relative_path),
    ];
    
    $updated++;
    \Drupal::logger('fix_s3_uris')->notice('Updated URI for file ID @fid: @old_uri to @new_uri', [
      '@fid' => $file->id(),
      '@old_uri' => $uri,
      '@new_uri' => $new_uri,
    ]);
  }
  $count++;
}

// Append to s3_uri_verification.txt
file_put_contents('s3_uri_verification.txt', json_encode($to_verify, JSON_PRETTY_PRINT), FILE_APPEND);

echo "Processed $count files, updated $updated URIs.\n";
echo "Updated URIs appended to s3_uri_verification.txt.\n";