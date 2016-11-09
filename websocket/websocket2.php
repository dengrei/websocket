<?php
/**
 * websocket 服务端处理类
 *
 */

class Websocket
{
	//各种帧类型的常量
	const FRAME_CONTINUE = 0x00;
	const FRAME_TEXT     = 0x01;
	const FRAME_BIN      = 0x02;
	const FRAME_CLOSE    = 0x08; //关闭帧
	const FRAME_PING     = 0x09;  //ping帧
	const FRAME_PONG     = 0x0A;  //pong帧
	
	protected $serverSocket     = null;      //服务器监听的socket
	protected $shutdown         = false;     //关闭状态，如果是true表示服务器准备关闭
	protected $socketList       = array();   //保存所有socket的数组
	protected $socketListMap    = array();   //根据唯一id对socket进行索引，并保存socket的其他自定义属性
	private $handshakingList    = array();   //正在进行握手的socket
	private $lastHealthCheck    = null;      //最后一次进行健康检查的时间，这里根据最后一次通信时间判断健康状态，检查时默认不会发送pong帧
	private $healthCheckInterval= 300;       //健康检查间隔，单位秒，每次处理完一个连接后会判断是否进行健康检查。
	private $handshakeTimeout   = 10;        //握手超时时间，单位秒，为了防止过多的未完成握手占用系统资源，会对超时的握手连接进行关闭处理。
	
