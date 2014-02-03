<?php
namespace Chabot\Socket;

/**
 * <pre>phpSocketDaemon 1.0
 * Copyright (C) 2006 Chris Chabot <chabotc@xs4all.nl>
 * See http://www.chabotc.nl/ for more information</pre>
 *
 * <p>This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.</p>
 *
 * <p>This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.</p>
 *
 * <p>You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA</p>
 */
class SocketDaemon {
    public $servers = array();
	public $clients = array();

    protected $tv_sec;
    protected $tv_usec;

    public function __construct($tv_sec = 2, $tv_usec = 0) {
        $this->tv_sec  = $tv_sec;
        $this->tv_usec = $tv_usec;
    }

	public function create_server($server_class, $client_class = null, $bind_address = 0, $bind_port = 0, $domain = AF_INET, $type = SOCK_STREAM, $protocol = SOL_TCP)
	{
        if (func_num_args() < 4 && preg_match('#[.:]|^0?$#', $client_class)) {
            $bind_port    = $bind_address;
            $bind_address = $client_class;
            $client_class = $server_class;
            $server_class = null;
        }
        if (!$server_class) {
            $server_class = __NAMESPACE__ . '\\Server';
        }
		$server = new $server_class($client_class, $bind_address, $bind_port, $domain, $type, $protocol);
		if (!$server instanceof Server) {
			throw new Exception("Invalid server class specified! Has to be a subclass of SocketServer");
		}
		$this->servers[(int)$server->socket] = $server;
		return $server;
	}

	public function create_client($client_class, $remote_address, $remote_port, $bind_address = 0, $bind_port = 0)
	{
        /** @var SocketClient $client */
		$client = new $client_class($bind_address, $bind_port);
		if (!$client instanceof SocketClient) {
			throw new Exception("Invalid client class specified! Has to be a subclass of SocketClient");
		}
		$client->set_non_block(true);
		$client->connect($remote_address, $remote_port);
		$this->clients[(int)$client->socket] = $client;
		return $client;
	}

	private function create_read_set()
	{
		$ret = array();
		foreach ($this->clients as $socket) {
			$ret[] = $socket->socket;
		}
		foreach ($this->servers as $socket) {
			$ret[] = $socket->socket;
		}
		return $ret;
	}

	private function create_write_set()
	{
		$ret = array();
		foreach ($this->clients as $socket) {
			if (!empty($socket->write_buffer) || $socket->connecting) {
				$ret[] = $socket->socket;
			}
		}
		foreach ($this->servers as $socket) {
			if (!empty($socket->write_buffer)) {
				$ret[] = $socket->socket;
			}
		}
		return $ret;
	}

	private function create_exception_set()
	{
		return $this->create_read_set();
	}

    private function clean_servers() {
        $this->servers = array_filter($this->servers, function ($server) {
            /** @var Server $server */
            if ($server->should_run()) {
                return true;
            } else {
                $server->close();
                return false;
            }
        });
    }

	private function clean_sockets()
	{
		foreach ($this->clients as $socket) {
			if ($socket->disconnected || !is_resource($socket->socket)) {
				if (isset($this->clients[(int)$socket->socket])) {
					unset($this->clients[(int)$socket->socket]);
				}
			}
		}
	}

	private function get_class($socket)
	{
		if (isset($this->clients[(int)$socket])) {
			return $this->clients[(int)$socket];
		} elseif (isset($this->servers[(int)$socket])) {
			return $this->servers[(int)$socket];
		} else {
			throw (new Exception("Could not locate socket class for $socket"));
		}
	}

	public function process()
	{
		// if socketClient is in write set, and $socket->connecting === true, set connecting to false and call on_connect
		$event_time = time();

		while ($this->should_run()) {
            $read_set   = $this->create_read_set();
            $write_set  = $this->create_write_set();
            $except_set = $this->create_exception_set();

            if (($events = socket_select($read_set, $write_set, $except_set, $this->tv_sec, $this->tv_usec)) === false) {
                break;
            }

            foreach ($read_set as $socket) {
                /** @var \Chabot\Socket\Socket $socket */
                $socket = $this->get_class($socket);
                if ($socket instanceof Server) {
                    /** @var SocketClient $client */
                    $client = $socket->accept();
                    $this->clients[(int)$client->socket] = $client;
                } elseif ($socket instanceof SocketClient) {
                    // regular on_read event
                    $socket->read();
                }
            }

            foreach ($write_set as $socket) {
                $socket = $this->get_class($socket);
                if ($socket instanceof SocketClient) {
                    /** @var SocketClient $socket */
                    if ($socket->connecting === true) {
                        $socket->on_connect();
                        $socket->connecting = false;
                    }
                    $socket->do_write();
                }
            }

            foreach ($except_set as $socket) {
                $socket = $this->get_class($socket);
                if ($socket instanceof SocketClient) {
                    $socket->on_disconnect();
                    if (isset($this->clients[(int)$socket->socket])) {
                        unset($this->clients[(int)$socket->socket]);
                    }
                }
            }

			if (time() - $event_time > 1) {
				// only do this if more then a second passed, else we'd keep looping this for every bit received
				foreach ($this->clients as $socket) {
					$socket->on_timer();
				}
				$event_time = time();
			}

            $this->clean_servers();
            $this->clean_sockets();
		}
	}

    public function should_run() {
        return count($this->servers) > 0;
    }
}
