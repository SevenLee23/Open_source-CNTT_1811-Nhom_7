# Evaluation API

Endpoint: `POST /api/v1/evaluate`

Send the project's shared read-only API key in the `Authorization` header. The same key works across that project's environments; every request must include `environment` so the API can choose the correct configuration snapshot. Valid values are `staging` and `production_sim`. Keys are stored hashed and the full key is shown only once when created or rotated.

```http
POST /Open_source-CNTT_1811-Nhom_7/api/v1/evaluate HTTP/1.1
Authorization: Bearer YOUR_PROJECT_API_KEY
Content-Type: application/json

{"environment":"staging","user_id":"USER_0001","flags":["new_dashboard","beta_search"]}
```

Use `"environment":"staging"` for the test configuration and `"environment":"production_sim"` for the release configuration. Missing or unknown environments are rejected; there is no implicit Staging default. Publishing from Staging creates a separate immutable version in the release environment and makes it current there.

Successful response:

```json
{
  "success": true,
  "environment": "staging",
  "version": 1,
  "results": {
    "new_dashboard": {
      "value": true,
      "reason": "user_rule",
      "matched_rule_id": 1,
      "bucket": null,
      "fallback_value": false
    }
  }
}
```

`fallback_value` is supplied automatically from the feature's default value (and is `false` while an emergency Kill Switch is active). Clients should cache this field locally after every successful response. If the API cannot be reached, apply the cached values immediately; on a client's first-ever request, use its safe built-in defaults because no server value can be fetched while offline. An unknown flag returns `value: false` and `fallback_value: false` with `reason: "unknown_flag"`. Invalid bodies or missing/unknown environments return HTTP 422, missing/invalid keys return 401, an environment mismatch returns 403, no published snapshot returns 503, and evaluation failures return 500.

## PHP client example

```php
<?php
$environment = 'production_sim'; // Use 'staging' to read the test snapshot.
$fallbackFile = __DIR__ . '/featurelab-fallback-' . $environment . '.json'; // Keep cached values separate by environment.
$fallback = ['new_dashboard' => false]; // Safe initial value used before the first successful API response.
if (is_file($fallbackFile)) {
    $cached = json_decode((string) file_get_contents($fallbackFile), true);
    if (is_array($cached)) $fallback = array_merge($fallback, $cached);
}
$result = $fallback;
$payload = json_encode(['environment' => $environment, 'user_id' => 'USER_0001', 'flags' => array_keys($fallback)]);
$ch = curl_init('http://localhost/Open_source-CNTT_1811-Nhom_7/api/v1/evaluate');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 4,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer YOUR_PROJECT_API_KEY',
        'Content-Type: application/json',
    ],
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($body !== false && $status >= 200 && $status < 300) {
    $decoded = json_decode($body, true);
    if (!empty($decoded['success'])) {
        $nextFallback = $fallback;
        foreach ($decoded['results'] as $key => $decision) {
            $result[$key] = (bool) $decision['value'];
            if (array_key_exists('fallback_value', $decision)) $nextFallback[$key] = (bool) $decision['fallback_value'];
        }
        file_put_contents($fallbackFile, json_encode($nextFallback), LOCK_EX);
    }
}
// On connectivity/API errors, use the last successfully cached fallback values.
```

Use the exact API base URL for the deployed application and keep the key in server-side configuration. Never embed a management password in the client application.

## Configuration and project log files

In the web app's **Audit & Import/Export** page, users with project edit permission can upload a configuration JSON file while the project is set to the `staging` environment. Use a file previously downloaded from the same project and environment. The importer validates the project/environment keys and requires every active project feature; a valid file is saved as a Staging draft. Publish that draft from Version History when it is ready. Configuration edits and publishing are blocked in release environments, which show their current published feature states as read-only.

The same page accepts project logs as JSON (`[{"timestamp":"2026-01-01T12:00:00Z","level":"info","source":"worker","message":"Started","context":{"job":12}}]` or `{"logs":[...]}`) or CSV with a required `message` column and optional `timestamp`, `level`, `source`, and `context` columns. Uploads are limited to 2 MB and 1,000 records and are appended to project log storage. Users allowed to view audit logs can download the project configuration as JSON or a combined audit, decision, and uploaded-log CSV.