	public function run()
	{
	  //将服务器的socket添加到初始化socket列表中
	  array_push($this->socketList, $this->serverSocket);
	  
	  while (true) {
		    $read = $this->socketList;
		    //如果shutdown变量设置为true，服务器关闭，退出循环
		    if ($this->shutdown) {
			      $this->onshutdown();
			      return;
		    }
		
		    if ($this->debug) {
		      	echo "Waiting for socket_select\n";
		    }
		    //该函数会从所有可读写的socket中选取一个socket进行处理，该方法会阻塞流程，只有在收到连接时该方法才会返回
		    if (socket_select($read, $write, $except, NULL) === false) {
			      if ($this->debug) {
			        	echo $this->getLastErrMsg();
			      }
			      continue;
		    }
		
		    foreach ($read as $socketItem) {
		      //如果选取的socket是服务器监听的socket，则此时是新连接接入
		      if ($socketItem === $this->serverSocket) {
			        //接受socket连接
			        $socket = $this->socketAccept();
			        if ($socket) {
			          //执行连接方法
			          $this->connect($socket);
			        }
		      }else {
			        //此时是连接过的socket，获取socketId
			        $socketId = $this->getSocketId($socketItem);
			        if ($socketId === FALSE) {
				          //获取socketId失败，则将该socket断开连接
				          $this->disconnectBySocket($socketItem);
				          continue;
			        }
			        //接收传来的数据
			        $data = $this->socketRecv($socketId);
			        if (strlen($data) > 0) {
				          //收到的数据长度不为空时，需要重置连接错误计数
				          $this->socketListMap[$socketId]['errorCnt'] = 0;
				          if (!isset($this->socketListMap[$socketId])) {
					            $this->disconnect($socketId);
					            continue;
				          } else if (!$this->socketListMap[$socketId]['handshake']) {
					            //尚未进行WebSocket协议握手，尝试读取连接缓冲区，如果缓冲区中没有数据，则将socketId记录到握手中列表
					            //这是为了防止握手包被分成多个包进行传递（正常情况下不会出现此问题）
					            //但根据HTTP协议，并未规定HTTP请求头不能被分割，故应该根据协议中的\r\n\r\n来判断请求头已发送完毕
					            if (strlen($this->socketListMap[$socketId]['buffer']) === 0) {
					              	$this->handshakingList[$socketId] = time();
					            }
					            //将数据写入缓冲区
					            $this->socketListMap[$socketId]['buffer'] .= $data;
					            //比较后4个字节是否为\r\n\r\n
					            if (substr_compare($this->socketListMap[$socketId]['buffer'], str_repeat(chr(0x0D) . chr(0x0A), 2), -4) === 0) {
						              	//进行握手处理
						              	$this->doHandShake($socketId);
					            } else {
					              	  	//数据没有传送完毕，需要缓冲数据直到全部接收请求头
					              		$this->onUpgradePartReceive($socketId);
					            }
				          } else if ($this->parseFrame($data, $socketId)) {
					            //parseFrame会解析数据帧，如果该帧FIN标识为1则函数会返回true，交给businessHandler进行业务逻辑处理，数据在socketListMap的buffer中，所以只需要提供socketId即可找到该socket的所有信息。
					            $this->businessHandler($socketId);
				          }
			        } else {
				          $this->socketListMap[$socketId]['errorCnt'] += 1;
				          if ($this->debug){
				            echo "Receive empty data![$errorCnt]\n";
				          }
				          if ($errorCnt >= 3) {
				            $this->disconnect($socketId);
				          }
			        }
		      }
		    }
		    //每次处理完连接后，判断是否需要健康检查，检查之后会移除不健康的socket
		    if (time() - $this->lastHealthCheck > $this->healthCheckInterval) {
		      $this->healthCheck();
		    }
		    $this->removeUnhandshakeConnect();
  		}
	}
	/**
  	 *
  	 * 服务器启动提示
  	 */
	function onstarted($serverSocket) {
	    if ($this->debug) {
	      	printf('Server start at %s', date('Y-m-d H:i:s') . "\n");
	    }
  	}
	/**
	 * 接入提示
	 * @param resource $socket
	 */
  	function onconnected($socket) {
	    if ($this->debug) {
	      	printf('进入连接 %s-%s', date('Y-m-d H:i:s'), $socket . "\n");
	    }
  	}
	/**
  	 *
  	 * 解析数据成功提示
  	 */
  	function onUpgradePartReceive($socketId) {
	    if ($this->debug) {
		      $buffer = $this->socketListMap[$socketId]['buffer'];
		      printf('Receive Upgrade Part at %s-%s%s(%d bytes)' . "\n", date('Y-m-d H:i:s'), $socketId . "\n", $buffer, strlen($buffer));
	    }
  	}
	/**
  	 *
  	 * 握手失败提示
  	 */
  	function onHandShakeFailure($socketId) {
	    if ($this->debug) {
	      	printf('HandShake Failed at %s-%s', date('Y-m-d H:i:s'), $socketId . "\n");
	    }
  	}
	/**
  	 *
  	 * 握手成功提示
  	 */
  	function onHandShakeSuccess($socketId) {
	    if ($this->debug) {
	      	printf('HandShake Success at %s-%s', date('Y-m-d H:i:s'), $socketId . "\n");
	    }
  	}
	/**
  	 *
  	 * 连接断开提示
  	 */
  	function ondisconnected($socketId) {
	    if ($this->debug) {
	      	printf('Socket disconnect at %s-%s', date('Y-m-d H:i:s'), $socketId . "\n");
	    }
  	}
	/**
  	 *
  	 * 关闭连接提示
  	 */
  	function onAfterRemoveSocket($socketId) {
	    if ($this->debug) {
	      	printf('[onAfterRemoveSocket]remove:' . $socketId . ',left:' . implode('|', array_keys($this->socketListMap)) . "\n");
	    }
  	}
	/**
  	 *
  	 *关闭不健康的连接
  	 */
  	function onafterhealthcheck($unhealthyList) {
	    foreach ($unhealthyList as $socketId) {
	      	$this->disconnect($socketId);
	    }
  	}
  	/**
  	 *
  	 * 错误提示
  	 */
  	function onerror($errCode, $socketId) {
	    switch ($errCode) {
	    case 10053:
		      $this->disconnect($socketId);
		      break;
	    default:
		      if ($this->debug) {
		        echo 'Socket Error:' . $errorCode . "\n";
		      }
		      break;
	    }
  	}
  	/**
  	 *
  	 * 服务器关闭提示
  	 */
  	function onshutdown() {
	    if ($this->debug) {
	      	printf('Server shutdown!');
	    }
  	}
	/**
	 * 获取最后一次socket的错误码
	 * @param string $socketId
	 */
  	function getLastErrCode($socketId = null) {
	    if (is_null($socketId)) {
	      	$socket = $this->serverSocket;
	    } else {
	      	$socket = $this->socketListMap[$socketId]['socket'];
	    }
	    return socket_last_error($socket);
  	}

