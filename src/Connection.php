<?php

namespace BrunoNatali\Socket;

use Evenement\EventEmitter;
use BrunoNatali\EventLoop\LoopInterface;
use BrunoNatali\Stream\DuplexResourceStream;
use BrunoNatali\Stream\Util;
use BrunoNatali\Stream\WritableResourceStream;
use BrunoNatali\Stream\WritableStreamInterface;

/**
 * The actual connection implementation for ConnectionInterface
 *
 * This class should only be used internally, see ConnectionInterface instead.
 *
 * @see ConnectionInterface
 * @internal
 */
class Connection extends EventEmitter implements ConnectionInterface
{
    /**
     * Internal flag whether this is a Unix domain socket (UDS) connection
     *
     * @internal
     */
    public $unix = false;

    /**
     * Internal flag whether encryption has been enabled on this connection
     *
     * Mostly used by internal StreamEncryption so that connection returns
     * `tls://` scheme for encrypted connections instead of `tcp://`.
     *
     * @internal
     */
    public $encryptionEnabled = false;

    /** @internal */
    public $stream;
    public $stream_as_int;

    private $input;
	private $socketSystem;
	private $socketProtocol;
	private $_socket_accept;
	

    public function __construct($resource, LoopInterface $loop, $socketSystem = false, $socket_accept = false)
    {
        // PHP < 7.1.4 (and PHP < 7.0.18) suffers from a bug when writing big
        // chunks of data over TLS streams at once.
        // We try to work around this by limiting the write chunk size to 8192
        // bytes for older PHP versions only.
        // This is only a work-around and has a noticable performance penalty on
        // affected versions. Please update your PHP version.
        // This applies to all streams because TLS may be enabled later on.
        // See https://github.com/reactphp/socket/issues/105
        $limitWriteChunks = (\PHP_VERSION_ID < 70018 || (\PHP_VERSION_ID >= 70100 && \PHP_VERSION_ID < 70104));

        // Construct underlying stream to always consume complete receive buffer.
        // This avoids stale data in TLS buffers and also works around possible
        // buffering issues in legacy PHP versions. The buffer size is limited
        // due to TCP/IP buffers anyway, so this should not affect usage otherwise.
        $this->input = new DuplexResourceStream(
            $resource,
            $loop,
            ($socketSystem === true) ? null : -1,
            new WritableResourceStream($resource, $loop, null, $limitWriteChunks ? 8192 : null, $socketSystem),
			$socketSystem
        );

        $this->stream = $resource;
		$this->stream_as_int = (int)$resource;
		$this->socketSystem = $socketSystem;
		$this->_socket_accept = $socket_accept;
		if($socketSystem) $this->socketProtocol = socket_getopt($this->stream ,SOL_SOCKET, SO_TYPE);		
		
        Util::forwardEvents($this->input, $this, array('data', 'end', 'error', 'close', 'pipe', 'drain'));

        $this->input->on('close', array($this, 'close'));
    }

    public function isReadable()
    {
        return $this->input->isReadable();
    }

    public function isWritable()
    {
        return $this->input->isWritable();
    }

    public function pause()
    {
        $this->input->pause();
    }

    public function resume()
    {
        $this->input->resume();
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        return $this->input->pipe($dest, $options);
    }

    public function write($data, $ip = null, $port = null)
    {
        return $this->input->write($data, $ip, $port);
    }

    public function end($data = null)
    {
        $this->input->end($data);
    }

    public function close()
    {
        $this->input->close();
        $this->handleClose();
        $this->removeAllListeners();
    }

    public function handleClose()
    {
        if (!\is_resource($this->stream)) {
            return;
        }

        // Try to cleanly shut down socket and ignore any errors in case other
        // side already closed. Shutting down may return to blocking mode on
        // some legacy versions, so reset to non-blocking just in case before
        // continuing to close the socket resource.
        // Underlying Stream implementation will take care of closing file
        // handle, so we otherwise keep this open here.
		if($this->socketSystem){
			\socket_close($this->stream);
		} else {
			@\stream_socket_shutdown($this->stream, \STREAM_SHUT_RDWR);
			\stream_set_blocking($this->stream, false);
		}
    }

    public function getRemoteAddress($simple = false)
    {
		if($this->socketSystem){
			$IP = null;
			$PORT = null;
			if($this->socketProtocol == 2){
				if($this->input->get_remote_addr($IP, $PORT)){
					
					return $this->parseAddress(($PORT === null) ? $IP : $IP . ':' . $PORT, $simple);
				}
			} else {
				if(!$this->_socket_accept){
					socket_getpeername($this->stream, $IP, $PORT);
					return $this->parseAddress($IP . ':' . $PORT);
				}
			}
		} else {
			$tttt = stream_socket_get_name($this->stream, true);
			return $this->parseAddress($tttt);
		}
        return $this->getThisResource(true);
    }

    public function getLocalAddress($simple = false)
    {
		if($this->socketSystem){
			$IP = null;
			$PORT = null;
			socket_getsockname($this->stream, $IP, $PORT);
			return $this->parseAddress(($PORT === null) ? $IP : $IP . ':' . $PORT, $simple);
		} else {
			$tttt = stream_socket_get_name($this->stream, false);
			return $this->parseAddress($tttt);
		}
    }

	public function getThisResource($as_int = false)
	{
		if($as_int) return $this->stream_as_int;
		return $this->stream;
	}

    private function parseAddress($address, $simple = false)
    {
        if ($address === false) {
            return $this->getThisResource(true);
        }

        if ($this->unix) {
            // remove trailing colon from address for HHVM < 3.19: https://3v4l.org/5C1lo
            // note that technically ":" is a valid address, so keep this in place otherwise
            if (\substr($address, -1) === ':' && \defined('HHVM_VERSION_ID') && \HHVM_VERSION_ID < 31900) {
                $address = (string)\substr($address, 0, -1);
            }

            // work around unknown addresses should return null value: https://3v4l.org/5C1lo and https://bugs.php.net/bug.php?id=74556
            // PHP uses "\0" string and HHVM uses empty string (colon removed above)
            if ($address === '' || $address[0] === "\x00" ) {
                return $this->getThisResource(true);
            }
			if($simple){
				return substr($address, strrpos($address, '/') + 1);
			}
            return 'unix://' . $address;
        }

        // check if this is an IPv6 address which includes multiple colons but no square brackets
        $pos = \strrpos($address, ':');
        if ($pos !== false && \strpos($address, ':') < $pos && \substr($address, 0, 1) !== '[') {
            $port = \substr($address, $pos + 1);
            $address = '[' . \substr($address, 0, $pos) . ']:' . $port;
        }

        return ($this->encryptionEnabled ? 'tls' : 'tcp') . '://' . $address;
    }
}
