<?php
use Chabot\Socket\ServerClient;

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

class HttpClient extends ServerClient {
	private $max_total_time = 45;
	private $max_idle_time  = 15;
	private $keep_alive = false;
	private $accepted;
	private $last_action;
    private $headers = array();
    private $request = array();

    public function on_connect() {
//        $this->log->info("Accepted connection from %s", $this->remote_address);
        $this->accepted = time();
        $this->last_action = $this->accepted;
    }

	public function on_read() {
		$this->last_action = time();

        if (empty($this->headers)) {
            $separator = "\r\n\r\n";
            if (($off = strpos($this->read_buffer, $separator)) === false) {
                $separator = "\n\n";
                if (($off = strpos($this->read_buffer, $separator)) === false) {
                    return;
                }
            }

            $rawHeaders = substr($this->read_buffer, 0, $off);
            $this->read_buffer = substr($this->read_buffer, $off + strlen($separator)) ?: '';
            $headers = array();
            $request = array();

            if (preg_match('#^([^ \r\n]+) ([^ \r\n]+) ([^ \r\n]+)#', $rawHeaders, $match)) {
                $request = array(
                    'method'   => strtoupper($match[1]),
                    'uri'      => $match[2],
                    'protocol' => strtoupper($match[3])
                );
            }

            preg_match_all('/^([^:\r\n]+): ?([^\r\n]+)/m', $rawHeaders, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $header = strtolower(trim($match[1]));
                $value = trim($match[2]);
                $headers[$header] = $value;
            }

            $this->request = $request;
            $this->headers = $headers;
            $this->keep_alive = isset($headers['connection']) && strcasecmp($headers['connection'], 'keep-alive') === 0;
            $this->write($this->dispatch());
        }
//
//        if (!empty($this->headers)) {
//            // content-length check
//        }
    }

	public function on_disconnect()
	{
		//echo "[httpServerClient] {$this->remote_address} disconnected\n";
	}

	public function on_write()
	{
		if (strlen($this->write_buffer) == 0 && !$this->keep_alive) {
			$this->disconnected = true;
			$this->on_disconnect();
			$this->close();
		}
	}

	public function on_timer()
	{
		$idle_time  = time() - $this->last_action;
		$total_time = time() - $this->accepted;
		if ($total_time > $this->max_total_time || $idle_time > $this->max_idle_time) {
			echo "[httpServerClient] Client keep-alive time exceeded ({$this->remote_address})\n";
			$this->close();
		}
	}

	private function dispatch()
	{
        $request = $this->request;
        $request['version'] = str_replace('HTTP/', '', $request['protocol']);

		if (!$request['version'] || ($request['version'] != '1.0' && $request['version'] != '1.1')) {
			// sanity check on HTTP version
			$header  = 'HTTP/'.$request['version']." 400 Bad Request\r\n";
			$output  = '400: Bad request';
			$header .= "Content-Length: ".strlen($output)."\r\n";
		} elseif (!isset($request['method']) || ($request['method'] != 'GET' && $request['method'] != 'POST')) {
			// sanity check on request method (only get and post are allowed)
			$header  = 'HTTP/'.$request['version']." 400 Bad Request\r\n";
			$output  = '400: Bad request';
			$header .= "Content-Length: ".strlen($output)."\r\n";
		} else {
			// handle request
			if (empty($request['uri'])) {
				$request['uri'] = '/';
			}
			if ($request['uri'] == '/' || $request['uri'] == '') {
				$request['uri'] = '/index.html';
			}
			// parse get params into $params variable
			if (strpos($request['uri'],'?') !== false) {
				$params = substr($request['uri'], strpos($request['uri'],'?') + 1);
				$params = explode('&', $params);
				foreach($params as $key => $param) {
					$pair = explode('=', $param);
					$params[$pair[0]] = isset($pair[1]) ? $pair[1] : '';
					unset($params[$key]);
				}
				$request['uri'] = substr($request['uri'], 0, strpos($request['uri'], '?'));
			}

			$file = './htdocs'.$request['uri'];
			if (file_exists($file) && is_file($file)) {
				$header  = "HTTP/{$request['version']} 200 OK\r\n";
				$header .= "Accept-Ranges: bytes\r\n";
				$header .= 'Last-Modified: '.gmdate('D, d M Y H:i:s T', filemtime($file))."\r\n";
				$size    = filesize($file);
				$header .= "Content-Length: $size\r\n";
				$output  = file_get_contents($file);
			} else {
				$output  = '<h1>404: Document not found.</h1>';
				$header  = "'HTTP/{$request['version']} 404 Not Found\r\n".
				"Content-Length: ".strlen($output)."\r\n";
			}
		}
		$header .=  'Date: '.gmdate('D, d M Y H:i:s T')."\r\n";
		if ($this->keep_alive) {
			$header .= "Connection: Keep-Alive\r\n";
			$header .= "Keep-Alive: timeout={$this->max_idle_time} max={$this->max_total_time}\r\n";
		} else {
			$this->keep_alive = false;
			$header .= "Connection: Close\r\n";
		}
		return $header."\r\n".$output;
	}
}