  	/**
  	 *
  	 * 通过错误码查找错误详情
  	 * @param $socketId
  	 * @param $errCode
  	 */
  	function getLastErrMsg($socketId = null, $errCode = null) {
	    if (!is_numeric($errCode)) {
	      	$errCode = $this->getLastErrCode($socketId);
	    }
    	return '[' . $errCode . ']' . socket_strerror($errCode) . "\n";
  	}
	/**
	 * 健康检测，断开不正常的连接，保证服务器正常
	 */
	private function healthCheck()
	{
		//获取当前时间
	    $now = time();
	
	    //记录最后健康检查时间
	    $this->lastHealthCheck = $now;
	
	    //初始化不健康的连接列表
	    $unhealthyList = array();
	
	    //循环连接池
	    foreach ($this->socketListMap as $socketId => $session) {
	      //找出最后通信时间超过超时时间（目前超时时间与健康检查时间相同）
	      if ($now - $session['lastCommuicate'] > $this->healthCheckInterval) {
	        array_push($unhealthyList, $socketId);
	      }
	    }
	    if ($this->debug) {
	      echo 'Unhealthy socket:' . implode(',', $unhealthyList) . "\n";
	    }
	
	    //健康检查回调，默认的行为是直接断开连接
	    //可以根据自己的需求改进，如发送ping帧探测等，如果仍无响应再断开连接
	    $this->onafterhealthcheck($unhealthyList);
	}
	/**
	 * 判断是否经过掩码处理的帧
	 * @param string $byte
	 * @return boolean
	 */
	private function isMasked($byte)
	{
		return (ord($byte) & 0x80) > 0;
	}
	/**
	 * 获取数据帧长度
	 * @param array $data
	 * @return boolean|number
	 */
	private function getPayloadLen($data)
	{
		$first    = ord($data[0]) & 0x7F;
		$second   = (ord($data[1]) << 8) + ord($data[2]);
		$third    = (ord($data[3]) << 40) + (ord($data[4]) << 32) + (ord($data[5]) << 24) + (ord($data[6]) << 16);
		$third   += (ord($data[7]) << 8) + ord($data[8]);
		
		if($first < 126){
			return $first;
		}
		elseif($first === 126){
			return $second;
		}
		elseif($first === 127){
			return ($second<<48) + $third;
		}
	}
	/**
	 * 获取数据帧内容
	 * @param array $data
	 * @param number $len
	 * @return string
	 */
	private function getPayloadData($data,$len)
	{
		$offset = 5;
		if($len < 126){
			return substr($data,$offset+1,$len);
		}
		elseif($len < 65536){
			return substr($data,$offset+3,$len);
		}
		else{
			return substr($data,$offset+9,$len);
		}
	}
	/**
	 * 获取掩码的值
	 * @param array $data
	 * @param number $len
	 * @return string
	 */
	private function getMask($data,$len)
	{
		$offset = 1;
		if($len < 126){
			return substr($data,$offset+1,4);
		}
		elseif($len < 65536){
			return substr($data,$offset+3,4);
		}
		else{
			return substr($data,$offset+9,4);
		}
	}
	/**
	 * 获取帧类型
	 * @param string $byte
	 * @return boolean
	 */
	private function getFrameType($byte)
	{
		return ord($byte) & 0x0F;
	}
	/**
	 * 判断是否为结束帧
	 * @param string $byte
	 * @return boolean
	 */
	private function isFin($byte)
	{
		return (ord($byte[0]) & 0x80) > 0;
	}
	/**
	 * 判断是否为控制帧，包含关闭帧，ping帧，pong帧
	 * @param unknown $frameType
	 * @return boolean
	 */
	private function isControlFrame($frameType)
	{
		return $frameType===self::FRAME_CLOSE||$frameType===self::FRAME_PING||$frameType===self::FRAME_PONG;
	}
	/**
	 * 处理负载的掩码，将其还原
	 * @param $payload
	 * @param $mask
	 */
	function parseRawFrame($payload, $mask) {
	    $payloadLen = strlen($payload);
	    $dest       = '';
	    $maskArr = array();
	    for ($i = 0; $i < 4; $i++) {
	      	$maskArr[$i] = ord($mask[$i]);
	    }
	    for ($i = 0; $i < $payloadLen; $i++) {
	      	$dest .= chr(ord($payload[$i]) ^ $maskArr[$i % 4]);
	    }
	    return $dest;
  	}
	/**
	 *处理文本帧
	 * @param $payload
	 * @param $mask
	 */
  	function parseTextFrame($payload, $mask) {
    	return $this->parseRawFrame($payload, $mask);
  	}
	/**
	 *
	 * 处理二进制帧
	 * @param $payload
	 * @param $mask
	 */
  	function parseBinaryFrame($payload, $mask) {
    	return $this->parseRawFrame($payload, $mask);
  	}
	/**
	 * 创建并发送关闭帧
	 * @param string $socketId
	 * @param number $closeCode
	 * @param string $closeMsg
	 */
  	function closeFrame($socketId, $closeCode = 1000, $closeMsg = 'goodbye') {
	    $closeCode = chr(intval($closeCode / 256)) . chr($closeCode % 256);
	    $frame     = $this->createFrame($closeCode . $closeMsg, self::FRAME_CLOSE);
	    $this->socketSend($socketId, $frame);
	    $this->disconnect($socketId);
  	}
	function sendPing($socketId, $data = 'ping') {
    	$frame = $this->createFrame($data, self::FRAME_PING);
    	$this->socketSend($socketId, $frame);
  	}

