<?php

declare(strict_types=1);

namespace Providentia\Geography\Application;

use DateTimeZone;
use Providentia\Access\Application\AccessService;
use Providentia\Access\Application\AccessStore;
use Providentia\Access\Domain\FeatureCatalog;
use Providentia\Identity\Application\AuthenticatedIdentity;
use Providentia\SharedKernel\Application\Clock;
use Providentia\SharedKernel\Application\Problem;
use Providentia\SharedKernel\Application\TransactionManager;
use Providentia\SharedKernel\Application\UuidGenerator;

final class CountryService
{
    public function __construct(
        private readonly CountryStore $store,
        private readonly AccessService $access,
        private readonly AccessStore $groups,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly UuidGenerator $ids,
    ) {
    }

    /**
     * @return list<array<string, mixed>> */
    public function countries(
        ?AuthenticatedIdentity $admin = null,
    ): array {
        if ($admin !== null) {
            $this->access->requireAdmin($admin, 'countries.manage');
        }
        return $this->store->countries($admin === null);
    }

    /**
     * @return array<string, mixed> */
    public function published(string $code): array
    {
        $settings = $this->store->settings(strtoupper($code));
        if ($settings === null || !(bool) $settings['published']) {
            throw new Problem(
                422,
                'Country unavailable',
                'Registration is not open in this country.',
            );
        }
        return $settings;
    }

    /**
     * @return array<string, mixed> */
    public function registrationPolicy(string $country): array
    {
        $settings = $this->published($country);
        $policy = $this->store->policy((string) $settings['policy_id']);
        if ($policy === null || $policy['status'] !== 'published') {
            throw new Problem(
                503,
                'Policy unavailable',
                'The country privacy notice is unavailable.',
            );
        }
        return $policy;
    }

