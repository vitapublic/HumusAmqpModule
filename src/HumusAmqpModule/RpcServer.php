<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace HumusAmqpModule;

use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;

/**
 * Class RpcServer
 * @package HumusAmqpModule
 */
class RpcServer extends Consumer
{
    /**
     * @var AMQPExchange
     */
    protected $exchange;

    /**
     * Constructor
     *
     * @param AMQPQueue $queue
     * @param float $idleTimeout in seconds
     * @param int $waitTimeout in microseconds
     */
    public function __construct(AMQPQueue $queue, $idleTimeout, $waitTimeout)
    {
        $queues = array($queue);
        parent::__construct($queues, $idleTimeout, $waitTimeout);
    }

    /**
     * Start consumer
     *
     * @param int $msgAmount
     */
    public function consume($msgAmount = 0)
    {
        // get first queue - we only have one queue for rpc server
        $this->queues->next();
        $queue = $this->getQueue();

        $queue->consume(
            function ($message) use ($queue) {
                $this->msgInProgress = true;
                if ($message instanceof AMQPEnvelope) {
                    try {
                        $processFlag = $this->handleDelivery($message, $queue);
                    } catch (\Exception $e) {
                        $this->handleDeliveryException($e);
                        $processFlag = false;
                    }
                    $this->handleProcessFlag($message, $processFlag);
                }
                $this->msgInProgress = false;

                if (false == $this->keepAlive) {
                    return false;
                }
            }
        );
    }

    /**
     * @param AMQPEnvelope $message
     * @param AMQPQueue $queue
     * @return bool|null
     * @triggers delivery
     */
    public function handleDelivery(AMQPEnvelope $message, AMQPQueue $queue)
    {
        try {
            $this->countMessagesConsumed++;
            $this->countMessagesUnacked++;
            $this->lastDeliveryTag = $message->getDeliveryTag();
            $this->timestampLastMessage = microtime(1);
            $this->ack();

            $params = compact('message', 'queue');
            $results = $this->getEventManager()->trigger('delivery', $this, $params);
            $result = $results->last();

            $response = json_encode(array('success' => true, 'result' => $result));
            $this->sendReply($response, $message->getReplyTo(), $message->getCorrelationId());
        } catch (\Exception $e) {
            $response = json_encode(array('success' => false, 'error' => $e->getMessage()));
            $this->sendReply($response, $message->getReplyTo(), $message->getCorrelationId());
        }
    }

    /**
     * Send reply to rpc client
     *
     * @param string $body
     * @param string $client
     * @param string $correlationId
     */
    protected function sendReply($body, $client, $correlationId)
    {
        $messageAttributes = new MessageAttributes();
        $messageAttributes->setCorrelationId($correlationId);

        $this->getExchange()->publish($body, $client, AMQP_NOPARAM, $messageAttributes->toArray());
    }

    /**
     * Handle process flag
     *
     * @param AMQPEnvelope $message
     * @param $flag
     * @return void
     */
    protected function handleProcessFlag(AMQPEnvelope $message, $flag)
    {
        // ignore, do nothing, message was already acked
    }

    /**
     * @return AMQPExchange
     */
    public function getExchange()
    {
        if (null !== $this->exchange) {
            return $this->exchange;
        }

        $channel = $this->getQueue()->getChannel();
        $exchange = new AMQPExchange($channel);
        $exchange->setType(AMQP_EX_TYPE_DIRECT);
        $this->exchange = $exchange;
        return $exchange;
    }

    /**
     * @param AMQPExchange $exchange
     * @return void
     */
    public function setExchange(AMQPExchange $exchange)
    {
        $this->exchange = $exchange;
    }
}
