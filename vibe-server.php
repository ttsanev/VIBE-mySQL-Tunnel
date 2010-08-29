<?php
/**
 * @author Tsanyo Tsanev
 * @copyright 2010 - Tsanyo Tsanev
 * @version 1.1
 * 
 * @link http://www.ts-tsanev.net
 *
 */

class vibe_tunnel {
	private $app = 'vibe-mysql-tunnel';
	private $version = '1.1';
	private $auth = null;
	private $debug_me = false;
	private $report = array();
	private $cwd = '';
	
	private $con = null;
	private $xml = '';
	private $close_status = 'OK';
	private $close_msg = '';
	
	private $return_types = array('VIBE-NUM' => MYSQL_NUM, 'VIBE-ASSOC' => MYSQL_ASSOC, 'VIBE-BOTH' => MYSQL_BOTH);
	private $action_index = 0;
	
	public function __construct() {
		$this->cwd = getcwd();
		$this->auth = md5('vib-tunnel>>'.date('Y-m')).'_'.md5('>>'.str_replace('www.','',$_SERVER['HTTP_HOST']));
	}
	
	public function __destruct() {
		if (is_resource($this->con)) @mysql_close($this->con);
		if (is_dir($this->cwd.'reports') and $this->debug_me == true) {
			@chmod($this->cwd.'reports', 0777);
			$rep_file = '_report_'.date('Y-m-d-H-i-s').'.html';
			if ($fh = @fopen($this->cwd.'reports/'.$rep_file, 'w')) {
				fwrite($fh, '<html><head><title>'.__CLASS__.' class report</title><meta http-equiv="Content-Type" content="text/html; charset=utf-8" /></head><body bgcolor="white"><h1>'.__CLASS__.' v'.$this->version.' execution report</h1>');
				foreach ($this->report as $line) {
					fwrite($fh, $line.'<br/>');
				}
				fwrite($fh, '</body></html>');
				fclose($fh);
			}
			@chmod($this->cwd.'reports', 0755);
		}
		header("Tunnel-Status: ".$this->close_status);
		if ($this->close_msg != '') header("Tunnel-Error-Msg: ".$this->close_msg);
		if ($this->close_status == 'OK') echo chunk_split(base64_encode($this->xml));
	}
	
	private function debug($msg, $type = E_WARNING) {
		$_msg = '<b>'.__CLASS__.'</b> :: ['.date('Y-m-d H:i:s').'] '.$msg.'';
		switch ($type) {
			case E_WARNING:
				$_msg = '<font color="orange">'.$_msg.'</font>';
			break;
			case E_NOTICE:
				$_msg = '<font color="green">'.$_msg.'</font>';
			break;
			case E_ERROR:
				$_msg = '<font color="red">'.$_msg.'</font>';
			break;
		}
		array_push($this->report, $_msg);
		if ($type == E_ERROR) { $this->close_status = 'Error'; $this->close_msg = $msg; exit(); }
	}
	
	public function set_debug($debug) {
		if (is_bool($debug)) { $this->debug_me = $debug; return true; }
		else return false;
	}
	
	public function listen() {
		$auth = trim($_SERVER['HTTP_AUTH']);
		if ($auth != $this->auth) $this->debug('Tunnel requres authorization!', E_ERROR);
		$request = file_get_contents("php://input");
		if (strlen($request) == 0) {
			$this->debug('Request is empty!', E_ERROR);
		} else $request = trim(base64_decode($request));
		
		$x = $this->read_request($request);
		
		if (empty($x['actions'])) $this->debug('Nothing to do...', E_ERROR);
		
		if (!$this->connect($x['settings'])) { $this->debug('Unable to connect to mySQL!', E_ERROR); exit(); }
		$response = array();
		foreach ($x['actions'] as $index=>$action) {
			$this->action_index = $index;
			if (method_exists($this, 'do_'.$action['type']) and is_callable(array($this, 'do_'.$action['type']))) {
				$response[$index] = $this->{'do_'.$action['type']}($action);
			}
		}
		$this->buildxml($response);
		exit();
	}
	
	private function connect($settings) {
		$con = @mysql_connect($settings['host'], $settings['user'], $settings['pass']);
		if (!$con) {
			$this->debug('Unable to connect to mySQL!', E_ERROR);
			return false;
		}
		$this->con = $con;
		if (!$this->change_db($settings['database'])) { $this->debug('Unable to select the DB!', E_ERROR); return false; }
		return true;
	}
	
