<?php

function download(string $url, string $accessKey, string $secretKey) {
  $parsedUrl = parse_url($url);

  $region = 'us-east-1';
  $service = 's3';
  $method = 'GET';
  $payload = '';

  $dateTime = new DateTime('now', new DateTimeZone('UTC'));
  $longDate = $dateTime->format('Ymd\\THis\\Z');
  $shortDate = $dateTime->format('Ymd');

  $headers = [
    'host' => $parsedUrl['host'],
    'x-amz-date' => $longDate
  ];

  $kSecret = 'AWS4' . $secretKey;
  $kDate = hash_hmac('sha256', $shortDate, $kSecret, true);
  $kRegion = hash_hmac('sha256', $region, $kDate, true);
  $kService = hash_hmac('sha256', $service, $kRegion, true);
  $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

  $canonicalheaders = [];
  foreach ($headers as $key => $value) {
    $canonicalheaders[strtolower($key)] = trim($value);
  }
  uksort($canonicalheaders, 'strcmp');

  $canonicalRequest = [];
  $canonicalRequest[] = $method;
  $canonicalRequest[] = $parsedUrl['path'];
  $canonicalRequest[] = '';
  foreach ($canonicalheaders as $key => $value) {
    $canonicalRequest[] = $key . ':' . $value;
  }
  $canonicalRequest[] = '';
  $canonicalRequest[] = implode(';', array_keys($canonicalheaders));
  $canonicalRequest[] = hash('sha256', $payload);

  $signedRequest = hash('sha256', implode("\n", $canonicalRequest));
  $signString = "AWS4-HMAC-SHA256\n{$longDate}\n$shortDate/$region/$service/aws4_request\n" . $signedRequest;
  $signature = hash_hmac('sha256', $signString, $kSigning);

  $headers['authorization'] = 'AWS4-HMAC-SHA256 '
                            . "Credential=$accessKey/$shortDate/$region/$service/aws4_request, "
                            . 'SignedHeaders=' . implode(';', array_keys($headers)) . ', '
                            . "Signature=$signature";

  $ch = curl_init();
  $curlHeaders = [];
  foreach ($headers as $key => $value) {
    $curlHeaders[] = $key . ': ' . $value;
  }
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
  $response = curl_exec($ch);

  $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($status !== 200) {
    print("Failed to download $url: $status\n");
    print($response);
    return '';
  }

  return $response;
}

function isValidName(string $name): bool {
  return preg_match('/^[a-z0-9-]{1,30}$/', $name) && !str_starts_with($name, '-') && !str_ends_with($name, '-') && !str_contains($name, '--');
}

function buildStoreMap(array $remoteBucketMap, array $extraStoreBuckets): array {
  $stores = [];
  foreach ($remoteBucketMap as $remote => $bucket) {
    $storeConfig = new stdClass();
    $storeConfig->bucket = $bucket;
    $stores["remote-" . $remote] = $storeConfig;
  }
  foreach ($extraStoreBuckets as $bucket) {
    $storeConfig = new stdClass();
    $storeConfig->bucket = $bucket;
    $stores["extra-" . $bucket] = $storeConfig;
  }
  return $stores;
}

function writeSharedTempFile(string $contents, string $suffix, string $oldFilename = ''): string {
  if ($oldFilename !== '') {
    unlink($oldFilename);
  }
  $tempFilename = tempnam('/srun', 'config-');
  rename($tempFilename, $tempFilename .= $suffix);
  file_put_contents($tempFilename, $contents);
  return $tempFilename;
}

function renderConfig($config, $outputFilename) {
  ob_start();
  template($config);
  $rendered = ob_get_clean();
  file_put_contents($outputFilename, $rendered);
}

function main() {
  error_reporting(E_ALL);

  $minioAccessKey = getenv('MINIO_ACCESS_KEY');
  $minioSecretKey = getenv('MINIO_SECRET_KEY');
  $fifoWatcher = getenv('FIFO_WATCHER');
  $fifoOutputDynamicConfig = getenv('FIFO_OUTPUT_DYNAMIC_CONFIG');

  $extraStoreBucketsEnv = getenv('BUCKET_EXTRA_STORE_LIST');
  $extraStoreBuckets = [];
  if ($extraStoreBucketsEnv !== "") {
    foreach (preg_split('/\s+/', trim($extraStoreBucketsEnv)) as $bucket) {
      if (isValidName($bucket)) {
        $extraStoreBuckets[] = $bucket;
      }
    }
  }

  $config = new stdClass();
  $config->remotes = [];
  $config->stores = [];

  $traefikConfigDefault = file_get_contents(dirname(__FILE__) . '/traefik.default.yaml');
  $traefikConfigTime = "";
  $config->traefik = writeSharedTempFile($traefikConfigDefault, ".yaml");

  // Poll the watcher's FIFO for new configuration
  while (true) {
    if (!file_exists($fifoWatcher)) {
      sleep(1);
      print("FIFO $fifoWatcher not found\n");
      continue;
    }
    $contents = file_get_contents($fifoWatcher);
    $lines = explode("\n", trim($contents));
    $event = $lines[0];
    $list = array_map('json_decode', array_slice($lines, 1));

    $update = false;
    if ($event === 'bucket') {
      $remoteBucketMap = [];
      foreach ($list as $bucket) {
        if ($bucket->type === "folder" && preg_match('/^(thanos-([\s\S]+))\/$/', $bucket->key, $matches)) {
          $bucket = $matches[1];
          $remote = $matches[2];
          if (isValidName($bucket)) {
            $remoteBucketMap[$remote] = $bucket;
          }
        }
      }
      $newRemoteList = array_keys($remoteBucketMap);
      if ($newRemoteList !== $config->remotes) {
        $config->remotes = $newRemoteList;
        $config->stores = buildStoreMap($remoteBucketMap, $extraStoreBuckets);
        $update = true;
      }
    } else if ($event === 'synced_config') {
      foreach ($list as $entry) {
        if ($entry->status !== 'success' || $entry->type !== 'file') {
          continue;
        }
        if ($entry->key === "traefik.yaml" && $entry->lastModified !== $traefikConfigTime) {
          $config->traefik = writeSharedTempFile(download($entry->url . $entry->key, $minioAccessKey, $minioSecretKey), ".yaml", $config->traefik);
          $traefikConfigTime = $entry->lastModified;
          $update = true;
        }
      }
    }

    if ($update) {
      renderConfig($config, $fifoOutputDynamicConfig);
    }
  }
}

main();