    /**
     * @return list<array<string, mixed>> */
    public function places(
        string $country,
        ?int $state,
        string $query,
        bool $cities,
        int $offset,
    ): array {
        $this->published($country);
        return $this->store->places(
            $country,
            $state,
            mb_substr($query, 0, 100),
            $cities,
            $offset,
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{country_code: string, state_id: int|null, city_id: int|null}
     */
    public function validatePlace(array $input): array
    {
        $country = strtoupper((string) ($input['countryCode'] ?? ''));
        $this->published($country);
        $state = isset($input['stateId'])
            ? (int) $input['stateId']
            : null;
        $city = isset($input['cityId'])
            ? (int) $input['cityId']
            : null;
        if (!$this->store->validPlace($country, $state, $city)) {
            throw new Problem(
                422,
                'Invalid location',
                'Select a region and city belonging to this country.',
            );
        }
        return [
            'country_code' => $country,
            'state_id' => $state,
            'city_id' => $city,
        ];
    }

    public function accept(
        string $userId,
        string $country,
        string $policyId,
        int $revision,
    ): void {
        $policy = $this->registrationPolicy($country);
        if ($policy['id'] !== $policyId || (int) $policy['revision'] !== $revision) {
            throw new Problem(
                409,
                'Policy changed',
                'Read and accept the current privacy notice.',
            );
        }
        $this->store->acceptPolicy(
            $userId,
            $policyId,
            $revision,
            $country,
            $this->clock->now()
                ->format('Y-m-d H:i:s'),
        );
    }

    /**
     * @return array<string, mixed> */
    public function settings(
        AuthenticatedIdentity $admin,
        string $country,
    ): array {
        $this->access->requireAdmin($admin, 'countries.manage');
        return $this->store->settings($country) ?? throw new Problem(
            404,
            'Country unavailable',
            'Import the country reference data first.',
        );
    }

    /**
     * @param array<string, mixed> $input */
    public function configure(
        AuthenticatedIdentity $admin,
        string $country,
        array $input,
    ): void {
        $this->access->requireAdmin($admin, 'countries.manage');
        $values = [];
        foreach (
            [
            'accountGroupId' => 'account_group_id',
            'invitedGroupId' => 'invited_group_id',
            'homeGroupId' => 'home_group_id',
            ] as $key => $column
        ) {
            $id = (string) ($input[$key] ?? '');
            $group = $this->groups->group($id);
            if (
                $group === null || $group['scope'] !== ($key === 'homeGroupId'
                ? FeatureCatalog::HOME
                : FeatureCatalog::ACCOUNT)
            ) {
                throw new Problem(
                    422,
                    'Invalid group',
                    'Choose a default group of the matching scope.',
                );
            }
            $values[$column] = $id;
        }
        $policy = $this->store->policy((string) ($input['policyId'] ?? ''));
        if (
            $policy === null || $policy['status'] !== 'published'
            || $policy['country_code'] !== null && $policy['country_code'] !== $country
        ) {
            throw new Problem(
                422,
                'Invalid policy',
                'Choose a published policy for this country or the shared default.',
            );
        }
        $currency = strtoupper((string) ($input['defaultCurrency'] ?? ''));
        $timezone = (string) ($input['defaultTimezone'] ?? '');
        if (
            preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || !in_array($timezone, DateTimeZone::listIdentifiers(), true)
            || !is_bool($input['published'] ?? null)
        ) {
            throw new Problem(
                422,
                'Invalid defaults',
                'Provide currency, timezone and publication status.',
            );
        }
        $values += [
            'policy_id' => $policy['id'],
            'default_currency' => $currency,
            'default_timezone' => $timezone,
            'published' => (int) $input['published'],
            'updated_at' => $this->clock->now()
                ->format('Y-m-d H:i:s'),
        ];
        $this->transactions->transactional(
            function () use ($admin, $country, $values, $input): void {
                if (
                    !$this->store->saveSettings(
                        $country,
                        $values,
                        (int) ($input['expectedRevision'] ?? 0),
                    )
                ) {
                    throw new Problem(
                        409,
                        'Revision conflict',
                        'Reload country settings.',
                    );
                }
                $this->groups->audit(
                    $admin->userId,
                    'country.configured',
                    'country',
                    $country,
                    $values,
                );
            },
        );
    }

    /**
     * @return list<array<string, mixed>> */
    public function policies(
        AuthenticatedIdentity $admin,
        ?string $country,
    ): array {
        $this->access->requireAdmin($admin, 'policies.manage');
        return $this->store->policies($country);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function savePolicy(
        AuthenticatedIdentity $admin,
        ?string $id,
        array $input,
    ): array {
        $this->access->requireAdmin($admin, 'policies.manage');
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $status = (string) ($input['status'] ?? 'draft');
        $country = isset($input['countryCode'])
            ? strtoupper((string) $input['countryCode'])
            : null;
        if (
            $title === '' || mb_strlen($title) > 160 || strlen($body) < 100 || strlen($body) > 100000
            || !in_array($status, ['draft', 'published'], true)
            || $country !== null && $this->store->settings($country) === null
        ) {
            throw new Problem(
                422,
                'Invalid policy',
                'Supply a title, policy text, valid country and draft or published status.',
            );
        }
        $id ??= $this->ids->generate();
        $now = $this->clock->now()
            ->format('Y-m-d H:i:s');
        $values = [
            'country_code' => $country,
            'title' => $title,
            'body' => $body,
            'status' => $status,
            'updated_at' => $now,
            'published_at' => $status === 'published'
                ? $now
                : null,
        ];
        $this->transactions->transactional(
            function () use ($admin, $id, $values, $input): void {
                if (
                    !$this->store->savePolicy(
                        $id,
                        $values,
                        (int) ($input['expectedRevision'] ?? 0),
                    )
                ) {
                    throw new Problem(
                        409,
                        'Policy locked or changed',
                        'Published policies are immutable. Create a new version or reload the draft.',
                    );
                }
                $this->groups->audit(
                    $admin->userId,
                    'policy.saved',
                    'policy',
                    $id,
                    ['status' => $values['status']],
                );
            },
        );
        return $this->store->policy($id) ?? throw new \LogicException('Saved policy missing.');
    }

    /**
     * @return list<array<string, mixed>> */
    public function jobs(AuthenticatedIdentity $admin): array
    {
        $this->access->requireAdmin($admin, 'countries.manage');
        return $this->store->jobs();
    }

    /**
     * @return array{id: string, status: string} */
    public function requestUpdate(AuthenticatedIdentity $admin): array
    {
        $this->access->requireAdmin($admin, 'countries.manage');
        $id = $this->ids->generate();
        $this->store->requestUpdate(
            $id,
            $admin->userId,
            $this->clock->now()
                ->format('Y-m-d H:i:s'),
        );
        return ['id' => $id, 'status' => 'queued'];
    }
}
