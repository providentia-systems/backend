from pathlib import Path
p=Path('tests/Unit/AiIntegration/AiSettingsPrivacyTest.php');s=p.read_text();a=s.index('public function testHttpBoundaryErasesAllBuffersAndClosesStreamsWhenTheProviderFails');b=s.index('\n    public function ',a+30);part=s[a:b];assert part.count('$this->syntheticProfile()')==1;part=part.replace('$this->syntheticProfile()', "[...$this->syntheticProfile(), 'provider' => 'failing']");p.write_text(s[:a]+part+s[b:])
p=Path('tests/Acceptance/step2-live-http.dart');s=p.read_text().replace('settings.availableServerProviders', 'settings.availableProviders');p.write_text(s)
p=Path('tests/Acceptance/headless-platform-acceptance.sh');s=p.read_text();needle='image_file="${evidence_dir}/acceptance-stock.png"';assert s.count(needle)==1;s=s.replace(needle, '''# Extraction uses saved, revisioned profiles and a shared policy, not an
# undisclosed legacy provider/model fallback.
profile_body="$(jq -cn '{label:"Acceptance shared profile",ownerScope:"home",
    provider:"openai-compatible",model:"acceptance-vision",
    credential:"acceptance-shared-profile-synthetic-3333",
    estimatedCostMicros:0,expectedRevision:0}')"
http_json POST "/api/v1/homes/${home_id}/ai/profiles" \\
    201 "$homeowner_access_token" "$profile_body"
shared_profile_id="$(jq -er '.id' "$response_body")"
policy_body="$(jq -cn --arg id "$shared_profile_id" '{extractionProfileIds:[$id],
    validationProfileId:null,maxAttempts:2,maxTotalTokens:50000,
    maxEstimatedCostMicros:1000000,expectedRevision:0}')"
http_json PUT "/api/v1/homes/${home_id}/ai/orchestration-policy" \\
    200 "$homeowner_access_token" "$policy_body"

'''+needle);p.write_text(s)
