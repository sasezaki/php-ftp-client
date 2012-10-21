<?php
/**
 * @package   FtpAlternative
 * @copyright 2012 tsyk goto
 * @author    tsyk goto
 * @license   http://www.opensource.org/licenses/mit-license.php  MIT License
 * @link      https://github.com/ngyuki/FtpAlternative
 */

/**
 * @package   FtpAlternative
 * @copyright 2012 tsyk goto
 * @author    tsyk goto
 * @license   http://www.opensource.org/licenses/mit-license.php  MIT License
 * @link      https://github.com/ngyuki/FtpAlternative
 */
class FtpAlternative_FtpClient
{
	/**
	 * @var FtpAlternative_TransportInterface コントロールコネクション
	 */
	private $_control;
	
	/**
	 * @var FtpAlternative_TransportInterface データ転送コネクション
	 */
	private $_transfer;
	
	/**
	 * @var int タイムアウト秒数
	 */
	private $_timeout;
	
	/**
	 * コンストラクタ
	 *
	 * @param FtpAlternative_TransportInterface $control
	 * @param FtpAlternative_TransportInterface $transfer
	 */
	public function __construct(FtpAlternative_TransportInterface $control = null, FtpAlternative_TransportInterface $transfer = null)
	{
		if ($control === null)
		{
			$control = new FtpAlternative_TransportStream();
		}
		
		if ($transfer === null)
		{
			$transfer = new FtpAlternative_TransportStream();
		}
		
		$this->_control = $control;
		$this->_transfer = $transfer;
	}
	
	/**
	 * デストラクタ
	 */
	 public function __destruct()
	 {
	 	$this->close();
	 }
	
	/**
	 * FTP サーバに接続する
	 *
	 * @param string $host
	 * @param int    $port
	 * @param int    $timeout
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function connect($host, $port, $timeout)
	{
		// 切断
		$this->close();
		
		// コントロール接続
		$this->_control->connect($host, $port, $timeout);
		
		try
		{
			$this->_timeout = $timeout;
			
			$resp = $this->_recvResponse();
			
			if ($resp->code != 220)
			{
				throw new FtpAlternative_FtpException("connect(): returned \"$resp\"", $resp);
			}
		}
		catch (Exception $ex)
		{
			try
			{
				$this->_control->close();
			}
			catch (Exception $ex)
			{}
			
			throw $ex;
		}
	}
	
	/**
	 * 接続を強制的に閉じる
	 *
	 * @throws RuntimeException
	 */
	public function close()
	{
		if ($this->_control->connected())
		{
			try
			{
				$this->_control->close();
			}
			catch (Exception $ex)
			{
				if ($this->_transfer->connected())
				{
					try
					{
						$this->_transfer->close();
					}
					catch (Exception $ex2)
					{}
					
					throw $ex;
				}
			}
		}
		
		if ($this->_transfer->connected())
		{
			$this->_transfer->close();
		}
	}
	
	/**
	 * QUIT コマンドを発行して接続を閉じる
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function quit()
	{
		try
		{
			if ($this->_control->connected())
			{
				$resp = $this->_sendCommand("QUIT");
				
				if ($resp->code != 221)
				{
					throw new FtpAlternative_FtpException("quit(): QUIT command returned \"$resp\"", $resp);
				}
			}
			
			$this->close();
		}
		catch (Exception $ex)
		{
			try
			{
				$this->close();
			}
			catch (Exception $ex2)
			{}
			
			throw $ex;
		}
	}
	
	/**
	 * ログインする
	 *
	 * @param string $user
	 * @param string $pass
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function login($user, $pass)
	{
		$resp = $this->_sendCommand("USER $user");
		
		if ($resp->code == 230)
		{
			return;
		}
		
		if ($resp->code != 331)
		{
			throw new FtpAlternative_FtpException("login(): USER command returned \"$resp\"", $resp);
		}
		
		$resp = $this->_sendCommand("PASS $pass");
	
		if ($resp->code != 230)
		{
			throw new FtpAlternative_FtpException("login(): PASS command returned \"$resp\"", $resp);
		}
	}
	
	/**
	 * PASV コマンドを発行してデータ転送コネクションを得る
	 *
	 * @param string $type
	 *
	 * @return FtpAlternative_Transport
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	private function _connectPassiveTransport($type)
	{
		$resp = $this->_sendCommand("PASV");
	
		if ($resp->code != 227)
		{
			throw new FtpAlternative_FtpException("pasv(): PASV command returned \"$resp\"", $resp);
		}
		
		list ($addr, $port) = $this->_parsePassiveResponse($resp);
		
		$this->_transfer->connect($addr, $port, $this->_timeout);
		
		try
		{
			$resp = $this->_sendCommand("TYPE $type");
			
			if ($resp->code != 200)
			{
				throw new FtpAlternative_FtpException("pasv(): TYPE command returned \"$resp\"", $resp);
			}
			
			return $this->_transfer;
		}
		catch (Exception $ex)
		{
			try
			{
				$this->_transfer->close();
			}
			catch (Exception $ex2)
			{}
			
			throw $ex;
		}
	}
	
	/**
	 * PASV コマンドの応答を解析してホスト名とポート番号を得る
	 *
	 * @param FtpAlternative_FtpResponse $resp
	 *
	 * @return array() ホスト名とポート番号 [ $host, $port ]
	 *
	 * @throws FtpAlternative_FtpException
	 */
	private function _parsePassiveResponse(FtpAlternative_FtpResponse $resp)
	{
		$mesg = $resp->mesg;
		
		if (preg_match("/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/", $mesg, $m) == 0)
		{
			throw new FtpAlternative_FtpException("pasv(): PASV not parsed \"$resp\"", $resp);
		}
		
		$arr = array_slice($m, 1, 6);
		$arr = array_map('intval', $arr);
		
		foreach ($arr as $val)
		{
			if (($val < 0) || ($val > 255))
			{
				throw new FtpAlternative_FtpException("pasv(): PASV invalid number \"$resp\"", $resp);
			}
		}
		
		$addr = $arr[0] . '.' . $arr[1] . '.' . $arr[2] . '.' . $arr[3];
		$port = ($arr[4] << 8) + $arr[5];
		
		return array($addr, $port);
	}
	
