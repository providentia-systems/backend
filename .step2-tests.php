<?php

declare(strict_types=1);

function insert(string $path, string $needle, string $code): void
{
    $source = file_get_contents($path);
    if ($source === false || substr_count($source, $needle) !== 1) {
        throw new RuntimeException('Unexpected source: ' . $path);
    }
    file_put_contents($path, str_replace($needle, $code . $needle, $source));
}

insert('tests/Integration/CatalogContributionPrivacyTest.php', '    public function testModeratorQueue', <<<'CODE'
    public function testPersistedConsentKeepsBooleanTypesForEveryCombinationAndConsumer(): void
    {
        $at = new \DateTimeImmutable('2026-09-14T12:00:00+00:00');
        self::assertNull($this->store->consent('unsaved-home'));
        for ($mask = 0; $mask < 8; $mask++) {
            $flags = [($mask & 1) !== 0, ($mask & 2) !== 0, ($mask & 4) !== 0];
            $revision = 4 + $mask;
            self::assertTrue($this->store->saveConsent(
                'receipt-' . $mask,
                'home-private',
                ...[...$flags, 'catalog-sharing-v1', $revision, 'user-private', $at],
            ));
            $reopened = new DbalCatalogContributionStore($this->connection);
            $consent = json_decode(json_encode($reopened->consent('home-private'), JSON_THROW_ON_ERROR), true);
            self::assertSame($flags[0], $consent['shareProductIdentity']);
            self::assertSame($flags[1], $consent['shareProductImages']);
            self::assertSame($flags[2], $consent['shareStorePrices']);
            self::assertSame($revision + 1, $consent['revision']);
            foreach (['product_identity', 'product_image', 'store_price'] as $index => $type) {
                $result = $reopened->createContribution(
                    'submission-' . $mask . '-' . $index,
                    'home-private',
                    'receipt-' . $mask,
                    $type,
                    null,
                    ['canonicalName' => 'Synthetic item'],
                    'user-private',
                    $at,
                );
                self::assertSame($flags[$index] ? 'created' : 'conflict', $result['outcome']);
            }
        }
        self::assertTrue($this->store->saveConsent(
            'withdrawal', 'home-private', false, false, false,
            'catalog-sharing-v1', 12, 'user-private', $at,
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM catalog_contributions WHERE moderation_status = 'pending'",
        ));
        self::assertSame(13, $this->store->consent('home-private')['revision']);
    }

    public function testCorruptPersistedConsentFailsClosedRatherThanBecomingTruthy(): void
    {
        $this->connection->executeStatement(
            "UPDATE catalog_contribution_consents SET share_product_images = 2 WHERE home_id = 'home-private'",
        );
        $this->expectException(\UnexpectedValueException::class);
        $this->store->consent('home-private');
    }

CODE);

insert('tests/Integration/InventoryItemMasterTest.php', '    public function testPagesAreStable', <<<'CODE'
    public function testProductFamilyWithoutResolvedPackRemainsVisibleWithoutGuessingAPack(): void
    {
        $this->store->createHomeProduct(
            self::PRIVATE_PRODUCT_ID, self::HOME_ID, self::BEANS_ID, null,
            null, null, 'original unresolved pack wording', null,
            new DateTimeImmutable('2026-09-14T12:00:00+00:00'),
        );
        $reopened = new DbalInventoryStore($this->connection);
        $page = $reopened->itemMaster(self::HOME_ID, '', null, null, 100, 0);
        self::assertSame(4, $page['total']);
        $family = array_values(array_filter(
            $page['items'],
            static fn (array $item): bool => $item['homeProductId'] === self::PRIVATE_PRODUCT_ID,
        ));
        self::assertCount(1, $family);
        self::assertSame(self::BEANS_ID, $family[0]['productId']);
        self::assertNull($family[0]['packId']);
        self::assertSame('Baked Beans', $family[0]['canonicalName']);
        self::assertSame('original unresolved pack wording', $family[0]['packText']);
        self::assertSame(2, count(array_filter($page['items'], static fn (array $item): bool =>
            $item['productId'] === self::BEANS_ID && $item['packId'] !== null)));
    }

    public function testPackOnlyCreationCannotBypassParentPublicationState(): void
    {
        $this->connection->executeStatement(
            "UPDATE products SET status = 'draft' WHERE id = :id", ['id' => self::BEANS_ID],
        );
        $this->expectException(\DomainException::class);
        $this->store->createHomeProduct(
            self::PRIVATE_PRODUCT_ID, self::HOME_ID, null, self::BEANS_PACK_ONE,
            null, null, '1 kg', null, new DateTimeImmutable('2026-09-14T12:00:00+00:00'),
        );
    }

CODE);

insert('tests/Unit/AiIntegration/AiSettingsPrivacyTest.php', '    public function testSettingsDistinguish', <<<'CODE'
    public function testUnconfiguredServerProxyKeepsManagementReachableWithoutALegacyRecipient(): void
    {
        $store = $this->createStub(AiStore::class);
        $store->method('settings')->willReturn([
            'mode' => 'server_proxy', 'provider' => 'ollama', 'model' => 'synthetic', 'revision' => 2,
        ]);
        $service = $this->directExtractionService(
            $store, $this->createStub(AiMaturityStore::class),
            $this->syntheticProvider('ollama', false), new SodiumSensitiveBufferEraser(),
        );
        $settings = $service->settings($this->identity(), self::HOME_ID);
        self::assertSame(2, $settings['revision']);
        self::assertNull($settings['transmissionPlan']);
        self::assertSame([], $service->orchestrationPolicy($this->identity(), self::HOME_ID)['extractionProfileIds']);
    }

    public function testLegacyPrivatePolicyReferencesAreNeverDisclosedAndKeepRepairRevision(): void
    {
        foreach ([self::USER_ID, self::OTHER_USER_ID] as $owner) {
            $profile = [...$this->providerProfile(), 'ownerUserId' => $owner];
            $maturity = $this->createStub(AiMaturityStore::class);
            $maturity->method('providerProfile')->willReturn($profile);
            $maturity->method('providerProfiles')->willReturn($owner === self::USER_ID ? [$profile] : []);
            $maturity->method('orchestrationPolicy')->willReturn([
                'extractionProfileIds' => [self::PROFILE_ID], 'validationProfileId' => null,
                'maxAttempts' => 4, 'maxTotalTokens' => 50000,
                'maxEstimatedCostMicros' => 1000000, 'revision' => 7,
            ]);
            $service = $this->profileService($maturity);
            $policy = $service->orchestrationPolicy($this->identity(), self::HOME_ID);
            self::assertSame([], $policy['extractionProfileIds']);
            self::assertNull($policy['validationProfileId']);
            self::assertSame(7, $policy['revision']);
            self::assertSame($policy, $service->settings($this->identity(), self::HOME_ID)['orchestrationPolicy']);
        }
    }

    public function testSharedPolicyCannotSaveTheAuthorsPrivateProfile(): void
    {
        $maturity = $this->createMock(AiMaturityStore::class);
        $maturity->method('providerProfile')->willReturn([
            ...$this->providerProfile(), 'ownerUserId' => self::USER_ID,
        ]);
        $maturity->expects(self::never())->method('saveOrchestrationPolicy');
        $this->expectException(Problem::class);
        $this->expectExceptionMessage('shared profiles');
        $this->profileService($maturity)->putOrchestrationPolicy(
            $this->identity(), self::HOME_ID, [self::PROFILE_ID], null, 4, 50000, 1000000, 0,
        );
    }

CODE);
