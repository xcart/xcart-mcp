<?php

declare(strict_types=1);

namespace XC\MCP\MCP\Resources;

use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Exception\ToolCallException;
use XLite\Model\Profile;
use XLite\Model\Order;

class ProfileResources
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[McpResource(
        uri: 'xcart://profiles/stats',
        name: 'profile_stats',
        title: 'Profile Stats',
        description: 'Customer statistics: total, new this month, top spenders',
        mimeType: 'application/json'
    )]
    public function getProfileStats(): array
    {
        // Total customers (non-admin profiles with order association or customer role)
        $totalCustomers = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.profile_id)')
            ->from(Profile::class, 'p')
            ->where('p.access_level = :level')
            ->setParameter('level', 0)
            ->getQuery()
            ->getSingleScalarResult();

        // New customers this month
        $monthStart = strtotime('first day of this month midnight');
        $newThisMonth = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.profile_id)')
            ->from(Profile::class, 'p')
            ->where('p.access_level = :level')
            ->andWhere('p.added >= :monthStart')
            ->setParameter('level', 0)
            ->setParameter('monthStart', $monthStart)
            ->getQuery()
            ->getSingleScalarResult();

        // Top spenders
        $topSpenders = $this->em->createQueryBuilder()
            ->select(
                'p.profile_id',
                'p.login AS email',
                'COUNT(o.order_id) AS orders_count',
                'SUM(o.total) AS total_spent',
            )
            ->from(Order::class, 'o')
            ->innerJoin('o.profile', 'p')
            ->where('p.access_level = :level')
            ->setParameter('level', 0)
            ->groupBy('p.profile_id', 'p.login')
            ->orderBy('total_spent', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $topSpendersFormatted = [];
        foreach ($topSpenders as $row) {
            $topSpendersFormatted[] = [
                'id' => $row['profile_id'],
                'email' => $row['email'],
                'orders_count' => (int) $row['orders_count'],
                'total_spent' => round((float) $row['total_spent'], 2),
            ];
        }

        return [
            'total_customers' => $totalCustomers,
            'new_this_month' => $newThisMonth,
            'top_spenders' => $topSpendersFormatted,
        ];
    }

    #[McpResourceTemplate(
        uriTemplate: 'xcart://profiles/{profileId}',
        name: 'profile_detail',
        title: 'Profile Detail',
        description: 'Customer profile: name, email, orders count, total spent, addresses. No passwords or payment data.',
        mimeType: 'application/json'
    )]
    public function getProfile(int $profileId): array
    {
        $profile = $this->em->getRepository(Profile::class)->find($profileId);

        if (!$profile) {
            throw new ToolCallException("Profile #{$profileId} not found");
        }

        // Order statistics for this customer
        $orderStats = $this->em->createQueryBuilder()
            ->select(
                'COUNT(o.order_id) AS orders_count',
                'COALESCE(SUM(o.total), 0) AS total_spent',
                'MAX(o.date) AS last_order_date',
            )
            ->from(Order::class, 'o')
            ->where('o.profile = :profile')
            ->setParameter('profile', $profile)
            ->getQuery()
            ->getSingleResult();

        // Addresses
        $addresses = [];
        foreach ($profile->getAddresses() as $address) {
            $addresses[] = [
                'id' => $address->getAddressId(),
                'name' => trim(
                    ($address->getFirstname() ?? '') . ' ' . ($address->getLastname() ?? '')
                ),
                'street' => $address->getStreet(),
                'city' => $address->getCity(),
                'state' => $address->getState()?->getCode(),
                'zipcode' => $address->getZipcode(),
                'country' => $address->getCountry()?->getCode(),
                'phone' => $address->getPhone(),
                'is_billing' => $address->getIsBilling(),
                'is_shipping' => $address->getIsShipping(),
            ];
        }

        // Membership
        $membership = $profile->getMembership();

        return [
            'id' => $profile->getProfileId(),
            'email' => $profile->getLogin(),
            'registered' => $profile->getAdded()
                ? date('c', $profile->getAdded())
                : null,
            'last_login' => $profile->getLastLogin()
                ? date('c', $profile->getLastLogin())
                : null,
            'status' => $profile->getStatus(),
            'membership' => $membership ? [
                'id' => $membership->getMembershipId(),
                'name' => $membership->getName(),
            ] : null,
            'language' => $profile->getLanguage(),
            'orders_count' => (int) $orderStats['orders_count'],
            'total_spent' => round((float) $orderStats['total_spent'], 2),
            'last_order_date' => $orderStats['last_order_date']
                ? date('c', (int) $orderStats['last_order_date'])
                : null,
            'addresses' => $addresses,
        ];
    }
}
