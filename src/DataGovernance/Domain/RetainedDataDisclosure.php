<?php

declare(strict_types=1);

namespace Providentia\DataGovernance\Domain;

final class RetainedDataDisclosure
{
    /** @return list<array{category: string, treatment: string, reason: string}> */
    public static function forRequest(string $requestKind): array
    {
        if (! str_ends_with($requestKind, '_erasure')) {
            return [];
        }

        return [
            [
                'category' => 'security_and_audit_records',
                'treatment' => 'restricted_and_pseudonymized',
                'reason' => 'Security, fraud prevention, and accountability retention policy.',
            ],
            [
                'category' => 'approved_catalog_contributions',
                'treatment' => 'retained_without_private_attribution',
                'reason' => 'Published shared product knowledge remains useful to the community.',
            ],
            [
                'category' => 'shared_store_price_observations',
                'treatment' => 'retained_without_user_or_household_attribution',
                'reason' => 'Consented aggregate price history is not private stock information.',
            ],
            [
                'category' => 'billing_and_tax_records',
                'treatment' => 'restricted_until_applicable_retention_expires',
                'reason' => 'Records may be retained when required for financial compliance.',
            ],
            [
                'category' => 'encrypted_backups',
                'treatment' => 'ages_out_under_external_backup_retention',
                'reason' => 'Restic snapshots are immutable and expire through infrastructure policy.',
            ],
        ];
    }
}
