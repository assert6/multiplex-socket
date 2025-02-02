<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Multiplex\Socket;

use Multiplex\Contract\HasHeartbeatInterface;
use Multiplex\Contract\HasSerializerInterface;
use Multiplex\Contract\PackerInterface;
use Multiplex\Contract\SerializerInterface;
use Multiplex\Contract\ServerInterface;
use Multiplex\Exception\ServerBindFailedException;
use Multiplex\Exception\ServerStartFailedException;
use Multiplex\Packer;
use Multiplex\Packet;
use Multiplex\Serializer\StringSerializer;
use Swoole\Coroutine;
use Swoole\Coroutine\Server as SwooleServer;
use Swoole\Coroutine\Server\Connection;
use Throwable;

class Server implements ServerInterface, HasSerializerInterface
{
    protected Packer $packer;

    protected SerializerInterface $serializer;

    protected ?SwooleServer $server = null;

    /**
     * @var callable
     */
    protected mixed $handler;

    public function __construct(?SerializerInterface $serializer = null, ?PackerInterface $packer = null)
    {
        $this->packer = $packer ?? new Packer();
        $this->serializer = $serializer ?? new StringSerializer();
    }

    public function bind(string $name, int $port, array $config): static
    {
        if ($this->server) {
            throw new ServerBindFailedException('The server should not be bound more than once.');
        }
        $this->server = new SwooleServer($name, $port);
        $this->server->set([
            'open_length_check' => true,
            'package_max_length' => $config['package_max_length'] ?? 1024 * 1024 * 2,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
        ]);
        return $this;
    }

    public function handle(callable $callable): static
    {
        $this->handler = $callable;
        return $this;
    }

    public function getSerializer(): SerializerInterface
    {
        return $this->serializer;
    }

    public function start(): void
    {
        if (! $this->server instanceof SwooleServer) {
            throw new ServerStartFailedException('The server must be bound.');
        }
        if (! is_callable($this->handler)) {
            throw new ServerStartFailedException('The handler must be bound.');
        }
        $this->server->handle(function (Connection $conn) {
            while (true) {
                $ret = $conn->recv();
                if (empty($ret)) {
                    break;
                }

                Coroutine::create(function () use ($ret, $conn) {
                    $packet = $this->packer->unpack($ret);

                    if ($packet->isHeartbeat()) {
                        $conn->send($this->packer->pack(new Packet(0, HasHeartbeatInterface::PONG)));
                        return;
                    }

                    $id = $packet->getId();
                    try {
                        $result = $this->handler->__invoke($packet, $this->getSerializer());
                    } catch (Throwable $exception) {
                        $result = $exception;
                    } finally {
                        $conn->send($this->packer->pack(new Packet($id, $this->getSerializer()->serialize($result))));
                    }
                });
            }
        });

        $this->server->start();
    }
}
