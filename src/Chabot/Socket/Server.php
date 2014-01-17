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
class Server extends Socket {
	protected $client_class;

	public function __construct($client_class, $bind_address = 0, $bind_port = 0, $domain = AF_INET, $type = SOCK_STREAM, $protocol = SOL_TCP)
	{
		parent::__construct($bind_address, $bind_port, $domain, $type, $protocol);
		$this->client_class = $client_class;
		$this->listen();
	}

	public function accept()
	{
		$client = new $this->client_class(parent::accept());
		if (!$client instanceof ServerClient) {
			throw new Exception("Invalid serverClient class specified! Has to be a subclass of AbstractServerClient");
		}
		$this->on_accept($client);
		return $client;
	}

	// override if desired
	public function on_accept(ServerClient $client) {
    }
}
