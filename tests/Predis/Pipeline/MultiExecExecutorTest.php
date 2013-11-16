<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Pipeline;

use \PHPUnit_Framework_TestCase as StandardTestCase;

use SplQueue;
use Predis\Profile\ServerProfile;
use Predis\Response;

/**
 *
 */
class MultiExecExecutorTest extends StandardTestCase
{
    /**
     * @group disconnected
     */
    public function testExecutorWithSingleConnection()
    {
        $executor = new MultiExecExecutor();
        $pipeline = $this->getCommandsQueue();
        $queued = new Response\StatusQueued();

        $connection = $this->getMock('Predis\Connection\SingleConnectionInterface');
        $connection->expects($this->exactly(2))
                   ->method('executeCommand')
                   ->will($this->onConsecutiveCalls(true, array('PONG', 'PONG', 'PONG')));
        $connection->expects($this->exactly(3))
                   ->method('writeCommand');
        $connection->expects($this->at(3))
                   ->method('readResponse')
                   ->will($this->onConsecutiveCalls($queued, $queued, $queued));

        $replies = $executor->execute($connection, $pipeline);

        $this->assertTrue($pipeline->isEmpty());
        $this->assertSame(array(true, true, true), $replies);
    }

    /**
     * @group disconnected
     * @expectedException Predis\ClientException
     * @expectedExceptionMessage The underlying transaction has been aborted by the server
     */
    public function testExecutorWithAbortedTransaction()
    {
        $executor = new MultiExecExecutor();
        $pipeline = $this->getCommandsQueue();

        $connection = $this->getMock('Predis\Connection\SingleConnectionInterface');
        $connection->expects($this->exactly(2))
                   ->method('executeCommand')
                   ->will($this->onConsecutiveCalls(true, null));

        $executor->execute($connection, $pipeline);
    }

    /**
     * @group disconnected
     * @expectedException Predis\Response\ServerException
     * @expectedExceptionMessage ERR Test error
     */
    public function testExecutorWithErrorInTransaction()
    {
        $executor = new MultiExecExecutor();
        $pipeline = $this->getCommandsQueue();
        $queued = new Response\StatusQueued();
        $error = new Response\Error('ERR Test error');

        $connection = $this->getMock('Predis\Connection\SingleConnectionInterface');
        $connection->expects($this->at(0))
                   ->method('executeCommand')
                   ->will($this->returnValue(true));
        $connection->expects($this->exactly(3))
                   ->method('readResponse')
                   ->will($this->onConsecutiveCalls($queued, $queued, $error));
        $connection->expects($this->at(7))
                   ->method('executeCommand')
                   ->with($this->isInstanceOf('Predis\Command\TransactionDiscard'));

        $executor->execute($connection, $pipeline);
    }

    /**
     * @group disconnected
     */
    public function testExecutorWithErrorInCommandResponse()
    {
        $executor = new MultiExecExecutor();
        $pipeline = $this->getCommandsQueue();
        $queued = new Response\StatusQueued();
        $error = new Response\Error('ERR Test error');

        $connection = $this->getMock('Predis\Connection\SingleConnectionInterface');
        $connection->expects($this->exactly(3))
                   ->method('readResponse')
                   ->will($this->onConsecutiveCalls($queued, $queued, $queued));
        $connection->expects($this->at(7))
                   ->method('executeCommand')
                   ->will($this->returnValue(array('PONG', 'PONG', $error)));

        $replies = $executor->execute($connection, $pipeline);

        $this->assertSame(array(true, true, $error), $replies);
    }

    /**
     * @group disconnected
     * @expectedException Predis\ClientException
     * @expectedExceptionMessage Predis\Pipeline\MultiExecExecutor can be used only with single connections
     */
    public function testExecutorWithAggregatedConnection()
    {
        $executor = new MultiExecExecutor();
        $pipeline = $this->getCommandsQueue();

        $connection = $this->getMock('Predis\Connection\ReplicationConnectionInterface');

        $replies = $executor->execute($connection, $pipeline);
    }

    // ******************************************************************** //
    // ---- HELPER METHODS ------------------------------------------------ //
    // ******************************************************************** //

    /**
     * Returns a list of queued command instances.
     *
     * @return SplQueue
     */
    protected function getCommandsQueue()
    {
        $profile = ServerProfile::getDevelopment();

        $pipeline = new SplQueue();
        $pipeline->enqueue($profile->createCommand('ping'));
        $pipeline->enqueue($profile->createCommand('ping'));
        $pipeline->enqueue($profile->createCommand('ping'));

        return $pipeline;
    }
}