	private function change_db($database) {
		if (!is_resource($this->con)) $this->debug('Not connected to SQL!', E_ERROR);
		if (is_string($database) and $database != '') {
			$database = preg_replace("'[^A-z0-9\-\_]'isu", '', $database);
			if (@mysql_select_db($database, $this->con)) { @mysql_query("set names utf8", $this->con); return true; }
			else { $this->debug('Unable to select the DB!', E_ERROR); return false; }
		}
		return false;
	}
	
	private function do_change_db($params) {
		if ($this->change_db($params['data'])) return array('result'=>'success');
		else return array('result'=>'error');
	}
	
	private function do_ping($params) {
		return array('result' => 'success');	
	}
	
	private function do_query($params) {
		if (!is_resource($this->con)) $this->debug('Not connected to SQL!', E_ERROR);
		if (!is_array($params) or $params['data'] == '' or !is_string($params['data'])) $this->debug($this->action_index.': Invalid query', E_ERROR);
		$res = @mysql_query($params['data'], $this->con);
		if (!$res) return array('result'=>'error', 'error'=>$this->action_index.': Error in query: '.mysql_error());
		// Gather all possible data
		$result = array();
		$result['result'] = 'success';
		$result['numrows'] = @mysql_num_rows($res);
		$result_pointer = -1;
		while ($x = @mysql_fetch_array($res, $this->return_types[$params['return']])) {
			$result_pointer++;
			foreach ($x as $key=>$val) {
				$result['set'][$result_pointer][$key] = $val;
			}
		}
		$result['insertid'] = @mysql_insert_id();
		$result['affectedrows'] = @mysql_affected_rows();
		return $result;
	}
	
	private function buildxml($response) {
		if (empty($response)) { $this->debug('Empty response', E_ERROR); }
		$xml = new DOMDocument('1.0', 'UTF-8');
		$root = $xml->createElement('response');
		$meta = $xml->createElement('meta');
		$meta->setAttribute('app', $this->app);
		$meta->setAttribute('ver', $this->version);
		$root->appendChild($meta);
		foreach ($response as $index=>$resp) {
			$act = $xml->createElement('action');
			$act->setAttribute('index', $index);
			$act->setAttribute('result', $resp['result']);
			if ($resp['result'] == 'error') {
				$text = $xml->createCDATASection($resp['error']);
				$act->appendChild($text);
			} else {
				$act->setAttribute('numrows', $resp['numrows']);
				$act->setAttribute('affectedrows', $resp['affectedrows']);
				$act->setAttribute('insertid', $resp['insertid']);
				if (is_array($resp['set']) and !empty($resp['set'])) {
					foreach ($resp['set'] as $row_index=>$row) {
						$xml_row = $xml->createElement('row');
						$xml_row->setAttribute('index', $row_index);
						foreach ($row as $fld_name=>$fld_val) {
							$field = $xml->createElement('field');
							$field->setAttribute('name',$fld_name);
							$value = $xml->createCDATASection($fld_val);
							$field->appendChild($value);
							$xml_row->appendChild($field);
						}
						$act->appendChild($xml_row);
					}
				}	
			}
			$root->appendChild($act);
		}
		$xml->appendChild($root);
		$this->xml = $xml->saveXML();
		$this->close_status = 'OK';
		$this->close_msg = '';
	}
	
	private function read_request($xml) {
		if (!is_string($xml) or $xml == '') return array();
		$objxml = @simplexml_load_string($xml);
		if (!$objxml) $this->debug('Invalid XML data!', E_ERROR);
		if ($objxml->meta['app'] != $this->app or $objxml->meta['ver'] != $this->version) $this->debug('Invalid application or version!', E_ERROR);
		$settings = array();
		if ($objxml->mysql) {
			$settings['user'] = (string)$objxml->mysql['user'];
			$settings['pass'] = (string)$objxml->mysql['pass'];
			$settings['host'] = (string)$objxml->mysql['host'];
			$settings['database'] = (string)$objxml->mysql['database'];
		}
		$actions = array();
		if ($objxml->perform) {
			$per_pointer = 0;
			foreach ($objxml->perform as $perform) {
				if (array_key_exists((string)$perform['return'], $this->return_types)) $actions[$per_pointer]['return'] = (string) $perform['return'];
				else $actions[$per_pointer]['return'] = 'VIBE-BOTH';
				$actions[$per_pointer]['type'] = (string) $perform['type'];
				$actions[$per_pointer]['data'] = (string) $perform;
			}
		}
		return array('actions'=>$actions, 'settings'=>$settings);
	}
}
$vts = new vibe_tunnel();
$vts->listen();
?>