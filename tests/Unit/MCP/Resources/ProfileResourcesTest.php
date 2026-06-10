<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Resources;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Resources\ProfileResources;
use XLite\Model\Profile;

class ProfileResourcesTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $profileRepo;
    private ProfileResources $resources;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->profileRepo = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')
            ->with(Profile::class)
            ->willReturn($this->profileRepo);

        $this->resources = new ProfileResources($this->em);
    }

    // ── getProfileStats ──

    public function testGetProfileStats(): void
    {
        // Total customers
        $totalQuery = $this->createMock(AbstractQuery::class);
        $totalQuery->method('getSingleScalarResult')->willReturn(500);
        $totalQb = $this->createMock(QueryBuilder::class);
        $totalQb->method('select')->willReturnSelf();
        $totalQb->method('from')->willReturnSelf();
        $totalQb->method('where')->willReturnSelf();
        $totalQb->method('setParameter')->willReturnSelf();
        $totalQb->method('getQuery')->willReturn($totalQuery);

        // New this month
        $newQuery = $this->createMock(AbstractQuery::class);
        $newQuery->method('getSingleScalarResult')->willReturn(25);
        $newQb = $this->createMock(QueryBuilder::class);
        $newQb->method('select')->willReturnSelf();
        $newQb->method('from')->willReturnSelf();
        $newQb->method('where')->willReturnSelf();
        $newQb->method('andWhere')->willReturnSelf();
        $newQb->method('setParameter')->willReturnSelf();
        $newQb->method('getQuery')->willReturn($newQuery);

        // Top spenders
        $topQuery = $this->createMock(AbstractQuery::class);
        $topQuery->method('getResult')->willReturn([
            [
                'profile_id' => 1,
                'email' => 'john@example.com',
                'firstname' => 'John',
                'lastname' => 'Doe',
                'orders_count' => '10',
                'total_spent' => '5000.50',
            ],
        ]);
        $topQb = $this->createMock(QueryBuilder::class);
        $topQb->method('select')->willReturnSelf();
        $topQb->method('from')->willReturnSelf();
        $topQb->method('innerJoin')->willReturnSelf();
        $topQb->method('where')->willReturnSelf();
        $topQb->method('setParameter')->willReturnSelf();
        $topQb->method('groupBy')->willReturnSelf();
        $topQb->method('orderBy')->willReturnSelf();
        $topQb->method('setMaxResults')->willReturnSelf();
        $topQb->method('getQuery')->willReturn($topQuery);

        $this->em
            ->expects($this->exactly(3))
            ->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($totalQb, $newQb, $topQb);

        $result = $this->resources->getProfileStats();

        $this->assertSame(500, $result['total_customers']);
        $this->assertSame(25, $result['new_this_month']);
        $this->assertCount(1, $result['top_spenders']);
        $this->assertSame(1, $result['top_spenders'][0]['id']);
        $this->assertSame('john@example.com', $result['top_spenders'][0]['email']);
        $this->assertSame('John Doe', $result['top_spenders'][0]['name']);
        $this->assertSame(10, $result['top_spenders'][0]['orders_count']);
        $this->assertSame(5000.50, $result['top_spenders'][0]['total_spent']);
    }

    // ── getProfile ──

    public function testGetProfileNotFound(): void
    {
        $this->profileRepo
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Profile #999 not found');

        $this->resources->getProfile(999);
    }

    public function testGetProfile(): void
    {
        $profile = $this->createMock(Profile::class);
        $profile->method('getProfileId')->willReturn(42);
        $profile->method('getLogin')->willReturn('jane@example.com');
        $profile->method('getFirstname')->willReturn('Jane');
        $profile->method('getLastname')->willReturn('Smith');
        $profile->method('getAdded')->willReturn(1700000000);
        $profile->method('getLastLogin')->willReturn(1706000000);
        $profile->method('getStatus')->willReturn('E');
        $profile->method('getMembership')->willReturn(null);
        $profile->method('getLanguage')->willReturn('en');
        $profile->method('getAddresses')->willReturn(new \Doctrine\Common\Collections\ArrayCollection());

        $this->profileRepo->method('find')->with(42)->willReturn($profile);

        $orderStatsQuery = $this->createMock(AbstractQuery::class);
        $orderStatsQuery->method('getSingleResult')->willReturn([
            'orders_count' => '5',
            'total_spent' => '750.00',
            'last_order_date' => '1705000000',
        ]);

        $orderStatsQb = $this->createMock(QueryBuilder::class);
        $orderStatsQb->method('select')->willReturnSelf();
        $orderStatsQb->method('from')->willReturnSelf();
        $orderStatsQb->method('where')->willReturnSelf();
        $orderStatsQb->method('setParameter')->willReturnSelf();
        $orderStatsQb->method('getQuery')->willReturn($orderStatsQuery);

        $this->em
            ->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($orderStatsQb);

        $result = $this->resources->getProfile(42);

        $this->assertSame(42, $result['id']);
        $this->assertSame('jane@example.com', $result['email']);
        $this->assertSame('Jane', $result['first_name']);
        $this->assertSame('Smith', $result['last_name']);
        $this->assertSame('E', $result['status']);
        $this->assertNull($result['membership']);
        $this->assertSame('en', $result['language']);
        $this->assertSame(5, $result['orders_count']);
        $this->assertSame(750.00, $result['total_spent']);
        $this->assertEmpty($result['addresses']);
    }
}
