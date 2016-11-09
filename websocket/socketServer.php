<?php
include 'websocket2.php';
class socketServer extends Websocket
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
	
	
	/**
	 *
	 *连接socket服务器
	 * @param $port     监听的端口号
	 * @param $address  监听的IP地址，0.0.0.0表示监听本机上任何地址
	 * @param $debug    debug为调试开关，为true是会记录日志
	 */
	function __construct($port, $address = '0.0.0.0', $debug = false) {
		  $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		  socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
		  socket_set_option($this->serverSocket, SOL_SOCKET, TCP_NODELAY, 1);
		  //socket绑定
		  if (socket_bind($this->serverSocket, $address, $port) === false) {
			    if ($debug) {
			      	echo $this->getLastErrMsg();
			    }
			    return;
		  }
		  //监听开始
		  if (socket_listen($this->serverSocket) === false) {
			    if ($debug) {
			      	echo $this->getLastErrMsg();
			    }
			    return;
		  }
		
		  $this->onstarted($this->serverSocket);
		  $this->lastHealthCheck = time();
		  $this->run();
	}
	function __destruct() {
	  	socket_close($this->serverSocket);
	}
}