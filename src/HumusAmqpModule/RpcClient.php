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

use AMQPExchange;
use AMQPQueue;
use Zend\EventManager\EventManagerAwareTrait;

/**
 * Class RpcClient
 * @package HumusAmqpModule
 */
class RpcClient
{
    use EventManagerAwareTrait;
    /**
     * @var AMQPQueue
     */
    protected $queue;

    /**
     * @var array
     */
    protected $requests = [];

    /**
     * @var array
     */
    protected $replies = [];

    /**
     * @var int
     */
    protected $timeout = 0;

    /**
     * @var AMQPExchange[]
     */
    protected $exchanges = [];

    /**
     * Constructor
     *
     * @param AMQPQueue $queue
     */
    public function __construct(AMQPQueue $queue)
    {
        $this->queue = $queue;
    }

    /**
     * Add a request to rpc client
     *
     * @param string $msgBody
     * @param string $server
     * @param string $requestId
     * @param string $routingKey
     * @param int $expiration
     * @param array $headers
     * @throws Exception\InvalidArgumentException
     */
    public function addRequest($msgBody, $server, $requestId, $routingKey = '', $expiration = 0, $headers = [])
    {
        if (empty($requestId)) {
            throw new Exception\InvalidArgumentException('You must provide a request Id');
        }

        $params = compact('msgBody', 'server', 'requestId', 'routingKey', 'expiration', 'headers');
        $results = $this->getEventManager()->trigger(__FUNCTION__, $this, $params);
        $result = $results->last();

        if (is_array($result)) {
            $msgBody    = $result['msgBody'];
            $server     = $result['server'];
            $requestId  = $result['requestId'];
            $routingKey = $result['routingKey'];
            $expiration = $result['expiration'];
            $headers    = $result['headers'];
        }

        $messageAttributes = new MessageAttributes();
        $messageAttributes->setReplyTo($this->queue->getName());
        $messageAttributes->setDeliveryMode(MessageAttributes::DELIVERY_MODE_NON_PERSISTENT);
        $messageAttributes->setCorrelationId($requestId);
        $messageAttributes->setHeaders($headers);

        if (0 != $expiration) {
            $messageAttributes->setExpiration($expiration * 1000);
        }

        $exchange = $this->getExchange($server);
        $exchange->publish($msgBody, $routingKey, $messageAttributes->getFlags(), $messageAttributes->toArray());
        $this->requests[$requestId] = true;

        if ($expiration > $this->timeout) {
            $this->timeout = $expiration;
        }
    }

    /**
     * Get rpc client replies
     *
     * Example:
     *
     * array(
     *     'message_id_1' => 'foo',
     *     'message_id_2' => 'bar'
     * )
     *
     * @return array
     */
    public function getReplies()
    {
        $now = microtime(1);
        $this->replies = [];
        do {
            $message = $this->queue->get(AMQP_AUTOACK);

            if ($message && array_key_exists($message->getCorrelationId(), $this->requests)) {
                $this->replies[$message->getCorrelationId()] = $message->getBody();
            } else {
                usleep(1000); // 1/1000 sec
            }

            $time = microtime(1);
        } while (
            (count($this->replies) < count($this->requests))
            && (($time - $now) < $this->timeout && $this->timeout > 0)
        );

        $this->requests = [];
        $this->timeout = 0;

        return $this->replies;
    }

    /**
     * @param string $name
     * @return AMQPExchange
     */
    protected function getExchange($name)
    {
        if (isset($this->exchanges[$name])) {
            return $this->exchanges[$name];
        }

        $channel = $this->queue->getChannel();
        $exchange = new AMQPExchange($channel);
        $exchange->setType(AMQP_EX_TYPE_DIRECT);
        $exchange->setName($name);
        $this->exchanges[$name] = $exchange;
        return $exchange;
    }

    /**
     * @return null
     */
    public function getEventManager()
    {
        return $this->eventManager;
    }

    /**
     * @param null $eventManager
     */
    public function setEventManager($eventManager)
    {
        $this->eventManager = $eventManager;
    }

}
