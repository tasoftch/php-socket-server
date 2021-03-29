<?php
/*
 * Copyright (c) 2021 TASoft Applications, Th. Abplanalp <info@tasoft.ch>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace TASoft\Server;


abstract class AbstractSocketServer implements ServerInterface
{
	const ERROR_CODE_SOCKET_ESTABLISHMENT = -102;
	const ERROR_CODE_BACKLOG_REACHED = 103;

	/**
	 * @return resource
	 */
	abstract protected function establishSocket();

	/**
	 * @param resource $socket
	 */
	abstract protected function closeSocket($socket);

	/**
	 * Called when a client has sent data to the server.
	 * If the server returns a string, it will be sent back to the client.
	 * Returning false closes the connection to the client.
	 *
	 * @param $data
	 * @param $clientInfo
	 * @return string|false|null
	 */
	abstract protected function handleReceivedData($data, &$clientInfo);

	/**
	 * @return int
	 */
	protected function getBacklog(): int {
		return 10;
	}

	/**
	 * @param int $code
	 * @param string $msg
	 */
	protected function error(int $code, string $msg) {
	}

	/**
	 * @return float
	 */
	protected function getTimeout(): ?float {
		return NULL;
	}

	/**
	 * @return int
	 */
	protected function getReadBufferSize(): int {
		return 2048;
	}

	/**
	 * @return int
	 */
	protected function getWriteBufferSize(): int {
		return 2048;
	}

	/**
	 * @inheritDoc
	 */
	public function run()
	{
		$SOCKET = $this->establishSocket();
		if(!is_resource($SOCKET)) {
			$this->error( static::ERROR_CODE_SOCKET_ESTABLISHMENT, "No connection established" );
			return;
		}

		$backlog = $this->getBacklog();
		socket_listen( $SOCKET, $backlog );

		$client = [];
		$cleanup_and_close = function() use (&$client, $SOCKET) {
			foreach($client as $c) {
				if(isset($c["sock"])) {
					$this->willCloseClientConnection($c["info"], $c["sock"]);
					socket_close($c["sock"]);
				}
			}
			$this->closeSocket( $SOCKET );
			exit(0);
		};

		if(function_exists('pcntl_signal')) {
			pcntl_signal(SIGINT, $cleanup_and_close, false);
			pcntl_signal(SIGTERM, $cleanup_and_close, false);
		}

		$closeClient = function($i) use (&$client) {
			if(isset($client[$i])) {
				$this->willCloseClientConnection($client[$i]["info"], $client[$i]["sock"]);
				socket_close($client[$i]['sock']);
				unset($client[$i]);
			}
		};

		while (1) {
			$read = [$SOCKET];
			for ($i = 0; $i < $backlog; $i++)
			{
				if (isset($client[$i]))
					if ($client[$i]['sock']  != null)
						$read[$i + 1] = $client[$i]['sock'] ;
			}
			$write = NULL;
			$except = NULL;

			$ts = $this->getTimeout();
			if($ts !== NULL) {
				$ys = ($ts - ((int)$ts)) * 1e6;
				$ts = (int)$ts;
			} else
				$ys = NULL;


			declare(ticks=1) {
				@socket_select($read, $write, $except, $ts, $ys);
			}

			if (in_array($SOCKET, $read)) {
				for ($i = 0; $i < $backlog; $i++) {
					if (!isset($client[$i])) {
						$client[$i] = [];
						$client[$i]['sock'] = socket_accept($SOCKET);
						socket_getpeername($client[$i]['sock'], $name, $port);
						$client[$i]['info'] = [
							"name" => $name,
							"port" =>$port
						];

						if(!$this->shouldConnectionToClient($client[$i]['info'])) {
							$closeClient($i);
						}
						break;
					} elseif ($i == $backlog - 1) {
						$this->error(static::ERROR_CODE_BACKLOG_REACHED, 'Too many connections');

					}
				}
				continue;
			}

			for ($i = 0; $i < $backlog; $i++) // for each client
			{
				if (isset($client[$i])) {
					if (in_array($client[$i]['sock'], $read)) {
						$input = $this->socketRead($client[$i]['sock'], $this->getReadBufferSize());
						if ($input == NULL) {
							$closeClient($i);
							continue;
						}

						$result = $this->handleReceivedData($input, $client[$i]['info']);
						if($result === false) {
							$closeClient($i);
							continue;
						} elseif(is_string($result)) {
							$this->socketWrite($client[$i]['sock'], $result, $this->getWriteBufferSize());
						}
					}
				}
			}
		}
	}

	/**
	 * @param $SOCKET
	 * @param $length
	 * @return string
	 */
	protected function socketRead($SOCKET, $length) {
		$response = "";
		while ($out = socket_read($SOCKET, $length)) {
			$response.=$out;
			if(strlen($out) < $length)
				break;
		}
		return $response;
	}

	/**
	 * @param $SOCKET
	 * @param $data
	 * @param $chunckSize
	 */
	protected function socketWrite($SOCKET, $data, $chunckSize) {
		while ($len = socket_write($SOCKET, $data, $chunckSize)) {
			if($len == ($strlen = min($chunckSize, strlen($data))))
				break;
			$data = substr($data, $strlen);
		}
	}

	/**
	 * Simple File converter.
	 * Leaves unix absolute paths as is: /home/pi
	 *
	 * @param $f
	 * @return string
	 */
	protected function getFile($f) {
		if($f[0] == '/')
			return $f;
		if($f[0] == '.' && $f[1] == '/')
			return getcwd() . "/" . substr($f, 1);
		if($f[0] == '.' && $f[1] == '.')
			return dirname(getcwd()) . "/" . substr($f, 1);
		return $f;
	}

	/**
	 * Delegate method before close client socket
	 *
	 * @param $client
	 * @param $socket
	 */
	protected function willCloseClientConnection($client, $socket) {
	}

	/**
	 * @param $clientInfo
	 * @return bool
	 */
	protected function shouldConnectionToClient(&$clientInfo): bool {
		return true;
	}
}