	/**
	 * ファイルをダウンロードする
	 *
	 * @param string $fn
	 *
	 * @return string
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function get($fn)
	{
		return $this->_getdata(__FUNCTION__, 'RETR', $fn, 'I');
	}
	
	/**
	 * ファイルをアップロードする
	 *
	 * @param string $fn
	 * @param string $data
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function put($fn, $data)
	{
		$transfer = $this->_connectPassiveTransport('I');
		
		try
		{
			$resp = $this->_sendCommand("STOR $fn");
			
			if (($resp->code != 150) && ($resp->code != 125))
			{
				throw new FtpAlternative_FtpException("put(): STOR command returned \"$resp\"", $resp);
			}
			
			// データ転送
			$transfer->send($data);
			$transfer->close();
		}
		catch (Exception $ex)
		{
			$transfer->close();
			throw $ex;
		}
		
		// 完了の応答
		$resp = $this->_recvResponse();
		
		if (($resp->code != 226) && ($resp->code != 250) && ($resp->code != 200))
		{
			throw new FtpAlternative_FtpException("put(): STOR complete returned \"$resp\"", $resp);
		}
	}
	
	/**
	 * データ転送ポートと接続してコマンドの結果を得る
	 *
	 * @param string $func	呼び出し元関数名
	 * @param string $cmd	FTPコマンド
	 * @param string $arg	引数
	 * @param string $type	データ転送のタイプ ... A or I
	 *
	 * @return string
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws Exception
	 */
	public function _getdata($func, $cmd, $arg, $type)
	{
		$data = "";
		
		$transfer = $this->_connectPassiveTransport($type);
		
		try
		{
			$resp = $this->_sendCommand("$cmd $arg");
			
			if (($resp->code == 226))
			{
				$transfer->close();
				return "";
			}
			
			if (($resp->code != 150) && ($resp->code != 125))
			{
				throw new FtpAlternative_FtpException("$func(): $cmd command returned \"$resp\"", $resp);
			}
			
			// データ受信
			$data = $transfer->recvall();
			$transfer->close();
		}
		catch (Exception $ex)
		{
			$transfer->close();
			throw $ex;
		}
		
		// 完了の応答
		$resp = $this->_recvResponse();
		
		if (($resp->code != 226) && ($resp->code != 250) && ($resp->code != 200))
		{
			throw new FtpAlternative_FtpException("$func(): $cmd complete returned \"$resp\"", $resp);
		}
		
		return $data;
	}
	
	/**
	 * データ転送ポートと接続してコマンドの結果を得る
	 *
	 * @param string $func	呼び出し元関数名
	 * @param string $cmd	FTPコマンド
	 * @param string $arg	引数
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws Exception
	 */
	public function _getlist($func, $cmd, $arg)
	{
		$data = $this->_getdata($func, $cmd, $arg, 'A');
		
		$data = trim($data);
		
		if ($data === "")
		{
			return array();
		}
		
		return explode("\r\n", $data);
	}
	
