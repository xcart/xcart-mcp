<?php

declare(strict_types=1);

namespace Tests\Unit\MCP\Tools;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use XC\MCP\MCP\Tools\OrderTools;
use XLite\Model\Order;
use XLite\Model\OrderHistoryEvents;

class OrderToolsTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $orderRepo;
    private OrderTools $tools;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->orderRepo = $this->createMock(EntityRepository::class);

        $this->em->method('getRepository')
            ->with(Order::class)
            ->willReturn($this->orderRepo);

        $this->tools = new OrderTools($this->em);
    }

    public function testUpdateOrderStatusValid(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(100);
        $order->method('getOrderNumber')->willReturn('XC-100');
        $order->method('getPaymentStatus')->willReturn('Pending');
        $order->method('getShippingStatus')->willReturn('New');
        $order->expects($this->once())->method('setPaymentStatus')->with('Paid');

        $this->orderRepo
            ->expects($this->once())
            ->method('find')
            ->with(100)
            ->willReturn($order);

        $this->em->expects($this->once())->method('flush');

        $result = $this->tools->updateOrderStatus(
            orderId: 100,
            paymentStatus: 'Paid',
        );

        $this->assertSame(100, $result['id']);
        $this->assertSame('XC-100', $result['order_number']);
        $this->assertSame('Order status updated', $result['message']);
        $this->assertNotEmpty($result['changes']);
    }

    public function testUpdateOrderStatusInvalidStatus(): void
    {
        $order = $this->createMock(Order::class);

        $this->orderRepo
            ->expects($this->once())
            ->method('find')
            ->with(100)
            ->willReturn($order);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Invalid payment status 'InvalidStatus'");

        $this->tools->updateOrderStatus(
            orderId: 100,
            paymentStatus: 'InvalidStatus',
        );
    }

    public function testSearchOrdersWithDateRange(): void
    {
        $profile = $this->createMock(\XLite\Model\Profile::class);
        $profile->method('getLogin')->willReturn('customer@example.com');
        $profile->method('getFirstname')->willReturn('John');
        $profile->method('getLastname')->willReturn('Doe');

        $orderDate = new \DateTime('2026-01-15 10:30:00');

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(200);
        $order->method('getOrderNumber')->willReturn('XC-200');
        $order->method('getTotal')->willReturn(149.99);
        $order->method('getPaymentStatus')->willReturn('Paid');
        $order->method('getShippingStatus')->willReturn('Processing');
        $order->method('getDate')->willReturn($orderDate);
        $order->method('getProfile')->willReturn($profile);

        $resultQuery = $this->createMock(AbstractQuery::class);
        $resultQuery->method('getResult')->willReturn([$order]);

        $countQuery = $this->createMock(AbstractQuery::class);
        $countQuery->method('getSingleScalarResult')->willReturn(1);

        $countQb = $this->createMock(QueryBuilder::class);
        $countQb->method('select')->willReturnSelf();
        $countQb->method('getQuery')->willReturn($countQuery);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('setFirstResult')->willReturnSelf();
        $qb->method('getQuery')->willReturn($resultQuery);

        $this->em
            ->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($qb);

        // dateFrom + dateTo = 2 andWhere calls
        $qb->expects($this->exactly(2))
            ->method('andWhere')
            ->willReturnSelf();

        $result = $this->tools->searchOrders(
            dateFrom: '2026-01-01',
            dateTo: '2026-01-31',
            limit: 10,
        );

        $this->assertArrayHasKey('total_found', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertCount(1, $result['items']);
        $this->assertSame(200, $result['items'][0]['id']);
        $this->assertSame('XC-200', $result['items'][0]['order_number']);
        $this->assertSame(149.99, $result['items'][0]['total']);
        $this->assertSame('Paid', $result['items'][0]['payment_status']);
    }

    public function testAddOrderNote(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(100);
        $order->method('getOrderNumber')->willReturn('XC-100');

        $this->orderRepo
            ->expects($this->once())
            ->method('find')
            ->with(100)
            ->willReturn($order);

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(OrderHistoryEvents::class));

        $this->em->expects($this->once())->method('flush');

        $result = $this->tools->addOrderNote(
            orderId: 100,
            note: 'Contacted customer about delivery',
        );

        $this->assertSame(100, $result['id']);
        $this->assertSame('XC-100', $result['order_number']);
        $this->assertSame('Contacted customer about delivery', $result['note']);
        $this->assertSame('Note added to order', $result['message']);
    }
}