  	function sendPong($socketId, $data = 'pong') {
    	$frame = $this->createFrame($data, self::FRAME_PONG);
    	$this->socketSend($socketId, $frame);
  	}
	//封装帧头的相关标识位、长度等信息
  	function createFrame($data, $type, $fin = 0x01) {
	    $dataLen = strlen($data);
	    $frame   = chr(($fin << 7) + $type);
	    if ($dataLen < 126) {
	      	$frame .= chr($dataLen);
	    } else if ($dataLen < 65536) {
		      $frame .= chr(126);
		      $frame .= chr(intval($dataLen / 256));
		      $frame .= chr(intval($dataLen % 256));
	    } else {
		      $frame .= chr(127);
		      $hexLen = str_pad(base_convert($dataLen, 10, 16), 16, '0', STR_PAD_LEFT);
		      for ($i = 0; $i < 15; $i += 2) {
		        	$frame .= chr((intval($hexLen[$i], 16) << 8) + intval($hexLen[$i + 1], 16));
		      }
	    }
	    $frame .= $data;
	    return $frame;
  	}
  	/**
  	 *解析数据帧
  	 * @param $data
  	 * @param $socketId
  	 */
	function parseFrame($data, $socketId) {
		  //判断该帧是否是经过掩码处理
		  $isMasked = $this->isMasked($data[1]);
		
		  //如果未经掩码处理，则根据协议规定，需要断开连接
		  if (!$isMasked) {
			    //此处使用1002状态码，表示协议错误，发送关闭帧
			    $this->closeFrame($socketId, 1002, 'There is no mask!');
			
			    //断开连接
			    $this->disconnect($socketId);
			    return false;
		  }
		  //获取负载的长度字节数
		  $payloadLen = $this->getPayloadLen(substr($data, 1, 9));
		
		  //根据负载长度获取负载的全部数据
		  $payload = $this->getPayloadData($data, $payloadLen);
		
		  //获取掩码值
		  $mask = $this->getMask($data, $payloadLen);
		
		  //获取帧的类型
		  $frameType = $this->getFrameType($data[0]);
		
		  //处理帧
		  switch ($frameType) {
		  case self::FRAME_CONTINUE:
			    //后续帧，需要拼接buffer
			    $this->socketListMap[$socketId]['buffer'] .= $this->parseRawFrame($payload, $mask);
			    break;
		  case self::FRAME_TEXT:
			    //文本帧，处理方式默认保持一致，均使用parseRawFrame处理，如果由特殊需求可以重写parseTextFrame函数
			    $this->socketListMap[$socketId]['buffer'] = $this->parseTextFrame($payload, $mask);
			    break;
		  case self::FRAME_BIN:
			    //二进制帧，处理方式默认保持一致，均使用parseRawFrame处理，如果由特殊需求可以重写parseBinaryFrame函数
			    $this->socketListMap[$socketId]['buffer'] = $this->parseBinaryFrame($payload, $mask);
			    break;
		  case self::FRAME_CLOSE:
			    //发送关闭帧（应答帧）
			    $this->closeFrame($socketId);
			    break;
		  case self::FRAME_PING:
			    //发送pong帧响应，浏览器目前不提供ping、pong帧的API，此处逻辑基本不会走到，只为实现协议内容
			    //$this->sendPong($socketId, $this->parseRawFrame($payload, $mask));
			    break;
		  case self::FRAME_PONG:
			    //收到pong帧不进行任何处理（正常情况下不会收到，浏览器不会主动发送pong帧）
			    break;
		  default:
			    //其他帧类型无法处理，直接断开连接，根据协议，此处使用1003状态码关闭连接更好
			    $this->disconnect($socketId);
			    break;
		  }
		  if ($this->debug) {
		    //输出调试信息
		    echo "isFin:" . ((ord($data[0]) & 0x80) >> 7) . "\n";
		    echo "opCode:$frameType\n";
		    echo "payLoad Length:$payloadLen\n";
		    echo "Mask:$mask\n\n";
		  }
		
		  //如果是结束的数据帧，返回true，否则均为false
		  //当返回true时，外层调用函数会继续将执行核心业务逻辑，读取缓冲区中的数据进行处理
		  //如果是false，则不进行进一步的处理（控制帧及非结束帧都不会提交到业务层处理）
		  return $this->isFin($data[0]) && !$this->isControlFrame($frameType);
	}
	/**
	 * 获取socket唯一标识
	 * @param $socket
	 */
	function getSocketId($socket) {
		  //socketId由socket中的地址和端口号拼接，这样可以保证socket的唯一性，又可以通过id快速读取保存socket的信息以及其附加的其他相关信息
		  if (socket_getpeername($socket, $address, $port) === FALSE) {
		    	return false;
		  }
		  return $address . '_' . $port;
	}
	/**
	 *
	 * 将socket添加到已接受的socket连接池中,并生成socket用户信息
	 * @param $socket
	 */
	function addSocket($socket) {
		  array_push($this->socketList, $socket);
		  $socketId = $this->getSocketId($socket);

		  $this->socketListMap[$socketId] = array(
			    //读取缓冲区，由于可能存在分帧的情况，此处统一先保存到缓冲区中，带收到结束帧后统一处理缓冲区
			    'buffer' => '',
			    //握手成功标识，addSocket在接受连接后调用，故此时并未进行握手，初始化为false
			    'handshake' => false,
			    //最后通信时间，用于判断超时断开操作
			    'lastCommuicate' => time(),
			    //socket实例
			    'socket' => $socket,
			    //错误计数
			    'errorCnt' => 0
		  );
	}
	/**
	 *
	 * 移除socket
	 * @param $socketId
	 */
	function removeSocket($socketId) {
		  $socket = $this->socketListMap[$socketId]['socket'];
		
		  //找出socket在socketList中的索引
		  $socketIndex = array_search($socket, $this->socketList);
		  if ($this->debug) {
		    	echo "RemoveSocket at $socketIndex\n";
		  }
		
		  //移除socketList中的socket
		  array_splice($this->socketList, $socketIndex, 1);
		
		  //移除socketListMap中的相关信息
		  unset($this->socketListMap[$socketId]);
		
		  //回调事件
		  $this->onAfterRemoveSocket($socketId);
	}
	/**
	 * 接收socket
	 */
	function socketAccept() {
		  $socket = socket_accept($this->serverSocket);
		  if ($socket !== FALSE) {
		    	return $socket;
		  } else if ($this->debug) {
		    	echo $this->getLastErrMsg();
		  }
	}
	/**
	 *从缓存区中获取socket数据
	 * @param string $socketId
	 */
	function socketRecv($socketId) {
		  $socket    = $this->socketListMap[$socketId]['socket'];
		  $bufferLen = socket_get_option($socket, SOL_SOCKET, SO_RCVBUF);
		  $recv      = socket_recv($socket, $buffer, $bufferLen, 0);
		  if ($recv === FALSE) {
			    $errCode = $this->getLastErrCode($socketId);
			    $this->onerror($errCode, $socketId);
			    if ($this->debug) {
			      	echo $this->getLastErrMsg(null, $errCode);
			    }
			    return NULL;
		  } else if ($recv > 0) {
			    if ($this->debug) {
				      echo "Recv:ok\n";
				      //$this->showData($buffer);
			    }
		    	$this->socketListMap[$socketId]['lastCommuicate'] = time();
		  }
		  return $buffer;
	}
	/**
	 * 发送数据包
	 * @param $socketId
	 * @param $data
	 */
	function socketSend($socketId, $data) {
	  $socket = $this->socketListMap[$socketId]['socket'];
	  if ($this->debug) {
		    echo "Send:\n";
		    $this->showData($data);
	  }
	  if (socket_write($socket, $data, strlen($data)) > 0) {
	    	$this->socketListMap[$socketId]['lastCommuicate'] = time();
	  }
	}
	/**
	 *关闭socket
	 * @param $socketId
	 */
	function socketClose($socketId) {
		  $socket = $this->socketListMap[$socketId]['socket'];
		  socket_close($socket);
	}
	/**
	 *
	 *连接socket
	 * @param resource $socket
	 */
	function connect($socket) {
		  $this->addSocket($socket);
		  $this->onconnected($socket);
	}
	/**
	 *
	 * 查找指定socket连接断开
	 * @param $socket
	 */
	function disconnectBySocket($socket) {
		  $socketIndex = array_search($socket, $this->socketList);
		  foreach ($this->socketListMap as $socketId => $session) {
		    	if ($session['socket'] == $socket) {
		      		$this->disconnect($socketId);
		     	 	return;
		    	}
		  }
	}
	/**
	 * 直接断开socket连接
	 * @param string $socketId
	 * @param bool $silent
	 */
	function disconnect($socketId, $silent = false) {
		  $this->socketClose($socketId);
		  if (!$silent) {
		    	$this->ondisconnected($socketId);
		  }
		  $this->removeSocket($socketId);
	}
	/**
	 *接收到完整的请求头，并处理
	 * @param $socketId
	 */
	function doHandShake($socketId){
		  //一旦进入了doHandshake函数，说明已收到完整的请求头，故将此socketId从handshakingList中移除
		  array_splice($this->handshakingList, array_search($socketId, $this->handshakingList), 1);
		
		  //获取socket的相关信息
		  $session = $this->socketListMap[$socketId];
		
		  //获取http请求头
		  $headers = $this->getHeaders($session['buffer']);
		
		  //请求的数据内容会清空，因为已经读取过了，这里buffer是一个读取缓冲区
		  $this->socketListMap[$socketId]['buffer'] = '';
		  $this->socketListMap[$socketId]['headers'] = $headers;
		
		  //checkBaseHeader用于检查基本头信息，如果有任何一个头信息不符合WebSocket协议，则检查失败
		  //checkCustomHeader为用户自定义头部检查，需要继承类覆盖实现，一般检查cookie、origin等与业务相关的头部信息
		  if (!$this->checkBaseHeader($headers) || !$this->checkCustomHeader($headers)) {
			    //生成握手失败响应
			    $this->badRequest($socketId);
			
			    //关闭连接
			    $this->disconnect($socketId);
			
			    //握手失败回调
			    $this->onHandShakeFailure($socketId);
			    return false;
		  } else {
			    //获取握手返回头部数据
			    $responseHeader = $this->getHandShakeHeader($headers);
		  }
		  //发送响应头
		  $this->socketSend($socketId, $responseHeader);
		
		  //已握手标记置为true，之后在收到该socket数据将进入数据处理逻辑
		  $this->socketListMap[$socketId]['handshake'] = true;
		
		  //握手成功回调
		  $this->onHandShakeSuccess($socketId);
	}
	/**
	 *检查头部信息
	 * @param array $header
	 */
	function checkBaseHeader($header) {
		  //检查Upgrade字段是否为websocket
		  return strcasecmp($header['Upgrade'], 'websocket') === 0 &&
		  //检查Connection字段是否为Upgrade
		  strcasecmp($header['Connection'], 'Upgrade') === 0 &&
		  //检查Sec-WebSocket-Key字段Base64解码后长度是否为16字节
		  strlen(base64_decode($header['Sec-WebSocket-Key'])) === 16 &&
		  //检查WebSocket协议版本是否为13，该类仅处理版本为13的WebSocket协议
	      $header['Sec-WebSocket-Version'] === '13';
	}
	/**
	 *
	 * 响应握手错误信息
	 * @param $socketId
	 */
	function badRequest($socketId) {
		  //该函数仅拼装握手错误的响应信息，并发送
		  $message = 'This is a websocket server!';
		  $out = "HTTP/1.1 400 Bad request\n";
		  $out .= "Server: WebSocket Server/lyz810\n";
		  $out .= "Content-Length: " . strlen($message) . "\n";
		  $out .= "Connection: close\n\n";
		  $out .= $message;
		  $this->socketSend($socketId, $out);
	}
	/**
	 *封装响应头信息
	 * @param $headers
	 */
	function getHandShakeHeader($headers) {
		  //拼装响应头的相关字段
		  $responseHeader = array(
			    'HTTP/1.1 101 Switching Protocols',
			    'Upgrade: WebSocket',
			    'Connection: Upgrade',
			    'Sec-WebSocket-Accept: ' . $this->getWebSocketAccept($headers['Sec-WebSocket-Key']),
		  );
		  if (isset($headers['Sec-WebSocket-Protocol'])) {
			    //子协议选择，应由继承类覆盖实现，否则默认使用最先出现的子协议
			    $protocol = $this->selectProtocol(explode(',', $headers['Sec-WebSocket-Protocol']));
			    array_push($responseHeader, 'Sec-WebSocket-Protocol: ' . $protocol);
		  }
		  return implode("\r\n", $responseHeader) . "\r\n\r\n";
	}
	/**
	 *
	 * 计算WebSocket-accept-key
	 * @param $websocketKey
	 */
	function getWebSocketAccept($websocketKey) {
	  	//根据协议要求，计算WebSocket-accept-key
	  	return base64_encode(sha1($websocketKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
	}
}