<?php
exit();
/**
 * @author Tsanyo Tsanev
 * @copyright 2010 - Tsanyo Tsanev
 * @version 1.1
 * 
 * @link http://www.ts-tsanev.net
 *
 */

class vibe_tunnel_cli {
	
	public $connected = false;
	
	private $app = 'vibe-mysql-tunnel';
	private $version = '1.1';
	private $auth = null;
	private $debug_me = false;
	private $report = array();
	private $execute = true;
	
	private $xml = '';
	private $parsedXML = null;
	private $action_pointer = 0;
	
	private $rqxml = null;
	
	private $tunnel_host = null;
	private $tunnel_url = null;
	
	private $db_host = null;
	private $db_user = null;
	private $db_pass = null;
	private $db_database = null;
	
	private $return_types = array('VIBE-NUM', 'VIBE-ASSOC', 'VIBE-BOTH');
	
	public function vibe_tunnel_cli($tunnel_host, $tunnel_url, $host, $username, $password, $database) {
		$this->debug("Class loaded", E_NOTICE);
		$this->auth = md5('vib-tunnel>>'.date('Y-m')).'_'.md5('>>'.$tunnel_host);
		$this->cwd = getcwd();
		$this->parsedXML = array();
		$this->tunnel_host = $tunnel_host;
		$this->tunnel_url = $tunnel_url;
		$this->db_host = $host;
		$this->db_user = $username;
		$this->db_pass = $password;
		$this->db_database = $database;
		
		$this->new_request_xml();
		
		if ($this->check_connection() != true) {
			$this->debug("Unable to establish connection", E_ERROR);
			$this->execute = false;
			$this->connected = false;
		} else $this->debug("Connection established", E_NOTICE);
		$this->connected = true;
	}
	
	public function __destruct() {
		$this->debug("Class unloaded", E_NOTICE);
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
		if ($this->debug_me === true) echo $_msg.'<br/>';
	}
	
	public function set_debug($debug) {
		if (is_bool($debug)) { $this->debug_me = $debug; return true; }
		else return false;
	}
	
	private function new_request_xml() {
		$xml = new DOMDocument('1.0', 'UTF-8');
		$r = $xml->createElement('request');
		$meta = $xml->createElement('meta');
		$meta->setAttribute('app', $this->app);
		$meta->setAttribute('ver', $this->version);
		$r->appendChild($meta);
		$mysql = $xml->createElement('mysql');
		$mysql->setAttribute('user', htmlentities($this->db_user, ENT_QUOTES));
		$mysql->setAttribute('pass', htmlentities($this->db_pass, ENT_QUOTES));
		$mysql->setAttribute('host', htmlentities($this->db_host, ENT_QUOTES));
		$mysql->setAttribute('database', htmlentities($this->db_database, ENT_QUOTES));
		$r->appendChild($mysql);
		$xml->appendChild($r);
		$this->rqxml = $xml;
		return true;
	}
	
