#!/usr/bin/env bash
# Point the zone's apex→www Single Redirect at HTTP 308 so POST /mcp keeps its body.
# Requires CLOUDFLARE_API_TOKEN and CLOUDFLARE_ZONE_ID. No-ops when unset.
set -euo pipefail

if [[ -z "${CLOUDFLARE_API_TOKEN:-}" || -z "${CLOUDFLARE_ZONE_ID:-}" ]]; then
  echo "Skipping Cloudflare apex redirect: CLOUDFLARE_API_TOKEN / CLOUDFLARE_ZONE_ID not set."
  exit 0
fi

API="https://api.cloudflare.com/client/v4/zones/${CLOUDFLARE_ZONE_ID}"
AUTH=(-H "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" -H "Content-Type: application/json")

php -r '
$token = getenv("CLOUDFLARE_API_TOKEN");
$zone = getenv("CLOUDFLARE_ZONE_ID");
$api = "https://api.cloudflare.com/client/v4/zones/{$zone}";

function cf(string $method, string $url, ?array $body = null) use ($token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json",
        ],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        fwrite(STDERR, curl_error($ch)."\n");
        exit(1);
    }
    $json = json_decode($raw, true);
    if (! is_array($json) || empty($json["success"])) {
        fwrite(STDERR, $raw."\n");
        exit(1);
    }
    return $json;
}

$entry = cf("GET", $api."/rulesets/phases/http_request_dynamic_redirect/entrypoint");
$rulesetId = $entry["result"]["id"] ?? null;
if (! is_string($rulesetId) || $rulesetId === "") {
    fwrite(STDERR, "No dynamic redirect ruleset entrypoint.\n");
    exit(1);
}

$ruleset = cf("GET", $api."/rulesets/{$rulesetId}");
$rules = $ruleset["result"]["rules"] ?? [];
$changed = false;

foreach ($rules as &$rule) {
    if (($rule["action"] ?? "") !== "redirect") {
        continue;
    }
    $from = $rule["action_parameters"]["from_value"] ?? [];
    $target = $from["target_url"]["value"]
        ?? $from["target_url"]["expression"]
        ?? "";
    $expr = (string) ($rule["expression"] ?? "");
    $mentionsApex = str_contains($expr, "snitchsocial.net")
        || str_contains((string) $target, "www.snitchsocial.net");
    if (! $mentionsApex) {
        continue;
    }
    $status = (int) ($from["status_code"] ?? 0);
    if ($status === 308 && ! empty($from["preserve_query_string"])) {
        continue;
    }
    $rule["action_parameters"]["from_value"]["status_code"] = 308;
    $rule["action_parameters"]["from_value"]["preserve_query_string"] = true;
    $changed = true;
}
unset($rule);

if (! $changed) {
    echo "Cloudflare apex→www redirect already uses HTTP 308 (or no matching rule).\n";
    exit(0);
}

cf("PUT", $api."/rulesets/{$rulesetId}", [
    "name" => $ruleset["result"]["name"] ?? "Redirect rules ruleset",
    "kind" => "zone",
    "phase" => "http_request_dynamic_redirect",
    "rules" => $rules,
]);

echo "Updated Cloudflare apex→www Single Redirect to HTTP 308.\n";
'
