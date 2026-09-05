<?php

declare(strict_types=1);

namespace Providentia\Identity\Application;

use Providentia\Access\Application\AccessService;
use Providentia\Catalog\Application\CatalogImageSanitizer;
use Providentia\Geography\Application\CountryService;
use Providentia\Home\Application\HomeAuthorization;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;

final class ProfileMediaService
{
    public function __construct(
        private readonly ProfileMediaStore $store,
        private readonly AccountProfileStore $profiles,
        private readonly HomeAuthorization $homes,
        private readonly AccessService $access,
        private readonly CountryService $countries,
        private readonly CatalogImageSanitizer $images,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed> */
    public function home(
        AuthenticatedIdentity $identity,
        string $id,
    ): array {
        $this->homes->requirePermission($identity, $id, 'home.read');
        return $this->store->homeProfile($id) ?? throw new Problem(
            404,
            'Home unavailable',
            'This home is unavailable.',
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function saveHome(
        AuthenticatedIdentity $identity,
        string $id,
        array $input,
    ): array {
        $this->homes->requirePermission($identity, $id, 'home.manage');
        $description = trim((string) ($input['description'] ?? ''));
        $place = $this->countries->validatePlace($input);
        $latitude = $input['latitude'] ?? null;
        $longitude = $input['longitude'] ?? null;
        if (
            mb_strlen($description) > 4000 || ($latitude === null) !== ($longitude === null)
            || $latitude !== null
            && (!is_numeric($latitude) || !is_numeric($longitude) || abs((float) $latitude) > 90
            || abs((float) $longitude) > 180)
        ) {
            throw new Problem(
                422,
                'Invalid home profile',
                'Use a description of up to 4000 characters and valid coordinates.',
            );
        }
        $values = [
            ...$place,
            'description' => $description,
            'latitude' => $latitude === null
                ? null
                : (string) $latitude,
            'longitude' => $longitude === null
                ? null
                : (string) $longitude,
            'updated_at' => $this->clock->now()
                ->format('Y-m-d H:i:s'),
        ];
        $this->transactions->transactional(
            function () use ($identity, $id, $values, $input): void {
                $this->access->serialize('home', $id);
                $this->homes->requirePermission($identity, $id, 'home.manage');
                if (
                    !$this->store->updateHomeProfile(
                        $id,
                        $values,
                        (int) ($input['expectedRevision'] ?? 0),
                    )
                ) {
                    throw new Problem(
                        409,
                        'Revision conflict',
                        'Reload the home profile.',
                    );
                }
            },
        );
        return $this->home($identity, $id);
    }

    /**
     * @return array{bytes: string, digest: string}|null */
    public function image(
        AuthenticatedIdentity $identity,
        string $scope,
        string $id,
        bool $operator = false,
    ): ?array {
        if ($operator) {
            $this->access->requireAdmin(
                $identity,
                $scope === 'account'
                    ? 'people.read'
                    : 'homes.read',
            );
        } elseif ($scope === 'home') {
            $this->homes->requirePermission($identity, $id, 'home.read');
        } elseif ($identity->userId !== $id && !$this->store->sharesHome($identity->userId, $id)) {
            throw new Problem(
                404,
                'Image unavailable',
                'This profile image is unavailable.',
            );
        }
        return $this->store->image($scope, $id);
    }

    public function saveImage(
        AuthenticatedIdentity $identity,
        string $scope,
        string $id,
        ?string $bytes,
        int $revision,
    ): void {
        if ($scope === 'home') {
            $this->homes->requirePermission($identity, $id, 'home.manage');
        } elseif ($scope !== 'account' || $id !== $identity->userId) {
            throw new Problem(
                403,
                'Profile unavailable',
                'You can only edit your own avatar.',
            );
        }
        $image = $bytes === null
            ? null
            : $this->images->sanitize($bytes);
        $this->transactions->transactional(
            function () use ($identity, $scope, $id, $image, $revision): void {
                $this->access->serialize($scope, $id);
                if ($scope === 'home') {
                    $this->homes->requirePermission($identity, $id, 'home.manage');
                }
                if (
                    !$this->store->saveImage(
                        $scope,
                        $id,
                        $image?->bytes,
                        $image?->digest,
                        $revision,
                        $this->clock->now()
                        ->format('Y-m-d H:i:s'),
                    )
                ) {
                    throw new Problem(
                        409,
                        'Revision conflict',
                        'Reload the profile image before replacing it.',
                    );
                }
            },
        );
    }

    public function selectGravatar(
        AuthenticatedIdentity $identity,
        string $emailId,
        int $revision,
    ): void {
        $emails = $this->profiles->emails($identity->userId);
        if (!in_array($emailId, array_column($emails, 'id'), true)) {
            throw new Problem(
                422,
                'Verified email required',
                'Choose one of your verified email addresses.',
            );
        }
        if (
            !$this->profiles->update(
                $identity->userId,
                [
                'avatar_source' => 'gravatar',
                'avatar_email_id' => $emailId,
                'updated_at' => $this->clock->now()
                    ->format('Y-m-d H:i:s'),
                ],
                $revision,
            )
        ) {
            throw new Problem(409, 'Revision conflict', 'Reload your profile.');
        }
    }
}
