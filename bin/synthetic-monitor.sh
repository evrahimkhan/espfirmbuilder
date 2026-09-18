#!/usr/bin/env bash
set -euo pipefail

base="${ESPFORGE_URL:?ESPFORGE_URL is required}"
email="${ESPFORGE_SYNTHETIC_EMAIL:?ESPFORGE_SYNTHETIC_EMAIL is required}"
password="${ESPFORGE_SYNTHETIC_PASSWORD:?ESPFORGE_SYNTHETIC_PASSWORD is required}"
base="${base%/}"
cookies="$(mktemp)"; session_body="$(mktemp)"; login_body="$(mktemp)"; protected_body="$(mktemp)"
trap 'rm -f "$cookies" "$session_body" "$login_body" "$protected_body"' EXIT
curl_common=(--proto '=https' --tlsv1.2 --fail-with-body --silent --show-error --connect-timeout 10 --max-time 30 -H 'Accept: application/json' -H 'User-Agent: ESPForge-Synthetic-Monitor/1')

status="$(curl "${curl_common[@]}" -c "$cookies" -o "$session_body" -w '%{http_code}' "$base/api/auth.php?action=session")"
[[ "$status" == 200 ]]
csrf="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||!preg_match("/^[a-f0-9]{48}$/",$j["csrf"]??""))exit(2);echo $j["csrf"];' "$session_body")"
login_json="$(php -r 'echo json_encode(["email"=>$argv[1],"password"=>$argv[2]],JSON_THROW_ON_ERROR);' "$email" "$password")"
status="$(curl "${curl_common[@]}" -b "$cookies" -c "$cookies" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" --data-binary "$login_json" -o "$login_body" -w '%{http_code}' "$base/api/auth.php?action=login")"
[[ "$status" == 200 ]]
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||empty($j["user"]["id"]))exit(2);' "$login_body"

# Re-read session to prove that the authenticated cookie is accepted and obtain
# the rotated token. Exercise a protected, read-only endpoint without mutation.
status="$(curl "${curl_common[@]}" -b "$cookies" -c "$cookies" -o "$session_body" -w '%{http_code}' "$base/api/auth.php?action=session")"
[[ "$status" == 200 ]]
csrf="$(php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||empty($j["user"]["id"])||!preg_match("/^[a-f0-9]{48}$/",$j["csrf"]??""))exit(2);echo $j["csrf"];' "$session_body")"
status="$(curl "${curl_common[@]}" -b "$cookies" -H "X-CSRF-Token: $csrf" -o "$protected_body" -w '%{http_code}' "$base/api/projects.php")"
[[ "$status" == 200 ]]
php -r '$j=json_decode(file_get_contents($argv[1]),true);if(!is_array($j)||!array_key_exists("repositories",$j))exit(2);' "$protected_body"

# Logout both verifies the mutating CSRF boundary and leaves no live session.
status="$(curl "${curl_common[@]}" -b "$cookies" -H 'Content-Type: application/json' -H "X-CSRF-Token: $csrf" --data-binary '{}' -o /dev/null -w '%{http_code}' "$base/api/auth.php?action=logout")"
[[ "$status" == 200 ]]
echo 'Authenticated synthetic check passed.'
