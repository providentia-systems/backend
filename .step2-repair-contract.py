import gzip, hashlib, json, pathlib, subprocess
r = pathlib.Path('.')
def edit(path, old, new):
    p = r / path
    s = p.read_text()
    if s.count(old) != 1:
        raise RuntimeError('Unexpected source: ' + path)
    p.write_text(s.replace(old, new))
edit('tests/Integration/CatalogContributionPrivacyTest.php', "        self::assertSame(13, $this->store->consent('home-private')['revision']);", "        $withdrawn = $this->store->consent('home-private');\n        self::assertNotNull($withdrawn);\n        self::assertArrayHasKey('revision', $withdrawn);\n        self::assertSame(13, $withdrawn['revision']);")
p = r / 'tests/Unit/Catalog/CatalogImportServiceTest.php'
s = p.read_text(); start = s.index('public function testConfirmationPublishesEveryCreatedHomeProductToTheChangeFeed'); before=s[:start]; tail=s[start:]; tail=tail.replace("'privateName' => null,", "'privateName' => null,\n                'productName' => null,", 1); p.write_text(before+tail)
p = r / 'tests/Unit/AiIntegration/AiSettingsPrivacyTest.php'; s=p.read_text(); start=s.index('public function testHttpBoundaryErasesAllBuffersAndClosesStreamsWhenTheProviderFails'); end=s.index('\n    public function ', start+30); part=s[start:end]; part=part.replace('$this->providerProfile()', "[...$this->providerProfile(), 'provider' => 'failing']"); p.write_text(s[:start]+part+s[end:])
edit('src/AiIntegration/Application/AiTransmissionPlan.php', "        return [\n            'profileId' => $profile['id'] ?? null,\n            'revision' => (int) ($profile['revision'] ?? 0),", "        if (! is_string($profile['id'] ?? null)\n            || preg_match('/^[A-Za-z0-9-]{1,36}$/D', $profile['id']) !== 1\n            || ! is_int($profile['revision'] ?? null) || $profile['revision'] < 1\n        ) {\n            throw new Problem(409, 'AI setup required', 'Select saved, revisioned provider profiles before extraction.');\n        }\n\n        return [\n            'profileId' => $profile['id'],\n            'revision' => $profile['revision'],")
edit('src/AiIntegration/Application/AiService.php', '                    function () use ($identity, $homeId, $transmissionPlanHash, $selectedProfileId): void {\n                        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);', '                    function () use ($identity, $homeId, $transmissionPlanHash, $selectedProfileId): void {\n                        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_USE);\n                        $this->authorization->requirePermission($identity, $homeId, HomePermission::AI_CREDENTIALS_USE);')
old_archive=(r/'contracts/source/providentia-v1.json.gz').read_bytes(); old=gzip.decompress(old_archive); c=json.loads(old); s=c['components']['schemas']; ref=lambda n:{'$ref':'#/components/schemas/'+n}
settings=s['AiSettings']; props=settings['properties']
props['providerProfiles']={'type':'array','items':ref('AiProviderProfile'),'description':"Atomic viewer-authorized snapshot. Includes shared profiles and this viewer's private profiles only; never another member's private configuration."}
props['orchestrationPolicy']=ref('AiOrchestrationPolicy')
props['transmissionPlan']['description']='Null is an actionable setup/unavailable state, not a malformed response. Management remains reachable; extraction is prohibited until a saved shared policy resolves to usable, disclosed recipients for this viewer. Settings, profiles, policy and plan are returned from one transaction.'
settings['required']=list(dict.fromkeys(settings['required']+['providerProfiles','orchestrationPolicy','transmissionPlan']))
s['AiTransmissionRecipient']['properties']['profileId']={'type':'string','pattern':'^[A-Za-z0-9-]{1,36}$','description':'Actual saved profile executed for this viewer, including an explicitly disclosed personal override. Never null.'}
s['AiTransmissionRecipient']['properties']['revision']['minimum']=1
s['AiOrchestrationPolicy']['description']='Shared home policy. References shared profiles only. Empty extraction IDs and null validation profile mean setup/repair required; the current revision is retained for optimistic repair. Private/deleted references are never disclosed. Personal overrides are resolved separately into the viewer-specific transmission plan, never substituted after consent.'
for name in s:
    if 'OrchestrationPolicy' in name and name!='AiOrchestrationPolicy':s[name]['description']='Shared policy writes may reference only active shared-home profiles. Personal profiles remain private; the effective per-viewer plan discloses and hashes any permitted private override.'
for name in ['HomeProduct','HomeItemMasterProduct','CreateHomeProductRequest']:
    if name in s:s[name]['description']="Supported identities: private (productId and packId null), product family awaiting pack resolution (productId set, packId null), or an explicitly selected catalog pack (both set). Pack-only creation resolves the exact pack parent in persistence; response and sync always contain the normalized parent. Never select the first or infer a pack from an ambiguous family. Family records retain homeProductId, raw wording, balances and history."
for name in ['HomeProduct','HomeItemMasterProduct']:
    if name in s:s[name].setdefault('allOf',[]).append({'anyOf':[{'properties':{'packId':{'type':'null'}}},{'required':['productId'],'properties':{'productId':ref('Uuid')}}]})
if 'CatalogContributionConsent' in s:s['CatalogContributionConsent']['description']='Versioned granular consent; sharing defaults to false. Persisted GET and save responses use JSON booleans (never database integers/strings) and an integer revision. Invalid stored values fail closed.'
new=(json.dumps(c,indent=2,ensure_ascii=False)+'\n').encode(); digest=hashlib.sha256(new).hexdigest(); assert digest=='b29608746e59216ef69554c346d0606ac5815e42a6e04d5f284872b608d05647'
archive=subprocess.run(['gzip','-n','-9'],input=new,stdout=subprocess.PIPE,check=True).stdout; archive_digest=hashlib.sha256(archive).hexdigest(); assert archive_digest=='beba4827515c2dc63f69145fad090769fea50a031c9f88f31bf2be4ddd951e55'
(r/'contracts/source/providentia-v1.json.gz').write_bytes(archive)
p=r/'tool/materialize-openapi-contract.sh';p.write_text(p.read_text().replace(hashlib.sha256(old_archive).hexdigest(),archive_digest).replace(hashlib.sha256(old).hexdigest(),digest))
p=r/'contracts/openapi/contract.lock.json';lock=json.loads(p.read_text());lock['artifacts']['providentia-v1.json']['sha256']=digest;p.write_text(json.dumps(lock,indent=2)+'\n')
subprocess.run(['bash','tool/materialize-openapi-contract.sh'],check=True)