	/**
	 * ファイルのリストを得る
	 *
	 * @param string $dir
	 *
	 * @return array
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function rawlist($dir)
	{
		return $this->_getlist(__FUNCTION__, 'LIST', $dir, 'A');
	}
	
	/**
	 * ファイルのリストを得る
	 *
	 * @param string $dir
	 *
	 * @return array
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function nlist($dir)
	{
		return $this->_getlist(__FUNCTION__, 'NLST', $dir);
	}
	
	/**
	 * 現在のディレクトリを得る
	 *
	 * @return string
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function pwd()
	{
		$resp = $this->_sendCommand("PWD");
		
		if ($resp->code != 257)
		{
			throw new FtpAlternative_FtpException("pwd(): PWD command returned \"$resp\"", $resp);
		}
		
		if (preg_match('/"(.+)"/', $resp->line, $m) == 0)
		{
			throw new FtpAlternative_FtpException("pwd(): PWD cannot parsd \"$resp\"", $resp);
		}
		
		return $m[1];
	}
	
	/**
	 * ディレクトリを移動する
	 *
	 * @param string $dir
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function chdir($dir)
	{
		$resp = $this->_sendCommand("CWD $dir");
		
		if ($resp->code != 250)
		{
			throw new FtpAlternative_FtpException("chdir(): CWD command returned \"$resp\"", $resp);
		}
	}
	
	/**
	 * ディレクトリを作成する
	 *
	 * @param string $dir
	 *
	 * @return string
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function mkdir($dir)
	{
		$resp = $this->_sendCommand("MKD $dir");
		
		if ($resp->code != 257)
		{
			throw new FtpAlternative_FtpException("mkdir(): MKD command returned \"$resp\"", $resp);
		}
		
		if (preg_match('/"(.+)"/', $resp->line, $m) == 0)
		{
			throw new FtpAlternative_FtpException("mkdir(): MKD cannot parsd \"$resp\"", $resp);
		}
		
		return $m[1];
	}
	
	/**
	 * ディレクトリを削除する
	 *
	 * @param string $dir
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function rmdir($dir)
	{
		$resp = $this->_sendCommand("RMD $dir");
		
		if ($resp->code != 250)
		{
			throw new FtpAlternative_FtpException("rmdir(): RMD command returned \"$resp\"", $resp);
		}
	}
	
	/**
	 * ファイルのパーミッションを設定する
	 *
	 * @param string $fn
	 * @param int    $mode
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function chmod($fn, $mode)
	{
		ASSERT(' is_string($fn) && strlen($fn) ');
		ASSERT(' is_int($mode) ');
		
		$mode = sprintf("%o", $mode);
		
		$resp = $this->_sendCommand("SITE CHMOD $mode $fn");
		
		if ($resp->code != 200)
		{
			throw new FtpAlternative_FtpException("chmod(): SITE CHMOD command returned \"$resp\"", $resp);
		}
	}
	
	/**
	 * ファイルを削除する
	 *
	 * @param string $fn
	 *
	 * @throws FtpAlternative_FtpException
	 * @throws RuntimeException
	 */
	public function delete($fn)
	{
		ASSERT(' is_string($fn) && strlen($fn) ');
		
		$resp = $this->_sendCommand("DELE $fn");
		
		if ($resp->code != 250)
		{
			throw new FtpAlternative_FtpException("delete(): DELE command returned \"$resp\"", $resp);
		}
	}
	
	/**
	 * コマンドを送信して応答を受信する
	 *
	 * @param string $cmd
	 *
	 * @throws RuntimeException
	 */
	private function _sendCommand($cmd)
	{
		$this->_control->send($cmd . "\r\n");
		return $this->_recvResponse();
	}
	
	/**
	 * 応答を受信する
	 *
	 * @return FtpAlternative_FtpResponse
	 *
	 * @throws RuntimeException
	 */
	private function _recvResponse()
	{
		for (;;)
		{
			$line = $this->_control->recvline();
			
			$line = rtrim($line);
			
			list ($code, $mesg) = $this->_parseResponse($line);
			
			if ($code !== null)
			{
				$resp = new FtpAlternative_FtpResponse($code, $mesg, $line);
				return $resp;
			}
		}
	}
	
	/**
	 * レスポンスを解析する
	 *
	 * 戻り値は [ $code, $mesg ] の形式
	 *
	 *   $code  レスポンスコード、解析出来ない場合は null
	 *   $mesg  メッセージ、解析出来ない場合は null
	 *
	 * @param string $line
	 * @return array
	 */
	private function _parseResponse($line)
	{
		ASSERT(' is_string($line) ');
		
		$code = null;
		$mesg = "";
		
		$arr = explode(" ", $line, 2);
		
		if (count($arr) < 2)
		{
			list ($code) = $arr;
		}
		else
		{
			list ($code, $mesg) = $arr;
		}
		
		if (strlen($code) !== 3)
		{
			return array(null, null);
		}
		
		if (ctype_digit($code) === false)
		{
			return array(null, null);
		}
		
		return array((int)$code, (string)$mesg);
	}
}