	private function make_request() {
		if ($this->execute == false) return false;
		$req =  $this->rqxml->saveXML();
		$req = base64_encode($req);
		$this->new_request_xml();
		
		$reqest_base =  "POST ".$this->tunnel_url." HTTP/1.1\r\n";
		$reqest_base .= "Host: ".$this->tunnel_host."\r\n";
		$reqest_base .= "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.1.4) Gecko/20091016 Firefox/3.5.4\r\n";
		$reqest_base .= "Auth: ".$this->auth."\r\n";
		$reqest_base .= "Content-Type: application/x-www-form-urlencoded\r\n";
		$reqest_base .= "Keep-Alive: 300\r\n";
		
		$con = fsockopen($this->tunnel_host, 80);
		if (!$con) { $this->debug("Unable to connect to tunnel", E_ERROR); return false; }
		$reqest = $reqest_base."Content-Length: " . strlen($req) . "\r\n\r\n".$req;
		fputs($con, $reqest);
		$response = array(); $in_headers = true; $error_type = 'none'; $transfer_encoding = 'normal';
		while (!feof($con)) {
			$line = fgets($con, 1024);
			if ($in_headers) {
				if (strpos($line, "HTTP/1.1") !== false) {
					if (trim($line) != 'HTTP/1.1 200 OK') { $error_type = 'HTTP'; break; }
				}
				if (strpos($line, "Tunnel-Status") !== false) {
					preg_match("'^Tunnel-Status: (.*?)$'isu", $line, $res);
					$tunnel_status = trim($res[1]);
				}
				if (strpos($line, "Tunnel-Error-Msg:") !== false) {
					preg_match("'^Tunnel-Error-Msg: (.*?)$'isu", $line, $res);
					$tunnel_error = trim($res[1]);
				}
				if (strpos($line, "Transfer-Encoding:") !== false) {
					preg_match("'^Transfer-Encoding: (.*?)$'isu", trim($line), $res);
					$transfer_encoding = trim($res[1]);
				}
				$full_headers .= $line.'<br/>';
			}
			if (!$in_headers) $response[] = $line;
			if ($line == "\r\n") $in_headers = false;
		}
		fclose($con);
		if ($error_type != 'none') {
			$this->debug("An error occured with the request: ".$error_type, E_ERROR);
			return false;
		}
		if ($tunnel_status != 'OK') {
			$this->debug("Tunnel error: ".$tunnel_error, E_ERROR);
			return false;
		} elseif ($action == 'check-conn') {
			return true;
		}
		if (empty($response)) {
			$this->debug('Request is empty!', E_ERROR);
			return false;
		}
		switch ($transfer_encoding) {
			case 'chunked':
				$chunk_size = 0;
				$chunk_content = '';
				$real_response = '';
				$new_chunk = true;
				$size = 0;
				foreach ($response as $line) {
					if ($new_chunk) {
						$chunk_size = (int)hexdec(trim($line));
						$new_chunk = false;
						continue;
					}
					if ($size == $chunk_size) {
						if (trim($line) == '') {
							$size = 0;
							$new_chunk = true;
							$real_response .= $chunk_content;
							$chunk_content = '';
							continue;
						}
						$this->debug('Invalid response received!', E_ERROR);
						return false;
					}
					$size += strlen($line);
					$chunk_content .= trim($line);
				}
				$response = $real_response;
			break;
			case 'normal':
				$response = implode('', $response);
			break;
			default:
				$this->debug('This class does not yet support the transfer encoding specified. Please check for updates or feel free to write an interpretation yourself. Thank you.', E_ERROR);
				return false;
			break;
		}
		$response = trim(base64_decode($response));
		
		$this->xml = $response;
		return $this->read_response();
	}
	
	private function perform($type, $data='', $return = 'VIBE-BOTH') {
		$xml = $this->rqxml;
		$node = $xml->createElement('perform');
		$node->setAttribute('type', htmlentities($type, ENT_QUOTES));
		if (in_array($return, $this->return_types)) $node->setAttribute('return', $return);
		else $node->setAttribute('return', 'VIBE-BOTH');
		if (is_string($data) and $data != '') {
			$rq = $xml->createCDATASection($data);
			$node->appendChild($rq);
		}
		$xml->getElementsByTagName('request')->item(0)->appendChild($node);
		$this->rqxml = $xml;
		return true;
	}
	
	private function read_response() {
		if (!is_string($this->xml) or $this->xml == '') return false;
		$xml = @simplexml_load_string($this->xml);
		if (!$xml) $this->debug('Invalid XML data received!', E_ERROR);
		if ($xml->meta['app'] != $this->app or $xml->meta['ver'] != $this->version) $this->debug('Invalid application or version!', E_ERROR);
		$this->action_pointer = 0;
		if ($xml->action) {
			$result = array();
			foreach ($xml->action as $transaction) {
				$result[(int)$action['index']] = array();
				$r = &$result[(int)$action['index']];
				if ($transaction['result'] == 'success') {
					$r['info']['numrows'] = (string) $transaction['numrows'];
					$r['info']['affectedrows'] = (string) $transaction['affectedrows'];
					$r['info']['insertid'] = (string) $transaction['insertid'];
					$r['set'] = array();
					foreach ($transaction->row as $row) {
						foreach ($row->field as $field) {
							$field_name = (string) $field['name'];
							if (ctype_digit($field_name)) {
								$r['set']['numeric'][(int)$row['index']][(int)$field_name] = (string) $field;
							} else {
								$field_name = preg_replace("'[^A-z0-9\_]'isu", '', $field_name);
								$r['set']['assoc'][(int)$row['index']][$field_name] = (string) $field;
							}
						}
					}
					$r['error'] = false;
				} else {
					$r['error'] = (string) $transaction;
				}
			}
			$this->parsedXML = $result;
			return true;
		} else {
			$this->debug('No actions where executed!', E_WARNING);
			return true;
		}
	}
	
