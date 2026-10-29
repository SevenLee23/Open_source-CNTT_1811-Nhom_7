# Evaluation API

Endpoint: `POST /api/v1/evaluate`

Send the owner account's project API key in the `Authorization` header. Each project has a separate account-scoped read-only key; only the owner receives it and subaccounts do not receive API keys. Keys are stored hashed. The project key can select an environment in its project using the optional `environment` body field (defaults to `staging`). Existing environment-specific keys remain restricted to that environment.

```http
POST /Open_source-CNTT_1811-Nhom_7/api/v1/evaluate HTTP/1.1
Authorization: Bearer YOUR_ACCOUNT_API_KEY
Content-Type: application/json

{"user_id":"USER_0001","flags":["new_dashboard","beta_search"],"environment":"staging"}
```

For an account API key, `environment` may choose any environment in that account's workspace and defaults to `staging`. An environment-specific key remains restricted to its own environment.

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
      "bucket": null
    }
  }
}
```

An unknown flag returns `value: false` with `reason: "unknown_flag"`. Invalid bodies return HTTP 422, missing/invalid keys return 401, an environment mismatch returns 403, no published snapshot returns 503, and evaluation failures return 500. Clients should define a local fallback and use it on network errors or non-success responses.

## PHP client example

```php
<?php
$fallback = ['new_dashboard' => false];
$result = $fallback;
$payload = json_encode(['user_id' => 'USER_0001', 'flags' => array_keys($fallback)]);
$ch = curl_init('http://localhost/Open_source-CNTT_1811-Nhom_7/api/v1/evaluate');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 4,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer YOUR_ACCOUNT_API_KEY',
        'Content-Type: application/json',
    ],
]);
$body = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($body !== false && $status >= 200 && $status < 300) {
    $decoded = json_decode($body, true);
    if (!empty($decoded['success'])) {
        foreach ($decoded['results'] as $key => $decision) $result[$key] = (bool) $decision['value'];
    }
}
// Use $result; on connectivity/API errors the fallback stays in effect.
```

Use the exact API base URL for the deployed application and keep the key in server-side configuration. Never embed a management password in the client application.

## Configuration and project log files

In the web app's **Audit & Import/Export** page, users with project edit permission can upload a configuration JSON file while the project is set to the `staging` environment. Use a file previously downloaded from the same project and environment. The importer validates the project/environment keys and requires every active project feature; a valid file is saved as a Staging draft. Publish that draft from Version History when it is ready. Configuration edits and publishing are blocked in release environments, which show their current published feature states as read-only.

The same page accepts project logs as JSON (`[{"timestamp":"2026-01-01T12:00:00Z","level":"info","source":"worker","message":"Started","context":{"job":12}}]` or `{"logs":[...]}`) or CSV with a required `message` column and optional `timestamp`, `level`, `source`, and `context` columns. Uploads are limited to 2 MB and 1,000 records and are appended to project log storage. Users allowed to view audit logs can download the project configuration as JSON or a combined audit, decision, and uploaded-log CSV.