	public function check_connection() {
		$this->perform('ping');
		return $this->make_request();
	}

	public function exec() {
		if ($this->make_request()) {
			$this->debug("All transacions executed", E_NOTICE);
			return true;
		} else {
			$this->debug("Unable to execute the transactions", E_ERROR);
			return false;
		}
	}
	
	public function query($query, $return = 'VIBE-BOTH') {
		if ($this->execute == false) {
			$this->debug("Can not execute query!", E_ERROR);
			return false;
		}
		if (!is_string($query) or $query == '') {
			$this->debug("Invalid query", E_WARNING);
			return false;
		}
		$this->perform('query', $query, $return);
		return true;
	}
	
	public function fetch_array($type="numeric") {
		if (!in_array($type, array('numeric', 'assoc'))) $type = 'numeric';
		if (empty($this->parsedXML) and is_array($this->parsedXML[$this->action_pointer]['set'])) {
			$this->debug("No Result!", E_WARNING);
			return false;
		}
		if ($this->parsedXML[$this->action_pointer]['error'] !== false) {
			$this->debug("Current transaction (".$this->action_pointer.") has error!", E_WARNING);
			return false;
		}
		if (array_key_exists($type, $this->parsedXML[$this->action_pointer]['set']) and !empty($this->parsedXML[$this->action_pointer]['set'][$type])) {
			return $this->parsedXML[$this->action_pointer]['set'][$type];
		} else {
			if ($type == 'numeric') {
				$this->debug("The numeric result set for this action is empty, associatve returned instead!", E_WARNING);
				return $this->parsedXML[$this->action_pointer]['set']['assoc'];
			} else {
				$this->debug("The associative result set for this action is empty, numeric returned instead!", E_WARNING);
				return $this->parsedXML[$this->action_pointer]['set']['numeric'];
			}
		}
	}
	
	public function fetch_assoc() {
		return $this->fetch_array('assoc');
	}
	
	public function fetch_numeric() {
		return $this->fetch_array('numeric');
	}
	
	public function insert_id() {
		if (empty($this->parsedXML)) {
			$this->debug("No Result!", E_WARNING);
			return false;
		}
		if ($this->parsedXML[$this->action_pointer]['error'] !== false) {
			$this->debug("Current transaction (".$this->action_pointer.") has error!", E_WARNING);
			return false;
		}
		if ($this->parsedXML[$this->action_pointer]['info']['insertid'] == '') {
			$this->debug("No insert id value!", E_WARNING);
			return false;
		}
		return $this->parsedXML[$this->action_pointer]['info']['insertid'];
	}
	
	public function affected_rows() {
		if (empty($this->parsedXML)) {
			$this->debug("No Result!", E_WARNING);
			return false;
		}
		if ($this->parsedXML[$this->action_pointer]['error'] !== false) {
			$this->debug("Current transaction (".$this->action_pointer.") has error!", E_WARNING);
			return false;
		}
		if ($this->parsedXML[$this->action_pointer]['info']['affectedrows'] == '') {
			$this->debug("No affected rows value!", E_WARNING);
			return false;
		}
		return$this->parsedXML[$this->action_pointer]['info']['affectedrows'];
	}
	
	public function num_rows() {
		if (empty($this->parsedXML)) {
			$this->debug("No Result!", E_WARNING);
			return false;
		}
		if ($this->parsedXML[$this->action_pointer]['error'] !== false) {
			$this->debug("Current transaction (".$this->action_pointer.") has error!", E_WARNING);
			return false;
		}
		if ($this->parsedXML[$this->action_pointer]['info']['numrows'] == '') {
			$this->debug("No num rows value!", E_WARNING);
			return false;
		}
		return $this->parsedXML[$this->action_pointer]['info']['numrows'];
	}
	
	public function get_xml() {
		return $this->xml;
	}
	
	public function get_report() {
		return $this->report;
	}
	
	public function next_transaction() {
		$this->action_pointer++;
		if (is_array($this->parsedXML[$this->action_pointer])) return true;
		else {
			$this->action_pointer--;
			$this->debug('No more transactions in this result set!', E_WARNING);
			return false;
		}
	}
	
	public function count_transactions() {
		return count($this->parsedXML);
	}
	
	public function get_transaction_error() {
		return $this->parsedXML[$this->action_pointer]['error'];
	}
}
?>